"""Offline deployment policy and disposable Linux release-switch tests."""
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import sys
import tarfile
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('toronto_deployer', ROOT / 'ops/deploy/toronto.py')
deploy = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = deploy
spec.loader.exec_module(deploy)
A, B = 'a' * 40, 'b' * 40


class PolicyTests(unittest.TestCase):
    def test_exact_commit_and_restricted_ssh_command(self):
        self.assertEqual(deploy.command([], 'deploy ' + A), ('deploy', A))
        for value in ['', 'deploy ' + A + '; id', 'deploy ' + A + '\n', 'prepare ' + A,
                      'activate-reviewed ' + A, 'rollback ' + A, 'recover', 'bash', 'scp -t /tmp/x']:
            with self.subTest(value=value), self.assertRaises(deploy.Refused):
                deploy.command([], value)
        for value in ['main', '../main', A.upper(), A + ' ', 'a' * 39, 'a' * 41]:
            with self.assertRaises(deploy.Refused):
                deploy.commit(value)
        self.assertEqual(deploy.command(['activate-reviewed', A]), ('activate-reviewed', A))

    def test_release_paths_and_operational_changes(self):
        for name in ['public/index.php', 'app/director.php', 'docs/guide.md', '.env.example']:
            self.assertEqual(str(deploy.safe_path(name)), name)
        for name in ['/etc/passwd', '../village.sqlite', 'public/../key', 'public//index.php',
                     'public\\key', 'public/.env', 'public/api.pem', 'data/x', '.git/config',
                     'private/village.sqlite-wal', '.release-commit', 'public/\nname']:
            with self.subTest(name=name), self.assertRaises(deploy.Refused):
                deploy.safe_path(name)
        for name in ['app/director_schema.php', 'scripts/migrate-director.php',
                     'scripts/register-director.php', 'ops/systemd/paid.conf.example',
                     'ops/deploy/toronto.py', '.env.example']:
            self.assertTrue(deploy.operational_change(name), name)
        for name in ['app/director.php', 'public/assets/village.css', 'docs/guide.md']:
            self.assertFalse(deploy.operational_change(name), name)

    def test_workflow_has_separate_secret_free_ci_and_disabled_deployment(self):
        ci = (ROOT / '.github/workflows/ci.yml').read_text()
        workflow = (ROOT / '.github/workflows/deploy.yml').read_text()
        self.assertIn('pull_request:', ci)
        self.assertIn('workflow_call:', ci)
        self.assertNotIn('pull_request_target', ci + workflow)
        self.assertNotIn('secrets.', ci)
        self.assertNotIn('environment:', ci)
        self.assertIn('unshare --net', ci)
        self.assertIn('setpriv --reuid=', ci)
        self.assertIn('persist-credentials: false', ci)
        self.assertIn("vars.TORONTO_DEPLOY_ENABLED == '1'", workflow)
        self.assertIn("github.ref == 'refs/heads/main'", workflow)
        self.assertIn('needs: checks', workflow)
        self.assertIn('cancel-in-progress: false', workflow)
        self.assertIn('name: production', workflow)
        self.assertIn('permissions: {}', workflow)
        self.assertIn('StrictHostKeyChecking=yes', workflow)
        self.assertIn('HostKeyAlias=sanctuary-toronto-deploy', workflow)
        self.assertNotIn('ssh-keyscan', workflow)
        self.assertNotIn('uses: actions/checkout', workflow)
        self.assertIn('TARGET_SHA: ${{ github.sha }}', workflow)
        self.assertIn('"deploy $TARGET_SHA"', workflow)
        # No mutable action tags or unpinned third-party SSH helpers.
        import re
        for reference in re.findall(r'uses: ([^\s#]+)', ci + workflow):
            self.assertTrue(reference.startswith('./') or re.fullmatch(r'[^@]+@[a-f0-9]{40}', reference), reference)

    def test_tailscale_is_pinned_and_only_after_production_approval(self):
        ci = (ROOT / '.github/workflows/ci.yml').read_text()
        workflow = (ROOT / '.github/workflows/deploy.yml').read_text()
        checks, deployment = workflow.split('  deploy:\n', 1)
        self.assertNotIn('tailscale/github-action', ci + checks)
        self.assertNotIn('TS_OAUTH_', ci + checks)
        self.assertNotIn('secrets: inherit', checks)
        pin = 'tailscale/github-action@99a8333667fff04fdd6cffb46dfd409f8329a7bf'
        self.assertEqual(workflow.count(pin), 1)
        self.assertLess(deployment.index('name: production'), deployment.index(pin))
        self.assertLess(deployment.index(pin), deployment.index('name: Deploy the exact tested main commit'))
        self.assertIn('oauth-client-id: ${{ secrets.TS_OAUTH_CLIENT_ID }}', deployment)
        self.assertIn('oauth-secret: ${{ secrets.TS_OAUTH_SECRET }}', deployment)
        self.assertIn('tags: tag:sanctuarytorontodeploy\n', deployment)
        self.assertEqual(deployment.count('tags:'), 1)
        self.assertIn("version: '1.104.2'", deployment)
        self.assertIn('sha256sum: 512f613ed7d04fc7282f7dc5bd76aa105aae28168fbb9357eb749ebcbc29f0ae', deployment)
        self.assertIn('args: --accept-routes=false --accept-dns=false', deployment)
        self.assertIn("TORONTO_HOST: '100.75.63.110'", deployment)
        self.assertIn("TORONTO_PORT: '22'", deployment)
        self.assertNotIn('vars.TORONTO_DEPLOY_HOST', deployment)
        self.assertNotIn('vars.TORONTO_DEPLOY_PORT', deployment)
        self.assertNotIn('172.105.101.62', deployment)
        self.assertNotIn('tailscale ssh', deployment)
        self.assertNotIn('continue-on-error:', deployment)
        self.assertNotIn('id-token: write', workflow)

    @unittest.skipUnless(os.name == 'posix', 'Mock SSH shell check runs on Linux CI')
    def test_ssh_step_uses_tailnet_only_and_preserves_pins_without_network(self):
        import textwrap
        workflow = (ROOT / '.github/workflows/deploy.yml').read_text()
        command = textwrap.dedent(workflow.split('        run: |\n', 1)[1])
        with tempfile.TemporaryDirectory(prefix='sanctuary-tailnet-test-') as directory:
            root = Path(directory)
            binary = root / 'ssh'
            binary.write_text('''#!/usr/bin/env python3
import json,os,stat,sys
from pathlib import Path
args=sys.argv[1:]
key=Path(args[args.index('-i')+1])
pin=Path(next(x.split('=',1)[1] for x in args if x.startswith('UserKnownHostsFile=')))
Path(os.environ['SSH_CAPTURE']).write_text(json.dumps({'args':args,'key_mode':stat.S_IMODE(key.stat().st_mode),'pin_mode':stat.S_IMODE(pin.stat().st_mode),'key':str(key),'pin':str(pin)}))
''')
            binary.chmod(0o700)
            capture = root / 'captured.json'
            env = dict(os.environ, PATH=str(root)+os.pathsep+os.environ['PATH'],
                       TMPDIR=str(root), SSH_CAPTURE=str(capture), GITHUB_STEP_SUMMARY=str(root / 'summary'),
                       TARGET_SHA=A, TORONTO_HOST='100.75.63.110', TORONTO_USER='sanctuarydeploy', TORONTO_PORT='22',
                       PRIVATE_KEY='DUMMY_OFFLINE_KEY', KNOWN_HOSTS='DUMMY_OFFLINE_PUBLIC_PIN')
            result = subprocess.run(['bash', '-c', command], env=env, capture_output=True, text=True)
            self.assertEqual(result.returncode, 0, result.stderr)
            saved = json.loads(capture.read_text())
            self.assertEqual(saved['args'][-2:], ['sanctuarydeploy@100.75.63.110', 'deploy ' + A])
            self.assertEqual(saved['args'][saved['args'].index('-p')+1], '22')
            self.assertIn('StrictHostKeyChecking=yes', saved['args'])
            self.assertIn('HostKeyAlias=sanctuary-toronto-deploy', saved['args'])
            self.assertEqual((saved['key_mode'], saved['pin_mode']), (0o600, 0o600))
            self.assertNotIn('DUMMY_OFFLINE_KEY', json.dumps(saved)+result.stdout+result.stderr)
            for name in ['key', 'pin']:
                self.assertFalse(Path(saved[name]).exists(), 'Ephemeral SSH material removed by trap')
            capture.unlink()
            for override in [{'TORONTO_HOST':'172.105.101.62'}, {'TORONTO_PORT':'2222'}, {'TORONTO_USER':'root'}, {'TARGET_SHA':'main'}]:
                result = subprocess.run(['bash', '-c', command], env=env|override, capture_output=True, text=True)
                self.assertNotEqual(result.returncode, 0)
                self.assertFalse(capture.exists(), 'Invalid target cannot even reach mock SSH')

    def test_health_reads_only_local_release_marker(self):
        php = os.environ.get('PHP_BINARY') or shutil.which('php')
        self.assertIsNotNone(php, 'Set PHP_BINARY for offline tests')
        with tempfile.TemporaryDirectory(prefix='sanctuary-health-test-') as directory:
            root = Path(directory)
            (root / 'public').mkdir()
            shutil.copyfile(ROOT / 'public/health.php', root / 'public/health.php')
            (root / '.release-commit').write_bytes((A + '\n').encode())
            env = dict(os.environ, OPENAI_API_KEY='', LITTLE_SPARKS_DB=str(root / 'must-not-create.sqlite'))
            code = 'include $argv[1];fwrite(STDERR,(string)http_response_code());'
            result = subprocess.run([php, '-r', code, str(root / 'public/health.php')], env=env, capture_output=True, text=True, check=True)
            self.assertEqual(json.loads(result.stdout)['commit'], A)
            self.assertIn(result.stderr, ['200', '503'])  # Local PHP 8.2 fails readiness honestly.
            self.assertFalse((root / 'must-not-create.sqlite').exists())
            self.assertEqual(sorted(p.name for p in root.iterdir()), ['.release-commit', 'public'])
            (root / '.release-commit').write_text('invalid\n')
            result = subprocess.run([php, '-r', code, str(root / 'public/health.php')], env=env, capture_output=True, text=True, check=True)
            self.assertEqual(json.loads(result.stdout), {'status': 'unavailable', 'commit': None})
            self.assertEqual(result.stderr, '503')

    def test_health_requires_exact_release_and_bounded_checks(self):
        d = deploy.Deployer()
        class Response:
            status = 200
            def __init__(self, sha): self.sha = sha
            def __enter__(self): return self
            def __exit__(self, *args): return False
            def read(self, limit):
                self.limit = limit
                return json.dumps({'status': 'ok', 'commit': self.sha}).encode()
        class Opener:
            def __init__(self, sha): self.sha, self.calls = sha, []
            def open(self, request, timeout):
                self.calls.append((request.full_url, timeout))
                return Response(self.sha)
        opener = Opener(A)
        with patch.object(deploy.urllib.request, 'build_opener', return_value=opener):
            self.assertTrue(d.healthy(A))
        self.assertEqual(opener.calls, [('https://sanctuaryfor.ai/health.php?release=' + A, 5)])
        stale = Opener(B)
        with patch.object(deploy.urllib.request, 'build_opener', return_value=stale), patch.object(deploy.time, 'sleep'):
            self.assertFalse(d.healthy(A))
        self.assertEqual(len(stale.calls), 25)


