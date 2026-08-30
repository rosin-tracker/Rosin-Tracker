<?php

declare(strict_types=1);

$data = isset($analytics) && is_array($analytics) ? $analytics : [];
$summary = isset($statistics) && is_array($statistics)
    ? $statistics
    : (is_array($data['summary'] ?? null) ? $data['summary'] : []);
$units = isset($unitSystem) && $unitSystem === 'imperial' ? 'imperial' : 'metric';
$icons = $view->asset('/assets/icons.svg');

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
$rows = static fn (mixed $value): array => is_array($value)
    ? array_values(array_filter($value, static fn (mixed $row): bool => is_array($row) || is_object($row)))
    : [];
$options = static function (mixed $value): array {
    if (!is_array($value)) {
        return [];
    }
    $result = [];
    foreach ($value as $option) {
        if (!is_scalar($option) || trim((string) $option) === '') {
            continue;
        }
        $result[] = (string) $option;
    }
    return array_values(array_unique($result));
};
$number = static fn (mixed $value, float $fallback = 0.0): float => is_numeric($value) ? (float) $value : $fallback;
$nullableNumber = static fn (mixed $value): ?float => is_numeric($value) ? (float) $value : null;
$formatPercent = static fn (float $value): string => number_format($value, 1) . '%';
$formatWeight = static function (float $grams) use ($units): string {
    if ($units === 'imperial') {
        return number_format($grams * 0.03527396195, 2) . ' oz';
    }
    return number_format($grams, $grams >= 100 ? 0 : 1) . ' g';
};
$formatTemperature = static function (?float $celsius, int $precision = 0) use ($units): string {
    if ($celsius === null) {
        return 'Not recorded';
    }
    return $units === 'imperial'
        ? number_format(($celsius * 9 / 5) + 32, $precision) . '°F'
        : number_format($celsius, $precision) . '°C';
};
$countLabel = static fn (int $count): string => number_format($count) . ' batch' . ($count === 1 ? '' : 'es');
$confidence = static function (int $count): array {
    if ($count <= 1) {
        return ['Single result', 'sample-confidence--single'];
    }
    if ($count <= 4) {
        return ['Limited sample', 'sample-confidence--limited'];
    }
    return ['Larger sample', 'sample-confidence--repeated'];
};

$materialSource = $data['materials'] ?? (isset($materials) ? $materials : []);
$strainSource = $data['strains'] ?? (isset($strains) ? $strains : []);
$materials = $options($materialSource);
$strains = $options($strainSource);
$selectedMaterial = trim((string) ($data['selectedMaterial'] ?? (isset($selectedMaterial) ? $selectedMaterial : '')));
$selectedStrain = trim((string) ($data['selectedStrain'] ?? (isset($selectedStrain) ? $selectedStrain : '')));
if (strcasecmp($selectedMaterial, 'all') === 0) {
    $selectedMaterial = '';
}

$materialPerformance = $rows($data['materialPerformance'] ?? (isset($materialPerformance) ? $materialPerformance : []));
$strainPerformance = $rows($data['strainPerformance'] ?? (isset($strainPerformance) ? $strainPerformance : []));
$blendPerformance = $rows($data['blendPerformance'] ?? (isset($blendPerformance) ? $blendPerformance : []));
$temperaturePoints = $rows($data['temperaturePoints'] ?? ($data['temperatureYield']['points'] ?? (isset($temperaturePoints) ? $temperaturePoints : [])));
$temperatureBands = $rows($data['temperatureBands'] ?? ($data['temperatureYield']['bands'] ?? (isset($temperatureBands) ? $temperatureBands : [])));
$yieldSequence = $rows($data['yieldSequence'] ?? (isset($yieldSequence) ? $yieldSequence : []));
$batchComparison = $rows($data['batchComparison'] ?? (isset($batchComparison) ? $batchComparison : []));

$totalBatches = max(0, (int) $number($summary['totalBatches'] ?? 0));
$averageYield = max(0, $number($summary['averageYield'] ?? 0));
$medianYield = max(0, $number($summary['medianYield'] ?? 0));
$overallYield = max(0, $number($summary['overallYield'] ?? 0));
$hasSummary = $totalBatches > 0;

