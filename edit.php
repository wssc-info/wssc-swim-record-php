<?php
/**
 * West Side Record Board — edit.php
 * Edit a single record.  Receives context via GET params, submits to save.php.
 *
 * GET params:
 *   panel    — "team" | "pool" | "individual"
 *   section  — "main" | "girls" | "boys"
 *   ageGroup — e.g. "9-10", "11-12", "15-18_200IM", "girls_200events"
 *   gender   — "girls" | "boys"
 *   idx      — integer index into the records array
 */

$dataFile = __DIR__ . '/data/records.json';
$data     = json_decode(file_get_contents($dataFile), true);

// ── Validate & resolve params ───────────────────────────────────
$panel    = $_GET['panel']    ?? '';
$section  = $_GET['section']  ?? '';
$ageGroup = $_GET['ageGroup'] ?? '';
$gender   = $_GET['gender']   ?? '';
$idx      = (int)($_GET['idx'] ?? -1);

// Resolve the record from the data structure
$record = null;

if ($panel === 'team' && isset($data['teamRecords']['ageGroups'][$ageGroup][$gender][$idx])) {
    $record = $data['teamRecords']['ageGroups'][$ageGroup][$gender][$idx];
} elseif ($panel === 'pool' && isset($data['poolRecords']['ageGroups'][$ageGroup][$gender][$idx])) {
    $record = $data['poolRecords']['ageGroups'][$ageGroup][$gender][$idx];
} elseif ($panel === 'individual') {
    $indiv = $data['poolRecords_teamRecords_individual']['poolIndividual_note']['girls_200events'] ?? [];
    if ($gender === 'boys') {
        $records = $indiv['boys'] ?? [];
    } else {
        // Flatten girls entries (all non-"boys" keys)
        $records = [];
        foreach ($indiv as $key => $val) {
            if ($key !== 'boys' && is_array($val)) {
                foreach ($val as $r) { $records[] = $r; }
            }
        }
    }
    $record = $records[$idx] ?? null;
}

if ($record === null) {
    header('Location: index.php?saved=err');
    exit;
}

// ── Human-readable context labels ──────────────────────────────
$panelLabel = match($panel) {
    'team'       => 'Team Records',
    'pool'       => 'Pool Records',
    'individual' => 'Individual Long Course',
    default      => ucfirst($panel),
};

$ageGroupLabels = [
    '9-10'            => 'Age 9–10',
    '11-12'           => 'Age 11–12',
    '13-14'           => 'Age 13–14',
    '15-18'           => 'Age 15–18',
    '15-18_200IM'     => 'Age 15–18 (200 I.M.)',
    '15-18_200events' => 'Age 15–18 (200 events)',
    'girls_200events' => '200 Free (Long Course)',
];

$ageGroupLabel = $ageGroupLabels[$ageGroup] ?? $ageGroup;
$genderLabel   = ($gender === 'girls') ? 'Girls' : 'Boys';

$pool = $data['pool'];
$year = $data['year'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Edit Record — <?= htmlspecialchars($pool) ?> <?= $year ?></title>
  <link rel="stylesheet" href="style.css">
</head>
<body>

<div class="edit-wrapper">

  <div style="margin-bottom:14px">
    <a href="index.php" class="btn btn-secondary" style="display:inline-block">← Back to Board</a>
  </div>

  <div class="edit-card">
    <div class="edit-card-header">Edit Record</div>
    <div class="edit-card-body">

      <!-- Context breadcrumb -->
      <div class="edit-context">
        <span><?= htmlspecialchars($panelLabel) ?></span>
        &nbsp;/&nbsp;
        <span><?= htmlspecialchars($ageGroupLabel) ?></span>
        &nbsp;/&nbsp;
        <span><?= htmlspecialchars($genderLabel) ?></span>
      </div>

      <form method="POST" action="save.php">
        <!-- Hidden routing fields -->
        <input type="hidden" name="panel"    value="<?= htmlspecialchars($panel) ?>">
        <input type="hidden" name="section"  value="<?= htmlspecialchars($section) ?>">
        <input type="hidden" name="ageGroup" value="<?= htmlspecialchars($ageGroup) ?>">
        <input type="hidden" name="gender"   value="<?= htmlspecialchars($gender) ?>">
        <input type="hidden" name="idx"      value="<?= $idx ?>">

        <!-- Event (read-only — editing event type changes board structure) -->
        <div class="form-group">
          <label for="event">Event</label>
          <input type="text" id="event" name="event"
                 value="<?= htmlspecialchars($record['event']) ?>"
                 readonly>
        </div>

        <!-- Holder name -->
        <div class="form-group">
          <label for="name">Record Holder(s)</label>
          <input type="text" id="name" name="name"
                 value="<?= htmlspecialchars($record['name']) ?>"
                 required
                 autocomplete="off">
        </div>

        <!-- Year -->
        <div class="form-group">
          <label for="rec_year">Year Set</label>
          <input type="number" id="rec_year" name="rec_year"
                 value="<?= htmlspecialchars((string)$record['year']) ?>"
                 min="1960" max="2099"
                 required>
        </div>

        <!-- Time -->
        <div class="form-group">
          <label for="time">Time</label>
          <input type="text" id="time" name="time"
                 value="<?= htmlspecialchars($record['time']) ?>"
                 placeholder="e.g. 1:23.45 or 29.35"
                 required
                 pattern="^(\d+:)?\d+\.\d+$"
                 title="Format: seconds (e.g. 29.35) or minutes:seconds (e.g. 1:23.45)">
        </div>

        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Save Record</button>
          <a href="index.php" class="btn btn-secondary">Cancel</a>
        </div>
      </form>

    </div>
  </div>

</div>

</body>
</html>
