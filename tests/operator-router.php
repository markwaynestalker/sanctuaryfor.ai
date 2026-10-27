<?php
// Offline fixture only: simulate the trusted HTTPS FastCGI parameter on loopback.
if(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)==='/owner.php'){
    $_SERVER['HTTPS']='on';require __DIR__.'/../public/owner.php';return true;
}
return false;