@unittest.skipUnless(os.name == 'posix' and hasattr(os, 'geteuid') and os.geteuid() != 0,
                     'Linux unprivileged filesystem/locking checks run in isolated CI')
class ReleaseTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='sanctuary-deploy-test-')
        self.base = Path(self.temp.name).resolve()
        self.source = self.base / 'source'
        self.source.mkdir()
        self.git('init', '-b', 'main')
        self.git('config', 'user.email', 'offline@example.invalid')
        self.git('config', 'user.name', 'Offline fixture')
        (self.source / 'public').mkdir()
        shutil.copyfile(ROOT / 'public/health.php', self.source / 'public/health.php')
        (self.source / 'public/index.php').write_text('<?php echo "first";\n')
        self.first = self.save('initial')
        self.root = self.base / 'releases-state'
        for path, mode in [(self.root, 0o755), (self.root / 'private', 0o700), (self.root / 'releases', 0o755)]:
            path.mkdir()
            path.chmod(mode)
        subprocess.run(['git', 'clone', '--bare', str(self.source), str(self.root / 'private/repository.git')],
                       stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=True)
        self.settings = deploy.Settings(root=self.root, live=self.base / 'live', remote=str(self.source),
                                        git=shutil.which('git'), php=os.environ.get('PHP_BINARY') or shutil.which('php'))
        self.d = deploy.Deployer(self.settings)
        self.d.healthy = lambda target: True
        self.d.validate_installation()
        self.d.stage(self.first)
        self.d.switch(self.first)
        # Persistent village and credentials are completely outside the releases.
        self.canary = self.base / 'private-village.sqlite'
        self.canary.write_bytes(b'unchanged private fixture, never a real database')
        self.canary.chmod(0o600)
        self.before = hashlib.sha256(self.canary.read_bytes()).hexdigest()

    def tearDown(self):
        self.assertEqual(hashlib.sha256(self.canary.read_bytes()).hexdigest(), self.before)
        # Only this test-created, resolved temporary root is made writable for cleanup.
        for path in self.base.rglob('*'):
            if not path.is_symlink():
                path.chmod(0o700 if path.is_dir() else 0o600)
        self.temp.cleanup()

    def git(self, *args):
        return subprocess.check_output(['git', '-C', str(self.source), *args], stderr=subprocess.PIPE).decode().strip()

    def save(self, message):
        self.git('add', '.')
        self.git('commit', '-m', message)
        return self.git('rev-parse', 'HEAD')

    def next(self, name='public/index.php', data='<?php echo "second";\n'):
        path = self.source / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(data)
        return self.save('next')

    def test_exact_release_idempotency_modes_no_execution_and_rollback(self):
        target = self.next(data='<?php file_put_contents(' + repr(str(self.base / 'must-not-execute')) + ',"bad");\n')
        with self.d.lock():
            result = self.d.deploy(target)
        self.assertEqual(result['commit'], target)
        self.assertEqual(self.d.current_commit(), target)
        self.assertFalse((self.base / 'must-not-execute').exists())
        self.assertFalse(self.d.pending.exists())
        self.assertEqual(stat.S_IMODE((self.d.releases / target / 'public/health.php').stat().st_mode), 0o444)
        self.assertEqual(self.d.deploy(target)['status'], 'already_current')
        self.assertEqual(self.d.deploy(self.first, rollback=True)['status'], 'rolled_back')
        self.assertEqual(self.d.current_commit(), self.first)

    def test_non_main_target_and_operational_changes_refused(self):
        target = self.next('.env.example', 'DOCUMENTATION_ONLY=1\n')
        with self.assertRaisesRegex(deploy.Refused, 'target_is_not_current_main'):
            self.d.deploy(self.first)
        with self.assertRaisesRegex(deploy.Refused, 'separate_operational_review_required'):
            self.d.deploy(target)
        self.assertEqual(self.d.current_commit(), self.first)
        # This flag is exposed only by a local owner command, never the forced SSH command.
        self.assertEqual(self.d.deploy(target, reviewed=True)['commit'], target)

    def test_dirty_existing_release_never_overwritten(self):
        target = self.next()
        path = self.d.releases / self.first / 'public/index.php'
        path.chmod(0o644)
        path.write_text('owner hotfix must survive')
        path.chmod(0o444)
        with self.assertRaisesRegex(deploy.Refused, 'unexpected_local_changes'):
            self.d.deploy(target)
        self.assertEqual(path.read_text(), 'owner hotfix must survive')
        self.assertFalse((self.d.releases / target).exists())

    def test_symlinks_and_tampered_manifests_refused(self):
        (self.source / 'public/escape').symlink_to('/nonexistent-private-target')
        target = self.save('unsafe symbolic link')
        self.d.approved_main(target)
        with self.assertRaisesRegex(deploy.Refused, 'symlink_submodule_or_special_file'):
            self.d.stage(target)
        self.d.manifest_path(self.first).chmod(0o644)
        with self.assertRaisesRegex(deploy.Refused, 'unsafe_manifest'):
            self.d.check_release(self.first)

    def test_archive_traversal_is_not_extracted(self):
        target = self.next()
        self.d.approved_main(target)
        stream = io.BytesIO()
        with tarfile.open(fileobj=stream, mode='w') as archive:
            item = tarfile.TarInfo('../outside')
            item.size = 1
            archive.addfile(item, io.BytesIO(b'x'))
        original = self.d.git
        self.d.git = lambda *args: stream.getvalue() if args[0] == 'archive' else original(*args)
        with self.assertRaisesRegex(deploy.Refused, 'unsafe_release_path'):
            self.d.stage(target)
        self.assertFalse((self.d.releases / 'outside').exists())
        self.assertEqual(self.d.current_commit(), self.first)

    def test_health_failure_restores_code_only(self):
        target = self.next()
        self.d.healthy = lambda sha: sha == self.first
        with self.assertRaisesRegex(deploy.Refused, 'deployment_failed_previous_release_restored'):
            self.d.deploy(target)
        self.assertEqual(self.d.current_commit(), self.first)
        self.assertFalse(self.d.pending.exists())
        rows = [json.loads(line) for line in (self.d.private / 'audit.jsonl').read_text().splitlines()]
        self.assertEqual([row['state'] for row in rows], ['switch_started', 'rolled_back_after_failure'])

    def test_failed_rollback_preserves_journal_for_owner_recovery(self):
        target = self.next()
        self.d.healthy = lambda sha: False
        with self.assertRaisesRegex(deploy.Refused, 'rollback_health_failed_owner_recovery_required'):
            self.d.deploy(target)
        self.assertTrue(self.d.pending.exists())
        with self.assertRaisesRegex(deploy.Refused, 'unfinished_deployment_use_owner_recovery'):
            self.d.deploy(target)
        self.d.healthy = lambda sha: True
        self.assertEqual(self.d.recover()['commit'], self.first)
        self.assertFalse(self.d.pending.exists())

    def test_lock_excludes_other_invocations_and_identity_is_fixed(self):
        with self.d.lock():
            with self.assertRaisesRegex(deploy.Refused, 'deployment_busy'):
                with deploy.Deployer(self.settings).lock():
                    self.fail('Second lock acquired')
        self.d.git('config', 'remote.origin.url', 'https://example.invalid/other.git')
        with self.assertRaisesRegex(deploy.Refused, 'repository_identity_mismatch'):
            self.d.validate_installation()

    def test_interrupted_switch_recovery_and_pointer_escape(self):
        target = self.next()
        self.d.approved_main(target)
        self.d.stage(target)
        deploy.write_new(self.d.pending, json.dumps({'previous': self.first, 'target': target}).encode())
        self.d.switch(target)
        self.assertEqual(self.d.recover()['commit'], self.first)
        self.d.current.unlink()
        self.d.current.symlink_to(self.base / 'outside')
        with self.assertRaisesRegex(deploy.Refused, 'current_pointer_outside_releases'):
            self.d.current_commit()


if __name__ == '__main__':
    unittest.main()
