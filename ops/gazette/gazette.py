#!/usr/bin/python3 -I
"""Local root-owner control. No key reads, provider HTTP, SQL, retry or auto-publication."""
import argparse
from contextlib import contextmanager
from datetime import datetime, timezone
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import stat
import subprocess
import sys
import tempfile
import time
from zoneinfo import ZoneInfo
if sys.platform == 'linux':
    import fcntl
    import grp
    import pwd

CONFIG = Path('/etc/sanctuary-gazette-db/config.json')
ENV = Path('/etc/sanctuary-gazette/editor.env')
RUNTIME = Path('/run/sanctuary-gazette-owner')
REQUEST = RUNTIME / 'request.json'
UNIT = 'sanctuary-gazette-editor.service'
SOCKET = 'sanctuary-gazette-db.socket'
PREFIX = 'LITTLE_SPARKS_GAZETTE_EDITOR_'


def run(argv, *, check=True, data=None):
    result = subprocess.run(argv, input=data, text=True, stdout=subprocess.PIPE,
                            stderr=subprocess.PIPE, timeout=180, check=False)
    if check and result.returncode:
        raise ValueError('operation_refused_inspect_status_no_automatic_retry')
    return result


def trusted(path, directory=False):
    path = Path(path)
    for part in [*reversed(path.parents), path]:
        info = part.lstat()
        if info.st_uid != 0 or info.st_mode & 0o022 or stat.S_ISLNK(info.st_mode):
            raise ValueError('untrusted_owner_path')
    if directory != path.is_dir():
        raise ValueError('unexpected_owner_path_type')


def config():
    trusted(CONFIG)
    value = json.loads(CONFIG.read_text())
    code = Path(value['release'])
    if (not re.fullmatch('[a-f0-9]{40}', code.name)
            or code != Path('/var/lib/sanctuaryfor-ai-deploy/releases') / code.name
            or Path('/var/lib/sanctuaryfor-ai-deploy/current').resolve() != code
            or (code / '.release-commit').read_text() != code.name + '\n'):
        raise ValueError('reviewed_release_not_current')
    trusted(code, directory=True)
    return value, code


def state(unit):
    return run(['systemctl', 'show', unit, '-p', 'ActiveState', '--value']).stdout.strip()


def idle():
    if state(UNIT) not in {'inactive', 'failed'}:
        raise ValueError('editor_in_flight_do_not_restart')


def socket_ready():
    idle()
    run(['systemctl', 'start', 'sanctuary-gazette-runtime.service'])
    path = Path('/run/sanctuary-gazette-db/editor.sock')
    if state(SOCKET) == 'active' and not path.exists():
        # No editor is running; recover only its broken socket, never a paid worker.
        run(['systemctl', 'restart', SOCKET])
    else:
        run(['systemctl', 'reset-failed', SOCKET], check=False)
        run(['systemctl', 'start', SOCKET])
    if not stat.S_ISSOCK(path.lstat().st_mode):
        raise ValueError('private_socket_unavailable')


