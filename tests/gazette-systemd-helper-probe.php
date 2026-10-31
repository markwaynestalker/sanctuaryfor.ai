<?php
declare(strict_types=1);
// No stdout: ExecStartPre must never inject data into the accepted protocol socket.
if(PHP_SAPI!=='cli'||getenv('CREDENTIALS_DIRECTORY')||getenv('OPENAI_API_KEY')
    ||is_readable('/etc/sanctuary-gazette/openai_api_key'))exit(1);
exit(0);
