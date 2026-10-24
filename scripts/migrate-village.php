<?php
declare(strict_types=1);

// CLI only. No web request can initialize or overwrite a database.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../app/village.php';

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
    $db->exec('BEGIN IMMEDIATE');
    $version = (int) $db->query('PRAGMA user_version')->fetchColumn();
    if ($version === 0) {
        // Intentionally no IF NOT EXISTS: refuse an unrelated existing schema.
        if ((int) $db->query("SELECT COUNT(*) FROM sqlite_master WHERE name NOT LIKE 'sqlite_%'")->fetchColumn() !== 0) {
            throw new RuntimeException('Refusing to initialize a nonempty, unversioned database.');
        }
        $db->exec("CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT NOT NULL);
            CREATE TABLE residents (
                id TEXT PRIMARY KEY, name TEXT NOT NULL, color TEXT NOT NULL,
                antenna TEXT NOT NULL, personality TEXT NOT NULL, created_at TEXT NOT NULL,
                owner_hash TEXT NOT NULL, request_id TEXT NOT NULL, request_hash TEXT NOT NULL,
                token_hash TEXT NOT NULL UNIQUE, UNIQUE(owner_hash, request_id)
            );
            CREATE INDEX residents_order ON residents(created_at, id);
            CREATE TABLE rate_limits (key TEXT NOT NULL, window INTEGER NOT NULL, attempts INTEGER NOT NULL, PRIMARY KEY(key, window));
            PRAGMA user_version = 1;");
        $db->prepare('INSERT INTO settings (name, value) VALUES (?, ?)')->execute(['secret', bin2hex(random_bytes(32))]);
    } elseif ($version !== 1) {
        throw new RuntimeException('Unsupported schema version; no migration applied.');
    }
    $db->exec('COMMIT');
    echo "Little Sparks schema v1 ready. Existing residents preserved.\n";
} catch (Throwable $error) {
    if (isset($db) && $db->inTransaction()) {
        $db->exec('ROLLBACK');
    }
    fwrite(STDERR, 'Migration stopped: ' . $error->getMessage() . "\n");
    exit(1);
}
