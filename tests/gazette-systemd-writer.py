"""CI-only competing writer under the real DB-owning UID; never changes rows."""
from pathlib import Path
import os
import sqlite3
import time
marker=Path('/run/sanctuary-gazette-systemd-ci-marker')
if os.environ.get('SANCTUARY_CI_SYSTEMD_TEST') != '1' or not marker.is_file() or marker.stat().st_uid != 0 or marker.read_text() != 'DISPOSABLE_CI_ONLY':
    raise RuntimeError('Disposable CI root required before SQLite access')
database = sqlite3.connect('/var/lib/sanctuaryfor-ai/village.sqlite', timeout=1)
database.execute('BEGIN IMMEDIATE')
state = Path('/var/lib/sanctuary-gazette-writer-test')
(state / 'held').write_text('1')
deadline = time.monotonic() + 25
while not (state / 'release').exists():
    if time.monotonic() > deadline:
        raise RuntimeError('Synthetic writer barrier expired')
    time.sleep(.05)
database.rollback()
database.close()
