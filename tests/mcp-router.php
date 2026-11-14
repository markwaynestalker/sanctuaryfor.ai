<?php
// Disposable loopback PHP-server fixture, never installed in production.
declare(strict_types=1);
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($path==='/mcp'){
    $_SERVER['SANCTUARY_MCP_ROUTE']='1';$_SERVER['HTTPS']='on';
    require __DIR__.'/../public/mcp.php';return true;
}
return false;