$filterUrl = static function (?string $material, ?string $strain): string {
    $material = $material === null ? '' : trim($material);
    $query = ['material' => $material === '' || strcasecmp($material, 'all') === 0 ? 'all' : $material];
    if ($strain !== null && trim($strain) !== '') {
        $query['strain'] = $strain;
    }
    return '/analytics' . ($query === [] ? '' : '?' . http_build_query($query));
};
$isSelected = static fn (string $value, string $selected): bool => $selected !== '' && strcasecmp($value, $selected) === 0;

$markerIds = ['burst', 'dot', 'diamond', 'square', 'triangle', 'cross'];
$markerPalette = ['#75beff', '#73c991', '#dcdcaa', '#c586c0', '#ce9178', '#4ec9b0', '#d16d9e', '#b5cea8'];
$configuredMaterialStyles = [];
$materialStyleSource = isset($materialChartStyles) && is_array($materialChartStyles)
    ? $materialChartStyles
    : (is_array($data['materialChartStyles'] ?? null) ? $data['materialChartStyles'] : []);
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

$scatter = [];
foreach ($temperaturePoints as $point) {
    $temperature = $nullableNumber($read($point, 'temperatureC', 'temperature_c'));
    $yield = $nullableNumber($read($point, 'yieldPercentage', 'yield_percentage'));
    $id = max(0, (int) $number($read($point, 'id')));
    if ($temperature === null || $yield === null || $id === 0) {
        continue;
    }
    $scatter[] = [
        'id' => $id,
        'material' => trim((string) $read($point, 'material', 'startMaterial', 'start_material')),
        'strains' => trim((string) $read($point, 'strains', 'strainNames', 'strain_names')),
        'temperatureC' => $temperature,
        'yieldPercentage' => $yield,
        'passCount' => max(1, (int) $number($read($point, 'passCount', 'numberOfPresses', 'number_of_presses'), 1)),
    ];
}

$scatterLeft = 56.0;
$scatterRight = 704.0;
$scatterTop = 18.0;
$scatterBottom = 254.0;
$scatterWidth = $scatterRight - $scatterLeft;
$scatterHeight = $scatterBottom - $scatterTop;
$temperatures = array_column($scatter, 'temperatureC');
$minimumTemperature = $temperatures === [] ? 0.0 : floor(min($temperatures) / 5) * 5;
$maximumTemperature = $temperatures === [] ? 5.0 : ceil(max($temperatures) / 5) * 5;
if ($minimumTemperature === $maximumTemperature) {
    $minimumTemperature -= 5;
    $maximumTemperature += 5;
}
$temperatureSpan = max(1.0, $maximumTemperature - $minimumTemperature);

$sequence = [];
foreach ($yieldSequence as $point) {
    $yield = $nullableNumber($read($point, 'yieldPercentage', 'yield_percentage'));
    $id = max(0, (int) $number($read($point, 'id')));
    if ($yield === null || $id === 0) {
        continue;
    }
    $sequence[] = [
        'id' => $id,
        'material' => trim((string) $read($point, 'material', 'startMaterial', 'start_material')),
        'strains' => trim((string) $read($point, 'strains', 'strainNames', 'strain_names')),
        'yieldPercentage' => $yield,
    ];
}
usort($sequence, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);
$sequenceLeft = 56.0;
$sequenceCount = count($sequence);
$sequenceChartWidth = max(740.0, 112.0 + (max(0, $sequenceCount - 1) * 58.0));
$sequenceRight = $sequenceChartWidth - 36.0;
$sequenceTop = 18.0;
$sequenceBottom = 218.0;
$sequenceHeight = $sequenceBottom - $sequenceTop;
$sequenceCoordinates = [];
foreach ($sequence as $index => $point) {
    $x = $sequenceCount <= 1
        ? ($sequenceLeft + $sequenceRight) / 2
        : $sequenceLeft + (($index / ($sequenceCount - 1)) * ($sequenceRight - $sequenceLeft));
    $y = $sequenceBottom - ((max(0, min(100, $point['yieldPercentage'])) / 100) * $sequenceHeight);
    $sequenceCoordinates[] = ['x' => round($x, 2), 'y' => round($y, 2)] + $point;
}
$sequenceLine = implode(' ', array_map(
    static fn (array $point): string => $point['x'] . ',' . $point['y'],
    $sequenceCoordinates,
));

