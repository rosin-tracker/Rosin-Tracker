<?php

declare(strict_types=1);

$formValues = isset($values) && is_array($values) ? $values : [];
$errorItems = isset($errors) && is_array($errors) ? $errors : [];
$presetGroups = isset($presets) && is_array($presets) ? $presets : [];
$csrf = isset($csrfToken) && is_string($csrfToken) ? $csrfToken : '';
$editing = isset($editingPass) && $editingPass === true;
$currentPass = isset($pass) && is_array($pass) ? $pass : null;
$resolvedBatchId = isset($batchId) && is_numeric($batchId) ? (int) $batchId : 0;
$resolvedPassId = $currentPass !== null && is_numeric($currentPass['id'] ?? null) ? (int) $currentPass['id'] : 0;
$formAction = $editing
    ? '/batch/' . $resolvedBatchId . '/passes/' . $resolvedPassId . '/edit'
    : '/batch/' . $resolvedBatchId . '/passes';
$unitMode = (($formValues['unit_system'] ?? $unitSystem ?? 'metric') === 'imperial') ? 'imperial' : 'metric';
$temperatureCode = strtolower((string) ($formValues['temperature_unit'] ?? ($unitMode === 'imperial' ? 'f' : 'c')));
$temperatureUnit = in_array($temperatureCode, ['f', 'fahrenheit', '°f'], true) ? '°F' : '°C';
$pressureUnit = (string) ($formValues['pressure_unit'] ?? ($unitMode === 'imperial' ? 'psi' : 'bar'));
$icons = $view->asset('/assets/icons.svg');
$value = static fn (string $key, mixed $default = ''): mixed => $formValues[$key] ?? $default;
$errorFor = static function (string $key) use ($errorItems): string {
    $message = $errorItems[$key] ?? '';
    if (is_array($message)) $message = reset($message);
    return is_scalar($message) ? (string) $message : '';
};
$presetValues = static function (string $key) use ($presetGroups): array {
    $result = [];
    foreach (is_array($presetGroups[$key] ?? null) ? $presetGroups[$key] : [] as $item) {
        $candidate = is_array($item) ? ($item['value'] ?? null) : $item;
        if (is_array($candidate)) $candidate = $candidate['value'] ?? null;
        if (is_scalar($candidate) && trim((string) $candidate) !== '') $result[] = (string) $candidate;
    }
    return array_values(array_unique($result));
};
$renderDatalist = static function (string $id, array $items) use ($view): void {
    if ($items === []) return;
    echo '<datalist id="' . $view->escape($id) . '">';
    foreach ($items as $item) echo '<option value="' . $view->escape($item) . '"></option>';
    echo '</datalist>';
};
$bags = isset($batch['bags']) && is_array($batch['bags']) ? $batch['bags'] : [];
$lengthUnit = $unitMode === 'imperial' ? 'in' : 'mm';
$bagLabel = static function (array $bag) use ($lengthUnit): string {
    $brandValue = $bag['brand'] ?? $bag['bagBrand'] ?? $bag['bag_brand'] ?? '';
    $brand = is_scalar($brandValue) ? trim((string) $brandValue) : '';
    $width = (float) ($bag['widthMm'] ?? $bag['width_mm'] ?? 0);
    $length = (float) ($bag['lengthMm'] ?? $bag['length_mm'] ?? 0);
    if ($lengthUnit === 'in') {
        $width /= 25.4;
        $length /= 25.4;
    }
    $format = static fn (float $number): string => rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
    return ($brand !== '' ? $brand . ' · ' : '')
        . $format($width) . ' × ' . $format($length) . ' ' . $lengthUnit
        . ' · ' . (int) ($bag['micron'] ?? 0) . ' μm';
};
?>

