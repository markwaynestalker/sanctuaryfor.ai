<?php
declare(strict_types=1);
require __DIR__.'/../app/village.php';require __DIR__.'/../app/activity.php';require __DIR__.'/../app/director.php';
$db=LittleSparks\connect($argv[1]);$r=$db->query('SELECT a.id,a.day,a.month,l.expires_at AS expires,a.reserved_microusd AS reserved,a.resident_a FROM ai_attempts a JOIN ai_lease l ON l.owner=a.id')->fetch();
try{LittleSparks\startSparkCall($db,$r,$r['resident_a'],'reply',time()+30);echo '{"dispatched":true}';}
catch(Throwable){echo '{"dispatched":false}';}
