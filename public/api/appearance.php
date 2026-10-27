<?php
declare(strict_types=1);
require __DIR__.'/../../app/http.php';
sparksApi(function(PDO $db,array $context):never{sparksReply(LittleSparks\editAppearance($db,sparksInput($context['csrf']),$_SERVER['REMOTE_ADDR']??'unknown',time()));});