<?php if ($errorItems !== []): ?>
  <div class="error-summary page-error-summary" role="alert" tabindex="-1" data-error-summary>
    <strong>Please check this pass.</strong>
    <ul><?php foreach (array_unique(array_filter(array_map(static fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $errorItems))) as $message): ?><li><?= $view->escape($message) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<section class="panel pass-context" aria-label="Batch bag stack">
  <div class="pass-context__copy">
    <span class="section-icon"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#stack"></use></svg></span>
    <div><strong>Bag stack stays fixed</strong><p><?= $view->escape(count($bags)) ?> bag layer<?= count($bags) === 1 ? '' : 's' ?> from Batch #<?= $view->escape($resolvedBatchId) ?>.</p></div>
  </div>
  <?php if ($bags !== []): ?><ol class="pass-bag-stack"><?php foreach ($bags as $bag): ?><?php if (is_array($bag)): ?><li><?= $view->escape($bagLabel($bag)) ?></li><?php endif; ?><?php endforeach; ?></ol><?php endif; ?>
</section>

<form class="pass-form page-stack" action="<?= $view->escape($formAction) ?>" method="post">
  <input type="hidden" name="_csrf" value="<?= $view->escape($csrf) ?>">
  <input type="hidden" name="unit_system" value="<?= $view->escape($unitMode) ?>">
  <input type="hidden" name="temperature_unit" value="<?= $temperatureUnit === '°F' ? 'f' : 'c' ?>">
  <input type="hidden" name="pressure_unit" value="<?= $view->escape($pressureUnit) ?>">

  <section class="panel form-card">
    <header class="panel-header"><div><h2>Pass <?= $view->escape($position ?? '') ?> Settings</h2><p><?= $editing ? 'Update the measurements recorded for this pass.' : 'Copied from the previous pass; change only what was different.' ?></p></div></header>
    <div class="panel-content form-fields">
      <div class="field-row">
        <div class="field-group"><label for="temperature">Temperature <span class="unit-label"><?= $view->escape($temperatureUnit) ?></span></label><input id="temperature" name="temperature" type="number" step="0.1" inputmode="decimal" list="pass-temperature-options" value="<?= $view->escape($value('temperature')) ?>" required <?= $errorFor('temperature') !== '' ? 'aria-invalid="true"' : '' ?>><?php if ($errorFor('temperature') !== ''): ?><span class="field-error"><?= $view->escape($errorFor('temperature')) ?></span><?php endif; ?></div>
        <div class="field-group"><label for="pressure">Pressure <span class="unit-label"><?= $view->escape($pressureUnit) ?></span></label><input id="pressure" name="pressure" type="number" min="0" step="0.1" inputmode="decimal" list="pass-pressure-options" value="<?= $view->escape($value('pressure')) ?>" placeholder="Optional"><?php if ($errorFor('pressure') !== ''): ?><span class="field-error"><?= $view->escape($errorFor('pressure')) ?></span><?php endif; ?></div>
      </div>
      <div class="field-row">
        <div class="field-group"><label for="preheat">Preheating Time <span class="unit-label">seconds</span></label><input id="preheat" name="preheat" type="number" min="0" max="7200" step="1" inputmode="numeric" list="pass-preheat-options" value="<?= $view->escape($value('preheat')) ?>" placeholder="Optional"><?php if ($errorFor('preheat') !== ''): ?><span class="field-error"><?= $view->escape($errorFor('preheat')) ?></span><?php endif; ?></div>
        <div class="field-group"><label for="press-duration">Press Duration <span class="unit-label">seconds</span></label><input id="press-duration" name="press_duration" type="number" min="0" max="7200" step="1" inputmode="numeric" list="pass-duration-options" value="<?= $view->escape($value('press_duration')) ?>" placeholder="Optional"><?php if ($errorFor('press_duration') !== ''): ?><span class="field-error"><?= $view->escape($errorFor('press_duration')) ?></span><?php endif; ?></div>
      </div>
    </div>
  </section>

  <footer class="form-actions"><a class="button button--ghost" href="/batch/<?= $view->escape($resolvedBatchId) ?>">Cancel</a><button class="button button--primary button--large" type="submit"><?= $editing ? 'Update Pass' : 'Add Pass' ?><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#arrow-right"></use></svg></button></footer>

  <?php $renderDatalist('pass-temperature-options', $presetValues('temperature')); ?>
  <?php $renderDatalist('pass-pressure-options', $presetValues('pressure')); ?>
  <?php $renderDatalist('pass-preheat-options', $presetValues('preheat')); ?>
  <?php $renderDatalist('pass-duration-options', $presetValues('press_duration')); ?>
</form>
