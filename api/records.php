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
    foreach (['gender', 'idx', 'updates'] as $key) {
        if (!array_key_exists($key, $patch)) {
            http_response_code(422);
            echo json_encode(['error' => "Missing required patch field: $key"]);
            exit;
        }
    }

    // Load current data from disk
    if (!file_exists($dataFile)) {
        http_response_code(404);
        echo json_encode(['error' => 'records.json not found']);
        exit;
    }

    $data = json_decode(file_get_contents($dataFile), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(500);
        echo json_encode(['error' => 'Corrupt data file: ' . json_last_error_msg()]);
        exit;
    }

    // Whitelist the fields that may be changed
    $allowed = ['name', 'year', 'time'];
    $updates = array_intersect_key($patch['updates'], array_flip($allowed));

    $panel  = $patch['panel']  ?? null;
    $title  = $patch['title']  ?? null;
    $ageKey = $patch['ageKey'] ?? null;
    $gender = $patch['gender'];
    $idx    = (int) $patch['idx'];

    // Navigate to the correct record and apply updates
    if ($panel === 'TEAM SWIMMING RECORDS' && $ageKey) {
        if (!isset($data['teamRecords']['ageGroups'][$ageKey][$gender][$idx])) {
            http_response_code(404);
            echo json_encode(['error' => 'Record not found (teamRecords)']);
            exit;
        }
        $before  = $data['teamRecords']['ageGroups'][$ageKey][$gender][$idx];
        $section = "Team Swimming / $ageKey / $gender / row $idx";
        $data['teamRecords']['ageGroups'][$ageKey][$gender][$idx] = array_merge($before, $updates);
        $updated = $data['teamRecords']['ageGroups'][$ageKey][$gender][$idx];

    } elseif ($panel === 'POOL SWIMMING RECORDS' && $ageKey) {
        if (!isset($data['poolRecords']['ageGroups'][$ageKey][$gender][$idx])) {
            http_response_code(404);
            echo json_encode(['error' => 'Record not found (poolRecords)']);
            exit;
        }
        $before  = $data['poolRecords']['ageGroups'][$ageKey][$gender][$idx];
        $section = "Pool Swimming / $ageKey / $gender / row $idx";
        $data['poolRecords']['ageGroups'][$ageKey][$gender][$idx] = array_merge($before, $updates);
        $updated = $data['poolRecords']['ageGroups'][$ageKey][$gender][$idx];

    } elseif ($title === 'TEAM DIVING RECORDS') {
        if (!isset($data['divingRecords']['team'][$gender][$idx])) {
            http_response_code(404);
            echo json_encode(['error' => 'Record not found (divingRecords.team)']);
            exit;
        }
        $before  = $data['divingRecords']['team'][$gender][$idx];
        $section = "Team Diving / $gender / row $idx";
        $data['divingRecords']['team'][$gender][$idx] = array_merge($before, $updates);
        $updated = $data['divingRecords']['team'][$gender][$idx];

    } elseif ($title === 'POOL DIVING RECORDS') {
        if (!isset($data['divingRecords']['pool'][$gender][$idx])) {
            http_response_code(404);
            echo json_encode(['error' => 'Record not found (divingRecords.pool)']);
            exit;
        }
        $before  = $data['divingRecords']['pool'][$gender][$idx];
        $section = "Pool Diving / $gender / row $idx";
        $data['divingRecords']['pool'][$gender][$idx] = array_merge($before, $updates);
        $updated = $data['divingRecords']['pool'][$gender][$idx];

    } else {
        http_response_code(422);
        echo json_encode(['error' => 'Cannot determine record location from patch payload']);
        exit;
    }

    // Write back atomically
    $tmp     = $dataFile . '.tmp';
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

    // Sync to DB — the AFTER UPDATE trigger records history automatically
    $dbPanel   = panel_to_enum($panel, $title);
    $dbEvent   = $updated['event'] ?? $updated['ageGroup'] ?? '';  // swimming uses 'event', diving uses 'ageGroup'
    if ($dbPanel && $dbEvent) {
        db_sync_record($dbPanel, $ageKey, $gender, $dbEvent, $updated);
    }

    // Build a diff of changed fields: "name: Old Name → New Name"
    $diff = [];
    foreach ($updates as $field => $newVal) {
        $oldVal = $before[$field] ?? '(none)';
        if ((string)$oldVal !== (string)$newVal) {
            $diff[] = "$field: $oldVal → $newVal";
        }
    }
    log_change('PATCH', [
        'section' => $section,
        'changes' => $diff ? implode(', ', $diff) : 'no change',
    ]);

    http_response_code(200);
    echo json_encode(['ok' => true, 'record' => $updated]);
    exit;
}

// ── Method not allowed ────────────────────────────────────────────────────────
http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
