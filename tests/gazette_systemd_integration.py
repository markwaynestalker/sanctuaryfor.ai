#!/usr/bin/env python3
"""GitHub Ubuntu VM only. Temporary runtime-linked units, isolated root tree, mock provider.
No production access, persistent unit installation, account creation, timer or real credential.
"""
from contextlib import contextmanager
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
import time
import uuid

ROOT = Path(__file__).resolve().parents[1]
DB_UID, EDITOR_UID, OTHER_UID = 64010, 64011, 64012
P = 'LITTLE_SPARKS_GAZETTE_EDITOR_'
POLICY = {P + key: value for key, value in {
    'ENABLED': '0', 'PRICING_CONFIRMED': 'gazette-luna-standard-2026-10-31',
    'DAILY_MICROUSD': '50000', 'MONTHLY_MICROUSD': '1000000', 'DAILY_REQUESTS': '2',
    'MODERATION_MICROUSD': '0', 'MAX_INPUT_TOKENS': '96000', 'MAX_OUTPUT_TOKENS': '12000',
    'MAX_REQUEST_BYTES': '90000'}.items()}


def run(args, *, check=True, env=None, timeout=45):
    result = subprocess.run(args, capture_output=True, text=True, timeout=timeout, env=env)
    if check and result.returncode:
        # All outputs are synthetic fixtures; no provider/credential content is ever read.
        raise AssertionError('CI command failed: ' + str(args[:3]) + '\n' + result.stdout[-1500:] + result.stderr[-2500:])
    return result


def wait_for(predicate, why, timeout=20):
    until = time.monotonic() + timeout
    while time.monotonic() < until:
        if predicate():
            return
        time.sleep(.05)
    raise AssertionError(why)


def property_value(unit, key):
    return run(['systemctl', 'show', unit, '-p', key, '--value']).stdout.strip()


def jailed_paths(text):
    lines = []
    for line in text.splitlines():
        if line.startswith(('ReadWritePaths=', 'InaccessiblePaths=')):
            key, value = line.split('=', 1)
            paths = [(' -+' + path[1:]).strip() if path.startswith('-') else '+' + path for path in value.split()]
            line = key + '=' + ' '.join(paths)
        lines.append(line)
    return '\n'.join(lines) + '\n'


