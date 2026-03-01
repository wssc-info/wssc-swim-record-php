<?php
/**
 * West Side Record Board — save.php
 * Receives POST from edit.php, updates records.json, redirects back to index.php.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$dataFile = __DIR__ . '/data/records.json';

// ── Read & decode existing data ─────────────────────────────────
$raw = file_get_contents($dataFile);
if ($raw === false) {
    header('Location: index.php?saved=err');
    exit;
}
$data = json_decode($raw, true);

// ── Sanitise inputs ─────────────────────────────────────────────
$panel    = trim($_POST['panel']    ?? '');
$section  = trim($_POST['section']  ?? '');
$ageGroup = trim($_POST['ageGroup'] ?? '');
$gender   = trim($_POST['gender']   ?? '');
$idx      = (int)($_POST['idx']     ?? -1);

$name    = trim($_POST['name']     ?? '');
$recYear = (int)($_POST['rec_year'] ?? 0);
$time    = trim($_POST['time']      ?? '');
$event   = trim($_POST['event']     ?? '');

// Basic validation
$validPanels  = ['team', 'pool', 'individual'];
$validGenders = ['girls', 'boys'];

if (!in_array($panel, $validPanels, true)
    || !in_array($gender, $validGenders, true)
    || $idx < 0
    || $name === ''
    || $time === ''
    || $recYear < 1960 || $recYear > 2099
) {
    header('Location: index.php?saved=err');
    exit;
}

// Validate time format: optional "m:" prefix then digits.digits
if (!preg_match('/^(\d+:)?\d+\.\d+$/', $time)) {
    header('Location: index.php?saved=err');
    exit;
}

// ── Update the correct record ────────────────────────────────────
$updated = false;

if ($panel === 'team') {
    if (isset($data['teamRecords']['ageGroups'][$ageGroup][$gender][$idx])) {
        $data['teamRecords']['ageGroups'][$ageGroup][$gender][$idx]['name'] = strtoupper($name);
        $data['teamRecords']['ageGroups'][$ageGroup][$gender][$idx]['year'] = $recYear;
        $data['teamRecords']['ageGroups'][$ageGroup][$gender][$idx]['time'] = $time;
        $updated = true;
    }
} elseif ($panel === 'pool') {
    if (isset($data['poolRecords']['ageGroups'][$ageGroup][$gender][$idx])) {
        $data['poolRecords']['ageGroups'][$ageGroup][$gender][$idx]['name'] = strtoupper($name);
        $data['poolRecords']['ageGroups'][$ageGroup][$gender][$idx]['year'] = $recYear;
        $data['poolRecords']['ageGroups'][$ageGroup][$gender][$idx]['time'] = $time;
        $updated = true;
    }
} elseif ($panel === 'individual') {
    $indiv = &$data['poolRecords_teamRecords_individual']['poolIndividual_note']['girls_200events'];
    if ($gender === 'boys') {
        if (isset($indiv['boys'][$idx])) {
            $indiv['boys'][$idx]['name'] = strtoupper($name);
            $indiv['boys'][$idx]['year'] = $recYear;
            $indiv['boys'][$idx]['time'] = $time;
            $updated = true;
        }
    } else {
        // Flatten girls into an ordered list matching what index.php builds
        $flat = [];
        foreach ($indiv as $key => $val) {
            if ($key !== 'boys' && is_array($val)) {
                foreach ($val as $i => $r) {
                    $flat[]  = ['key' => $key, 'i' => $i];
                }
            }
        }
        if (isset($flat[$idx])) {
            $ref = $flat[$idx];
            $indiv[$ref['key']][$ref['i']]['name'] = strtoupper($name);
            $indiv[$ref['key']][$ref['i']]['year'] = $recYear;
            $indiv[$ref['key']][$ref['i']]['time'] = $time;
            $updated = true;
        }
    }
}

if (!$updated) {
    header('Location: index.php?saved=err');
    exit;
}

// ── Write updated JSON back to disk ─────────────────────────────
$written = file_put_contents(
    $dataFile,
    json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

if ($written === false) {
    header('Location: index.php?saved=err');
    exit;
}

header('Location: index.php?saved=1');
exit;
