<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/../app/village.php';
require __DIR__.'/../app/social.php';
try {
    $options=getopt('',['database:','period:']);
    if (!isset($options['database'],$options['period'])) throw new RuntimeException('Explicit --database and --period=YYYY-MM-DD are required.');
    $result=LittleSparks\simulateDay(LittleSparks\connect((string)$options['database']),(string)$options['period']);
    echo json_encode($result,JSON_THROW_ON_ERROR)."\n";
    if ($result['status']==='failed') exit(1);
} catch (Throwable) {
    fwrite(STDERR,"Simulation not applied. Check the approved database path, schema, UTC period, feature flag, and recorded run status.\n"); exit(1);
}
