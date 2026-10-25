<?php
declare(strict_types=1);
require __DIR__.'/../../app/http.php';
sparksApi(function(PDO $db,array $context): never {
    $input=sparksInput($context['csrf'],24576);
    sparksReply(LittleSparks\editIdentity($db,$input,$_SERVER['REMOTE_ADDR']??'unknown',time()));
});
