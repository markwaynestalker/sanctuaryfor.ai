import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
const result=spawnSync(process.env.PHP_BINARY||'php',['tests/director-selection-core.php'],{encoding:'utf8'});
assert.equal(result.status,0,result.stderr);process.stdout.write(result.stdout);