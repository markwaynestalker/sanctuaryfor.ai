#!/usr/bin/env python3
"""Run syntax checks and discover all offline suites. No dependencies or secrets."""
import os
from pathlib import Path
import shutil
import subprocess
import sys


def main():
    # Match the service's private-file policy, including copied SQLite fixtures.
    os.umask(0o077)
    root = Path(__file__).resolve().parents[2]
    os.chdir(root)
    env = {k: v for k, v in os.environ.items()
           if not k.startswith(('LITTLE_SPARKS_', 'OPENAI_'))}
    env.update(OPENAI_API_KEY='', LITTLE_SPARKS_AI_ENABLED='0',
               LITTLE_SPARKS_DIRECTOR_ENABLED='0', LITTLE_SPARKS_SIMULATION_ENABLED='0',
               LITTLE_SPARKS_SCHEDULER_ENABLED='0', LITTLE_SPARKS_SCHEDULER_ALLOW_PAID='0')
    php = os.environ.get('PHP_BINARY') or shutil.which('php')
    node = shutil.which('node')
    if not php or not node:
        raise RuntimeError('PHP and Node are required')
    env['PHP_BINARY'] = php

    def run(args):
        subprocess.run(args, env=env, check=True, timeout=600)

    php_files = sorted(p for folder in ('app', 'scripts', 'public', 'tests')
                       for p in (root / folder).rglob('*.php'))
    for path in php_files:
        run([php, '-l', str(path)])
    js_files = sorted((root / 'public').rglob('*.js')) + sorted((root / 'tests').glob('*.mjs'))
    for path in js_files:
        run([node, '--check', str(path)])
    # Python's test module also validates workflow policy without third-party YAML.
    run([sys.executable, '-B', '-m', 'unittest', 'discover', '-s', 'tests', '-p', 'deployment_test.py', '-v'])
    suites = sorted((root / 'tests').glob('*.mjs'))
    if not suites:
        raise RuntimeError('No JavaScript suites discovered')
    for suite in suites:
        print('OFFLINE SUITE:', suite.name, flush=True)
        run([node, str(suite)])
    print(f'PASS {len(php_files)} PHP syntax checks, {len(js_files)} JS syntax checks, '
          f'deployment tests and all {len(suites)} JavaScript suites', flush=True)


if __name__ == '__main__':
    try:
        main()
    except (OSError, RuntimeError, subprocess.SubprocessError) as error:
        print(f'Offline checks failed: {error}', file=sys.stderr)
        sys.exit(1)
