<?php
/**
 * West Side Record Board — index.php
 * Displays the record board from data/records.json, matching the SVG board layout.
 */

$dataFile = __DIR__ . '/data/records.json';
$data     = json_decode(file_get_contents($dataFile), true);

$teamRecords = $data['teamRecords']['ageGroups'];
$poolRecords = $data['poolRecords']['ageGroups'];
$year        = $data['year'];
$pool        = $data['pool'];

// Flash message from save.php redirect
$flash = $_GET['saved'] ?? null;

// ── Helper: render a gender block (Girls or Boys) ──────────────
function renderGenderSection(string $gender, array $records, string $section, string $ageGroup, string $panel): void {
    $label = ($gender === 'girls') ? 'Girls' : 'Boys';
    $dot   = ($gender === 'girls') ? 'style="background:#64cadd"' : 'style="background:#0095da"';
    ?>
    <div class="gender-section">
      <div class="gender-header">
        <span class="dot" <?= $dot ?>></span>
        <?= htmlspecialchars($label) ?>
      </div>
      <div class="col-headers">
        <span>Event</span>
        <span>Name</span>
        <span style="text-align:center">Year</span>
        <span style="text-align:right">Time</span>
        <span></span>
      </div>
      <?php foreach ($records as $idx => $rec): ?>
        <?php
          $editHref = sprintf(
              'edit.php?panel=%s&section=%s&ageGroup=%s&gender=%s&idx=%d',
              urlencode($panel),
              urlencode($section),
              urlencode($ageGroup),
              urlencode($gender),
              $idx
          );
          // Highlight records set in the current year or marked as new
          $isNew = ($rec['year'] >= 2025);
        ?>
        <div class="record-row<?= $isNew ? ' new-record' : '' ?>">
          <span class="rec-event"><?= htmlspecialchars($rec['event']) ?></span>
          <span class="rec-name"><?= htmlspecialchars($rec['name']) ?></span>
          <span class="rec-year"><?= htmlspecialchars((string)$rec['year']) ?></span>
          <span class="rec-time"><?= htmlspecialchars($rec['time']) ?></span>
          <span class="rec-edit">
            <a href="<?= $editHref ?>" class="btn-edit" title="Edit record">✎</a>
          </span>
        </div>
      <?php endforeach; ?>
    </div>
    <?php
}

// ── Helper: render a full panel (Team or Pool records) ─────────
function renderPanel(string $title, array $ageGroups, string $panel): void {
    // Human-readable age group labels
    $ageGroupLabels = [
        '8Under'         => 'Age 8 & Under',
        '9-10'         => 'Age 9–10',
        '11-12'        => 'Age 11–12',
        '13-14'        => 'Age 13–14',
        '15-18'        => 'Age 15–18',
    ];
    ?>
    <div class="panel">
      <div class="panel-header"><?= htmlspecialchars($title) ?></div>

      <?php foreach ($ageGroups as $ageGroup => $genders): ?>
        <div class="age-group">
          <div class="age-group-header">
            <?= htmlspecialchars($ageGroupLabels[$ageGroup] ?? $ageGroup) ?>
          </div>
          <?php
            foreach (['girls', 'boys'] as $gender) {
                if (!empty($genders[$gender])) {
                    renderGenderSection($gender, $genders[$gender], 'main', $ageGroup, $panel);
                }
            }
          ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php
}

// ── Individual / Long Course section ───────────────────────────
function renderIndividualSection(array $data): void {
    $indivData = $data['poolRecords_teamRecords_individual']['poolIndividual_note'] ?? [];
    if (empty($indivData)) return;

    $girlsData = $indivData['girls_200events'] ?? [];
    $boysData  = $indivData['girls_200events']['boys'] ?? [];

    // Re-key: girls_200events contains both girls (10_under through 15-18) and boys sub-key
    $girlsRows = [];
    $boysRows  = [];
    foreach ($girlsData as $key => $value) {
        if ($key === 'boys') {
            $boysRows = $value;
        } else {
            // Each entry is an array of records
            if (is_array($value)) {
                foreach ($value as $rec) {
                    $girlsRows[] = $rec;
                }
            }
        }
    }
    ?>
    <div class="individual-section">
      <div class="panel-header">Individual Long Course Records</div>
      <div class="individual-grid">

        <div class="gender-section">
          <div class="gender-header">
            <span class="dot" style="background:#64cadd"></span>Girls — 200 Free (seconds)
          </div>
          <div class="col-headers">
            <span>Age Group</span>
            <span>Name</span>
            <span style="text-align:center">Year</span>
            <span style="text-align:right">Time</span>
            <span></span>
          </div>
          <?php foreach ($girlsRows as $idx => $rec): ?>
            <?php $isNew = ($rec['year'] >= 2025); ?>
            <div class="record-row<?= $isNew ? ' new-record' : '' ?>">
              <span class="rec-event"><?= htmlspecialchars($rec['event']) ?></span>
              <span class="rec-name"><?= htmlspecialchars($rec['name']) ?></span>
              <span class="rec-year"><?= htmlspecialchars((string)$rec['year']) ?></span>
              <span class="rec-time"><?= htmlspecialchars($rec['time']) ?></span>
              <span class="rec-edit">
                <a href="edit.php?panel=individual&section=girls&ageGroup=girls_200events&gender=girls&idx=<?= $idx ?>" class="btn-edit" title="Edit">✎</a>
              </span>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="gender-section">
          <div class="gender-header">
            <span class="dot" style="background:#0095da"></span>Boys — 200 Free (seconds)
          </div>
          <div class="col-headers">
            <span>Age Group</span>
            <span>Name</span>
            <span style="text-align:center">Year</span>
            <span style="text-align:right">Time</span>
            <span></span>
          </div>
          <?php foreach ($boysRows as $idx => $rec): ?>
            <?php $isNew = ($rec['year'] >= 2025); ?>
            <div class="record-row<?= $isNew ? ' new-record' : '' ?>">
              <span class="rec-event"><?= htmlspecialchars($rec['event']) ?></span>
              <span class="rec-name"><?= htmlspecialchars($rec['name']) ?></span>
              <span class="rec-year"><?= htmlspecialchars((string)$rec['year']) ?></span>
              <span class="rec-time"><?= htmlspecialchars($rec['time']) ?></span>
              <span class="rec-edit">
                <a href="edit.php?panel=individual&section=boys&ageGroup=girls_200events&gender=boys&idx=<?= $idx ?>" class="btn-edit" title="Edit">✎</a>
              </span>
            </div>
          <?php endforeach; ?>
        </div>

      </div>
    </div>
    <?php
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pool) ?> Record Board <?= $year ?></title>
  <link rel="stylesheet" href="style.css">
</head>
<body>

<div class="board-wrapper">

  <!-- Title -->
  <div class="board-title">
    <h1><?= htmlspecialchars($pool) ?> Swimming</h1>
    <div class="subtitle">Record Board &nbsp;·&nbsp; <?= $year ?></div>
  </div>

  <!-- Flash message -->
  <?php if ($flash === '1'): ?>
    <div class="flash flash-success">Record updated successfully.</div>
  <?php elseif ($flash === 'err'): ?>
    <div class="flash flash-error">Error saving record. Please try again.</div>
  <?php endif; ?>

  <!-- Two-panel layout -->
  <div class="panels">
    <?php renderPanel('Team Records', $teamRecords, 'team'); ?>
    <?php renderPanel('Pool Records', $poolRecords, 'pool'); ?>
  </div>

  <!-- Individual / Long Course -->
  <?php renderIndividualSection($data); ?>

</div>

</body>
</html>
