"""CI-only competing writer under the real DB-owning UID; never changes rows."""
from pathlib import Path
import sqlite3
import time
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
