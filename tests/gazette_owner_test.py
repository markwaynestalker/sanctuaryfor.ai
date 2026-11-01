import importlib.util
import hashlib
import io
import os
import json
from pathlib import Path
import stat
import sys
import tempfile
import unittest
from types import SimpleNamespace
from unittest.mock import patch
from datetime import datetime, timezone

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('owner', ROOT / 'ops/gazette/gazette.py')
owner = importlib.util.module_from_spec(spec); spec.loader.exec_module(owner)


class OwnerWorkflow(unittest.TestCase):
    def setUp(self):
        if sys.platform != 'linux':
            # Permission enforcement is exercised on Ubuntu; production entry
            # refuses Windows. Only the local test's file-mode syscall is stubbed.
            patcher=patch.object(owner.os, 'fchmod', create=True)
            patcher.start(); self.addCleanup(patcher.stop)
    def test_calendar_dates_preview_and_explicit_paid_approval(self):
        now = datetime(2026, 11, 1, 12, tzinfo=timezone.utc)
        self.assertEqual(owner.edition('2026-10-31', False, now), '2026-10-31')
        self.assertEqual(owner.edition('today', True, now), '2026-11-01')
        for date, preview in [('2026-11-01', False), ('2026-10-31', True), ('2026-11-02', True)]:
            with self.assertRaises(ValueError): owner.edition(date, preview, now)
        args = owner.parser().parse_args(['generate', '--date', '2026-10-31'])
        with patch.object(owner, 'run', side_effect=AssertionError('operation before approval')):
            with self.assertRaises(ValueError): owner.generation(args, {}, Path('/unused'))
        refresh=owner.parser().parse_args(['refresh','--approve-paid'])
        self.assertEqual(refresh.date,'today')
        refresh=owner.parser().parse_args(['refresh','--date','2026-10-31'])
        with patch.object(owner,'run',side_effect=AssertionError('operation before approval')):
            with self.assertRaises(ValueError):owner.generation(refresh,{},Path('/unused'))

    def test_safe_install_phase_and_migration_failure_codes(self):
        for phase,error,code in [('backup',ValueError('PRIVATE_CANARY'),'backup_failed'),
                                 ('installation',PermissionError('PRIVATE_CANARY'),'permission_denied')]:
            with self.assertRaises(owner.InstallFailure) as caught:
                with owner.install_phase(phase):raise error
            self.assertEqual((caught.exception.phase,caught.exception.code),(phase,code))
            self.assertNotIn('PRIVATE_CANARY',str(caught.exception))
        for raw,code in [('{"code":"database_busy"}','database_busy'),
                         ('{"code":"schema_failure"}','schema_failure'),
                         ('{"code":"PRIVATE_CANARY"}','migration_process_failed'),
                         ('PRIVATE_CANARY','migration_process_failed')]:
            with patch.object(owner,'run',return_value=SimpleNamespace(returncode=1,stdout=raw)):
                with self.assertRaises(owner.InstallFailure) as caught:owner.migrate_bundle(Path('/bundle'),Path('/db'),'dbuser')
                self.assertEqual(caught.exception.code,code)

    def test_install_does_not_select_budgets_enable_flags_or_read_keys(self):
        source = (ROOT / 'ops/gazette/gazette.py').read_text()
        install = source.split('def install(args):', 1)[1].split('def parser():', 1)[0]
        for forbidden in ['openai_api_key', "'DAILY_MICROUSD'", "'MONTHLY_MICROUSD'", "'DAILY_REQUESTS'", "'ENABLED'", "'enable'", "'--now'"]:
            self.assertNotIn(forbidden, install)
        self.assertIn("'--confirm-drained=yes'", source.split('def migrate_bundle',1)[1].split('def run',1)[0])
        self.assertIn('s.backup(d)', install)

    def test_proposed_limits_require_explicit_approval_and_preserve_activation(self):
        with tempfile.TemporaryDirectory() as directory:
            env = Path(directory) / 'editor.env'; cfgfile = Path(directory) / 'config.json'
            prefix = owner.PREFIX
            original = {prefix+'ENABLED':'0', prefix+'DAILY_MICROUSD':'50000', prefix+'MONTHLY_MICROUSD':'1000000', prefix+'DAILY_REQUESTS':'2'}
            env.write_text(''.join(k+'='+v+'\n' for k,v in original.items()))
            cfg = {'environment': dict(original)}; cfgfile.write_text(json.dumps(cfg))
            args = owner.parser().parse_args(['configure'])
            with patch.object(owner, 'ENV', env), patch.object(owner, 'CONFIG', cfgfile), patch.object(owner, 'trusted'), patch.object(owner, 'idle'), patch('sys.stdout', new_callable=io.StringIO):
                with self.assertRaises(ValueError): owner.configure(args, cfg)
                self.assertEqual(json.loads(cfgfile.read_text())['environment'], original)
                args.approve_limits = True; owner.configure(args, cfg)
                proposed = json.loads(cfgfile.read_text())['environment']
                self.assertEqual([proposed[prefix+k] for k in ['DAILY_MICROUSD','MONTHLY_MICROUSD','DAILY_REQUESTS','ENABLED']], ['1000000','20000000','20','0'])

    def test_generation_has_one_start_and_removes_only_its_admission_file(self):
        args = owner.parser().parse_args(['generate', '--date', '2026-10-31', '--approve-paid'])
        policy={owner.PREFIX+k:v for k,v in {'DAILY_MICROUSD':'1000000','MONTHLY_MICROUSD':'20000000','DAILY_REQUESTS':'20','MAX_INPUT_TOKENS':'96000','MAX_OUTPUT_TOKENS':'12000','MODERATION_MICROUSD':'0'}.items()}
        initial={'ledger_revision':2,'budgets':{'day':{'committed_microusd':0,'reserved_requests':0},'month':{'committed_microusd':0}}}
        with tempfile.TemporaryDirectory() as directory:
            request = Path(directory) / 'request.json'; calls=[]
            def execute(argv, **kwargs):
                calls.append(argv); job=json.loads(request.read_text())
                self.assertEqual(job['date'], '2026-10-31'); self.assertEqual(job['preview'], False)
                if sys.platform == 'linux': self.assertEqual(stat.S_IMODE(request.stat().st_mode), 0o644)
                return SimpleNamespace(returncode=0)
            for outcome in ['finished', 'uncertain', None]:
                with patch.object(owner, 'REQUEST', request), patch.object(owner, 'edition', return_value='2026-10-31'), patch.object(owner, 'socket_ready'), patch.object(owner, 'check'), patch.object(owner, 'run', side_effect=execute), patch.object(owner, 'metadata', side_effect=[initial, {'attempts':[{'outcome':outcome}]}]), patch('sys.stdout', new_callable=io.StringIO):
                    before=len(calls)
                    if outcome == 'finished': owner.generation(args, {'environment':policy}, Path('/release') / ('a'*40))
                    else:
                        with self.assertRaises(ValueError): owner.generation(args, {'environment':policy}, Path('/release') / ('a'*40))
                    self.assertEqual(len(calls), before+1); self.assertFalse(request.exists())

    def test_preview_and_existing_publication_need_separate_decisions(self):
        args = owner.parser().parse_args(['publish', '--attempt', 'a'*32])
        for preview, current in [(True,None), (None,'b'*64)]:
            raw=json.dumps({'draft_sha256':'c'*64,'preview':preview,'current_publication_sha256':current,'draft':'PRIVATE_SYNTHETIC_CANARY'})
            with patch.object(owner, 'owner_tool', return_value=raw) as tool, patch('sys.stdout', new_callable=io.StringIO) as output:
                with self.assertRaises(ValueError): owner.review_or_publish(args, {}, Path('/unused'))
                self.assertEqual(tool.call_count,1); self.assertNotIn('PRIVATE_SYNTHETIC_CANARY', output.getvalue())

    def test_socket_recovery_never_restarts_an_active_editor(self):
        with patch.object(owner, 'state', return_value='active'), patch.object(owner, 'run') as run:
            with self.assertRaises(ValueError): owner.socket_ready()
            run.assert_not_called()
        fake=SimpleNamespace(exists=lambda:False, lstat=lambda:SimpleNamespace(st_mode=stat.S_IFSOCK|0o660))
        with patch.object(owner, 'Path', return_value=fake), patch.object(owner, 'state', side_effect=['inactive','active']), patch.object(owner, 'run') as run:
            owner.socket_ready()
            self.assertIn((['systemctl','restart',owner.SOCKET],), [c.args for c in run.call_args_list])
            self.assertNotIn((['systemctl','restart',owner.UNIT],), [c.args for c in run.call_args_list])

    @unittest.skipUnless(sys.platform == 'linux', 'Real immutable Unix modes required')
    def test_manifest_verified_owner_bundle_rejects_tampered_deploy_content(self):
        with tempfile.TemporaryDirectory() as directory:
            base=Path(directory); code=base/('a'*40); code.mkdir()
            (code/'app').mkdir(); source=code/'app/example.php'
            source.write_text('<?php /* synthetic, never executed */');source.chmod(0o444)
            (code/'.release-commit').write_text('a'*40+'\n');(code/'.release-commit').chmod(0o444)
            files={name:{'sha256':hashlib.sha256((code/name).read_bytes()).hexdigest(),'mode':0o444} for name in ['app/example.php','.release-commit']}
            manifest=base/'manifest.json';manifest.write_text(json.dumps({'commit':'a'*40,'files':files}));manifest.chmod(0o600)
            # Fixture runner is deliberately unprivileged. Only destination root
            # ownership is mocked; real source ownership/modes/hashes are checked.
            with patch.object(owner, 'trusted'):
                mask=os.umask(0o077)
                try:
                    bundle,_=owner.copy_owner_bundle(code,base/'installed',manifest)
                    # Simulate the exact previous partial installation and resume.
                    for folder in [base/'installed',bundle.parent,bundle,bundle/'app']:folder.chmod(0o700)
                    owner.copy_owner_bundle(code,base/'installed',manifest)
                    for folder in [base/'installed',bundle.parent,bundle,bundle/'app',bundle/'public']:
                        self.assertEqual(stat.S_IMODE(folder.stat().st_mode),0o755)
                finally:os.umask(mask)
                self.assertEqual((bundle/'app/example.php').read_bytes(),source.read_bytes())
                self.assertEqual(stat.S_IMODE((bundle/'app/example.php').stat().st_mode),0o444)
                source.chmod(0o644);source.write_text('tampered synthetic content');source.chmod(0o444)
                with self.assertRaises(ValueError):owner.copy_owner_bundle(code,base/'installed',manifest)


if __name__ == '__main__': unittest.main()
