<?php
declare(strict_types=1);

namespace LittleSparks;

use PDO;
use RuntimeException;

const PAGE_SIZE = 40;
const BROWSER_LIMIT = 3;
const POPULATION_LIMIT = 10000;
const COLORS = ['mint','lavender','peach','sky','gold','sage','coral','amber','violet','teal','rose','magenta','emerald','forest','copper','terracotta','silver','slate','cream','warm-white','indigo','deep-blue','berry','burgundy'];
const ANTENNAS = ['orb', 'sprout', 'bolt'];
const PERSONALITIES = ['curious', 'playful', 'shy', 'philosophical'];
require_once __DIR__.'/conversation_messages.php';

final class Problem extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}

function databasePath(string $path, bool $install = false): string
{
    if ($path === '' || !preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $path)) {
        throw new RuntimeException('An absolute LITTLE_SPARKS_DB path is required.');
    }
    $directory = realpath(dirname($path));
    $public = realpath(__DIR__ . '/../public');
    if (!$directory || !$public) {
        throw new RuntimeException('The private database directory must already exist.');
    }
    $resolved = str_replace('\\', '/', $directory . '/' . basename($path));
    $public = strtolower(str_replace('\\', '/', $public)) . '/';
    $target = realpath($resolved);
    if (str_starts_with(strtolower($resolved), $public)
        || ($target && str_starts_with(strtolower(str_replace('\\', '/', $target)), $public))
        || is_link($resolved)) {
        throw new RuntimeException('The database must be a regular file outside public/.');
    }
    if (!$install && !is_file($resolved)) {
        throw new RuntimeException('Run the explicit installation script first.');
    }
    if (PHP_OS_FAMILY !== 'Windows') {
        if ((fileperms($directory) & 0077) !== 0 || (is_file($resolved) && (fileperms($resolved) & 0077) !== 0)) {
            throw new RuntimeException('Private directory/file permissions must be 0700/0600.');
        }
    }
    return $resolved;
}

function connect(string $path): PDO
{
    umask(0077);
    $db = new PDO('sqlite:' . databasePath($path), null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READWRITE,
    ]);
    $db->exec('PRAGMA busy_timeout = 5000');
    $db->exec('PRAGMA foreign_keys = ON');
    if (!in_array((int) $db->query('PRAGMA user_version')->fetchColumn(), [2,3,4,5,6], true)) {
        throw new RuntimeException('Unsupported database version.');
    }
    identityEnabled($db); // Refuse incomplete add-ons instead of reverting to legacy behavior.
    return $db;
}

function secret(PDO $db): string
{
    $value = $db->query("SELECT value FROM settings WHERE name = 'secret'")->fetchColumn();
    if (!is_string($value) || strlen($value) !== 64) {
        throw new RuntimeException('Missing installation secret.');
    }
    return $value;
}

function publicResident(array $row): array
{
    return array_intersect_key($row, array_flip(['id', 'name', 'color', 'antenna', 'personality', 'created_at']));
}

function residents(PDO $db, int $page): array
{
    $total = (int) $db->query('SELECT COUNT(*) FROM residents')->fetchColumn();
    $lastPage = max(0, (int) ceil($total / PAGE_SIZE) - 1);
    $page = max(0, min($lastPage, $page));
    $identity=identityEnabled($db);
    $query = $db->prepare($identity
        ? 'SELECT r.id,r.name,i.color,r.antenna,r.personality,r.created_at,i.description,i.revision AS identity_version FROM residents r JOIN resident_identities i ON i.resident_id=r.id ORDER BY r.created_at,r.id LIMIT :limit OFFSET :offset'
        : 'SELECT id, name, color, antenna, personality, created_at FROM residents ORDER BY created_at, id LIMIT :limit OFFSET :offset');
    $query->bindValue(':limit', PAGE_SIZE, PDO::PARAM_INT);
    $query->bindValue(':offset', $page * PAGE_SIZE, PDO::PARAM_INT);
    $query->execute();
    return ['residents' => withAppearances($db,$query->fetchAll()), 'total' => $total, 'page' => $page, 'lastPage' => $lastPage];
}

