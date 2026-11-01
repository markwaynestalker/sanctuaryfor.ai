"""No systemd installation, credentials, SQLite mutation or network requests."""
from datetime import datetime, timezone
import importlib.util
import json
import os
import shutil
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch
from types import SimpleNamespace

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('gazette_run', ROOT / 'ops/gazette/run.py')
job = importlib.util.module_from_spec(spec)
spec.loader.exec_module(job)


class GazetteOperations(unittest.TestCase):
    def test_calendar_window_dst_and_finished_dates(self):
        cases = [('2026-03-08T09:15:00', '2026-03-07'),
                 ('2026-03-09T09:19:59', '2026-03-08'),
                 ('2026-11-01T10:15:00', '2026-10-31'),
                 ('2026-11-02T10:15:00', '2026-11-01'),
                 ('2027-01-01T10:15:00', '2026-12-31')]
        for instant, expected in cases:
            now = datetime.fromisoformat(instant).replace(tzinfo=timezone.utc)
            self.assertEqual(job.edition(now, True), expected)
        for instant in ['2026-11-01T10:14:59', '2026-11-01T10:20:00',
                        '2026-11-01T06:15:00', '2026-11-01T05:15:00']:
            with self.assertRaises(ValueError):
                job.edition(datetime.fromisoformat(instant).replace(tzinfo=timezone.utc), True)
        now = datetime(2026, 11, 2, 12, tzinfo=timezone.utc)
        self.assertEqual(job.edition(now, False, '2026-11-01'), '2026-11-01')
        for day in ['2026-11-02', '2026-11-03', '2026-02-30', '2026-1-01', '../2026-01-01']:
            with self.assertRaises(ValueError):
                job.edition(now, False, day)

    def test_release_pin_and_no_moving_script(self):
        with tempfile.TemporaryDirectory() as directory:
            base = Path(directory).resolve()
            sha = 'a' * 40
            release = base / sha
            release.mkdir()
            (release / '.release-commit').write_text(sha + '\n', encoding='ascii')
            # Use the physical directory as CURRENT; symlinks aren't needed on Windows tests.
            with patch.object(job, 'RELEASES', base), patch.object(job, 'CURRENT', release), patch.object(job.os, 'access', return_value=False):
                self.assertEqual(job.release(sha), release)
                self.assertEqual(job.command(release)[-1], '--allow-paid=yes')
                self.assertIn(str(release / 'scripts/generate-gazette-brokered.php'), job.command(release))
                self.assertFalse(any('--database' in arg or '--date=' in arg for arg in job.command(release)))
                self.assertNotIn('--allow-paid=yes', job.command(release, check=True))
                for invalid in ['main', 'A' * 40, sha + '/..', '']:
                    with self.assertRaises(ValueError):
                        job.release(invalid)
                (release / '.release-commit').write_text('b' * 40 + '\n', encoding='ascii')
                with self.assertRaises(ValueError):
                    job.release(sha)

    def test_disabled_has_no_subprocess_or_path_access(self):
        env = {k: v for k, v in os.environ.items() if not k.startswith(('OPENAI_', 'LITTLE_SPARKS_'))}
        env['LITTLE_SPARKS_GAZETTE_EDITOR_ENABLED'] = '0'
        result = subprocess.run([sys.executable, '-B', '-I', str(ROOT / 'ops/gazette/run.py'), '--scheduled'],
                                env=env, capture_output=True, text=True, check=True)
        self.assertEqual(json.loads(result.stdout), {'status': 'disabled', 'paid_requests': 0})
        with patch.object(sys, 'argv', ['run.py', '--scheduled']), patch.dict(os.environ, env, clear=True), \
                patch.object(job, 'release', side_effect=AssertionError('release read')), \
                patch.object(job.subprocess, 'run', side_effect=AssertionError('subprocess')):
            self.assertEqual(job.main(), 0)

    def test_receipts_do_not_log_private_fields_or_tokens(self):
        raw = json.dumps({'status': 'awaiting_owner_review', 'attempt_id': 'a' * 32,
                          'draft_sha256': 'b' * 64, 'input_tokens': 9000,
                          'sources': 'PRIVATE', 'draft': 'PRIVATE', 'key': 'PRIVATE'})
        self.assertEqual(job.safe_result(raw), {'status': 'awaiting_owner_review',
                         'attempt_id': 'a' * 32, 'draft_sha256': 'b' * 64})
        for bad in ['PRIVATE', '{"status":"PRIVATE"}', 'x' * 4097, '[]']:
            with self.assertRaises(ValueError):
                job.safe_result(bad)

    def test_rejection_codes_are_fixed_and_private_details_are_discarded(self):
        php = (ROOT / 'app/gazette_rejections.php').read_text()
        block = php.split('const EDITORIAL_REJECTION_CODES=[', 1)[1].split('];', 1)[0]
        import re
        self.assertEqual(job.REJECTION_CODES, set(re.findall(r"'([a-z_]+)'", block)))
        for code in job.REJECTION_CODES:
            result = job.safe_result(json.dumps({'status': 'rejected_or_uncertain',
                'rejection_code': code, 'exception': 'PRIVATE_CANARY', 'draft': 'PRIVATE_CANARY'}))
            self.assertEqual(result, {'status': 'rejected_or_uncertain', 'rejection_code': code})
        for code in ['PRIVATE_CANARY', 'draft_story_shape\nPRIVATE_CANARY', [], {}, None]:
            self.assertEqual(job.safe_result(json.dumps({'status': 'rejected_or_uncertain',
                'rejection_code': code})), {'status': 'rejected_or_uncertain'})
        self.assertEqual(job.safe_result(json.dumps({'status': 'preflight_ready',
            'rejection_code': 'draft_story_shape'})), {'status': 'preflight_ready'})

    def test_enabled_invokes_once_and_never_retries_unknown_outcome(self):
        env = {'LITTLE_SPARKS_GAZETTE_EDITOR_ENABLED': '1',
               'LITTLE_SPARKS_GAZETTE_EDITOR_RELEASE_SHA': 'a' * 40}
        for outcome in [SimpleNamespace(returncode=0, stdout=b'{"status":"awaiting_owner_review","input_tokens":123}'),
                        SimpleNamespace(returncode=1, stdout=b'{"status":"rejected_or_uncertain","attempt_id":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"}')]:
            with patch.object(sys, 'argv', ['run.py', '--once']), \
                    patch.dict(os.environ, env, clear=True), patch.object(sys, 'platform', 'linux'), \
                    patch.object(job.os, 'geteuid', return_value=1001, create=True), \
                    patch.object(job, 'release', return_value=Path('/reviewed/release')), \
                    patch.object(job.subprocess, 'run', return_value=outcome) as invoke:
                self.assertEqual(job.main(), 0 if outcome.returncode == 0 else 1)
                invoke.assert_called_once()
                argv = invoke.call_args.args[0]
                self.assertEqual(argv[-1], '--allow-paid=yes')
                self.assertFalse(any('--database' in arg or '--date=' in arg for arg in argv))
                self.assertNotIn('shell', invoke.call_args.kwargs)
        with patch.object(sys, 'argv', ['run.py', '--once']), \
                patch.dict(os.environ, env, clear=True), patch.object(sys, 'platform', 'linux'), \
                patch.object(job.os, 'geteuid', return_value=1001, create=True), \
                patch.object(job, 'release', return_value=Path('/reviewed/release')), \
                patch.object(job.subprocess, 'run', side_effect=subprocess.TimeoutExpired('php', 135)) as invoke:
            self.assertEqual(job.main(), 1)
            invoke.assert_called_once()

    def test_unit_policy_and_explicit_reservation_math(self):
        service = (ROOT / 'ops/systemd/sanctuary-gazette-editor.service').read_text()
        timer = (ROOT / 'ops/systemd/sanctuary-gazette-editor.timer').read_text()
        for setting in ['User=sanctuarygazette', 'Group=sanctuarygazette', 'Restart=no',
                        'ProtectSystem=strict', 'ProtectControlGroups=true', 'NoNewPrivileges=true',
                        'LoadCredential=openai_api_key:/etc/sanctuary-gazette/openai_api_key',
                        'EnvironmentFile=/etc/sanctuary-gazette/editor.env', 'UMask=0077',
                        'LimitCORE=0', 'TimeoutStartSec=150s']:
            self.assertIn(setting, service)
        self.assertNotIn('[Install]', '\n'.join(x for x in service.splitlines() if not x.startswith('#')))
        for banned in ['simulation.env', 'User=root', 'User=www-data', 'PrivateUsers=',
                       'PrivateNetwork=true', 'ProtectProc=invisible', 'ExecStartPre=+', 'ExecStart=+']:
            self.assertNotIn(banned, service)
        self.assertIn('OnCalendar=*-*-* 05:15:00 America/New_York', timer)
        self.assertIn('Persistent=false', timer)
        self.assertNotIn('OnBootSec=', timer)
        self.assertIn('Requires=sanctuary-gazette-db.socket', service)
        self.assertIn('InaccessiblePaths=/var/lib/sanctuaryfor-ai', service)
        self.assertNotIn('ReadWritePaths=', service)
        example = dict(line.split('=', 1) for line in
                       (ROOT / 'ops/systemd/gazette-editor.env.example').read_text().splitlines()
                       if line and not line.startswith('#'))
        prefix = 'LITTLE_SPARKS_GAZETTE_EDITOR_'
        n = lambda name: int(example[prefix + name])
        self.assertEqual(n('ENABLED'), 0)
        cost = (n('MAX_INPUT_TOKENS') * 250000 + 999999) // 1000000
        cost += (n('MAX_OUTPUT_TOKENS') * 500000 + 999999) // 1000000 + n('MODERATION_MICROUSD')
        self.assertEqual(cost, 30000)
        self.assertLessEqual(cost, n('DAILY_MICROUSD'))
        self.assertLessEqual(31 * cost, n('MONTHLY_MICROUSD'))
        self.assertEqual(n('DAILY_REQUESTS'), 2)
        self.assertLessEqual(n('MAX_REQUEST_BYTES') + 4096, n('MAX_INPUT_TOKENS'))

    @unittest.skipUnless(sys.platform == 'linux' and shutil.which('systemd-analyze'), 'Ubuntu systemd parser required')
    def test_linux_systemd_parser_without_installing_or_running_units(self):
        # Verify a disposable copy: only the absent installed helper path is replaced.
        # systemd-analyze parses directives/dependencies; it does not execute ExecStart.
        with tempfile.TemporaryDirectory() as directory:
            base = Path(directory)
            unit = 'sanctuary-gazette-editor.service'
            content = (ROOT / 'ops/systemd' / unit).read_text().replace(
                '/usr/local/lib/sanctuary-gazette/run.py', str(ROOT / 'ops/gazette/run.py'))
            (base / unit).write_text(content)
            for helper in ['sanctuary-gazette-db.socket', 'sanctuary-gazette-db@.service', 'sanctuary-gazette-runtime.service']:
                shutil.copyfile(ROOT / 'ops/systemd' / helper, base / helper)
            timer = 'sanctuary-gazette-editor.timer'
            shutil.copyfile(ROOT / 'ops/systemd' / timer, base / timer)
            parsed = subprocess.run(['systemd-analyze', 'verify', str(base / unit), str(base / timer)],
                                    capture_output=True, text=True)
            self.assertEqual(parsed.returncode, 0, parsed.stderr)
            calendar = subprocess.run(['systemd-analyze', 'calendar', '*-*-* 05:15:00 America/New_York'],
                                      capture_output=True, text=True)
            self.assertEqual(calendar.returncode, 0, calendar.stderr)
        guide = (ROOT / 'docs/GAZETTE_OPERATIONS.md').read_text()
        self.assertIn('Activation is BLOCKED', guide)
        self.assertIn('Do not work around this', guide)


if __name__ == '__main__':
    unittest.main()
