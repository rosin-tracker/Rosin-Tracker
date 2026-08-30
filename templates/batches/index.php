<?php

declare(strict_types=1);

$rows = isset($batches) && is_array($batches) ? $batches : [];
$result = isset($batchResults) && is_array($batchResults) ? $batchResults : [];
$filters = isset($filters) && is_array($filters) ? $filters : [];
$searchValue = (string) ($filters['search'] ?? $query ?? '');
$materialValue = (string) ($filters['material'] ?? $material ?? '');
$sortValue = (string) ($filters['sort'] ?? $sort ?? 'date');
$materialOptions = isset($materials) && is_array($materials) ? $materials : [];
$units = isset($unitSystem) && $unitSystem === 'imperial' ? 'imperial' : 'metric';
$displayZone = new DateTimeZone(isset($timezone) && is_string($timezone) ? $timezone : 'Europe/Copenhagen');
$icons = $view->asset('/assets/icons.svg');
$read = static function (mixed $row, string ...$keys): mixed { foreach ($keys as $key) { if (is_array($row) && array_key_exists($key,$row)) return $row[$key]; if (is_object($row) && isset($row->{$key})) return $row->{$key}; } return null; };
$num = static fn (mixed $value): float => is_numeric($value) ? (float) $value : 0.0;
$weight = static fn (float $grams): string => $units === 'imperial' ? number_format($grams * .03527396195,2).' oz' : number_format($grams,$grams >= 100 ? 0 : 1).' g';
$temp = static fn (mixed $c): string => !is_numeric($c) ? 'N/A' : ($units === 'imperial' ? number_format(((float)$c*9/5)+32,0).'°F' : number_format((float)$c,0).'°C');
$pressure = static fn (mixed $bar): string => !is_numeric($bar) ? 'N/A' : ($units === 'imperial' ? number_format((float)$bar/0.0689475729,0).' psi' : number_format((float)$bar,1).' bar');
$date = static function (mixed $value) use ($displayZone): string { try { return is_scalar($value) ? (new DateTimeImmutable((string)$value))->setTimezone($displayZone)->format('M j, Y') : 'Unknown date'; } catch (Throwable) { return is_scalar($value) ? (string)$value : 'Unknown date'; } };
$normalized = [];
foreach ($rows as $row) {
    $start = max(0,$num($read($row,'startAmountG','start_amount_g','startAmount')));
    $yield = max(0,$num($read($row,'yieldAmountG','yield_amount_g','yieldAmount')));
    $yieldPercent = $read($row,'yieldPercentage','yield_percentage');
    $strains = $read($row,'strainNames','strain_names','strains','strain');
    if (is_array($strains)) $strains = implode(' • ',array_map('strval',$strains));
    $normalized[] = [
        'id'=>(int)$num($read($row,'id')),
        'date'=>$read($row,'pressedAt','pressed_at','pressDate'),
        'strains'=>is_scalar($strains)&&trim((string)$strains)!==''?(string)$strains:'Unnamed batch',
        'material'=>(string)($read($row,'startMaterial','start_material')??''),
        'start'=>$start,'yield'=>$yield,
        'yieldPercent'=>is_numeric($yieldPercent)?(float)$yieldPercent:($start>0?$yield/$start*100:0),
        'temperature'=>$read($row,'temperatureC','temperature_c','temperature'),
        'pressure'=>$read($row,'pressureBar','pressure_bar','pressure'),
        'humidity'=>$read($row,'humidityPercent','humidity_percent','humidity'),
        'duration'=>$read($row,'pressDurationSeconds','press_duration_seconds','pressDuration'),
        'capacity'=>$read($row,'pressCapacityTons','press_capacity_tons','pressSize'),
        'passCount'=>max(1,(int)$num($read($row,'numberOfPresses','number_of_presses'))),
        'search'=>strtolower((string)$strains.' '.(string)($read($row,'startMaterial','start_material')??'').' '.(string)($read($row,'notes')??'')),
    ];
}
$totalResults = max(count($normalized), (int) ($result['total'] ?? count($normalized)));
$pageLimit = max(1, (int) ($result['limit'] ?? max(1, count($normalized))));
$pageOffset = max(0, (int) ($result['offset'] ?? 0));
$currentPage = intdiv($pageOffset, $pageLimit) + 1;
$totalPages = max(1, (int) ceil($totalResults / $pageLimit));
$pageHref = static function (int $page) use ($searchValue, $materialValue, $sortValue): string {
    return '/batches?' . http_build_query(array_filter([
        'search' => $searchValue,
        'material' => $materialValue,
        'sort' => $sortValue,
        'page' => $page > 1 ? $page : null,
    ], static fn (mixed $value): bool => $value !== null && $value !== ''));
};
$hasActiveFilters = trim($searchValue) !== '' || trim($materialValue) !== '';
?>
<div class="page-stack batches-page" data-batch-browser>
  <header class="page-header"><div><h1>All Batches</h1></div><a class="button button--primary" href="/batches/new"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#plus"></use></svg>New Batch</a></header>

  <form class="panel filter-bar" action="/batches" method="get" data-batch-filters>
    <div class="search-field"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#search"></use></svg><label class="visually-hidden" for="batch-search">Search batches</label><input id="batch-search" name="search" type="search" value="<?= $view->escape($searchValue) ?>" placeholder="Search by strain, material, or notes..." data-batch-search></div>
    <div class="field-group compact-field"><label class="visually-hidden" for="batch-material">Filter by material</label><select id="batch-material" name="material"><option value="">All Materials</option><?php foreach ($materialOptions as $materialOption): ?><?php if (!is_scalar($materialOption)) { continue; } ?><option value="<?= $view->escape($materialOption) ?>" <?= strcasecmp((string) $materialOption, $materialValue) === 0 ? 'selected' : '' ?>><?= $view->escape(ucfirst((string) $materialOption)) ?></option><?php endforeach; ?></select></div>
    <div class="field-group compact-field"><label class="visually-hidden" for="batch-sort">Sort batches</label><select id="batch-sort" name="sort" data-batch-sort><option value="date" <?= $sortValue==='date'?'selected':'' ?>>Date (Newest)</option><option value="strain" <?= $sortValue==='strain'?'selected':'' ?>>Strain (A–Z)</option><option value="yield" <?= $sortValue==='yield'?'selected':'' ?>>Yield % (Highest)</option><option value="amount" <?= $sortValue==='amount'?'selected':'' ?>>Amount (Highest)</option></select></div>
    <button class="button button--ghost filter-submit" type="submit">Apply</button>
  </form>

  <?php if ($normalized === []): ?>
    <?php if ($hasActiveFilters): ?>
      <section class="panel dashboard-empty"><span class="empty-illustration"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#search"></use></svg></span><h2>No matching batches</h2><p>Try another search or choose a different material.</p><a class="button button--ghost" href="/batches">Clear filters</a></section>
    <?php else: ?>
      <section class="panel dashboard-empty"><span class="empty-illustration"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#press"></use></svg></span><h2>No batches yet</h2><p>Create your first filled bag to track its passes, combined yield, notes, and photographs.</p><a class="button button--primary" href="/batches/new"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#plus"></use></svg>Create New Batch</a></section>
    <?php endif; ?>
  <?php else: ?>
    <p class="result-count" aria-live="polite"><span data-visible-count><?= $view->escape(count($normalized)) ?></span> <span data-visible-noun>batch<?= count($normalized)===1?'':'es' ?></span></p>
    <div class="batch-card-grid" data-batch-grid>
      <?php foreach ($normalized as $batch): ?>
        <a class="batch-card" href="/batch/<?= $view->escape($batch['id']) ?>" data-batch-card data-search="<?= $view->escape($batch['search']) ?>" data-date="<?= $view->escape(is_scalar($batch['date'])?$batch['date']:'') ?>" data-strain="<?= $view->escape(strtolower($batch['strains'])) ?>" data-yield="<?= $view->escape($batch['yieldPercent']) ?>" data-amount="<?= $view->escape($batch['yield']) ?>">
          <header><div><span class="batch-id">Batch #<?= $view->escape($batch['id']) ?></span><h2><?= $view->escape($batch['strains']) ?></h2><time><?= $view->escape($date($batch['date'])) ?></time></div><span class="material-badge"><?= $view->escape($batch['material']!==''?ucfirst($batch['material']):'Unknown') ?></span></header>
          <div class="batch-card__primary"><div><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#percent"></use></svg><span><small>Yield</small><strong><?= $view->escape(number_format($batch['yieldPercent'],1)) ?>%</strong></span></div><div><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#weight"></use></svg><span><small>Amount</small><strong><?= $view->escape($weight($batch['yield'])) ?></strong></span></div></div>
          <dl class="batch-card__details"><div><dt>Passes</dt><dd><?= $view->escape($batch['passCount']) ?></dd></div><div><dt>Pass 1 Temperature</dt><dd><?= $view->escape($temp($batch['temperature'])) ?></dd></div><div><dt>Humidity</dt><dd><?= is_numeric($batch['humidity'])?$view->escape(number_format((float)$batch['humidity'],0).'%'):'N/A' ?></dd></div><?php if(is_numeric($batch['duration'])):?><div><dt>Pass 1 Duration</dt><dd><?= $view->escape(sprintf('%d:%02d',intdiv((int)$batch['duration'],60),(int)$batch['duration']%60)) ?></dd></div><?php endif; ?><?php if(is_numeric($batch['capacity'])):?><div><dt>Press Size</dt><dd><?= $view->escape(number_format((float)$batch['capacity'],1)) ?> tons</dd></div><?php endif; ?></dl>
          <footer><span>View batch details</span><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#arrow-right"></use></svg></footer>
        </a>
      <?php endforeach; ?>
    </div>
    <?php if ($totalPages > 1): ?>
      <nav class="pagination" aria-label="Batch pages">
        <?php if ($currentPage > 1): ?><a class="button button--ghost button--small" href="<?= $view->escape($pageHref($currentPage - 1)) ?>">Previous</a><?php endif; ?>
        <span>Page <?= $view->escape($currentPage) ?> of <?= $view->escape($totalPages) ?> · <?= $view->escape($totalResults) ?> batches</span>
        <?php if ($currentPage < $totalPages): ?><a class="button button--ghost button--small" href="<?= $view->escape($pageHref($currentPage + 1)) ?>">Next</a><?php endif; ?>
      </nav>
    <?php endif; ?>
    <section class="panel dashboard-empty is-hidden" data-filter-empty><span class="empty-illustration"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#search"></use></svg></span><h2>No matching batches</h2><p>Try another search or choose a different material.</p></section>
  <?php endif; ?>
</div>
