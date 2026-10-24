<?php
declare(strict_types=1);

// CLI only. No web request can initialize or overwrite a database.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../app/village.php';
require __DIR__ . '/../app/schema.php';

try {
    $options = getopt('', ['database:']);
    $path = LittleSparks\databasePath((string) ($options['database'] ?? ''), true);
    umask(0077);
    if (!file_exists($path)) {
        $file = fopen($path, 'x');
        if ($file === false) {
            throw new RuntimeException('Could not exclusively create the database.');
        }
        fclose($file);
        if (PHP_OS_FAMILY !== 'Windows' && !chmod($path, 0600)) {
            throw new RuntimeException('Could not restrict database permissions.');
        }
    }
    $db = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('PRAGMA busy_timeout = 5000');
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('BEGIN IMMEDIATE');
    LittleSparks\migrateSchema($db);
    $db->exec('COMMIT');
    echo "Little Sparks schema v2 ready. Existing V1 records and installation secret preserved.\n";
} catch (Throwable $error) {
    if (isset($db) && $db->inTransaction()) {
        $db->exec('ROLLBACK');
    }
    fwrite(STDERR, 'Migration stopped: ' . $error->getMessage() . "\n");
    exit(1);
}
