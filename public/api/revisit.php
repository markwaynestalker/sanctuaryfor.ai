<?php
declare(strict_types=1);
require __DIR__ . '/../../app/http.php';

sparksApi(function (PDO $db, array $context): never {
    $input = sparksInput($context['csrf']);
    if (!is_string($input['token'] ?? null)) {
        throw new LittleSparks\Problem(422, 'That private link is not valid.');
    }
    sparksReply(['resident' => LittleSparks\revisit($db, $input['token'], $_SERVER['REMOTE_ADDR'] ?? 'unknown', time())]);
});
