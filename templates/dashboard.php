<?php

declare(strict_types=1);

$stats = isset($analyticsSummary) && is_array($analyticsSummary)
    ? $analyticsSummary
    : (isset($statistics) && is_array($statistics) ? $statistics : []);
$materialItems = isset($materials) && is_array($materials) ? $materials : [];
$materialRows = isset($materialPerformance) && is_array($materialPerformance) ? $materialPerformance : [];
$sequenceRows = isset($yieldSequence) && is_array($yieldSequence) ? $yieldSequence : [];
$recentRows = isset($recentBatches) && is_array($recentBatches) ? $recentBatches : [];
$selected = isset($selectedMaterial) && is_scalar($selectedMaterial) ? trim((string) $selectedMaterial) : '';
if (strcasecmp($selected, 'all') === 0) {
    $selected = '';
}
$units = isset($unitSystem) && $unitSystem === 'imperial' ? 'imperial' : 'metric';
$icons = $view->asset('/assets/icons.svg');

try {
    $displayZone = new DateTimeZone(isset($timezone) && is_string($timezone) ? $timezone : 'Europe/Copenhagen');
} catch (Throwable) {
    $displayZone = new DateTimeZone('Europe/Copenhagen');
}

$read = static function (mixed $record, string ...$keys): mixed {
    foreach ($keys as $key) {
        if (is_array($record) && array_key_exists($key, $record)) {
            return $record[$key];
        }
        if (is_object($record) && isset($record->{$key})) {
            return $record->{$key};
        }
    }
    return null;
};
$number = static fn (mixed $value, float $fallback = 0.0): float => is_numeric($value) ? (float) $value : $fallback;
$nullableNumber = static fn (mixed $value): ?float => is_numeric($value) ? (float) $value : null;
$formatPercent = static fn (?float $value): string => $value === null ? '—' : number_format(max(0, $value), 1) . '%';
$formatWeight = static function (float $grams) use ($units): string {
    if ($units === 'imperial') {
        return number_format($grams * 0.03527396195, 2) . ' oz';
    }
    return number_format($grams, $grams >= 100 ? 0 : 1) . ' g';
};
$formatTemperature = static function (?float $celsius) use ($units): string {
    if ($celsius === null) {
        return '—';
    }
    return $units === 'imperial'
        ? number_format(($celsius * 9 / 5) + 32, 0) . '°F'
        : number_format($celsius, 0) . '°C';
};
$formatDate = static function (mixed $date, string $format = 'M j, Y') use ($displayZone): string {
    if (!is_scalar($date) || trim((string) $date) === '') {
        return 'Date not recorded';
    }
    try {
        return (new DateTimeImmutable((string) $date))->setTimezone($displayZone)->format($format);
    } catch (Throwable) {
        return (string) $date;
    }
};
$displayMaterial = static function (mixed $material): string {
    if (!is_scalar($material) || trim((string) $material) === '') {
        return 'Material not recorded';
    }
    return ucfirst(trim((string) $material));
};
$displayStrains = static function (mixed $strains): string {
    if (is_array($strains)) {
        $values = array_values(array_filter(array_map(
            static fn (mixed $strain): string => is_scalar($strain) ? trim((string) $strain) : '',
            $strains,
        ), static fn (string $strain): bool => $strain !== ''));
        return $values === [] ? 'Unnamed batch' : implode(' + ', $values);
    }
    if (is_scalar($strains) && trim((string) $strains) !== '') {
        return trim((string) $strains);
    }
    return 'Unnamed batch';
};
$materialUrl = static fn (string $material): string => $material === '' ? '/?material=all' : '/?material=' . rawurlencode($material);
$allMaterialsUrl = '/?material=all';

$materialOptions = [];
foreach ($materialItems as $item) {
    $value = is_scalar($item) ? trim((string) $item) : '';
    if ($value !== '') {
        $materialOptions[mb_strtolower($value)] = $value;
    }
}

