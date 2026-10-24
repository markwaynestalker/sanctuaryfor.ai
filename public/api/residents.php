<?php
declare(strict_types=1);
require __DIR__ . '/../../app/http.php';

sparksApi(function (PDO $db, array $context): never {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        header('Allow: GET');
        throw new LittleSparks\Problem(405, 'Use GET to visit the residents.');
    }
    $page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 250]]) ?: 0;
    sparksReply(LittleSparks\residents($db, $page) + ['csrf' => $context['csrf'], 'browserLimit' => LittleSparks\BROWSER_LIMIT]);
});