// Call inside BEGIN IMMEDIATE so concurrent requests cannot bypass the limits.
function rateLimit(PDO $db, string $key, int $limit, int $now): void
{
    $window = intdiv($now, 3600);
    $db->prepare('DELETE FROM rate_limits WHERE window < ?')->execute([$window - 1]);
    $db->prepare('INSERT INTO rate_limits (key, window, attempts) VALUES (?, ?, 1) ON CONFLICT(key, window) DO UPDATE SET attempts = attempts + 1')->execute([$key, $window]);
    $query = $db->prepare('SELECT attempts FROM rate_limits WHERE key = ? AND window = ?');
    $query->execute([$key, $window]);
    if ((int) $query->fetchColumn() > $limit) {
        throw new Problem(429, 'Too many attempts. Please try again after the next hour begins.');
    }
}

function validate(array $input,bool $identity=false,bool $appearances=false): array
{
    foreach (['name', 'color', 'antenna', $identity?'description':'personality', 'requestId', 'website'] as $key) {
        if (!isset($input[$key]) || !is_string($input[$key])) {
            throw new Problem(422, 'Please complete each robot field.');
        }
    }
    $name = trim($input['name']);
    // Names are public. No markup, controls, invisible formatting, links, or emoji.
    if (!preg_match("/\A[\p{L}\p{N}][\p{L}\p{M}\p{N} '\x{2019}-]{0,23}\z/u", $name)) {
        throw new Problem(422, 'Use 1–24 letters, numbers, spaces, apostrophes, or hyphens for the name.');
    }
    if (!in_array($input['color'], COLORS, true) || !in_array($input['antenna'], ANTENNAS, true)
        || (!$identity&&!in_array($input['personality'], PERSONALITIES, true))) {
        throw new Problem(422, 'Choose one of the available appearance and personality options.');
    }
    if ($input['website'] !== '' || !preg_match('/\A[a-f0-9]{32}\z/', $input['requestId'])) {
        throw new Problem(422, 'The welcome request could not be accepted.');
    }
    $appearance=[];if(array_key_exists('appearance',$input)){if(!$appearances)throw new Problem(503,'Explicit appearance migration required.');$appearance=['appearance'=>validateAppearance($input['appearance'])];}
    return ['name' => $name, 'color' => $input['color'], 'antenna' => $input['antenna'], 'personality' => $identity?'custom':$input['personality']]
        +($identity?['description'=>validateDescription($input['description'])]:[])+$appearance;
}

