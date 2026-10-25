<?php
declare(strict_types=1);
require __DIR__ . '/../../app/http.php';

sparksApi(function (PDO $db, array $context): never {
    $input = sparksInput($context['csrf'],LittleSparks\identityEnabled($db)?24576:2048);
    $result = LittleSparks\create($db, $input, $context['browser'], $_SERVER['REMOTE_ADDR'] ?? 'unknown', time(), $context['ready']);
    sparksReply($result, $result['replayed'] ? 200 : 201);
});
