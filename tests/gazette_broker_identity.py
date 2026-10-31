#!/usr/bin/env python3
"""Root-only disposable Linux UID test. No accounts, units, credentials or network calls."""
import importlib.util
import json
import os
from pathlib import Path
import shutil
import socket
import struct
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('db_helper', ROOT / 'ops/gazette/db_helper.py')
helper = importlib.util.module_from_spec(spec)
spec.loader.exec_module(helper)


def main():
    assert os.geteuid() == 0 and hasattr(os, 'fork'), 'Requires isolated Linux root test runner'
    dbuid, editor, stranger = 64010, 64011, 64012
    env = {'PATH': '/usr/bin:/bin', 'LANG': 'C.UTF-8'}
    values = {'ENABLED': '0', 'PRICING_CONFIRMED': 'gazette-luna-standard-2026-10-31',
              'DAILY_MICROUSD': '50000', 'MONTHLY_MICROUSD': '1000000', 'DAILY_REQUESTS': '2',
              'MODERATION_MICROUSD': '0', 'MAX_INPUT_TOKENS': '96000',
              'MAX_OUTPUT_TOKENS': '12000', 'MAX_REQUEST_BYTES': '90000'}
    policy = {helper.PREFIX + key: value for key, value in values.items()}
    with tempfile.TemporaryDirectory(prefix='gazette-uid-isolation-') as temporary:
        base = Path(temporary)
        base.chmod(0o711)
        private = base / 'private'
        private.mkdir(mode=0o700)
        def php(args, extra=None):
            return subprocess.run(['/usr/bin/php8.3', *args], env={**env, **policy, **(extra or {})},
                                  stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=True).stdout
        php([str(ROOT / 'tests/journal-core.php'), str(private)])
        database = private / 'v6.sqlite'
        shutil.copyfile(private / 'v4.sqlite', database)
        database.chmod(0o600)
        for phase in ['memory', 'identity', 'director', 'gazette-editor']:
            php([str(ROOT / ('scripts/migrate-' + phase + '.php')), '--database=' + str(database), '--confirm-drained=yes'])
        for item in private.iterdir():
            os.chown(item, dbuid, dbuid)
        os.chown(private, dbuid, dbuid)
        release = base / ('a' * 40)
        release.mkdir(mode=0o755)
        for folder in ['app', 'scripts', 'contracts']:
            shutil.copytree(ROOT / folder, release / folder)
        (release / 'public').mkdir(mode=0o755)
        (release / '.release-commit').write_text('a' * 40 + '\n')
        release.chmod(0o555)
        # A credential stand-in, not a real key. No credential contents are used by helper.
        credential_dir = base / 'credential-standin'
        credential_dir.mkdir(mode=0o500)
        credential = credential_dir / 'openai_api_key'
        credential.write_text('NON_SECRET_STANDIN')
        credential.chmod(0o400)
        os.chown(credential, editor, editor)
        os.chown(credential_dir, editor, editor)
        runtime = base / 'socket'
        runtime.mkdir(mode=0o710)
        os.chown(runtime, 0, editor)
        path = runtime / 'editor.sock'
        listener = socket.socket(socket.AF_UNIX)
        listener.bind(str(path))
        os.chown(path, 0, editor)
        path.chmod(0o660)
        listener.listen(2)
        listener.settimeout(10)
        cfg = {'editor_uid': editor, 'database': str(database), 'release': str(release),
               'approved_date': None, 'environment': policy}
        code = 'require "app/village.php"; require "app/gazette_broker.php"; echo LittleSparks\\gazetteBrokerPolicyHash(LittleSparks\\editorialConfiguration(false),90000,str_repeat("a",40));'
        digest = php(['-r', code]).decode()
        message = {'version': 1, 'release': 'a' * 40, 'policy_sha256': digest, 'op': 'ready'}

        def drop(uid):
            os.setgroups([])
            os.setgid(uid)
            os.setuid(uid)

        def inaccessible(uid, target):
            pid = os.fork()
            if pid == 0:
                try:
                    drop(uid)
                    try:
                        with open(target, 'rb'):
                            pass
                    except PermissionError:
                        os._exit(0)
                    os._exit(1)
                except BaseException:
                    os._exit(2)
            assert os.waitpid(pid, 0)[1] == 0, 'Unexpected file access'

        inaccessible(editor, database)
        inaccessible(dbuid, credential)
        inaccessible(stranger, credential)

        # Helper accepts using an inherited listener, exactly as the socket-activated shape.
        server = os.fork()
        if server == 0:
            try:
                drop(dbuid)
                connection, _ = listener.accept()
                with connection:
                    helper.operation(connection, cfg)
                os._exit(0)
            except BaseException:
                os._exit(3)
        client = os.fork()
        if client == 0:
            try:
                drop(editor)
                with socket.socket(socket.AF_UNIX) as connection:
                    connection.settimeout(10)
                    connection.connect(str(path))
                    raw = helper.encode(message)
                    connection.sendall(struct.pack('!I', len(raw)) + raw)
                    connection.shutdown(socket.SHUT_WR)
                    size = struct.unpack('!I', helper.exact(connection, 4))[0]
                    response = helper.decode(helper.exact(connection, size))
                    assert response['status'] == 'preflight_ready'
                os._exit(0)
            except BaseException:
                os._exit(4)
        assert os.waitpid(client, 0)[1] == 0, 'Real editor-to-helper readiness failed'
        assert os.waitpid(server, 0)[1] == 0, 'Real keyless database operation failed'
        # Unrelated UID cannot even connect; root can connect but fails kernel-peer admission.
        denied = os.fork()
        if denied == 0:
            try:
                drop(stranger)
                with socket.socket(socket.AF_UNIX) as connection:
                    try:
                        connection.connect(str(path))
                    except PermissionError:
                        os._exit(0)
                os._exit(5)
            except BaseException:
                os._exit(6)
        assert os.waitpid(denied, 0)[1] == 0
        with socket.socket(socket.AF_UNIX) as root_client:
            root_client.connect(str(path))
            connection, _ = listener.accept()
            with connection:
                try:
                    helper.operation(connection, cfg)
                    raise AssertionError('Root peer accepted')
                except ValueError:
                    pass
        listener.close()
        assert private.stat().st_mode & 0o777 == 0o700
        assert database.stat().st_mode & 0o777 == 0o600
        assert database.stat().st_uid == dbuid
        # Let TemporaryDirectory clean only its own known disposable tree as root.
        credential_dir.chmod(0o700)
        release.chmod(0o755)
    print('PASS real Linux distinct UID denial, root-owned socket permissions, SO_PEERCRED, keyless PHP readonly DB readiness and unchanged 0700/0600')


if __name__ == '__main__':
    main()
