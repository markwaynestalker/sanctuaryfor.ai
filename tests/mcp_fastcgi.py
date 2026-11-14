"""Disposable nginx/FPM integration; no installed service or production path."""
import hashlib
import http.client
import json
import os
from pathlib import Path
import shutil
import socket
import ssl
import subprocess
import sys
import time

ROOT = Path(__file__).resolve().parents[1]


def main():
    required = os.environ.get('SANCTUARY_REQUIRE_MCP_FASTCGI') == '1'
    nginx = os.environ.get('MCP_NGINX_BINARY') or shutil.which('nginx')
    fpm = os.environ.get('MCP_FPM_BINARY') or shutil.which('php-fpm8.3')
    if sys.platform != 'linux' or not nginx or not fpm:
        if required:
            raise RuntimeError('Required disposable nginx/PHP 8.3-FPM binaries missing')
        print('SKIP actual nginx/FPM on this host; required Ubuntu CI executes it')
        return
    import pwd
    import grp
    parent, database = (Path(p).resolve() for p in sys.argv[1:3])
    if not parent.name.startswith('little-sparks-journal-test-') or database.parent != parent:
        raise RuntimeError('Disposable fixture directory required')
    work = parent / 'fastcgi'
    work.mkdir(mode=0o700)
    token = parent / 'mcp-token'
    before = hashlib.sha256(database.read_bytes()).hexdigest()
    releases = []
    for name in ('a', 'b'):
        release = work / name
        shutil.copytree(ROOT / 'app', release / 'app')
        (release / 'public').mkdir()
        shutil.copyfile(ROOT / 'public/mcp.php', release / 'public/mcp.php')
        if name == 'b':
            app = release / 'app/mcp.php'
            app.write_text(app.read_text().replace("'version'=>'1.0.0'", "'version'=>'1.0.1'"))
        releases.append(release)
    current = work / 'current'
    current.symlink_to(releases[0], target_is_directory=True)
    certificate, key = work / 'test-cert.pem', work / 'test-key.pem'
    subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
                    '-subj', '/CN=localhost', '-keyout', str(key), '-out', str(certificate)],
                   stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=True)
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    pool_socket = work / 'mcp.sock'
    pool = (ROOT / 'ops/php-fpm/sanctuary-mcp.conf').read_text()
    pool = pool.replace('www-data', pwd.getpwuid(os.geteuid()).pw_name)
    group_name = grp.getgrgid(os.getegid()).gr_name
    pool = pool.replace('group = ' + pwd.getpwuid(os.geteuid()).pw_name, 'group = ' + group_name)
    pool = pool.replace('listen.group = ' + pwd.getpwuid(os.geteuid()).pw_name, 'listen.group = ' + group_name)
    pool = pool.replace('/run/php/sanctuary-mcp.sock', str(pool_socket))
    pool = pool.replace('/var/lib/sanctuaryfor-ai/village.sqlite', str(database))
    pool = pool.replace('/etc/sanctuaryfor-ai-mcp/token', str(token))
    # Exercise realpath routing with opcache timestamp validation disabled.
    pool += '\nphp_admin_value[opcache.validate_timestamps] = 0\n'
    fpm_config = work / 'fpm.conf'
    fpm_config.write_text('[global]\ndaemonize = no\nerror_log = ' + str(work / 'fpm.log') + '\n' + pool)
    php = os.environ.get('PHP_BINARY') or shutil.which('php')
    ini_info = json.loads(subprocess.check_output([php, '-r',
        'echo json_encode([php_ini_loaded_file(),array_values(array_unique(array_map(fn($p)=>dirname(trim($p)),array_filter(explode(",",php_ini_scanned_files()?:"")))))]);'], text=True))
    fpm_env = dict(os.environ, PHP_INI_SCAN_DIR=os.pathsep.join(ini_info[1]))
    fpm_command = [fpm, '-F', '-y', str(fpm_config)]
    if ini_info[0]:
        fpm_command.extend(['-c', ini_info[0]])
    server = (ROOT / 'ops/nginx/mcp-server.conf').read_text()
    server = server.replace('/run/php/sanctuary-mcp.sock', str(pool_socket))
    server = server.replace('/var/log/nginx/sanctuary-mcp-access.log', str(work / 'access.log'))
    # The actual, unmodified routing/limits directives run in a temporary TLS host.
    config = work / 'nginx.conf'
    config.write_text('daemon off;\nmaster_process off;\npid ' + str(work / 'nginx.pid') + ';\n'
        'error_log ' + str(work / 'nginx.log') + ' notice;\nevents { worker_connections 64; }\nhttp {\n'
        + (ROOT / 'ops/nginx/mcp-http.conf').read_text() + '\nclient_body_temp_path ' + str(work / 'body')
        + ';\nfastcgi_temp_path ' + str(work / 'fastcgi-temp') + ';\nserver {\nlisten 127.0.0.1:'
        + str(port) + ' ssl;\nserver_name localhost;\nssl_certificate ' + str(certificate)
        + ';\nssl_certificate_key ' + str(key) + ';\nroot ' + str(current / 'public')
        + ';\nallow 127.0.0.2;\ndeny all;\nauth_basic "Inherited fixture gate";\nauth_basic_user_file ' + str(work / 'unused-passwords') + ';\n' + server + '\n}\n}\n')
    subprocess.run([nginx, '-t', '-p', str(work), '-c', str(config)], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
    children = []
    def request(message=None, authorization=True, route='/mcp', method='POST', extra=None):
        headers = {'Accept': 'application/json, text/event-stream', 'Content-Type': 'application/json', 'MCP-Protocol-Version': '2025-11-25'}
        if authorization:
            headers['Authorization'] = 'Bearer ' + token.read_text().strip()
        headers.update(extra or {})
        conn = http.client.HTTPSConnection('127.0.0.1', port, context=ssl._create_unverified_context(), timeout=15)
        try:
            conn.request(method, route, json.dumps(message).encode() if message is not None else b'', headers)
            response = conn.getresponse()
            return response.status, response.read()
        finally:
            conn.close()
    init = {'jsonrpc': '2.0', 'id': 1, 'method': 'initialize', 'params': {'protocolVersion': '2025-11-25', 'capabilities': {}, 'clientInfo': {'name': 'offline', 'version': '1'}}}
    try:
        children.append(subprocess.Popen(fpm_command, env=fpm_env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL))
        for _ in range(100):
            if pool_socket.exists():
                break
            if children[0].poll() is not None:
                raise RuntimeError('Disposable FPM failed: ' + (work / 'fpm.log').read_text())
            time.sleep(0.05)
        children.append(subprocess.Popen([nginx, '-p', str(work), '-c', str(config)], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL))
        for _ in range(100):
            try:
                with socket.create_connection(('127.0.0.1', port), timeout=0.1):
                    break
            except OSError:
                time.sleep(0.05)
        status, body = request(init)
        assert status == 200, (status, body)
        assert json.loads(body)['result']['serverInfo']['version'] == '1.0.0'
        status, body = request({'jsonrpc': '2.0', 'id': 2, 'method': 'tools/call', 'params': {'name': 'search_village_conversations', 'arguments': {'query': 'HISTORICAL_199', 'limit': 1}}})
        assert status == 200 and json.loads(body)['result']['structuredContent']['conversations'][0]['id'] == 'archive-199'
        assert request(init, authorization=False)[0] == 401
        assert request(init, extra={'Origin': 'https://evil.example'})[0] == 403
        assert request(init, route='/mcp.php', extra={'Sanctuary-Mcp-Route': '1'})[0] == 404
        assert request(method='GET')[0] == 405
        assert request({'padding': 'x' * 9000})[0] == 413
        replacement = work / 'next'
        replacement.symlink_to(releases[1], target_is_directory=True)
        replacement.replace(current)
        status, body = request(init)
        assert status == 200 and json.loads(body)['result']['serverInfo']['version'] == '1.0.1', (status, body)
        statuses = [request(init)[0] for _ in range(16)]
        assert 429 in statuses, statuses
        assert hashlib.sha256(database.read_bytes()).hexdigest() == before
        for log in work.glob('*.log'):
            assert token.read_text().strip() not in log.read_text(), 'Credential appeared in fixture logs'
        print('PASS actual nginx/PHP 8.3-FPM TLS auth, shared rate limit, body/direct-path/Origin fences, readonly history and realpath release switch with opcache timestamps disabled')
    finally:
        for child in reversed(children):
            child.terminate()
        for child in children:
            try:
                child.wait(timeout=5)
            except subprocess.TimeoutExpired:
                child.kill()
                child.wait()


if __name__ == '__main__':
    main()
