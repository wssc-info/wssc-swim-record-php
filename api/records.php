<?php
/**
 * West Side Record Board — api/records.php
 *
 * GET   /api/records.php  → returns records.json as JSON
 * POST  /api/records.php  → accepts full records JSON body, writes it to disk
 * PATCH /api/records.php  → accepts a single-record delta, applies it, writes back
 *
 * PATCH body shape:
 *   { "panel":  "TEAM SWIMMING RECORDS" | "POOL SWIMMING RECORDS" | null,
 *     "title":  "TEAM DIVING RECORDS"   | "POOL DIVING RECORDS"   | null,
 *     "ageKey": "9-10" | "11-12" | …   (swimming only),
 *     "gender": "girls" | "boys",
 *     "idx":    <integer row index>,
 *     "updates": { "name": "…", "year": 2025, "time": "…" }
 *   }
 */

require_once __DIR__ . '/db_config.php';

// ── CORS headers (allow the Vite dev server + any local origin) ─────────────
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header("Access-Control-Allow-Origin: $origin");
header('Access-Control-Allow-Methods: GET, POST, PATCH, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Max-Age: 86400');
header('Content-Type: application/json; charset=UTF-8');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$dataFile = __DIR__ . '/../data/records.json';
$logFile  = __DIR__ . '/../data/changes.log';

/**
 * Append a structured line to changes.log.
 * @param string $method   'PATCH' | 'POST'
 * @param array  $context  Human-readable key/value pairs for the entry
 */