function create(PDO $db, array $input, string $browser, string $network, int $now, bool $ready): array
{
    $key = secret($db);
    $owner = hash_hmac('sha256', $browser, $key);
    $rate = 'create:' . hash_hmac('sha256', $network, $key);
    $db->exec('BEGIN IMMEDIATE');
    $savepoint = false;
    try {
        $identity=identityEnabled($db);
        // Return the same identity and private token when a successful request is retried.
        if (is_string($input['requestId'] ?? null)) {
            $lookup = $db->prepare('SELECT * FROM residents WHERE owner_hash = ? AND request_id = ?');
            $lookup->execute([$owner, $input['requestId']]);
            $existing = $lookup->fetch();
            if ($existing) {
                $fields = validate($input,$identity&&$existing['personality']==='custom',appearanceInstalled($db));
                if (!hash_equals($existing['request_hash'], hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR)))) {
                    throw new Problem(409, 'That request was already used. Start a new robot to change its details.');
                }
                $token = hash_hmac('sha256', $owner . ':' . $input['requestId'], $key);
                $db->exec('COMMIT');
                return ['resident' => withIdentity($db,publicResident($existing)), 'token' => $token, 'replayed' => true];
            }
        }
        rateLimit($db, $rate, 10, $now);
        if (!$ready) {
            throw new Problem(429, 'Take a moment to choose your robot, then try again.');
        }
        $fields = validate($input,$identity,appearanceInstalled($db));
        if($identity)assertIdentityIdle($db);
        $count = $db->prepare('SELECT COUNT(*) FROM residents WHERE owner_hash = ?');
        $count->execute([$owner]);
        if ((int) $count->fetchColumn() >= BROWSER_LIMIT) {
            throw new Problem(429, 'This browser has welcomed its three residents. Enjoy visiting the village!');
        }
        if ((int) $db->query('SELECT COUNT(*) FROM residents')->fetchColumn() >= POPULATION_LIMIT) {
            throw new Problem(503, 'The village is full for now. You can still visit its residents.');
        }
        $token = hash_hmac('sha256', $owner . ':' . $input['requestId'], $key);
        $resident = ['id' => bin2hex(random_bytes(16))] + $fields + ['created_at' => gmdate('Y-m-d\TH:i:s\Z', $now)];
        $db->exec('SAVEPOINT resident_create');
        $savepoint = true;
        $insert = $db->prepare('INSERT INTO residents (id, name, color, antenna, personality, created_at, owner_hash, request_id, request_hash, token_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([$resident['id'],$resident['name'],$resident['color'],$resident['antenna'],$resident['personality'],$resident['created_at'], $owner, $input['requestId'], hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR)), hash('sha256', $token)]);
        assignHome($db, $resident['id'], $resident['created_at']);
        if($identity)identityForNewResident($db,$resident['id'],$fields['description'],$resident['color'],$resident['created_at']);
        elseif ((int)$db->query('PRAGMA user_version')->fetchColumn()>=3) ensurePreferences($db,$resident['id'],$resident['personality']);
        if(isset($fields['appearance']))$db->prepare('INSERT INTO resident_appearances VALUES (?,?,0,?)')->execute([$resident['id'],json_encode($fields['appearance'],JSON_THROW_ON_ERROR),$resident['created_at']]);
        $db->exec('RELEASE resident_create');
        $savepoint = false;
        $db->exec('COMMIT');
        return ['resident' => withIdentity($db,publicResident($resident)), 'token' => $token, 'replayed' => false];
    } catch (Problem $error) {
        // Commit attempt counters on rejected requests as well as successful ones.
        if ($savepoint) { $db->exec('ROLLBACK TO resident_create'); $db->exec('RELEASE resident_create'); }
        $db->exec('COMMIT');
        throw $error;
    } catch (\Throwable $error) {
        $db->exec('ROLLBACK');
        throw $error;
    }
}

require_once __DIR__ . '/world.php';
require_once __DIR__ . '/limits.php';
require_once __DIR__ . '/identity.php';
require_once __DIR__ . '/appearance.php';
require_once __DIR__ . '/preferences.php';
require_once __DIR__ . '/memory.php';
require_once __DIR__ . '/memory_context.php';

function revisit(PDO $db, string $token, string $network, int $now): array
{
    $db->exec('BEGIN IMMEDIATE');
    try {
        rateLimit($db, 'revisit:' . hash_hmac('sha256', $network, secret($db)), 30, $now);
        if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) {
            throw new Problem(422, 'That private link is not valid.');
        }
        $query = $db->prepare('SELECT id, name, color, antenna, personality, created_at FROM residents WHERE token_hash = ?');
        $query->execute([hash('sha256', $token)]);
        $resident = $query->fetch();
        if (!$resident) {
            throw new Problem(404, 'No resident was found for that private link.');
        }
        $db->exec('COMMIT');
        return withIdentity($db,$resident);
    } catch (Problem $error) {
        $db->exec('COMMIT');
        throw $error;
    } catch (\Throwable $error) {
        $db->exec('ROLLBACK');
        throw $error;
    }
}
