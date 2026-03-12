<?php
/**
 * West Side Record Board — api/history.php
 *
 * GET /api/history.php                    → all history entries (newest first)
 * GET /api/history.php?limit=50           → limit rows returned
 * GET /api/history.php?panel=team_swimming
 * GET /api/history.php?gender=girls
 * GET /api/history.php?record_id=42
 */

require_once __DIR__ . '/db_config.php';

$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header("Access-Control-Allow-Origin: $origin");
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Max-Age: 86400');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }
    return $pdo;
}

// ── Build query with optional filters ────────────────────────────────────────

$where  = [];
$params = [];

$allowedPanels = ['team_swimming', 'pool_swimming', 'team_diving', 'pool_diving'];

if (!empty($_GET['panel'])) {
    if (!in_array($_GET['panel'], $allowedPanels, true)) {
        http_response_code(422);
        echo json_encode(['error' => 'Invalid panel value']);
        exit;
    }
    $where[]          = 'panel = :panel';
    $params[':panel'] = $_GET['panel'];
}

if (!empty($_GET['gender'])) {
    if (!in_array($_GET['gender'], ['girls', 'boys'], true)) {
        http_response_code(422);
        echo json_encode(['error' => 'Invalid gender value']);
        exit;
    }
    $where[]           = 'gender = :gender';
    $params[':gender'] = $_GET['gender'];
}

if (isset($_GET['record_id']) && ctype_digit((string)$_GET['record_id'])) {
    $where[]              = 'record_id = :record_id';
    $params[':record_id'] = (int)$_GET['record_id'];
}

$limit = 200;
if (isset($_GET['limit']) && ctype_digit((string)$_GET['limit'])) {
    $limit = min((int)$_GET['limit'], 1000);
}

$sql = 'SELECT id, record_id, panel, age_group, gender, event,
               old_name, new_name,
               old_year, new_year,
               old_time, new_time,
               changed_at
          FROM records_history'
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
    . ' ORDER BY changed_at DESC, id DESC'
    . ' LIMIT ' . $limit;

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Cast integer columns
foreach ($rows as &$row) {
    $row['id']        = (int)$row['id'];
    $row['record_id'] = (int)$row['record_id'];
    if ($row['old_year'] !== null) $row['old_year'] = (int)$row['old_year'];
    if ($row['new_year'] !== null) $row['new_year'] = (int)$row['new_year'];
}
unset($row);

echo json_encode(
    ['history' => $rows, 'count' => count($rows)],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