def main():
    assert sys.platform == 'linux' and os.geteuid() == 0
    assert os.environ.get('GITHUB_ACTIONS') == 'true' and os.environ.get('SANCTUARY_CI_SYSTEMD_TEST') == '1', 'CI-only test'
    assert Path('/run/systemd/system').is_dir(), 'Actual systemd manager required, no skip'
    prefix = 'sanctuary-ci-gazette-' + uuid.uuid4().hex[:12]
    helper_socket, helper_template = prefix + '-db.socket', prefix + '-db@.service'
    worker, writer = prefix + '-editor.service', prefix + '-writer.service'
    units, linked = [helper_socket, helper_template, worker, writer], []
    sha = run(['git', '-C', str(ROOT), 'rev-parse', 'HEAD']).stdout.strip()
    assert re.fullmatch('[a-f0-9]{40}', sha)
    with tempfile.TemporaryDirectory(prefix='sanctuary-ci-systemd-') as temporary:
        base = Path(temporary);base.chmod(0o711)
        jail = base / 'root';jail.mkdir(mode=0o755)
        def inside(path):
            return jail / path.lstrip('/')
        for directory in ['/usr', '/lib', '/lib64', '/bin', '/etc/php', '/etc/ssl', '/run', '/proc', '/sys', '/dev', '/tmp', '/var/tmp',
                          '/var/lib/sanctuaryfor-ai', '/var/lib/sanctuary-gazette-test', '/var/lib/sanctuary-gazette-writer-test',
                          '/etc/sanctuary-gazette-db', '/var/lib/sanctuaryfor-ai-deploy/releases', '/run/sanctuary-gazette-db']:
            inside(directory).mkdir(parents=True, exist_ok=True, mode=0o755)
        for tmp in ['/tmp', '/var/tmp']:
            inside(tmp).chmod(0o1777)
        runtime = inside('/run/sanctuary-gazette-db');os.chown(runtime, 0, EDITOR_UID);runtime.chmod(0o710)
        private = inside('/var/lib/sanctuaryfor-ai');os.chown(private, DB_UID, DB_UID);private.chmod(0o700)
        state = inside('/var/lib/sanctuary-gazette-test');os.chown(state, EDITOR_UID, EDITOR_UID);state.chmod(0o700)
        writer_state = inside('/var/lib/sanctuary-gazette-writer-test');os.chown(writer_state, DB_UID, DB_UID);writer_state.chmod(0o700)
        release_path = '/var/lib/sanctuaryfor-ai-deploy/releases/' + sha
        release = inside(release_path)
        release.mkdir()
        for folder in ['app', 'scripts', 'contracts', 'ops', 'tests']:
            shutil.copytree(ROOT / folder, release / folder, ignore=shutil.ignore_patterns('__pycache__', '*.log', 'node_modules'))
        (release / 'public').mkdir();(release / '.release-commit').write_text(sha + '\n')
        current = inside('/var/lib/sanctuaryfor-ai-deploy/current');current.symlink_to('releases/' + sha)
        seed = base / 'little-sparks-journal-test-systemd';seed.mkdir(mode=0o700)
        env = {'PATH': '/usr/bin:/bin', 'LANG': 'C.UTF-8'}
        def php(args):
            return run(['/usr/bin/php8.3', *args], env=env)
        php([str(ROOT / 'tests/journal-core.php'), str(seed)])
        shutil.copyfile(seed / 'v4.sqlite', seed / 'v6.sqlite');(seed / 'v6.sqlite').chmod(0o600)
        for phase in ['memory', 'identity', 'director']:
            php([str(ROOT / ('scripts/migrate-' + phase + '.php')), '--database=' + str(seed / 'v6.sqlite')])
        php([str(ROOT / 'tests/gazette-core.php'), str(seed)])
        php([str(ROOT / 'scripts/migrate-gazette-editor.php'), '--database=' + str(seed / 'v6.sqlite'), '--confirm-drained=yes'])
        php(['-r', 'require "app/village.php"; $d=LittleSparks\\connect($argv[1]); $d->query("PRAGMA journal_mode=WAL");', str(seed / 'v6.sqlite')])
        database = private / 'village.sqlite'
        helper_cfg = inside('/etc/sanctuary-gazette-db/config.json')
        worker_env = base / 'editor.env';standin = base / 'credential-standin'
        standin.write_text('NON_SECRET_SYSTEMD_STANDIN');standin.chmod(0o600)
        def configure(mode='generate', enabled=True, helper_enabled=True, barrier=False, worker_sha=sha, helper_sha=sha):
            cfg = {'editor_uid': EDITOR_UID, 'database': '/var/lib/sanctuaryfor-ai/village.sqlite',
                   'release': '/var/lib/sanctuaryfor-ai-deploy/releases/' + helper_sha, 'approved_date': '2026-10-29',
                   'environment': {**POLICY, P + 'ENABLED': '1' if helper_enabled else '0'}}
            helper_cfg.write_text(json.dumps(cfg));helper_cfg.chmod(0o644)
            values = {**POLICY, P + 'ENABLED': '1' if enabled else '0', P + 'RELEASE_SHA': worker_sha,
                      'SANCTUARY_CI_MODE': mode, 'SANCTUARY_CI_BARRIER': '1' if barrier else '0', 'SANCTUARY_CI_SYSTEMD_TEST': '1'}
            worker_env.write_text('\n'.join(key + '=' + value for key, value in values.items()) + '\n');worker_env.chmod(0o600)
        configure(enabled=False, helper_enabled=False)
        common = '\nRootDirectory=' + str(jail) + '\nMountAPIVFS=true\nBindReadOnlyPaths=/usr /lib /lib64 /bin /etc/php /etc/ssl /etc/ld.so.cache\n'
        helper = (ROOT / 'ops/systemd/sanctuary-gazette-db@.service').read_text()
        helper = helper.replace('sanctuary-gazette-db.socket', helper_socket).replace('REVIEWED_DATABASE_OWNER', str(DB_UID)).replace('REVIEWED_DATABASE_GROUP', str(DB_UID))
        helper = helper.replace('/usr/local/lib/sanctuary-gazette/db_helper.py', release_path + '/ops/gazette/db_helper.py')
        helper = jailed_paths(helper) + common + 'ExecStartPre=/usr/bin/php8.3 ' + release_path + '/tests/gazette-systemd-helper-probe.php\n'
        socket_unit = (ROOT / 'ops/systemd/sanctuary-gazette-db.socket').read_text().replace('/run/sanctuary-gazette-db/editor.sock', str(runtime / 'editor.sock')).replace('SocketGroup=sanctuarygazette', 'SocketGroup=' + str(EDITOR_UID))
        editor = (ROOT / 'ops/systemd/sanctuary-gazette-editor.service').read_text().replace('sanctuary-gazette-db.socket', helper_socket)
        editor = editor.replace('User=sanctuarygazette', 'User=' + str(EDITOR_UID)).replace('Group=sanctuarygazette', 'Group=' + str(EDITOR_UID))
        editor = editor.replace('/etc/sanctuary-gazette/editor.env', str(worker_env)).replace('/etc/sanctuary-gazette/openai_api_key', str(standin))
        editor = re.sub(r'^ExecStart=.*$', 'ExecStart=/usr/bin/php8.3 -d display_errors=0 -d log_errors=0 ' + release_path + '/tests/gazette-systemd-worker.php', editor, flags=re.M)
        editor = jailed_paths(editor) + common + 'PrivateNetwork=true\nReadWritePaths=+/var/lib/sanctuary-gazette-test\n'
        writer_unit = '[Unit]\nDescription=CI synthetic SQLite lock holder\n[Service]\nType=exec\nUser=' + str(DB_UID) + '\nGroup=' + str(DB_UID) + '\nExecStart=/usr/bin/python3 ' + release_path + '/tests/gazette-systemd-writer.py\nPrivateNetwork=true\nProtectSystem=strict\nNoNewPrivileges=true\nReadWritePaths=+/var/lib/sanctuaryfor-ai +/var/lib/sanctuary-gazette-writer-test\nRuntimeMaxSec=30\n' + common
        contents = dict(zip(units, [socket_unit, helper, editor, writer_unit]))
        unit_dir = base / 'units';unit_dir.mkdir()
        def stop_units():
            listing = run(['systemctl', 'list-units', '--all', '--plain', '--no-legend', prefix + '*'], check=False).stdout
            names = [line.split()[0] for line in listing.splitlines() if line.split() and line.split()[0].startswith(prefix)]
            if names:
                run(['systemctl', 'stop', *names], check=False)
                run(['systemctl', 'reset-failed', *names], check=False)
        def reset():
            # All services must be drained before replacing only this disposable test-case DB.
            run(['systemctl', 'stop', worker, writer], check=False)
            run(['systemctl', 'reset-failed', worker, writer], check=False)
            for suffix in ['', '-wal', '-shm', '-journal']:
                Path(str(database) + suffix).unlink(missing_ok=True)
            shutil.copyfile(seed / 'v6.sqlite', database);os.chown(database, DB_UID, DB_UID);database.chmod(0o600)
            for directory in [state, writer_state]:
                for item in directory.iterdir():
                    assert item.is_file();item.unlink()
        def info():
            code = 'import sqlite3,json,sys; d=sqlite3.connect("file:"+sys.argv[1]+"?mode=ro",uri=True); print(json.dumps([d.execute(q).fetchone()[0] for q in ["SELECT COUNT(*) FROM gazette_editor_attempts","SELECT COUNT(*) FROM gazette_editor_requests","SELECT COUNT(*) FROM gazette_editor_receipts","SELECT COALESCE(SUM(reserved_microusd),0) FROM gazette_editor_attempts","SELECT COUNT(*) FROM gazette_editor_reviews"]])); d.close()'
            def drop():
                os.setgroups([]);os.setgid(DB_UID);os.setuid(DB_UID)
            result = subprocess.run(['/usr/bin/python3', '-c', code, str(database)], capture_output=True, text=True, preexec_fn=drop, check=True)
            return json.loads(result.stdout)
        def start(wait=True):
            run(['systemctl', 'reset-failed', worker], check=False)
            previous=property_value(worker, 'InvocationID')
            result = run(['systemctl', 'start', '--no-block', worker], check=False)
            wait_for(lambda: property_value(worker, 'InvocationID') not in ['', previous], 'Worker did not enter a new invocation')
            if wait:
                wait_for(lambda: property_value(worker, 'ActiveState') in ['inactive', 'failed'], 'Worker did not finish', 35)
            return result
        def status():
            raw = run(['journalctl', '--no-pager', '-u', worker, '-o', 'cat'], check=False).stdout
            values = [json.loads(line) for line in raw.splitlines() if line.startswith('{') and line.endswith('}')]
            assert values, 'No sanitized worker result: ' + raw[-1200:]
            return values[-1]
        try:
            for name, content in contents.items():
                target = Path('/run/systemd/system') / name
                assert not target.exists() and not target.is_symlink()
                fixture = unit_dir / name;fixture.write_text(content)
                run(['systemctl', 'link', '--runtime', str(fixture)])
                linked.append((target, fixture))
            run(['systemctl', 'daemon-reload'])
            reset();run(['systemctl', 'start', helper_socket])
            start();assert status()['status'] == 'disabled' and info() == [0, 0, 0, 0, 0]
            configure('ready', enabled=False, helper_enabled=False);start()
            assert status()['status'] == 'preflight_ready' and info() == [0, 0, 0, 0, 0]
            configure(helper_enabled=False);start();assert info() == [0, 0, 0, 0, 0] and not (state / 'calls').exists()
            configure('pin', worker_sha='b' * 40);start();assert status()['status'] == 'refused_or_uncertain' and info()[0] == 0
            configure('ready', helper_sha='b' * 40);start();assert status()['status'] == 'refused_or_uncertain' and info()[0] == 0
            # Real LoadCredential mount while the API mock waits; probe from other real UIDs.
            configure(barrier=True);start(wait=False)
            wait_for(lambda: (state / 'dispatched').exists(), 'No generation barrier')
            pid = property_value(worker, 'MainPID');assert int(pid) > 0
            credential = '/proc/' + pid + '/root/run/credentials/' + worker + '/openai_api_key'
            metadata = os.stat(credential);assert metadata.st_size == standin.stat().st_size
            for uid in [DB_UID, OTHER_UID]:
                probe = prefix + '-probe-' + str(uid)
                code = 'import os,sys; assert all(not os.access(p,os.R_OK) for p in sys.argv[1:])'
                run(['systemd-run', '--quiet', '--wait', '--collect', '--unit=' + probe,
                     '-p', 'User=' + str(uid), '-p', 'Group=' + str(uid), '-p', 'PrivateNetwork=true',
                     '-p', 'NoNewPrivileges=true', '/usr/bin/python3', '-c', code, credential, str(standin)])
            (state / 'continue').write_text('1')
            wait_for(lambda: property_value(worker, 'ActiveState') in ['inactive', 'failed'], 'Successful worker did not finish')
            assert status()['status'] == 'awaiting_owner_review' and info() == [1, 2, 2, 30000, 0]
            assert (state / 'calls').read_text() == '2'
            run(['systemctl', 'restart', helper_socket]);start()
            assert info() == [1, 2, 2, 30000, 0] and (state / 'calls').read_text() == '2'
            # Real independent SQLite writer holds the lock during the completion observation.
            reset();configure(barrier=True);start(wait=False)
            wait_for(lambda: (state / 'dispatched').exists(), 'No busy-case dispatch')
            run(['systemctl', 'start', writer]);wait_for(lambda: (writer_state / 'held').exists(), 'No competing write lock')
            (state / 'continue').write_text('1')
            wait_for(lambda: property_value(worker, 'ActiveState') in ['inactive', 'failed'], 'Busy worker did not fail closed')
            (writer_state / 'release').write_text('1');wait_for(lambda: property_value(writer, 'ActiveState') in ['inactive', 'failed'], 'Writer not drained')
            assert info() == [1, 1, 0, 30000, 0] and (state / 'calls').read_text() == '1'
            start();assert info() == [1, 1, 0, 30000, 0]
            # Stopping a required socket fences an in-flight worker; restarting never replays it.
            reset();configure(barrier=True);start(wait=False)
            wait_for(lambda: (state / 'dispatched').exists(), 'No restart-case dispatch')
            run(['systemctl', 'stop', helper_socket]);wait_for(lambda: property_value(worker, 'ActiveState') in ['inactive', 'failed'], 'Socket stop did not fence dependent worker')
            run(['systemctl', 'start', helper_socket]);start()
            assert info() == [1, 1, 0, 30000, 0] and (state / 'calls').read_text() == '1'
            assert property_value(worker, 'NRestarts') == '0'
            assert private.stat().st_mode & 0o777 == 0o700 and database.stat().st_mode & 0o777 == 0o600
            assert database.stat().st_uid == DB_UID
            print('PASS actual systemd Accept=yes socket, separate UIDs, LoadCredential readonly isolation, PHP broker client, disabled gates, pins, WAL contention, socket/worker restarts and no replay (zero paid calls)')
        except BaseException:
            # CI fixtures only: expose unit setup failures without ENV, credential contents or payloads.
            print(run(['journalctl', '--no-pager', '-u', prefix + '*', '-n', '60', '-o', 'cat'], check=False).stdout, flush=True)
            for name in [helper_socket, helper_template, worker, writer]:
                print(run(['systemctl', 'show', name, '-p', 'LoadState', '-p', 'Result', '-p', 'ExecMainStatus'], check=False).stdout, flush=True)
            raise
        finally:
            stop_units()
            for target, fixture in linked:
                assert target.is_symlink() and target.resolve() == fixture.resolve()
                target.unlink()
            run(['systemctl', 'daemon-reload'])


if __name__ == '__main__':
    main()
