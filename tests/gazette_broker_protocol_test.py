import importlib.util
import json
import os
import shutil
from pathlib import Path
import socket
import struct
import subprocess
import sys
import threading
import unittest
from types import SimpleNamespace
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('db_helper', ROOT / 'ops/gazette/db_helper.py')
helper = importlib.util.module_from_spec(spec)
spec.loader.exec_module(helper)


class Protocol(unittest.TestCase):
    def test_duplicate_keys_and_malformed_bounds(self):
        for raw in [b'{"op":1,"op":2}', b'[]', b'{"x":NaN}', b'\xff', b'{',
                    ('{"x":' * 40 + '0' + '}' * 40).encode()]:
            with self.assertRaises((ValueError, UnicodeError)):
                helper.decode(raw)
        with self.assertRaises(ValueError):
            helper.encode({'x': 'a' * helper.MAX_FRAME})
        self.assertEqual(helper.decode(b'{"op":"ready"}'), {'op': 'ready'})

    def test_fixed_command_and_keyless_environment(self):
        source = (ROOT / 'ops/gazette/db_helper.py').read_text()
        self.assertNotIn('shell=True', source)
        self.assertIn('socket.SO_PEERCRED', source)
        unit = (ROOT / 'ops/systemd/sanctuary-gazette-db@.service').read_text()
        self.assertIn('PrivateNetwork=true', unit)
        self.assertIn('RestrictAddressFamilies=AF_UNIX', unit)
        self.assertIn('StandardInput=socket', unit)
        self.assertNotIn('LoadCredential=', unit)
        self.assertNotIn('User=root', unit)
        self.assertNotIn('EnvironmentFile=', unit)
        self.assertIn('Accept=yes', (ROOT / 'ops/systemd/sanctuary-gazette-db.socket').read_text())

    @unittest.skipUnless(sys.platform == 'linux', 'Linux peer credentials required')
    def test_real_socket_frame_and_peer_before_subprocess(self):
        cfg = {'editor_uid': os.getuid(), 'database': '/private/not-opened-by-test',
               'release': '/reviewed/' + 'a' * 40, 'approved_date': None,
               'environment': {helper.PREFIX + key: '0' for key in helper.ENV_KEYS}}
        for raw, accepted in [(b'{"op":"ready"}', True), (b'{"op":"ready","op":"sql"}', False),
                              (b'{"op":"ready"}TRAILING', False)]:
            client, server = socket.socketpair()
            # Kernel credentials are real; only the distinct service identity is simulated here.
            with client, server, patch.object(helper.os, 'geteuid', return_value=os.getuid() + 1), \
                    patch.object(helper.subprocess, 'run', return_value=SimpleNamespace(stdout=b'{"status":"preflight_ready"}', returncode=0)) as call:
                client.sendall(struct.pack('!I', len(raw)) + raw)
                client.shutdown(socket.SHUT_WR)
                if accepted:
                    helper.operation(server, cfg)
                    call.assert_called_once()
                    self.assertNotIn('OPENAI_API_KEY', call.call_args.kwargs['env'])
                    self.assertEqual(set(call.call_args.kwargs['env']), {'PATH', 'LANG'} | set(cfg['environment']))
                    length = struct.unpack('!I', helper.exact(client, 4))[0]
                    self.assertEqual(helper.decode(helper.exact(client, length))['status'], 'preflight_ready')
                else:
                    with self.assertRaises(ValueError):
                        helper.operation(server, cfg)
                    call.assert_not_called()
        client, server = socket.socketpair()
        with client, server, patch.object(helper.subprocess, 'run') as call:
            cfg['editor_uid'] = os.getuid() + 2
            with self.assertRaises(ValueError):
                helper.operation(server, cfg)
            call.assert_not_called()

    @unittest.skipUnless(sys.platform == 'linux', 'Linux ancillary data required')
    def test_descriptor_transfer_is_closed_and_refused(self):
        client, server = socket.socketpair()
        with client, server, open('/dev/null', 'rb') as descriptor:
            client.sendmsg([b'1234'], [(socket.SOL_SOCKET, socket.SCM_RIGHTS, struct.pack('i', descriptor.fileno()))])
            with self.assertRaises(ValueError):
                helper.exact(server, 4)

    @unittest.skipUnless(sys.platform == 'linux' and shutil.which('systemd-analyze'), 'Linux unit parser required')
    def test_helper_unit_parser(self):
        result = subprocess.run(['systemd-analyze', 'verify',
            str(ROOT / 'ops/systemd/sanctuary-gazette-db.socket'),
            str(ROOT / 'ops/systemd/sanctuary-gazette-db@.service')], capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stderr)


if __name__ == '__main__':
    unittest.main()
