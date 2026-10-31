import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
const r=spawnSync(process.env.PYTHON_BINARY||'python',['-B','-m','unittest','discover','-s','tests','-p','gazette_broker_protocol_test.py','-v'],{encoding:'utf8'});
assert.equal(r.status,0,r.stderr||r.error?.message);console.log('PASS strict keyless broker frames, operation policy and Linux socket peer checks (offline)');
