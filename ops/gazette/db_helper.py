#!/usr/bin/env python3
"""Socket-activated keyless adapter. One authenticated frame and fixed PHP operation."""
import json
import os
from pathlib import Path
import re
import socket
import stat
import struct
import subprocess
import sys
import time

CONFIG = Path('/etc/sanctuary-gazette-db/config.json')
MAX_FRAME = 524288
ENV_KEYS = {'ENABLED', 'PRICING_CONFIRMED', 'DAILY_MICROUSD', 'MONTHLY_MICROUSD',
            'DAILY_REQUESTS', 'MODERATION_MICROUSD', 'MAX_INPUT_TOKENS',
            'MAX_OUTPUT_TOKENS', 'MAX_REQUEST_BYTES'}
PREFIX = 'LITTLE_SPARKS_GAZETTE_EDITOR_'
OWNER_REQUEST = Path('/run/sanctuary-gazette-owner/request.json')


def owner_request(path=OWNER_REQUEST):
    """Root-owned authorization, not a client field or a credential."""
    if not path.exists():
        return None
    protected(path.parent, directory=True)
    protected(path)
    if path.stat().st_size > 1024:
        raise ValueError('owner_request_bound')
    job = decode(path.read_bytes())
    if (set(job) != {'id', 'date', 'preview', 'expires_at', 'max_microusd', 'release'}
        or not re.fullmatch('[a-f0-9]{32}', str(job['id']))
        or not re.fullmatch(r'\d{4}-\d{2}-\d{2}', str(job['date']))
        or type(job['preview']) is not bool or type(job['expires_at']) is not int
        or not time.time() < job['expires_at'] <= time.time() + 600
        or type(job['max_microusd']) is not int or job['max_microusd'] <= 0
        or not re.fullmatch('[a-f0-9]{40}', str(job['release']))):
        raise ValueError('owner_request_invalid')
    return job


