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

// ── GET ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!file_exists($dataFile)) {
        http_response_code(404);
        echo json_encode(['error' => 'records.json not found']);
        exit;
    }
    // Stream the file unchanged — no re-encoding avoids any float precision loss
    readfile($dataFile);
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
        $data['teamRecords']['ageGroups'][$ageKey][$gender][$idx] = array_merge(
            $data['teamRecords']['ageGroups'][$ageKey][$gender][$idx],
            $updates
        );
        $updated = $data['teamRecords']['ageGroups'][$ageKey][$gender][$idx];

    } elseif ($panel === 'POOL SWIMMING RECORDS' && $ageKey) {
        if (!isset($data['poolRecords']['ageGroups'][$ageKey][$gender][$idx])) {
            http_response_code(404);
            echo json_encode(['error' => 'Record not found (poolRecords)']);
            exit;
        }
        $data['poolRecords']['ageGroups'][$ageKey][$gender][$idx] = array_merge(
            $data['poolRecords']['ageGroups'][$ageKey][$gender][$idx],
            $updates
        );
        $updated = $data['poolRecords']['ageGroups'][$ageKey][$gender][$idx];

    } elseif ($title === 'TEAM DIVING RECORDS') {
        if (!isset($data['divingRecords']['team'][$gender][$idx])) {
            http_response_code(404);
            echo json_encode(['error' => 'Record not found (divingRecords.team)']);
            exit;
        }
        $data['divingRecords']['team'][$gender][$idx] = array_merge(
            $data['divingRecords']['team'][$gender][$idx],
            $updates
        );
        $updated = $data['divingRecords']['team'][$gender][$idx];

    } elseif ($title === 'POOL DIVING RECORDS') {
        if (!isset($data['divingRecords']['pool'][$gender][$idx])) {
            http_response_code(404);
            echo json_encode(['error' => 'Record not found (divingRecords.pool)']);
            exit;
        }
        $data['divingRecords']['pool'][$gender][$idx] = array_merge(
            $data['divingRecords']['pool'][$gender][$idx],
            $updates
        );
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

    http_response_code(200);
    echo json_encode(['ok' => true, 'record' => $updated]);
    exit;
}

// ── Method not allowed ────────────────────────────────────────────────────────
http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
