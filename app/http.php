<?php
declare(strict_types=1);

require_once __DIR__ . '/village.php';

function sparksHeaders(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
}

function sparksReply(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    exit;
}

function sparksSession(): array
{
    $secure = getenv('LITTLE_SPARKS_SECURE_COOKIES') === '1' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_name('sparks_session');
    if (!session_start(['use_strict_mode' => 1, 'use_only_cookies' => 1, 'cookie_httponly' => 1,
        'cookie_secure' => $secure, 'cookie_samesite' => 'Strict', 'cookie_path' => '/'])) {
        throw new RuntimeException('Private session storage is unavailable.');
    }
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    $_SESSION['started'] ??= time();
    $browser = $_COOKIE['sparks_browser'] ?? '';
    if (!is_string($browser) || !preg_match('/\A[a-f0-9]{64}\z/', $browser)) {
        $browser = bin2hex(random_bytes(32));
        setcookie('sparks_browser', $browser, ['expires' => time() + 31536000, 'path' => '/',
            'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict']);
    }
    $context = ['csrf' => $_SESSION['csrf'], 'ready' => time() - $_SESSION['started'] >= 2, 'browser' => $browser];
    if (!session_write_close()) {
        throw new RuntimeException('Private session storage could not be saved.');
    }
    return $context;
}

function sparksInput(string $csrf,int $maximum=2048): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        throw new LittleSparks\Problem(405, 'Use POST for this request.');
    }
    if (!hash_equals($csrf, $_SERVER['HTTP_X_SPARKS_CSRF'] ?? '')) {
        throw new LittleSparks\Problem(403, 'Refresh the village before sending this request.');
    }
    if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') {
        throw new LittleSparks\Problem(403, 'Open the village directly to send this request.');
    }
    if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
        throw new LittleSparks\Problem(415, 'Send a JSON request.');
    }
    $raw = file_get_contents('php://input', false, null, 0, $maximum+1);
    if ($raw === false || strlen($raw) > $maximum) {
        throw new LittleSparks\Problem(413, 'That request is too large.');
    }
    try {
        $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new LittleSparks\Problem(400, 'That JSON request could not be read.');
    }
    if (!is_array($data) || array_is_list($data)) {
        throw new LittleSparks\Problem(400, 'Send a JSON object.');
    }
    return $data;
}

function sparksApi(callable $action): never
{
    sparksHeaders();
    // Do not allow PHP notices/paths into JSON, regardless of a development ini.
    ini_set('display_errors', '0');
    try {
        $context = sparksSession();
        $db = LittleSparks\connect((string) getenv('LITTLE_SPARKS_DB'));
        $action($db, $context);
    } catch (LittleSparks\Problem $error) {
        if ($error->status === 429) {
            header('Retry-After: ' . (3600 - (time() % 3600)));
        }
        sparksReply(['error' => $error->getMessage()], $error->status);
    } catch (Throwable) {
        sparksReply(['error' => 'The village is resting. Please try again later.'], 503);
    }
}