def unique(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError('duplicate_key')
        result[key] = value
    return result


def decode(raw):
    def bad_constant(_):
        raise ValueError('non_json_number')
    value = json.loads(raw.decode('utf-8'), object_pairs_hook=unique, parse_constant=bad_constant)
    def depth(item, level=0):
        if level > 32:
            raise ValueError('depth')
        if isinstance(item, dict):
            for child in item.values():
                depth(child, level + 1)
        elif isinstance(item, list):
            for child in item:
                depth(child, level + 1)
    depth(value)
    if not isinstance(value, dict):
        raise ValueError('object_required')
    return value


def encode(value):
    raw = json.dumps(value, ensure_ascii=False, separators=(',', ':'), allow_nan=False).encode('utf-8')
    if len(raw) > MAX_FRAME:
        raise ValueError('frame_bound')
    return raw


def exact(connection, size):
    data = bytearray()
    while len(data) < size:
        # recvmsg rejects ancillary FD/credential transfers instead of accepting descriptors.
        chunk, ancillary, flags, _ = connection.recvmsg(min(size - len(data), 65536), socket.CMSG_SPACE(256))
        if ancillary or flags & (socket.MSG_CTRUNC | socket.MSG_TRUNC):
            for level, kind, payload in ancillary:
                if level == socket.SOL_SOCKET and kind == socket.SCM_RIGHTS:
                    for offset in range(0, len(payload) - 3, 4):
                        os.close(struct.unpack('i', payload[offset:offset + 4])[0])
            raise ValueError('ancillary_refused')
        if not chunk:
            raise ValueError('truncated_frame')
        data.extend(chunk)
    return bytes(data)


def protected(path, directory=False):
    path = Path(path)
    info = path.lstat()
    if info.st_uid != 0 or info.st_mode & 0o022 or stat.S_ISLNK(info.st_mode):
        raise ValueError('untrusted_config')
    if directory != stat.S_ISDIR(info.st_mode):
        raise ValueError('wrong_config_type')
    if not directory and not stat.S_ISREG(info.st_mode):
        raise ValueError('wrong_config_type')


def configuration(path=CONFIG):
    path = Path(path)
    for parent in reversed(path.parents):
        protected(parent, directory=True)
    protected(path)
    if path.stat().st_size > 8192:
        raise ValueError('config_bound')
    cfg = decode(path.read_bytes())
    if set(cfg) not in ({'editor_uid', 'database', 'release', 'approved_date', 'environment'},
                        {'editor_uid', 'database', 'release', 'approved_date', 'environment', 'auto_publish'}):
        raise ValueError('config_keys')
    if 'auto_publish' in cfg:
        policy = cfg['auto_publish']
        if (not isinstance(policy, dict) or set(policy) != {'version','mode','enabled_at'}
                or type(policy['version']) is not int or policy['version'] != 1
                or policy['mode'] != 'initial_only' or type(policy['enabled_at']) is not int
                or not 0 <= policy['enabled_at'] <= time.time()):
            raise ValueError('auto_publication_policy')
    uid = cfg['editor_uid']
    if type(uid) is not int or uid <= 0 or uid == os.geteuid():
        raise ValueError('distinct_editor_identity_required')
    environment = cfg['environment']
    if not isinstance(environment, dict) or set(environment) != {PREFIX + name for name in ENV_KEYS}:
        raise ValueError('policy_keys')
    if not all(isinstance(value, str) for value in environment.values()):
        raise ValueError('policy_strings_required')
    release = Path(cfg['release'])
    if not release.is_absolute() or not re.fullmatch('[a-f0-9]{40}', release.name) or release.resolve(strict=True) != release:
        raise ValueError('release_path')
    if os.access(release, os.W_OK) or (release / '.release-commit').read_text(encoding='ascii') != release.name + '\n':
        raise ValueError('release_pin')
    # Current physical pin is reviewed alongside the immutable deployment manifest.
    if Path('/var/lib/sanctuaryfor-ai-deploy/current').resolve(strict=True) != release:
        raise ValueError('release_not_current')
    database = Path(cfg['database'])
    if not database.is_absolute() or database.resolve(strict=True) != database or database.is_symlink():
        raise ValueError('database_path')
    if cfg['approved_date'] is not None and (not isinstance(cfg['approved_date'], str) or not re.fullmatch(r'\d{4}-\d{2}-\d{2}', cfg['approved_date'])):
        raise ValueError('approved_date')
    return cfg


def operation(connection, cfg):
    # Credentials come from the connected kernel socket, never a claimed JSON field.
    _, uid, _ = struct.unpack('3i', connection.getsockopt(socket.SOL_SOCKET, socket.SO_PEERCRED, 12))
    if uid != cfg['editor_uid'] or uid == os.geteuid():
        raise ValueError('peer_refused')
    connection.settimeout(4)
    size = struct.unpack('!I', exact(connection, 4))[0]
    if not 1 <= size <= MAX_FRAME:
        raise ValueError('frame_bound')
    message = decode(exact(connection, size))
    # Exactly one request, terminated with SHUT_WR. No pipelining or trailing bytes.
    extra, ancillary, flags, _ = connection.recvmsg(1, socket.CMSG_SPACE(256))
    if extra or ancillary or flags:
        raise ValueError('trailing_frame')
    release = Path(cfg['release'])
    request = {'peer_uid': uid, 'database': cfg['database'], 'release': release.name,
               'approved_date': cfg['approved_date'], 'message': message}
    job = owner_request() if message.get('op') == 'begin_generation' else None
    if job is not None:
        request['owner_request'] = job
    if 'auto_publish' in cfg:
        request['auto_publish'] = cfg['auto_publish']
    request = encode(request)
    # A fresh allowlisted environment: neither caller secrets nor arbitrary PHP settings.
    env = {'PATH': '/usr/bin:/bin', 'LANG': 'C.UTF-8', **cfg['environment']}
    result = subprocess.run(['/usr/bin/php8.3', '-d', 'display_errors=0', '-d', 'log_errors=0',
                             str(release / 'scripts/gazette-db-operation.php')],
                            input=request, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                            env=env, timeout=10, check=False)
    response = decode(result.stdout)  # Failure output is sanitized by the PHP entry point.
    raw = encode(response)
    connection.sendall(struct.pack('!I', len(raw)) + raw)


def main():
    try:
        if sys.platform != 'linux' or os.geteuid() == 0:
            raise ValueError('nonroot_linux_required')
        # Accept=yes passes an already-connected socket. A pipe/file or arbitrary stdin fails.
        connection = socket.socket(fileno=os.dup(0))
        if connection.family != socket.AF_UNIX or connection.getsockopt(socket.SOL_SOCKET, socket.SO_TYPE) != socket.SOCK_STREAM:
            raise ValueError('accepted_unix_socket_required')
        with connection:
            operation(connection, configuration())
        return 0
    except (OSError, ValueError, KeyError, TypeError, RecursionError, subprocess.SubprocessError):
        # No raw exception, path, payload, capability, draft, usage or environment.
        print('Gazette database operation refused; inspect private ledger, do not retry.', file=sys.stderr)
        return 1


if __name__ == '__main__':
    sys.exit(main())
