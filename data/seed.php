<?php
/**
 * seed.php — populates westside_records.records from records.json
 *
 * Usage (from phpRecordManagement/):
 *   php data/seed.php
 */

require_once __DIR__ . '/../api/db_config.php';

$jsonFile = __DIR__ . '/records.json';
$data     = json_decode(file_get_contents($jsonFile), true);

$dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
$pdo = new PDO($dsn, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$sql = 'INSERT INTO records (panel, age_group, gender, event, holder_name, record_year, record_time)
        VALUES (:panel, :age_group, :gender, :event, :name, :year, :time)
        ON DUPLICATE KEY UPDATE
            holder_name = VALUES(holder_name),
            record_year = VALUES(record_year),
            record_time = VALUES(record_time)';

$stmt  = $pdo->prepare($sql);
$count = 0;

// ── Swimming panels ──────────────────────────────────────────────────────────
$swimmingPanels = [
    'team_swimming' => $data['teamRecords']['ageGroups'],
    'pool_swimming' => $data['poolRecords']['ageGroups'],
];

foreach ($swimmingPanels as $panel => $ageGroups) {
    foreach ($ageGroups as $ageKey => $genders) {
        foreach ($genders as $gender => $rows) {
            foreach ($rows as $row) {
                $stmt->execute([
                    ':panel'     => $panel,
                    ':age_group' => $ageKey,
                    ':gender'    => $gender,
                    ':event'     => $row['event'],
                    ':name'      => $row['name'],
                    ':year'      => $row['year'],
                    ':time'      => $row['time'],
                ]);
                $count++;
            }
        }
    }
}

// ── Diving panels ────────────────────────────────────────────────────────────
$divingPanels = [
    'team_diving' => $data['divingRecords']['team'],
    'pool_diving' => $data['divingRecords']['pool'],
];

foreach ($divingPanels as $panel => $genders) {
    foreach ($genders as $gender => $rows) {
        foreach ($rows as $row) {
            $stmt->execute([
                ':panel'     => $panel,
                ':age_group' => null,
                ':gender'    => $gender,
                ':event'     => $row['ageGroup'],   // diving uses ageGroup as the event label
                ':name'      => $row['name'],
                ':year'      => $row['year'],
                ':time'      => (string) ($row['score'] ?? $row['time'] ?? ''),
            ]);
            $count++;
        }
    }
}

echo "Seeded $count records into westside_records.records\n";
