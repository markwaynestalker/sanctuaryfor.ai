#!/usr/bin/env python3
"""Root-installed entry point. No installer, shell, retry, publication or secret read."""
import argparse
from datetime import datetime, timedelta, timezone
import json
import os
from pathlib import Path
import re
import subprocess
import sys
from zoneinfo import ZoneInfo

RELEASES = Path('/var/lib/sanctuaryfor-ai-deploy/releases')
CURRENT = Path('/var/lib/sanctuaryfor-ai-deploy/current')
PHP = '/usr/bin/php8.3'
SHA = re.compile(r'[a-f0-9]{40}')


def edition(now, scheduled, explicit=None):
    """Calendar arithmetic, not minus 86400 seconds; DST days can be 23/25h."""
    local = now.astimezone(ZoneInfo('America/New_York'))
    if scheduled:
        if explicit is not None or local.hour != 5 or not 15 <= local.minute < 20:
            raise ValueError('outside_morning_window')
        return (local.date() - timedelta(days=1)).isoformat()
    if not explicit or not re.fullmatch(r'\d{4}-\d{2}-\d{2}', explicit):
        raise ValueError('finished_date_required')
    value = datetime.strptime(explicit, '%Y-%m-%d').date()
    if value.isoformat() != explicit or value >= local.date():
        raise ValueError('finished_date_required')
    return explicit


def release(sha):
    if not SHA.fullmatch(sha):
        raise ValueError('reviewed_release_required')
    path = RELEASES / sha
    # Owner verifies the private deploy manifest before pinning this SHA.
    # These checks bind invocation to that physical release, never a moving entry point.
    if path.is_symlink() or path.resolve(strict=True) != path or CURRENT.resolve(strict=True) != path:
        raise ValueError('reviewed_release_not_current')
    if (path / '.release-commit').read_text(encoding='ascii') != sha + '\n':
        raise ValueError('release_marker_mismatch')
    if os.access(path, os.W_OK):
        raise ValueError('service_can_write_release')
    return path


def command(path, check=False):
    args = [PHP, '-d', 'display_errors=0', '-d', 'log_errors=0',
            str(path / 'scripts/generate-gazette-brokered.php')]
    args += ['--check'] if check else ['--allow-paid=yes']
    return args


def safe_result(raw):
    if len(raw) > 4096:
        raise ValueError('invalid_job_receipt')
    result = json.loads(raw)
    allowed = {'awaiting_owner_review', 'quiet_or_input_bound', 'moderation_rejected',
               'rejected_or_uncertain', 'preflight_ready'}
    if not isinstance(result, dict) or result.get('status') not in allowed:
        raise ValueError('invalid_job_receipt')
    safe = {'status': result['status']}
    for key, size in [('attempt_id', 32), ('draft_sha256', 64)]:
        value = result.get(key)
        if isinstance(value, str) and re.fullmatch('[a-f0-9]{' + str(size) + '}', value):
            safe[key] = value
    # No source/draft copy, usage tokens, key, environment or vendor exception output.
    return safe


def main():
    parser = argparse.ArgumentParser()
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--scheduled', action='store_true')
    mode.add_argument('--once', action='store_true')  # Helper root config authorizes any one-off date.
    mode.add_argument('--check', action='store_true')  # No credential content/network/writes.
    args = parser.parse_args()
    if not args.check and os.environ.get('LITTLE_SPARKS_GAZETTE_EDITOR_ENABLED') != '1':
        print('{"status":"disabled","paid_requests":0}')
        return 0  # Before release resolution, database, credential metadata or subprocess.
    try:
        if sys.platform != 'linux' or os.geteuid() == 0:
            raise ValueError('nonroot_linux_service_required')
        if args.scheduled:
            edition(datetime.now(timezone.utc), True)
        path = release(os.environ.get('LITTLE_SPARKS_GAZETTE_EDITOR_RELEASE_SHA', ''))
        # No shell, no selected executable/environment path, no automatic second invocation.
        result = subprocess.run(command(path, args.check), stdin=subprocess.DEVNULL,
                                stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                                timeout=135, check=False)
        print(json.dumps(safe_result(result.stdout)))
        return 0 if result.returncode == 0 else 1
    except (OSError, ValueError, KeyError, subprocess.SubprocessError):
        print('{"status":"refused_or_uncertain","action":"inspect_ledger_do_not_retry"}')
        return 1


if __name__ == '__main__':
    sys.exit(main())
