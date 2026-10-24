<?php
declare(strict_types=1);
require __DIR__.'/../../app/http.php';
sparksApi(function(PDO $db,array $context): never {
    if ($_SERVER['REQUEST_METHOD']!=='GET') { header('Allow: GET'); throw new LittleSparks\Problem(405,'Use GET to read the journal.'); }
    $before=filter_input(INPUT_GET,'before',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
    sparksReply(LittleSparks\journal($db,$before));
});