function log_change(string $method, array $context): void {
    global $logFile;
    $ts   = date('Y-m-d H:i:s');
    $ip   = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $line = "[$ts] [$ip] $method";
    foreach ($context as $k => $v) {
        $line .= " | $k: $v";
    }
    file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

// ── Database helpers ─────────────────────────────────────────────────────────

/** Lazy singleton PDO connection to westside_records. */
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

/** Maps UI panel/title strings to DB enum values. */
function panel_to_enum(?string $panel, ?string $title): ?string {
    return match(true) {
        $panel === 'TEAM SWIMMING RECORDS' => 'team_swimming',
        $panel === 'POOL SWIMMING RECORDS' => 'pool_swimming',
        $title === 'TEAM DIVING RECORDS'   => 'team_diving',
        $title === 'POOL DIVING RECORDS'   => 'pool_diving',
        default                            => null,
    };
}

/**
 * UPDATE a single record row in the DB.
 * The AFTER UPDATE trigger will write to records_history automatically.
 */
function db_sync_record(string $dbPanel, ?string $ageGroup, string $gender, string $event, array $record): void {
    $sql = 'UPDATE records
               SET holder_name = :name,
                   record_year = :year,
                   record_time = :time
             WHERE panel      = :panel
               AND (age_group = :age_group OR (age_group IS NULL AND :age_group2 IS NULL))
               AND gender     = :gender
               AND event      = :event';

    db()->prepare($sql)->execute([
        ':panel'      => $dbPanel,
        ':age_group'  => $ageGroup,
        ':age_group2' => $ageGroup,
        ':gender'     => $gender,
        ':event'      => $event,
        ':name'       => $record['name'],
        ':year'       => $record['year'],
        ':time'       => $record['time'],
    ]);
}

/**
 * Full sync: upsert every record from the decoded JSON into the DB.
 * Used after a POST (full replacement).
 */
function db_sync_all(array $data): void {
    $sql = 'INSERT INTO records (panel, age_group, gender, event, holder_name, record_year, record_time)
            VALUES (:panel, :age_group, :gender, :event, :name, :year, :time)
            ON DUPLICATE KEY UPDATE
                holder_name = VALUES(holder_name),
                record_year = VALUES(record_year),
                record_time = VALUES(record_time)';
    $stmt = db()->prepare($sql);

    // Swimming panels
    $swimming = [
        'team_swimming' => $data['teamRecords']['ageGroups'] ?? [],
        'pool_swimming' => $data['poolRecords']['ageGroups'] ?? [],
    ];
    foreach ($swimming as $dbPanel => $ageGroups) {
        foreach ($ageGroups as $ageKey => $genders) {
            foreach ($genders as $gender => $rows) {
                foreach ($rows as $row) {
                    $stmt->execute([
                        ':panel'     => $dbPanel,
                        ':age_group' => $ageKey,
                        ':gender'    => $gender,
                        ':event'     => $row['event'],
                        ':name'      => $row['name'],
                        ':year'      => $row['year'],
                        ':time'      => $row['time'],
                    ]);
                }
            }
        }
    }

    // Diving panels
    $diving = [
        'team_diving' => $data['divingRecords']['team'] ?? [],
        'pool_diving' => $data['divingRecords']['pool'] ?? [],
    ];
    foreach ($diving as $dbPanel => $genders) {
        foreach ($genders as $gender => $rows) {
            foreach ($rows as $row) {
                $stmt->execute([
                    ':panel'     => $dbPanel,
                    ':age_group' => null,
                    ':gender'    => $gender,
                    ':event'     => $row['ageGroup'],
                    ':name'      => $row['name'],
                    ':year'      => $row['year'],
                    ':time'      => (string)($row['score'] ?? $row['time'] ?? ''),
                ]);
            }
        }
    }
}

/**
 * Query the DB and rebuild the nested structure the React app expects.
 * pool/year metadata is read from records.json (not stored in the DB).
 */
function db_build_response(string $dataFile): array {
    $meta = file_exists($dataFile)
        ? (json_decode(file_get_contents($dataFile), true) ?? [])
        : [];

    $out = [
        'pool'          => $meta['pool'] ?? 'West Side',
        'year'          => $meta['year'] ?? (int)date('Y'),
        'teamRecords'   => ['ageGroups' => []],
        'poolRecords'   => ['ageGroups' => []],
        'divingRecords' => ['team' => [], 'pool' => []],
    ];

    $rows = db()
        ->query('SELECT panel, age_group, gender, event, holder_name, record_year, record_time
                   FROM records
                  ORDER BY id ASC')
        ->fetchAll();

    foreach ($rows as $row) {
        $gender = $row['gender'];
        $event  = $row['event'];
        $base   = [
            'name' => $row['holder_name'],
            'year' => (int)$row['record_year'],
            'time' => $row['record_time'],
        ];

        switch ($row['panel']) {
            case 'team_swimming':
                $out['teamRecords']['ageGroups'][$row['age_group']][$gender][]
                    = ['event' => $event] + $base;
                break;

            case 'pool_swimming':
                $out['poolRecords']['ageGroups'][$row['age_group']][$gender][]
                    = ['event' => $event] + $base;
                break;

            case 'team_diving':
                $out['divingRecords']['team'][$gender][]
                    = ['ageGroup' => $event] + $base;
                break;

            case 'pool_diving':
                $out['divingRecords']['pool'][$gender][]
                    = ['ageGroup' => $event] + $base;
                break;
        }
    }

    return $out;
}

// ── GET ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(
        db_build_response($dataFile),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

// ── POST ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = file_get_contents('php://input');

    if (empty($body)) {
        http_response_code(400);
        echo json_encode(['error' => 'Empty request body']);
        exit;
    }

    $data = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON: ' . json_last_error_msg()]);
        exit;
    }

    // Basic sanity check — must have the expected top-level keys
    $required = ['pool', 'year', 'teamRecords', 'poolRecords', 'divingRecords'];
    foreach ($required as $key) {
        if (!array_key_exists($key, $data)) {
            http_response_code(422);
            echo json_encode(['error' => "Missing required key: $key"]);
            exit;
        }
    }

    // Write atomically via a temp file
    $tmp = $dataFile . '.tmp';
    $written = file_put_contents(
        $tmp,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    if ($written === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to write data file']);
        exit;
    }

    rename($tmp, $dataFile);

    db_sync_all($data);
    log_change('POST', ['action' => 'full records replacement', 'bytes' => $written]);

    http_response_code(200);
    echo json_encode(['ok' => true, 'bytes' => $written]);
    exit;
}

// ── PATCH ────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
    $body = file_get_contents('php://input');

    if (empty($body)) {
        http_response_code(400);
        echo json_encode(['error' => 'Empty request body']);
        exit;
    }

    $patch = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON: ' . json_last_error_msg()]);
        exit;
    }

    // Validate required PATCH fields
    foreach (['gender', 'updates', 'event'] as $key) {
        if (!array_key_exists($key, $patch)) {
            http_response_code(422);
            echo json_encode(['error' => "Missing required patch field: $key"]);
            exit;
        }
    }

    $panel  = $patch['panel']  ?? null;
    $title  = $patch['title']  ?? null;
    $ageKey = $patch['ageKey'] ?? null;   // null for diving panels
    $gender = $patch['gender'];
    $event  = $patch['event'];            // natural key: event name (swimming) or ageGroup label (diving)

    // Whitelist the fields that may be changed
    $allowed = ['name', 'year', 'time'];
    $updates = array_intersect_key($patch['updates'], array_flip($allowed));

    $dbPanel = panel_to_enum($panel, $title);
    if (!$dbPanel) {
        http_response_code(422);
        echo json_encode(['error' => 'Cannot determine record panel from patch payload']);
        exit;
    }

    // Update the record in the DB using its natural key (panel + age_group + gender + event).
    // The AFTER UPDATE trigger writes history automatically.
    // Using COALESCE so any field omitted from $updates retains its current DB value.
    $sql = 'UPDATE records
               SET holder_name = COALESCE(:name, holder_name),
                   record_year = COALESCE(:year, record_year),
                   record_time = COALESCE(:time, record_time)
             WHERE panel      = :panel
               AND (age_group = :age_group OR (age_group IS NULL AND :age_group2 IS NULL))
               AND gender     = :gender
               AND event      = :event';

    $stmt = db()->prepare($sql);
    $stmt->execute([
        ':panel'      => $dbPanel,
        ':age_group'  => $ageKey,
        ':age_group2' => $ageKey,
        ':gender'     => $gender,
        ':event'      => $event,
        ':name'       => $updates['name'] ?? null,
        ':year'       => $updates['year'] ?? null,
        ':time'       => $updates['time'] ?? null,
    ]);

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['error' => "Record not found: $dbPanel / $ageKey / $gender / $event"]);
        exit;
    }

    // Rebuild JSON from DB so it stays in sync with any events added directly to the DB.
    $newData = db_build_response($dataFile);
    $tmp     = $dataFile . '.tmp';
    $written = file_put_contents(
        $tmp,
        json_encode($newData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    if ($written === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to write data file']);
        exit;
    }

    rename($tmp, $dataFile);

    $section = ($panel ?? $title) . ' / ' . ($ageKey ?? 'diving') . ' / ' . $gender . ' / ' . $event;
    $changes = implode(', ', array_map(fn($k, $v) => "$k → $v", array_keys($updates), $updates));
    log_change('PATCH', ['section' => $section, 'changes' => $changes ?: 'no change']);

    http_response_code(200);
    echo json_encode(['ok' => true]);
    exit;
}

// ── Method not allowed ────────────────────────────────────────────────────────
http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