$performance = [];
foreach ($materialRows as $row) {
    $material = trim((string) ($read($row, 'material', 'startMaterial', 'start_material') ?? ''));
    if ($material === '') {
        continue;
    }
    $batchCount = max(0, (int) $number($read($row, 'batchCount', 'batch_count')));
    $performance[] = [
        'material' => $material,
        'batchCount' => $batchCount,
        'medianYield' => max(0, $number($read($row, 'medianYield', 'median_yield'))),
        'averageYield' => max(0, $number($read($row, 'averageYield', 'average_yield'))),
        'overallYield' => max(0, $number($read($row, 'overallYield', 'overall_yield'))),
        'bestYield' => max(0, $number($read($row, 'bestYield', 'best_yield'))),
    ];
    $materialOptions[mb_strtolower($material)] = $material;
}
usort($performance, static function (array $left, array $right): int {
    return ($right['medianYield'] <=> $left['medianYield'])
        ?: ($right['batchCount'] <=> $left['batchCount'])
        ?: strcasecmp($left['material'], $right['material']);
});
natcasesort($materialOptions);
$materialOptions = array_values($materialOptions);
$markerIds = ['burst', 'dot', 'diamond', 'square', 'triangle', 'cross'];
$markerPalette = ['#75beff', '#73c991', '#dcdcaa', '#c586c0', '#ce9178', '#4ec9b0', '#d16d9e', '#b5cea8'];
$configuredMaterialStyles = [];
$materialStyleSource = isset($materialChartStyles) && is_array($materialChartStyles) ? $materialChartStyles : [];
foreach ($materialStyleSource as $material => $style) {
    if (!is_scalar($material) || !is_array($style)) {
        continue;
    }
    $key = mb_strtolower(trim((string) $material));
    if ($key === '') {
        continue;
    }
    $marker = is_scalar($style['marker'] ?? null) ? strtolower(trim((string) $style['marker'])) : 'burst';
    $color = is_scalar($style['color'] ?? null) ? strtolower(trim((string) $style['color'])) : '';
    $configuredMaterialStyles[$key] = [
        'marker' => in_array($marker, $markerIds, true) ? $marker : 'burst',
        'color' => preg_match('/^#[0-9a-f]{6}$/', $color) === 1 ? $color : null,
    ];
}
$materialStyle = static function (string $material) use ($configuredMaterialStyles, $markerPalette): array {
    $key = mb_strtolower(trim($material));
    $fallbackKey = $key === '' ? '__not_recorded__' : $key;
    $knownBuckets = ['flower' => 0, 'hash' => 1, 'kief' => 2, 'trim' => 3, 'not recorded' => 4, '__not_recorded__' => 4];
    $bucket = $knownBuckets[$fallbackKey] ?? (ord(hash('sha256', $fallbackKey, true)[0]) % count($markerPalette));
    $configured = $configuredMaterialStyles[$key] ?? null;
    return [
        'marker' => is_array($configured) ? $configured['marker'] : 'burst',
        'color' => is_array($configured) && is_string($configured['color']) ? $configured['color'] : $markerPalette[$bucket],
    ];
};

$normalizeBatch = static function (mixed $row) use ($read, $number, $displayStrains): array {
    $startAmountG = max(0, $number($read($row, 'startAmountG', 'start_amount_g', 'startAmount')));
    $yieldAmountG = max(0, $number($read($row, 'yieldAmountG', 'yield_amount_g', 'yieldAmount')));
    $yieldValue = $read($row, 'yieldPercentage', 'yield_percentage');
    $yieldPercentage = is_numeric($yieldValue)
        ? max(0, (float) $yieldValue)
        : ($startAmountG > 0 ? ($yieldAmountG / $startAmountG) * 100 : 0.0);
    $temperature = $read($row, 'temperatureC', 'temperature_c', 'firstPassTemperatureC', 'first_pass_temperature_c');
    $material = $read($row, 'material', 'startMaterial', 'start_material');
    $passCount = $read($row, 'passCount', 'pass_count', 'numberOfPresses', 'number_of_presses');

    return [
        'id' => max(0, (int) $number($read($row, 'id'))),
        'pressedAt' => $read($row, 'pressedAt', 'pressed_at', 'pressDate'),
        'material' => is_scalar($material) ? trim((string) $material) : '',
        'strains' => $displayStrains($read($row, 'strains', 'strainNames', 'strain_names', 'strain')),
        'temperatureC' => is_numeric($temperature) ? (float) $temperature : null,
        'yieldPercentage' => $yieldPercentage,
        'passCount' => is_numeric($passCount) ? max(1, (int) $passCount) : 1,
        'startAmountG' => $startAmountG,
        'yieldAmountG' => $yieldAmountG,
    ];
};