$scatterLegendMaterials = [];
foreach ($scatter as $point) {
    $material = trim((string) ($point['material'] ?? ''));
    $scatterLegendMaterials[$material === '' ? '__not_recorded__' : strtolower($material)] = $material;
}
$sequenceLegendMaterials = [];
foreach ($sequence as $point) {
    $material = trim((string) ($point['material'] ?? ''));
    $sequenceLegendMaterials[$material === '' ? '__not_recorded__' : strtolower($material)] = $material;
}
?>
<div class="page-stack analytics-page">
  <header class="page-header analytics-header">
    <div><h1>Analytics</h1></div>
    <form class="analytics-filter" action="/analytics" method="get">
      <div class="analytics-filter__field">
        <label for="analytics-material">Material</label>
        <select id="analytics-material" name="material">
          <option value="all" <?= $selectedMaterial === '' ? 'selected' : '' ?>>All materials</option>
          <?php foreach ($materials as $material): ?>
            <option value="<?= $view->escape($material) ?>" <?= $isSelected($material, $selectedMaterial) ? 'selected' : '' ?>><?= $view->escape(ucfirst($material)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="analytics-filter__field">
        <label for="analytics-strain">Strain</label>
        <select id="analytics-strain" name="strain">
          <option value="">All strains and blends</option>
          <?php foreach ($strains as $strain): ?>
            <option value="<?= $view->escape($strain) ?>" <?= $isSelected($strain, $selectedStrain) ? 'selected' : '' ?>><?= $view->escape($strain) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="analytics-filter__actions">
        <button class="button button--primary button--small" type="submit">Apply</button>
        <?php if ($selectedMaterial !== '' || $selectedStrain !== ''): ?>
          <a class="button button--ghost button--small" href="/analytics?material=all">Clear</a>
        <?php endif; ?>
      </div>
    </form>
  </header>

  <section class="stat-grid" aria-label="Yield summary">
    <article class="stat-card">
      <span class="stat-icon stat-icon--green"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#percent"></use></svg></span>
      <div><span class="stat-label">Median Batch Yield</span><strong class="stat-value"><?= $hasSummary ? $view->escape($formatPercent($medianYield)) : '—' ?></strong></div>
    </article>
    <article class="stat-card">
      <span class="stat-icon stat-icon--blue"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#analytics"></use></svg></span>
      <div><span class="stat-label">Average Batch Yield</span><strong class="stat-value"><?= $hasSummary ? $view->escape($formatPercent($averageYield)) : '—' ?></strong></div>
    </article>
    <article class="stat-card">
      <span class="stat-icon stat-icon--amber"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#gauge"></use></svg></span>
      <div><span class="stat-label">Combined Input Yield</span><strong class="stat-value"><?= $hasSummary ? $view->escape($formatPercent($overallYield)) : '—' ?></strong></div>
    </article>
    <article class="stat-card">
      <span class="stat-icon stat-icon--violet"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#batches"></use></svg></span>
      <div><span class="stat-label">Batches</span><strong class="stat-value"><?= $view->escape(number_format($totalBatches)) ?></strong></div>
    </article>
  </section>

  <section class="panel analytics-panel analytics-panel--wide">
    <header class="panel-header"><div><h2>Yield by Material</h2></div></header>
    <div class="panel-content">
      <?php if ($materialPerformance === []): ?>
        <div class="compact-empty">
          <span class="empty-icon"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#analytics"></use></svg></span>
          <div><strong>No material results yet</strong></div>
        </div>
      <?php else: ?>
        <div class="material-comparison">
          <?php foreach ($materialPerformance as $performance): ?>
            <?php
            $material = trim((string) $read($performance, 'material'));
            $count = max(0, (int) $number($read($performance, 'batchCount', 'batch_count')));
            $median = max(0, $number($read($performance, 'medianYield', 'median_yield')));
            $average = max(0, $number($read($performance, 'averageYield', 'average_yield')));
            $overall = max(0, $number($read($performance, 'overallYield', 'overall_yield')));
            [$confidenceLabel, $confidenceClass] = $confidence($count);
            $style = $materialStyle($material);
            ?>
            <article class="material-result">
              <div class="material-result__heading">
                <div>
                  <a class="material-result__material" href="<?= $view->escape($filterUrl($material, $selectedStrain)) ?>"><svg class="material-marker material-marker--inline" viewBox="0 0 24 24" color="<?= $view->escape($style['color']) ?>" aria-hidden="true" focusable="false"><use href="<?= $view->escape($icons) ?>#marker-<?= $view->escape($style['marker']) ?>"></use></svg><?= $view->escape($material !== '' ? ucfirst($material) : 'Unspecified') ?></a>
                  <span class="sample-confidence <?= $view->escape($confidenceClass) ?>"><?= $view->escape($confidenceLabel) ?> · <?= $view->escape($countLabel($count)) ?></span>
                </div>
                <strong><span>Median</span><?= $view->escape($formatPercent($median)) ?></strong>
              </div>
              <progress class="metric-progress" max="100" value="<?= $view->escape(max(0, min(100, $median))) ?>"><?= $view->escape($formatPercent($median)) ?></progress>
              <dl class="result-metrics">
                <div><dt>Average</dt><dd><?= $view->escape($formatPercent($average)) ?></dd></div>
                <div><dt>Overall</dt><dd><?= $view->escape($formatPercent($overall)) ?></dd></div>
                <div><dt>Sample</dt><dd><?= $view->escape(number_format($count)) ?></dd></div>
              </dl>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <div class="analytics-grid analytics-grid--performance">
    <section class="panel analytics-panel">
      <header class="panel-header"><div><h2>Yield by Strain</h2></div></header>
      <div class="panel-content performance-groups">
        <section class="performance-group" aria-labelledby="single-strains-title">
          <header><h3 id="single-strains-title">Single strains</h3><span>One-strain batches only</span></header>
          <?php if ($strainPerformance === []): ?>
            <p class="inline-empty">No single-strain results.</p>
          <?php else: ?>
            <div class="performance-list">
              <?php foreach ($strainPerformance as $performance): ?>
                <?php
                $name = trim((string) $read($performance, 'strain'));
                $count = max(0, (int) $number($read($performance, 'batchCount', 'batch_count')));
                $median = max(0, $number($read($performance, 'medianYield', 'median_yield')));
                $average = max(0, $number($read($performance, 'averageYield', 'average_yield')));
                $overall = max(0, $number($read($performance, 'overallYield', 'overall_yield')));
                [$confidenceLabel, $confidenceClass] = $confidence($count);
                ?>
                <article class="performance-result<?= $isSelected($name, $selectedStrain) ? ' is-selected' : '' ?>">
                  <div class="performance-result__identity">
                    <a href="<?= $view->escape($filterUrl($selectedMaterial, $name)) ?>"><?= $view->escape($name !== '' ? $name : 'Unnamed strain') ?></a>
                    <span class="sample-confidence <?= $view->escape($confidenceClass) ?>"><?= $view->escape($confidenceLabel) ?> · <?= $view->escape($countLabel($count)) ?></span>
                  </div>
                  <dl class="performance-result__metrics">
                    <div><dt>Median</dt><dd><?= $view->escape($formatPercent($median)) ?></dd></div>
                    <div><dt>Average</dt><dd><?= $view->escape($formatPercent($average)) ?></dd></div>
                    <div><dt>Overall</dt><dd><?= $view->escape($formatPercent($overall)) ?></dd></div>
                  </dl>
                </article>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>

        <section class="performance-group performance-group--blends" aria-labelledby="exact-blends-title">
          <header><h3 id="exact-blends-title">Exact blends</h3><span>Multi-strain batches stay grouped</span></header>
          <?php if ($blendPerformance === []): ?>
            <p class="inline-empty">No blend results.</p>
          <?php else: ?>
            <div class="performance-list">
              <?php foreach ($blendPerformance as $performance): ?>
                <?php
                $name = trim((string) $read($performance, 'blend'));
                $count = max(0, (int) $number($read($performance, 'batchCount', 'batch_count')));
                $median = max(0, $number($read($performance, 'medianYield', 'median_yield')));
                $average = max(0, $number($read($performance, 'averageYield', 'average_yield')));
                $overall = max(0, $number($read($performance, 'overallYield', 'overall_yield')));
                [$confidenceLabel, $confidenceClass] = $confidence($count);
                ?>
                <article class="performance-result">
                  <div class="performance-result__identity">
                    <strong><?= $view->escape($name !== '' ? $name : 'Unnamed blend') ?></strong>
                    <span class="sample-confidence <?= $view->escape($confidenceClass) ?>"><?= $view->escape($confidenceLabel) ?> · <?= $view->escape($countLabel($count)) ?></span>
                  </div>
                  <dl class="performance-result__metrics">
                    <div><dt>Median</dt><dd><?= $view->escape($formatPercent($median)) ?></dd></div>
                    <div><dt>Average</dt><dd><?= $view->escape($formatPercent($average)) ?></dd></div>
                    <div><dt>Overall</dt><dd><?= $view->escape($formatPercent($overall)) ?></dd></div>
                  </dl>
                </article>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>
      </div>
    </section>

    <section class="panel analytics-panel temperature-panel">
      <header class="panel-header"><div><h2>Pass 1 Temperature vs Combined Yield</h2></div></header>
      <div class="panel-content">
        <?php if ($scatter === []): ?>
          <div class="compact-empty">
            <span class="empty-icon"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#thermometer"></use></svg></span>
            <div><strong>No Pass 1 temperature results.</strong></div>
          </div>
        <?php else: ?>
          <div class="analytics-chart analytics-scatter">
            <svg class="analytics-chart__plot" viewBox="0 0 740 292" role="group" aria-labelledby="temperature-chart-title temperature-chart-description">
              <title id="temperature-chart-title">Pass 1 temperature against combined batch yield</title>
              <desc id="temperature-chart-description">Each linked point represents one batch. Temperature is on the horizontal axis and combined yield percentage is on the vertical axis.</desc>
              <?php foreach ([0, 25, 50, 75, 100] as $yieldTick): ?>
                <?php $y = $scatterBottom - (($yieldTick / 100) * $scatterHeight); ?>
                <line class="chart-grid-line" x1="<?= $view->escape($scatterLeft) ?>" y1="<?= $view->escape(round($y, 2)) ?>" x2="<?= $view->escape($scatterRight) ?>" y2="<?= $view->escape(round($y, 2)) ?>"></line>
                <text class="analytics-chart__axis-label" x="46" y="<?= $view->escape(round($y + 4, 2)) ?>" text-anchor="end"><?= $view->escape($yieldTick) ?>%</text>
              <?php endforeach; ?>
              <?php foreach ([0.0, 0.5, 1.0] as $position): ?>
                <?php
                $temperature = $minimumTemperature + ($temperatureSpan * $position);
                $x = $scatterLeft + ($scatterWidth * $position);
                ?>
                <line class="analytics-chart__vertical-grid" x1="<?= $view->escape(round($x, 2)) ?>" y1="<?= $view->escape($scatterTop) ?>" x2="<?= $view->escape(round($x, 2)) ?>" y2="<?= $view->escape($scatterBottom) ?>"></line>
                <text class="analytics-chart__axis-label" x="<?= $view->escape(round($x, 2)) ?>" y="278" text-anchor="middle"><?= $view->escape($formatTemperature($temperature)) ?></text>
              <?php endforeach; ?>
              <?php foreach ($scatter as $point): ?>
                <?php
                $x = $scatterLeft + ((($point['temperatureC'] - $minimumTemperature) / $temperatureSpan) * $scatterWidth);
                $y = $scatterBottom - ((max(0, min(100, $point['yieldPercentage'])) / 100) * $scatterHeight);
                $materialLabel = $point['material'] !== '' ? ucfirst($point['material']) : 'Material not recorded';
                $strainLabel = $point['strains'] !== '' ? $point['strains'] : 'Strain not recorded';
                $pointLabel = 'Open Batch #' . $point['id'] . ': ' . $materialLabel . ', ' . $strainLabel . ', Pass 1 ' . $formatTemperature($point['temperatureC']) . ', ' . $point['passCount'] . ' pass' . ($point['passCount'] === 1 ? '' : 'es') . ', combined yield ' . $formatPercent($point['yieldPercentage']);
                $style = $materialStyle($point['material']);
                ?>
                <a href="/batch/<?= $view->escape($point['id']) ?>" aria-label="<?= $view->escape($pointLabel) ?>">
                  <circle class="chart-point-hit" cx="<?= $view->escape(round($x, 2)) ?>" cy="<?= $view->escape(round($y, 2)) ?>" r="12" aria-hidden="true"></circle>
                  <svg class="material-marker material-marker--chart" x="<?= $view->escape(round($x - 9, 2)) ?>" y="<?= $view->escape(round($y - 9, 2)) ?>" width="18" height="18" viewBox="0 0 24 24" color="<?= $view->escape($style['color']) ?>" aria-hidden="true" focusable="false">
                    <title><?= $view->escape($pointLabel) ?> · Click to open full batch</title>
                    <use href="<?= $view->escape($icons) ?>#marker-<?= $view->escape($style['marker']) ?>"></use>
                  </svg>
                </a>
              <?php endforeach; ?>
            </svg>
            <?php if ($scatterLegendMaterials !== []): ?>
              <div class="analytics-legend" aria-label="Material colours">
                <?php foreach ($scatterLegendMaterials as $material): ?>
                  <?php $style = $materialStyle($material); ?>
                  <?php if ($material === ''): ?>
                    <span class="analytics-legend__item"><svg class="material-marker material-marker--legend" viewBox="0 0 24 24" color="<?= $view->escape($style['color']) ?>" aria-hidden="true" focusable="false"><use href="<?= $view->escape($icons) ?>#marker-<?= $view->escape($style['marker']) ?>"></use></svg>Material not recorded</span>
                  <?php else: ?>
                    <a href="<?= $view->escape($filterUrl($material, $selectedStrain)) ?>"><svg class="material-marker material-marker--legend" viewBox="0 0 24 24" color="<?= $view->escape($style['color']) ?>" aria-hidden="true" focusable="false"><use href="<?= $view->escape($icons) ?>#marker-<?= $view->escape($style['marker']) ?>"></use></svg><?= $view->escape(ucfirst($material)) ?></a>
                  <?php endif; ?>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if ($temperatureBands !== []): ?>
          <section class="temperature-bands" aria-labelledby="temperature-bands-title">
            <header><h3 id="temperature-bands-title"><?= $units === 'imperial' ? '9°F band summary' : '5°C band summary' ?></h3></header>
            <div class="temperature-band-list">
              <?php foreach ($temperatureBands as $band): ?>
                <?php
                $temperature = $nullableNumber($read($band, 'temperatureC', 'temperature_c'));
                $count = max(0, (int) $number($read($band, 'batchCount', 'batch_count')));
                $median = max(0, $number($read($band, 'medianYield', 'median_yield')));
                $average = max(0, $number($read($band, 'averageYield', 'average_yield')));
                ?>
                <div class="temperature-band">
                  <strong>Around <?= $view->escape($formatTemperature($temperature)) ?></strong>
                  <dl><div><dt>Median</dt><dd><?= $view->escape($formatPercent($median)) ?></dd></div><div><dt>Average</dt><dd><?= $view->escape($formatPercent($average)) ?></dd></div><div><dt>Sample</dt><dd><?= $view->escape($countLabel($count)) ?></dd></div></dl>
                </div>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endif; ?>
      </div>
    </section>
  </div>

  <section class="panel analytics-panel analytics-panel--wide sequence-panel">
    <header class="panel-header panel-header--action">
      <div><h2>Yield by Batch Sequence</h2></div>
      <?php if ($sequence !== []): ?><span class="panel-badge"><?= $view->escape($countLabel(count($sequence))) ?> in recorded order</span><?php endif; ?>
    </header>
    <div class="panel-content">
      <?php if ($sequenceCoordinates === []): ?>
        <div class="compact-empty">
          <span class="empty-icon"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#analytics"></use></svg></span>
          <div><strong>No yield sequence yet.</strong></div>
        </div>
      <?php else: ?>
        <div class="analytics-chart analytics-sequence">
          <svg class="analytics-chart__plot" width="<?= $view->escape((int) ceil($sequenceChartWidth)) ?>" height="252" viewBox="0 0 <?= $view->escape($sequenceChartWidth) ?> 252" role="group" aria-labelledby="sequence-chart-title sequence-chart-description">
            <title id="sequence-chart-title">Combined yield by batch sequence</title>
            <desc id="sequence-chart-description">All matching batches are arranged in recorded order, with combined yield percentage on the vertical axis. Activate any point to open its full batch record.</desc>
            <?php foreach ([0, 25, 50, 75, 100] as $yieldTick): ?>
              <?php $y = $sequenceBottom - (($yieldTick / 100) * $sequenceHeight); ?>
              <line class="chart-grid-line" x1="<?= $view->escape($sequenceLeft) ?>" y1="<?= $view->escape(round($y, 2)) ?>" x2="<?= $view->escape($sequenceRight) ?>" y2="<?= $view->escape(round($y, 2)) ?>"></line>
              <text class="analytics-chart__axis-label" x="46" y="<?= $view->escape(round($y + 4, 2)) ?>" text-anchor="end"><?= $view->escape($yieldTick) ?>%</text>
            <?php endforeach; ?>
            <?php if (count($sequenceCoordinates) > 1): ?><polyline class="analytics-chart__line" points="<?= $view->escape($sequenceLine) ?>"></polyline><?php endif; ?>
            <?php foreach ($sequenceCoordinates as $point): ?>
              <?php
              $materialLabel = $point['material'] !== '' ? ucfirst($point['material']) : 'Material not recorded';
              $strainLabel = $point['strains'] !== '' ? $point['strains'] : 'Strain not recorded';
              $pointLabel = 'Open Batch #' . $point['id'] . ': ' . $materialLabel . ', ' . $strainLabel . ', combined yield ' . $formatPercent($point['yieldPercentage']);
              $style = $materialStyle($point['material']);
              ?>
              <a href="/batch/<?= $view->escape($point['id']) ?>" aria-label="<?= $view->escape($pointLabel) ?>">
                <circle class="chart-point-hit" cx="<?= $view->escape($point['x']) ?>" cy="<?= $view->escape($point['y']) ?>" r="12" aria-hidden="true"></circle>
                <svg class="material-marker material-marker--chart" x="<?= $view->escape($point['x'] - 9) ?>" y="<?= $view->escape($point['y'] - 9) ?>" width="18" height="18" viewBox="0 0 24 24" color="<?= $view->escape($style['color']) ?>" aria-hidden="true" focusable="false"><title><?= $view->escape($pointLabel) ?> · Click to open full batch</title><use href="<?= $view->escape($icons) ?>#marker-<?= $view->escape($style['marker']) ?>"></use></svg>
              </a>
            <?php endforeach; ?>
            <text class="analytics-chart__axis-label" x="<?= $view->escape($sequenceLeft) ?>" y="242" text-anchor="start">Batch #<?= $view->escape($sequenceCoordinates[0]['id']) ?></text>
            <?php if (count($sequenceCoordinates) > 1): ?><text class="analytics-chart__axis-label" x="<?= $view->escape($sequenceRight) ?>" y="242" text-anchor="end">Batch #<?= $view->escape($sequenceCoordinates[array_key_last($sequenceCoordinates)]['id']) ?></text><?php endif; ?>
          </svg>
          <?php if ($sequenceLegendMaterials !== []): ?>
            <div class="analytics-legend analytics-legend--sequence" aria-label="Materials in the batch sequence">
              <?php foreach ($sequenceLegendMaterials as $material): ?>
                <?php $style = $materialStyle($material); ?>
                <?php if ($material === ''): ?>
                  <span class="analytics-legend__item"><svg class="material-marker material-marker--legend" viewBox="0 0 24 24" color="<?= $view->escape($style['color']) ?>" aria-hidden="true" focusable="false"><use href="<?= $view->escape($icons) ?>#marker-<?= $view->escape($style['marker']) ?>"></use></svg>Material not recorded</span>
                <?php else: ?>
                  <a href="<?= $view->escape($filterUrl($material, $selectedStrain)) ?>"><svg class="material-marker material-marker--legend" viewBox="0 0 24 24" color="<?= $view->escape($style['color']) ?>" aria-hidden="true" focusable="false"><use href="<?= $view->escape($icons) ?>#marker-<?= $view->escape($style['marker']) ?>"></use></svg><?= $view->escape(ucfirst($material)) ?></a>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <section class="panel analytics-panel analytics-panel--wide comparison-panel">
    <header class="panel-header panel-header--action">
      <div><h2>Batch Comparison</h2></div>
      <?php if ($batchComparison !== []): ?><span class="panel-badge" data-comparison-sort-summary>Newest first</span><?php endif; ?>
    </header>
    <div class="panel-content">
      <?php if ($batchComparison === []): ?>
        <p class="inline-empty">No matching batches.</p>
      <?php else: ?>
        <div class="data-table-wrap">
          <table class="data-table comparison-table" data-sortable-comparison data-sort-column="0" data-sort-direction="desc">
            <caption class="visually-hidden">Matching batches. Use a column heading to change the sort order.</caption>
            <thead>
              <tr>
                <th scope="col" aria-sort="descending"><button class="comparison-sort" type="button" data-sort-column="0" data-sort-type="numeric">Batch<span class="comparison-sort__arrow" aria-hidden="true"></span></button></th>
                <th scope="col" aria-sort="none"><button class="comparison-sort" type="button" data-sort-column="1" data-sort-type="string">Material<span class="comparison-sort__arrow" aria-hidden="true"></span></button></th>
                <th scope="col" aria-sort="none"><button class="comparison-sort" type="button" data-sort-column="2" data-sort-type="string">Strain / blend<span class="comparison-sort__arrow" aria-hidden="true"></span></button></th>
                <th scope="col" aria-sort="none"><button class="comparison-sort" type="button" data-sort-column="3" data-sort-type="numeric">Pass 1 temp.<span class="comparison-sort__arrow" aria-hidden="true"></span></button></th>
                <th scope="col" aria-sort="none"><button class="comparison-sort" type="button" data-sort-column="4" data-sort-type="numeric">Passes<span class="comparison-sort__arrow" aria-hidden="true"></span></button></th>
                <th scope="col" aria-sort="none"><button class="comparison-sort" type="button" data-sort-column="5" data-sort-type="numeric">Input<span class="comparison-sort__arrow" aria-hidden="true"></span></button></th>
                <th scope="col" aria-sort="none"><button class="comparison-sort" type="button" data-sort-column="6" data-sort-type="numeric">Output<span class="comparison-sort__arrow" aria-hidden="true"></span></button></th>
                <th scope="col" aria-sort="none"><button class="comparison-sort" type="button" data-sort-column="7" data-sort-type="numeric">Yield<span class="comparison-sort__arrow" aria-hidden="true"></span></button></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($batchComparison as $rowIndex => $batch): ?>
                <?php
                $idRaw = $read($batch, 'id');
                $id = max(0, (int) $number($idRaw));
                $material = trim((string) $read($batch, 'material', 'startMaterial', 'start_material'));
                $strainLabel = trim((string) $read($batch, 'strains', 'strainNames', 'strain_names'));
                $temperatureRaw = $read($batch, 'temperatureC', 'temperature_c');
                $passCountRaw = $read($batch, 'passCount', 'numberOfPresses', 'number_of_presses');
                $inputRaw = $read($batch, 'startAmountG', 'start_amount_g');
                $outputRaw = $read($batch, 'yieldAmountG', 'yield_amount_g');
                $yieldRaw = $read($batch, 'yieldPercentage', 'yield_percentage');
                $temperature = $nullableNumber($temperatureRaw);
                $passCount = max(1, (int) $number($passCountRaw, 1));
                $input = max(0, $number($inputRaw));
                $output = max(0, $number($outputRaw));
                $yield = max(0, $number($yieldRaw));
                ?>
                <tr data-sort-original="<?= $view->escape($rowIndex) ?>">
                  <th scope="row" data-sort-value="<?= $view->escape($id) ?>"><a class="table-batch-link" href="/batch/<?= $view->escape($id) ?>">#<?= $view->escape($id) ?></a></th>
                  <td data-sort-value="<?= $view->escape(strtolower($material)) ?>"<?= $material === '' ? ' data-sort-missing="true"' : '' ?>><?= $view->escape($material !== '' ? ucfirst($material) : '—') ?></td>
                  <td data-sort-value="<?= $view->escape(strtolower($strainLabel)) ?>"<?= $strainLabel === '' ? ' data-sort-missing="true"' : '' ?>><?= $view->escape($strainLabel !== '' ? $strainLabel : '—') ?></td>
                  <td data-sort-value="<?= $temperature === null ? '' : $view->escape($temperature) ?>"<?= $temperature === null ? ' data-sort-missing="true"' : '' ?>><?= $temperature === null ? '—' : $view->escape($formatTemperature($temperature)) ?></td>
                  <td data-sort-value="<?= is_numeric($passCountRaw) ? $view->escape((float) $passCountRaw) : '' ?>"<?= is_numeric($passCountRaw) ? '' : ' data-sort-missing="true"' ?>><?= $view->escape(number_format($passCount)) ?></td>
                  <td data-sort-value="<?= is_numeric($inputRaw) ? $view->escape((float) $inputRaw) : '' ?>"<?= is_numeric($inputRaw) ? '' : ' data-sort-missing="true"' ?>><?= $view->escape($formatWeight($input)) ?></td>
                  <td data-sort-value="<?= is_numeric($outputRaw) ? $view->escape((float) $outputRaw) : '' ?>"<?= is_numeric($outputRaw) ? '' : ' data-sort-missing="true"' ?>><?= $view->escape($formatWeight($output)) ?></td>
                  <td class="comparison-table__yield" data-sort-value="<?= is_numeric($yieldRaw) ? $view->escape((float) $yieldRaw) : '' ?>"<?= is_numeric($yieldRaw) ? '' : ' data-sort-missing="true"' ?>><?= $view->escape($formatPercent($yield)) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <p class="visually-hidden" data-comparison-sort-status aria-live="polite"></p>
        </div>
      <?php endif; ?>
    </div>
  </section>
</div>