@contextmanager
def owner_lock():
    run(['systemctl', 'start', 'sanctuary-gazette-runtime.service'])
    trusted(RUNTIME, directory=True)
    fd = os.open(RUNTIME / 'owner.lock', os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    try:
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        yield
    finally:
        os.close(fd)


def write_file(path, raw, mode):
    fd, name = tempfile.mkstemp(prefix='.gazette-', dir=path.parent)
    try:
        with os.fdopen(fd, 'w') as stream:
            os.fchmod(stream.fileno(), mode)
            stream.write(raw)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(name, path)
    finally:
        if os.path.exists(name):
            os.unlink(name)


def metadata(cfg, code, *, date=None, job=None):
    argv = ['/usr/bin/php8.3', str(code / 'scripts/gazette-owner-status.php'),
            '--database=' + cfg['database']]
    if date:
        argv += ['--date=' + date]
    if job:
        argv += ['--job=' + job]
    result = json.loads(run(argv).stdout)
    result['limits'] = {key: cfg['environment'][PREFIX + key] for key in ('DAILY_MICROUSD','MONTHLY_MICROUSD','DAILY_REQUESTS')}
    return result


def edition(value, preview, now=None):
    today = (now or datetime.now(timezone.utc)).astimezone(ZoneInfo('America/New_York')).date()
    value = today.isoformat() if value == 'today' else value
    parsed = datetime.strptime(value, '%Y-%m-%d').date()
    if parsed.isoformat() != value or parsed.year < 1970 or parsed > today or (parsed == today) != preview:
        raise ValueError('choose_completed_date_or_explicit_today_preview')
    return value


def generation(args, cfg, code):
    if not args.approve_paid:
        raise ValueError('explicit_approve_paid_required')
    date = edition(args.date, args.command == 'preview')
    info = metadata(cfg, code)
    if info['ledger_revision'] != 2:
        raise ValueError('owner_ledger_upgrade_required')
    policy = cfg['environment']
    cost = ((int(policy[PREFIX+'MAX_INPUT_TOKENS'])*250000+999999)//1000000
            + (int(policy[PREFIX+'MAX_OUTPUT_TOKENS'])*500000+999999)//1000000
            + int(policy[PREFIX+'MODERATION_MICROUSD']))
    if cost > args.max_attempt_microusd:
        raise ValueError('maximum_attempt_cap')
    for period, name in [('day','DAILY_MICROUSD'),('month','MONTHLY_MICROUSD')]:
        if info['budgets'][period]['committed_microusd'] + cost > int(policy[PREFIX+name]):
            print(json.dumps(info))
            raise ValueError('daily_budget_cap' if period=='day' else 'monthly_budget_cap')
    if info['budgets']['day']['reserved_requests'] + 2 > int(policy[PREFIX+'DAILY_REQUESTS']):
        print(json.dumps(info)); raise ValueError('daily_request_cap')
    socket_ready()
    if REQUEST.exists():
        trusted(REQUEST)
        old = json.loads(REQUEST.read_text())
        records = metadata(cfg, code, job=old['id'])['attempts']
        if records or (not records and old['expires_at'] + 15 < time.time()):
            # A consumed SQL job can never dispatch twice. Removing its expired
            # admission file does not clear a lease/hold or unlock an uncertain
            # edition; the ledger still enforces those checks for the new job.
            REQUEST.unlink()
        else:
            raise ValueError('prior_owner_request_unknown_inspect_status')
    check(cfg, code)  # Real credential mount + readonly broker; no HTTP or SQL write.
    job = {'id': secrets.token_hex(16), 'date': date, 'preview': args.command == 'preview',
           'expires_at': int(time.time()) + 300, 'max_microusd': args.max_attempt_microusd,
           'release': code.name}
    if job['max_microusd'] < 1:
        raise ValueError('positive_attempt_cap_required')
    write_file(REQUEST, json.dumps(job), 0o644)
    try:
        # No ExecStart override, copied key, manual permit, or provider retry.
        result = run(['systemctl', 'start', UNIT], check=False)
        report = metadata(cfg, code, job=job['id'])
        print(json.dumps(report))
        if not report['attempts'] or report['attempts'][0]['outcome'] != 'finished':
            raise ValueError('generation_not_ready_inspect_status_no_automatic_retry')
        if result.returncode:
            raise ValueError('worker_exit_unknown_inspect_status')
    finally:
        # Completions use their bound capabilities, not this admission file.
        REQUEST.unlink(missing_ok=True)


def check(cfg, code):
    socket_ready()
    override = Path('/run/systemd/system/' + UNIT + '.d/95-gazette-check.conf')
    override.parent.mkdir(mode=0o755, exist_ok=True)
    trusted(override.parent, directory=True)
    if override.exists():
        raise ValueError('existing_check_override_requires_review')
    try:
        write_file(override, '[Service]\nExecStart=\nExecStart=/usr/bin/python3 -I /usr/local/lib/sanctuary-gazette/run.py --check\nPrivateNetwork=yes\n', 0o644)
        run(['systemctl', 'daemon-reload'])
        run(['systemctl', 'start', UNIT])
        print('Credential metadata and readonly broker readiness passed; zero API calls.')
    finally:
        override.unlink(missing_ok=True)
        run(['systemctl', 'daemon-reload'])


def owner_tool(cfg, code, *options):
    return run(['/usr/bin/php8.3', str(code / 'scripts/review-gazette.php'),
                '--database=' + cfg['database'], *options]).stdout


def review_or_publish(args, cfg, code):
    if not re.fullmatch('[a-f0-9]{32}', args.attempt):
        raise ValueError('attempt_id_required')
    raw = owner_tool(cfg, code, '--action=show', '--attempt=' + args.attempt)
    draft = json.loads(raw)
    if args.command == 'review':
        if not sys.stdin.isatty() or not sys.stdout.isatty():
            raise ValueError('private_interactive_owner_terminal_required')
        # Raw draft/source output goes only to a private owner file and pager.
        with tempfile.TemporaryDirectory(prefix='gazette-review-', dir='/root') as directory:
            file = Path(directory) / 'review.json'
            write_file(file, raw, 0o600)
            if draft.get('preview'):
                print('IN-PROGRESS PREVIEW — PARTIAL COVERAGE; cannot publish as a finalized edition.')
            env = {**os.environ, 'LESSSECURE': '1', 'LESSHISTFILE': '-'}
            subprocess.run(['/usr/bin/less', str(file)], env=env, check=True)
            answer = input('After reviewing all sources and claims, type approve or reject (anything else cancels): ')
            decision = {'approve': 'approved', 'reject': 'rejected'}.get(answer)
            if decision:
                print(owner_tool(cfg, code, '--action=review', '--attempt=' + args.attempt,
                                 '--sha256=' + draft['draft_sha256'], '--decision=' + decision,
                                 '--confirm-reviewed=sources-and-claims').strip())
        return
    if draft.get('preview'):
        raise ValueError('partial_preview_cannot_publish')
    current = draft['current_publication_sha256']
    if current is not None and not args.replace_current:
        raise ValueError('existing_publication_requires_explicit_replace_current')
    print(owner_tool(cfg, code, '--action=publish', '--attempt=' + args.attempt,
                     '--sha256=' + draft['draft_sha256'], '--expected-current=' + (current or 'none')).strip())


def configure(args, cfg):
    idle()
    trusted(ENV)
    client = dict(line.split('=', 1) for line in ENV.read_text().splitlines()
                  if line and not line.startswith('#'))
    changes = {}
    if args.approve_limits:
        changes = {PREFIX + 'DAILY_MICROUSD': str(args.daily_microusd),
                   PREFIX + 'MONTHLY_MICROUSD': str(args.monthly_microusd),
                   PREFIX + 'DAILY_REQUESTS': str(args.requests)}
        if min(args.daily_microusd, args.monthly_microusd, args.requests) < 0:
            raise ValueError('nonnegative_limits_required')
    if args.approve_enable:
        changes[PREFIX + 'ENABLED'] = '1'
    if not changes:
        raise ValueError('explicit_limit_or_enable_approval_required')
    client.update(changes)
    cfg['environment'].update(changes)
    write_file(ENV, ''.join(k + '=' + v + '\n' for k, v in client.items()), stat.S_IMODE(ENV.stat().st_mode))
    # A crash between files fails policy matching closed; no job is running.
    write_file(CONFIG, json.dumps(cfg, indent=2) + '\n', stat.S_IMODE(CONFIG.stat().st_mode))
    print('Explicitly approved Gazette policy saved on both sides; no request or timer activation.')


def install(args):
    if not args.approve_install or not re.fullmatch('[a-f0-9]{40}', args.release):
        raise ValueError('reviewed_install_approval_required')
    code = Path('/var/lib/sanctuaryfor-ai-deploy/releases') / args.release
    trusted(code, directory=True)
    if (Path('/var/lib/sanctuaryfor-ai-deploy/current').resolve() != code
            or (code / '.release-commit').read_text() != args.release + '\n'
            or Path(__file__).resolve() != code / 'ops/gazette/gazette.py'):
        raise ValueError('execute_reviewed_current_release_installer')
    trusted(CONFIG); trusted(ENV)
    cfg = json.loads(CONFIG.read_text())
    database = Path(cfg['database']); info = database.stat()
    if (stat.S_IMODE(info.st_mode) != 0o600 or stat.S_IMODE(database.parent.stat().st_mode) != 0o700
            or info.st_uid in {0, cfg['editor_uid']}):
        raise ValueError('private_database_identity_required')
    idle()
    timer = 'sanctuary-gazette-editor.timer'
    if (state(timer) != 'inactive'
            or run(['systemctl', 'is-enabled', timer], check=False).stdout.strip() != 'disabled'):
        raise ValueError('disable_gazette_timer_and_drain_editor_before_install')
    user = pwd.getpwuid(info.st_uid); group = grp.getgrgid(info.st_gid)
    editor = pwd.getpwuid(cfg['editor_uid'])
    if editor.pw_name != 'sanctuarygazette':
        raise ValueError('review_actual_editor_account')
    # SQLite backup runs as the existing DB owner, includes WAL, and creates no
    # root-owned journals. No Director stop/restart or accounting modification.
    backup = str(database) + '.before-owner-' + secrets.token_hex(8) + '.sqlite'
    backup_code = ('import sqlite3,sys,os\nos.umask(0o077)\n'
                   's=sqlite3.connect("file:"+sys.argv[1]+"?mode=ro",uri=True)\n'
                   'd=sqlite3.connect(sys.argv[2])\ns.backup(d)\n'
                   'if d.execute("PRAGMA integrity_check").fetchone()!=("ok",): raise SystemExit("Backup integrity refused")\n'
                   'd.close()\ns.close()\n')
    run(['runuser', '-u', user.pw_name, '--', '/usr/bin/python3', '-c', backup_code, str(database), backup])
    run(['runuser', '-u', user.pw_name, '--', '/usr/bin/php8.3',
         str(code / 'scripts/upgrade-gazette-owner.php'), '--database=' + str(database), '--confirm-drained=yes'])
    destination = Path('/usr/local/lib/sanctuary-gazette')
    destination.mkdir(mode=0o755, parents=True, exist_ok=True); trusted(destination, directory=True)
    for name in ('run.py', 'db_helper.py'):
        write_file(destination / name, (code / 'ops/gazette' / name).read_text(), 0o755)
    write_file(Path('/usr/local/sbin/gazette'), (code / 'ops/gazette/gazette.py').read_text(), 0o755)
    for name in ('sanctuary-gazette-editor.service', 'sanctuary-gazette-db.socket',
                 'sanctuary-gazette-db@.service', 'sanctuary-gazette-runtime.service'):
        content = (code / 'ops/systemd' / name).read_text().replace(
            'REVIEWED_DATABASE_OWNER', user.pw_name).replace('REVIEWED_DATABASE_GROUP', group.gr_name)
        write_file(Path('/etc/systemd/system') / name, content, 0o644)
    write_file(Path('/etc/tmpfiles.d/sanctuary-gazette-db.conf'),
               (code / 'ops/systemd/sanctuary-gazette-db.tmpfiles.conf').read_text(), 0o644)
    client = dict(line.split('=', 1) for line in ENV.read_text().splitlines() if line and not line.startswith('#'))
    client[PREFIX + 'RELEASE_SHA'] = args.release
    cfg['release'] = str(code); cfg['approved_date'] = None
    write_file(ENV, ''.join(k + '=' + v + '\n' for k, v in client.items()), stat.S_IMODE(ENV.stat().st_mode))
    write_file(CONFIG, json.dumps(cfg, indent=2) + '\n', stat.S_IMODE(CONFIG.stat().st_mode))
    run(['systemctl', 'daemon-reload'])
    run(['systemctl', 'start', 'sanctuary-gazette-runtime.service'])
    print('Reviewed owner workflow installed; history/limits/credentials retained; timer stays disabled. Backup: ' + backup)


def parser():
    root = argparse.ArgumentParser(prog='gazette')
    sub = root.add_subparsers(dest='command', required=True)
    for name in ('generate', 'preview'):
        item = sub.add_parser(name)
        item.add_argument('--date', required=name == 'generate', default='today')
        item.add_argument('--approve-paid', action='store_true')
        item.add_argument('--max-attempt-microusd', type=int, default=50000)
    sub.add_parser('check')
    item = sub.add_parser('status'); item.add_argument('--date')
    for name in ('review', 'publish'):
        item = sub.add_parser(name); item.add_argument('--attempt', required=True)
        if name == 'publish': item.add_argument('--replace-current', action='store_true')
    item = sub.add_parser('configure')
    item.add_argument('--approve-limits', action='store_true')
    item.add_argument('--approve-enable', action='store_true')
    item.add_argument('--daily-microusd', type=int, default=1000000)
    item.add_argument('--monthly-microusd', type=int, default=20000000)
    item.add_argument('--requests', type=int, default=20)
    item = sub.add_parser('install')
    item.add_argument('--release', required=True)
    item.add_argument('--approve-install', action='store_true')
    return root


def main():
    args = parser().parse_args()
    if sys.platform != 'linux' or os.geteuid() != 0:
        raise ValueError('local_root_owner_via_sudo_required')
    os.umask(0o077)
    if args.command == 'install':
        install(args)
        return
    cfg, code = config()
    with owner_lock():
        if args.command in {'generate', 'preview'}: generation(args, cfg, code)
        elif args.command == 'check': check(cfg, code)
        elif args.command == 'status': print(json.dumps(metadata(cfg, code, date=args.date)))
        elif args.command == 'configure': configure(args, cfg)
        else: review_or_publish(args, cfg, code)


if __name__ == '__main__':
    try:
        main()
    except (ValueError, OSError, KeyError, subprocess.SubprocessError) as error:
        # No arbitrary exception, config, provider text or private draft in diagnostics.
        advice = {
            'explicit_approve_paid_required':'Add --approve-paid to authorize one generation and conditional moderation.',
            'owner_ledger_upgrade_required':'Run the reviewed one-time owner workflow installer first.',
            'daily_budget_cap':'Daily budget exhausted. Inspect status; only explicit configure --approve-limits changes limits.',
            'monthly_budget_cap':'Monthly budget exhausted. Inspect status; retained reservations are never refunded.',
            'daily_request_cap':'Daily request allowance exhausted. Inspect status and explicitly approve appropriate limits.',
            'maximum_attempt_cap':'The configured reservation exceeds --max-attempt-microusd; explicitly review that per-job cap.',
            'partial_preview_cannot_publish':'Partial previews cannot be published as finalized daily newspapers.',
            'existing_publication_requires_explicit_replace_current':'Use --replace-current only after reviewing a correction.',
            'generation_not_ready_inspect_status_no_automatic_retry':'No ready draft. Inspect status. Only confirmed rejection permits a newly approved revision; never retry uncertain work.',
            'editor_in_flight_do_not_restart':'An editor is running; let it finish. Do not restart it.',
            'prior_owner_request_unknown_inspect_status':'Previous owner request is not safely finished. Inspect status; do not clear it.',
        }
        print(advice.get(str(error), 'Gazette operation refused or incomplete. Use gazette status; do not retry uncertain work.'), file=sys.stderr)
        sys.exit(1)