$recent = array_values(array_map($normalizeBatch, array_filter($recentRows, static fn (mixed $row): bool => is_array($row) || is_object($row))));
$sequence = array_values(array_map($normalizeBatch, array_filter($sequenceRows, static fn (mixed $row): bool => is_array($row) || is_object($row))));
if ($sequence === []) {
    $sequence = $recent;
}
usort($sequence, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);

$totalBatches = max(0, (int) $number($stats['totalBatches'] ?? $stats['total_batches'] ?? 0));
$medianYield = $nullableNumber($stats['medianYield'] ?? $stats['median_yield'] ?? null);
$averageYield = $nullableNumber($stats['averageYield'] ?? $stats['average_yield'] ?? null);
$overallYield = $nullableNumber($stats['overallYield'] ?? $stats['overall_yield'] ?? null);
if ($totalBatches === 0 && $recent !== []) {
    $totalBatches = count($recent);
}

$highestSource = null;
if (isset($highestYieldBatch) && (is_array($highestYieldBatch) || is_object($highestYieldBatch))) {
    $highestSource = $highestYieldBatch;
} elseif (isset($highestBatch) && (is_array($highestBatch) || is_object($highestBatch))) {
    $highestSource = $highestBatch;
}
$highestBatchDisplay = $highestSource === null ? null : $normalizeBatch($highestSource);
if ($highestBatchDisplay === null || $highestBatchDisplay['id'] === 0) {
    $highestBatchDisplay = null;
    foreach (array_merge($sequence, $recent) as $candidate) {
        if ($candidate['id'] > 0 && ($highestBatchDisplay === null || $candidate['yieldPercentage'] > $highestBatchDisplay['yieldPercentage'])) {
            $highestBatchDisplay = $candidate;
        }
    }
}

