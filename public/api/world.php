<?php
declare(strict_types=1);
require __DIR__.'/../../app/http.php';
sparksApi(function(PDO $db,array $context): never {
    if ($_SERVER['REQUEST_METHOD']!=='GET') { header('Allow: GET'); throw new LittleSparks\Problem(405,'Use GET to browse the world.'); }
    $sector=filter_input(INPUT_GET,'sector',FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>624]])?:0;
    sparksReply(LittleSparks\world($db,$sector)+['csrf'=>$context['csrf']]);
});