$chartMaximum = 25.0;
foreach ($sequence as $batch) {
    $chartMaximum = max($chartMaximum, $batch['yieldPercentage']);
}
$chartMaximum = min(100.0, max(25.0, ceil($chartMaximum / 5) * 5));
$chartHalf = $chartMaximum / 2;
$chartPoints = [];
$sequenceCount = count($sequence);
$chartWidth = max(720.0, 116.0 + (max(0, $sequenceCount - 1) * 64.0));
$chartLeft = 58.0;
$chartRight = $chartWidth - 24.0;
$chartLabelEvery = max(1, (int) ceil(max(1, $sequenceCount - 1) / 8));
foreach ($sequence as $index => $batch) {
    $x = $sequenceCount <= 1 ? $chartWidth / 2 : $chartLeft + (($index / ($sequenceCount - 1)) * ($chartRight - $chartLeft));
    $y = 160.0 - ((min($chartMaximum, $batch['yieldPercentage']) / $chartMaximum) * 132.0);
    $chartPoints[] = [
        'x' => round($x, 1),
        'y' => round($y, 1),
        'batch' => $batch,
        'showLabel' => $index === 0 || $index === $sequenceCount - 1 || $index % $chartLabelEvery === 0,
    ];
}
$pointString = implode(' ', array_map(
    static fn (array $point): string => $point['x'] . ',' . $point['y'],
    $chartPoints,
));
$sequenceMaterials = [];
foreach ($sequence as $batch) {
    $materialKey = $batch['material'] === '' ? '__not_recorded__' : strtolower($batch['material']);
    $sequenceMaterials[$materialKey] = $batch['material'];
}
$sequenceMaterials = array_values($sequenceMaterials);
?>
<div class="page-stack dashboard-page dashboard-page--yield-first">
  <header class="page-header dashboard-header">
    <div><h1>Dashboard</h1></div>
    <div class="dashboard-header__actions">
      <form class="dashboard-material-filter" action="/" method="get">
        <label for="dashboard-material">Material</label>
        <select id="dashboard-material" name="material">
          <option value="all" <?= $selected === '' ? 'selected' : '' ?>>All Materials</option>
          <?php foreach ($materialOptions as $material): ?>
            <option value="<?= $view->escape($material) ?>" <?= strcasecmp($material, $selected) === 0 ? 'selected' : '' ?>><?= $view->escape($displayMaterial($material)) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="button button--ghost button--small" type="submit">Apply</button>
        <?php if ($selected !== ''): ?><a class="text-link dashboard-material-filter__clear" href="<?= $view->escape($allMaterialsUrl) ?>">Clear</a><?php endif; ?>
      </form>
      <a class="button button--primary" href="/batches/new">
        <svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#plus"></use></svg>
        New Batch
      </a>
    </div>
  </header>

  <section class="stat-grid dashboard-stat-grid" aria-label="Yield summary">
    <article class="stat-card dashboard-stat-card">
      <span class="stat-icon stat-icon--green"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#percent"></use></svg></span>
      <div>
        <span class="stat-label">Typical Yield</span>
        <strong class="stat-value"><?= $view->escape($totalBatches > 0 ? $formatPercent($medianYield ?? $averageYield) : '—') ?></strong>
        <span class="stat-context">Median · <?= $view->escape(number_format($totalBatches)) ?> batch<?= $totalBatches === 1 ? '' : 'es' ?></span>
      </div>
    </article>
    <article class="stat-card dashboard-stat-card">
      <span class="stat-icon stat-icon--blue"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#gauge"></use></svg></span>
      <div>
        <span class="stat-label">Overall Yield</span>
        <strong class="stat-value"><?= $view->escape($totalBatches > 0 ? $formatPercent($overallYield ?? $averageYield) : '—') ?></strong>
        <span class="stat-context">Combined output ÷ input</span>
      </div>
    </article>
    <article class="stat-card dashboard-stat-card">
      <span class="stat-icon stat-icon--amber"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#trophy"></use></svg></span>
      <div>
        <span class="stat-label">Highest Yield</span>
        <?php if ($highestBatchDisplay === null): ?>
          <strong class="stat-value">—</strong>
          <span class="stat-context">No saved batches</span>
        <?php else: ?>
          <a class="stat-card__batch-link" href="/batch/<?= $view->escape($highestBatchDisplay['id']) ?>" aria-label="Open batch <?= $view->escape($highestBatchDisplay['id']) ?>, highest yield <?= $view->escape($formatPercent($highestBatchDisplay['yieldPercentage'])) ?>">
            <strong class="stat-value"><?= $view->escape($formatPercent($highestBatchDisplay['yieldPercentage'])) ?></strong>
            <span class="stat-context">#<?= $view->escape($highestBatchDisplay['id']) ?> · <?= $view->escape($displayMaterial($highestBatchDisplay['material'])) ?> · <?= $view->escape($highestBatchDisplay['strains']) ?></span>
          </a>
        <?php endif; ?>
      </div>
    </article>
    <article class="stat-card dashboard-stat-card">
      <span class="stat-icon stat-icon--violet"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#batches"></use></svg></span>
      <div>
        <span class="stat-label">Batches</span>
        <strong class="stat-value"><?= $view->escape(number_format($totalBatches)) ?></strong>
      </div>
    </article>
  </section>

  <section class="panel material-performance-panel" aria-labelledby="material-performance-title">
    <header class="panel-header"><div><h2 id="material-performance-title">Yield by Material</h2></div></header>
    <div class="panel-content">
      <?php if ($performance === []): ?>
        <div class="compact-empty">
          <span class="empty-icon"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#analytics"></use></svg></span>
          <div><strong>No material results yet</strong><p>Material comparisons appear after a batch has input and output values.</p></div>
        </div>
      <?php else: ?>
        <div class="material-ranking" role="list">
          <?php foreach ($performance as $index => $row): ?>
            <?php $style = $materialStyle($row['material']); ?>
            <article class="material-ranking__row" role="listitem">
              <span class="material-ranking__rank" aria-label="Rank <?= $view->escape($index + 1) ?>"><?= $view->escape($index + 1) ?></span>
              <a class="material-ranking__material" href="<?= $view->escape($materialUrl($row['material'])) ?>">
                <svg class="material-marker material-marker--inline" viewBox="0 0 24 24" color="<?= $view->escape($style['color']) ?>" aria-hidden="true" focusable="false"><use href="<?= $view->escape($icons) ?>#marker-<?= $view->escape($style['marker']) ?>"></use></svg>
                <strong><?= $view->escape($displayMaterial($row['material'])) ?></strong>
              </a>
              <div class="material-ranking__bar">
                <progress max="100" value="<?= $view->escape(min(100, $row['medianYield'])) ?>" aria-label="<?= $view->escape($displayMaterial($row['material']) . ' median yield ' . $formatPercent($row['medianYield'])) ?>"><?= $view->escape($formatPercent($row['medianYield'])) ?></progress>
              </div>
              <div class="material-ranking__primary"><strong><?= $view->escape($formatPercent($row['medianYield'])) ?></strong><span>median</span></div>
              <div class="material-ranking__secondary">
                <span><strong><?= $view->escape($formatPercent($row['averageYield'])) ?></strong> average</span>
                <span><strong><?= $view->escape($row['batchCount']) ?></strong> batch<?= $row['batchCount'] === 1 ? '' : 'es' ?></span>
                <?php if ($row['batchCount'] < 2): ?><span class="data-confidence data-confidence--limited">Limited data</span><?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <section class="panel sequence-panel" aria-labelledby="sequence-title">
    <header class="panel-header panel-header--action">
      <div><h2 id="sequence-title">Yield by Batch Sequence</h2></div>
      <?php if ($sequence !== []): ?><span class="panel-badge"><?= $view->escape(number_format(count($sequence))) ?> batch<?= count($sequence) === 1 ? '' : 'es' ?> in recorded order</span><?php endif; ?>
    </header>
    <div class="panel-content">
      <?php if ($sequence === []): ?>
        <div class="compact-empty">
          <span class="empty-icon"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#analytics"></use></svg></span>
          <div><strong>No yield sequence yet</strong><p>Your saved batches will appear in batch-number order.</p></div>
        </div>
      <?php else: ?>
        <?php if ($sequenceMaterials !== []): ?>
          <nav class="sequence-legend" aria-label="Materials in this chart">
            <?php foreach ($sequenceMaterials as $material): ?>
              <?php $style = $materialStyle($material); ?>
              <?php if ($material === ''): ?>
                <span class="sequence-legend__item"><svg class="material-marker material-marker--legend" viewBox="0 0 24 24" color="<?= $view->escape($style['color']) ?>" aria-hidden="true" focusable="false"><use href="<?= $view->escape($icons) ?>#marker-<?= $view->escape($style['marker']) ?>"></use></svg><?= $view->escape($displayMaterial($material)) ?></span>
              <?php else: ?>
                <a href="<?= $view->escape($materialUrl($material)) ?>" class="sequence-legend__item<?= $selected !== '' && strcasecmp($material, $selected) === 0 ? ' is-selected' : '' ?>">
                  <svg class="material-marker material-marker--legend" viewBox="0 0 24 24" color="<?= $view->escape($style['color']) ?>" aria-hidden="true" focusable="false"><use href="<?= $view->escape($icons) ?>#marker-<?= $view->escape($style['marker']) ?>"></use></svg>
                  <?= $view->escape($displayMaterial($material)) ?>
                </a>
              <?php endif; ?>
            <?php endforeach; ?>
          </nav>
        <?php endif; ?>
        <div class="sequence-chart">
          <svg width="<?= $view->escape((int) ceil($chartWidth)) ?>" height="208" viewBox="0 0 <?= $view->escape($chartWidth) ?> 208" role="group" aria-labelledby="sequence-chart-title sequence-chart-description">
            <title id="sequence-chart-title">Yield for all matching batches in batch-number order</title>
            <desc id="sequence-chart-description">Each linked point identifies its batch, material, combined yield, and first-pass temperature. Activate a point to open the full batch record.</desc>
            <line class="sequence-chart__grid" x1="<?= $view->escape($chartLeft) ?>" y1="28" x2="<?= $view->escape($chartRight) ?>" y2="28"></line>
            <line class="sequence-chart__grid" x1="<?= $view->escape($chartLeft) ?>" y1="94" x2="<?= $view->escape($chartRight) ?>" y2="94"></line>
            <line class="sequence-chart__grid" x1="<?= $view->escape($chartLeft) ?>" y1="160" x2="<?= $view->escape($chartRight) ?>" y2="160"></line>
            <text class="sequence-chart__axis" x="50" y="32" text-anchor="end"><?= $view->escape(number_format($chartMaximum, 0)) ?>%</text>
            <text class="sequence-chart__axis" x="50" y="98" text-anchor="end"><?= $view->escape(number_format($chartHalf, $chartHalf === floor($chartHalf) ? 0 : 1)) ?>%</text>
            <text class="sequence-chart__axis" x="50" y="164" text-anchor="end">0%</text>
            <?php if (count($chartPoints) > 1): ?><polyline class="sequence-chart__line" points="<?= $view->escape($pointString) ?>"></polyline><?php endif; ?>
            <?php foreach ($chartPoints as $point): ?>
              <?php $batch = $point['batch']; $style = $materialStyle($batch['material']); ?>
              <a href="/batch/<?= $view->escape($batch['id']) ?>" aria-label="<?= $view->escape('Open Batch ' . $batch['id'] . ': ' . $displayMaterial($batch['material']) . ', ' . $formatPercent($batch['yieldPercentage']) . ' yield') ?>">
                <circle class="chart-point-hit" cx="<?= $view->escape($point['x']) ?>" cy="<?= $view->escape($point['y']) ?>" r="12" aria-hidden="true"></circle>
                <svg class="material-marker material-marker--chart" x="<?= $view->escape($point['x'] - 9) ?>" y="<?= $view->escape($point['y'] - 9) ?>" width="18" height="18" viewBox="0 0 24 24" color="<?= $view->escape($style['color']) ?>" aria-hidden="true" focusable="false">
                  <title>#<?= $view->escape($batch['id']) ?> · <?= $view->escape($displayMaterial($batch['material'])) ?> · <?= $view->escape($formatPercent($batch['yieldPercentage'])) ?> · Pass 1 <?= $view->escape($formatTemperature($batch['temperatureC'])) ?> · Click to open batch</title>
                  <use href="<?= $view->escape($icons) ?>#marker-<?= $view->escape($style['marker']) ?>"></use>
                </svg>
              </a>
              <?php if ($point['showLabel']): ?><text class="sequence-chart__batch" x="<?= $view->escape($point['x']) ?>" y="187" text-anchor="middle">#<?= $view->escape($batch['id']) ?></text><?php endif; ?>
            <?php endforeach; ?>
          </svg>
        </div>
        <ol class="visually-hidden">
          <?php foreach ($sequence as $batch): ?>
            <li>Batch <?= $view->escape($batch['id']) ?>: <?= $view->escape($displayMaterial($batch['material'])) ?>, <?= $view->escape($formatPercent($batch['yieldPercentage'])) ?> yield, first-pass temperature <?= $view->escape($formatTemperature($batch['temperatureC'])) ?>.</li>
          <?php endforeach; ?>
        </ol>
      <?php endif; ?>
    </div>
  </section>

  <section class="panel recent-panel recent-panel--compact" aria-labelledby="recent-batches-title">
    <header class="panel-header panel-header--action">
      <div><h2 id="recent-batches-title">Recent Batches</h2></div>
      <a class="button button--ghost button--small" href="/batches">View all<svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#arrow-right"></use></svg></a>
    </header>
    <div class="panel-content">
      <?php if ($recent === []): ?>
        <div class="dashboard-empty dashboard-empty--compact">
          <span class="empty-illustration"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#press"></use></svg></span>
          <h3>No batches yet</h3>
          <a class="button button--primary" href="/batches/new"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#plus"></use></svg>Create first batch</a>
        </div>
      <?php else: ?>
        <div class="compact-batch-list">
          <?php foreach (array_slice($recent, 0, 8) as $batch): ?>
            <?php $style = $materialStyle($batch['material']); ?>
            <a class="compact-batch" href="/batch/<?= $view->escape($batch['id']) ?>">
              <div class="compact-batch__identity">
                <span class="batch-id">#<?= $view->escape($batch['id']) ?></span>
                <div><strong><?= $view->escape($batch['strains']) ?></strong><span><?= $view->escape($formatDate($batch['pressedAt'])) ?></span></div>
              </div>
              <span class="material-chip"><svg class="material-marker material-marker--inline" viewBox="0 0 24 24" color="<?= $view->escape($style['color']) ?>" aria-hidden="true" focusable="false"><use href="<?= $view->escape($icons) ?>#marker-<?= $view->escape($style['marker']) ?>"></use></svg><?= $view->escape($displayMaterial($batch['material'])) ?></span>
              <dl class="compact-batch__metrics">
                <div><dt>Pass 1</dt><dd><?= $view->escape($formatTemperature($batch['temperatureC'])) ?></dd></div>
                <div><dt>Input</dt><dd><?= $view->escape($formatWeight($batch['startAmountG'])) ?></dd></div>
                <div><dt>Output</dt><dd><?= $view->escape($formatWeight($batch['yieldAmountG'])) ?></dd></div>
                <div><dt>Passes</dt><dd><?= $view->escape($batch['passCount']) ?></dd></div>
              </dl>
              <span class="compact-batch__yield"><strong><?= $view->escape($formatPercent($batch['yieldPercentage'])) ?></strong><small>yield</small></span>
              <svg class="compact-batch__arrow" aria-hidden="true"><use href="<?= $view->escape($icons) ?>#arrow-right"></use></svg>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </section>
</div>
