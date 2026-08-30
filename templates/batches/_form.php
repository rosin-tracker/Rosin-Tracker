<?php

declare(strict_types=1);

$formValues = isset($form) && is_array($form) ? $form : [];
$errorItems = isset($errors) && is_array($errors) ? $errors : [];
$presetGroups = isset($presets) && is_array($presets) ? $presets : [];
$templateItems = isset($templates) && is_array($templates) ? $templates : [];
$photoItems = isset($photos) && is_array($photos) ? $photos : [];
$csrf = isset($csrfToken) && is_string($csrfToken) ? $csrfToken : '';
$editing = isset($isEdit) ? (bool) $isEdit : false;
$batchId = isset($batchId) && is_numeric($batchId) ? (int) $batchId : 0;
$formAction = isset($action) && is_string($action)
    ? $action
    : ($editing && $batchId > 0 ? '/batch/' . $batchId . '/edit' : '/batches/new');
$submitLabel = $editing ? 'Update Batch' : 'Save Batch';
$unitMode = (($formValues['unit_system'] ?? $unitSystem ?? 'metric') === 'imperial') ? 'imperial' : 'metric';
$weightUnit = (string) ($formValues['weight_unit'] ?? ($unitMode === 'imperial' ? 'oz' : 'g'));
$temperatureCode = strtolower((string) ($formValues['temperature_unit'] ?? ($unitMode === 'imperial' ? 'f' : 'c')));
$temperatureUnit = in_array($temperatureCode, ['f', 'fahrenheit', '°f'], true) ? '°F' : '°C';
$pressureUnit = (string) ($formValues['pressure_unit'] ?? ($unitMode === 'imperial' ? 'psi' : 'bar'));
$lengthUnit = (string) ($formValues['bag_size_unit'] ?? ($unitMode === 'imperial' ? 'in' : 'mm'));
$icons = $view->asset('/assets/icons.svg');

$value = static function (string $key, mixed $default = '') use ($formValues): mixed {
    return array_key_exists($key, $formValues) ? $formValues[$key] : $default;
};
$errorFor = static function (string $key) use ($errorItems): string {
    $message = $errorItems[$key] ?? '';
    if (is_array($message)) $message = reset($message);
    return is_scalar($message) ? (string) $message : '';
};
$passErrorFor = static function (int $index, string $key) use ($errorFor): string {
    $message = $errorFor("passes.{$index}.{$key}");
    return $message !== '' || $index !== 0 ? $message : $errorFor($key);
};
$presetValues = static function (string $key) use ($presetGroups): array {
    $items = $presetGroups[$key] ?? [];
    if (!is_array($items)) return [];
    $result = [];
    foreach ($items as $item) {
        if (is_array($item)) {
            $candidate = $item['value'] ?? $item['label'] ?? null;
            // Named presets are always consumed by their visible label. A material's
            // value may contain chart-only metadata that must never reach this input.
            if (is_array($candidate)) $candidate = $item['label'] ?? null;
            if (is_scalar($candidate) && (string) $candidate !== '') $result[] = (string) $candidate;
        } elseif (is_scalar($item) && (string) $item !== '') {
            $result[] = (string) $item;
        }
    }
    return array_values(array_unique($result));
};
$bagPresets = array_values(array_filter(
    is_array($presetGroups['bag'] ?? null) ? $presetGroups['bag'] : [],
    static fn (mixed $item): bool => is_array($item) && is_array($item['value'] ?? null),
));
$bagBrand = static function (array $bag): string {
    $brand = $bag['brand'] ?? $bag['bagBrand'] ?? $bag['bag_brand'] ?? '';
    return is_scalar($brand) ? trim((string) $brand) : '';
};
$matchingBagPresetId = static function (array $bag) use ($bagPresets, $bagBrand): string {
    $number = static function (array $item, string $key): ?float {
        $candidate = $item[$key] ?? null;
        return is_numeric($candidate) ? (float) $candidate : null;
    };
    $micron = $number($bag, 'micron');
    $width = $number($bag, 'width');
    $length = $number($bag, 'length');
    $brand = mb_strtolower($bagBrand($bag));
    if ($micron === null || $width === null || $length === null) return '';
    foreach ($bagPresets as $preset) {
        $saved = is_array($preset['value'] ?? null) ? $preset['value'] : [];
        $savedMicron = $number($saved, 'micron');
        $savedWidth = $number($saved, 'width');
        $savedLength = $number($saved, 'length');
        if ($savedMicron === null || $savedWidth === null || $savedLength === null) continue;
        if (abs($micron - $savedMicron) > 0.001 || abs($width - $savedWidth) > 0.01 || abs($length - $savedLength) > 0.01) continue;
        if (mb_strtolower($bagBrand($saved)) !== $brand) continue;
        return isset($preset['id']) && is_scalar($preset['id']) ? (string) $preset['id'] : '';
    }
    return '';
};
$renderSavedOptions = static function (array $options, string $label) use ($view): void {
    echo '<button class="saved-combobox__toggle" type="button" aria-label="' . $view->escape($label)
        . '" aria-haspopup="listbox" aria-expanded="false" data-combobox-toggle>';
    echo '<span class="saved-option-chevron" aria-hidden="true"></span></button>';
    echo '<div class="saved-combobox__list" role="listbox" data-combobox-list hidden>';
    foreach ($options as $option) {
        echo '<button type="button" role="option" tabindex="-1" aria-selected="false" data-combobox-option data-value="'
            . $view->escape($option) . '">' . $view->escape($option) . '</button>';
    }
    echo '<span class="saved-combobox__empty" data-combobox-empty hidden>No matching saved options</span></div>';
};
$renderBagPresetOptions = static function (
    string $selectedId = '',
    string $retainedBrand = '',
) use ($view, $bagPresets, $bagBrand): void {
    echo '<option value=""' . ($selectedId === '' && $retainedBrand === '' ? ' selected' : '')
        . '>Custom bag</option>';
    if ($selectedId === '' && $retainedBrand !== '') {
        echo '<option value="__retained_brand__" data-retained-bag-brand="'
            . $view->escape($retainedBrand) . '" selected>'
            . $view->escape($retainedBrand . ' · Custom dimensions') . '</option>';
    }
    foreach ($bagPresets as $preset) {
        $saved = is_array($preset['value'] ?? null) ? $preset['value'] : [];
        if (!isset($saved['micron'], $saved['width'], $saved['length'])) continue;
        $id = isset($preset['id']) && is_scalar($preset['id']) ? (string) $preset['id'] : '';
        echo '<option value="' . $view->escape($id) . '"'
            . ' data-bag-brand="' . $view->escape($bagBrand($saved)) . '"'
            . ' data-bag-micron="' . $view->escape($saved['micron']) . '"'
            . ' data-bag-width="' . $view->escape($saved['width']) . '"'
            . ' data-bag-length="' . $view->escape($saved['length']) . '"'
            . ($id !== '' && $id === $selectedId ? ' selected' : '') . '>'
            . $view->escape($preset['label'] ?? 'Saved bag') . '</option>';
    }
};
$strains = $value('strains', []);
if (is_string($strains)) $strains = preg_split('/[\r\n,]+/', $strains) ?: [];
if (!is_array($strains) || $strains === []) $strains = [''];
$strainAmounts = $value('strain_amounts', []);
if (!is_array($strainAmounts)) $strainAmounts = [];
$strainAmounts = array_values($strainAmounts);
while (count($strainAmounts) < count($strains)) $strainAmounts[] = '';
$hasStrainAmounts = count(array_filter(
    $strainAmounts,
    static fn (mixed $amount): bool => is_scalar($amount) && trim((string) $amount) !== '',
)) > 0;
$bags = $value('bags', []);
if (!is_array($bags)) $bags = [];
$passes = $value('passes', []);
if (!is_array($passes) || $passes === []) {
    $passes = [[
        'temperature' => $value('temperature'),
        'pressure' => $value('pressure'),
        'press_duration' => $value('press_duration'),
        'preheat' => $value('preheat'),
    ]];
}
$passes = array_slice(array_values($passes), 0, 20);
$pressedAtError = $errorFor('pressed_at');
$generalErrors = [];
foreach ($errorItems as $messages) {
    foreach (is_array($messages) ? $messages : [$messages] as $message) {
        if (is_scalar($message) && trim((string) $message) !== '') $generalErrors[] = (string) $message;
    }
}
$renderDatalist = static function (string $id, array $values) use ($view): void {
    if ($values === []) return;
    echo '<datalist id="' . $view->escape($id) . '">';
    foreach ($values as $option) echo '<option value="' . $view->escape($option) . '"></option>';
    echo '</datalist>';
};
?>

<?php if ($generalErrors !== []): ?>
  <div class="error-summary page-error-summary" role="alert" tabindex="-1" data-error-summary>
    <strong>Please check the highlighted fields.</strong>
    <ul><?php foreach (array_values(array_unique($generalErrors)) as $message): ?><li><?= $view->escape($message) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<form class="batch-form page-stack" action="<?= $view->escape($formAction) ?>" method="post" enctype="multipart/form-data" data-batch-form>
  <input type="hidden" name="_csrf" value="<?= $view->escape($csrf) ?>">
  <input type="hidden" name="unit_system" value="<?= $view->escape($unitMode) ?>">
  <input type="hidden" name="weight_unit" value="<?= $view->escape($weightUnit) ?>">
  <input type="hidden" name="temperature_unit" value="<?= $view->escape($temperatureUnit === '°F' ? 'f' : 'c') ?>">
  <input type="hidden" name="pressure_unit" value="<?= $view->escape($pressureUnit) ?>">
  <input type="hidden" name="bag_size_unit" value="<?= $view->escape($lengthUnit) ?>">
  <?php if (!$editing): ?><input type="hidden" name="pressed_at_mode" value="automatic"><?php endif; ?>

  <?php if (!$editing && $templateItems !== []): ?>
    <section class="template-picker panel">
      <div class="template-picker__copy">
        <span class="template-picker__icon"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#copy"></use></svg></span>
        <div><strong>Start from a saved setup</strong><p>Prefill reusable press settings, then change anything for this batch.</p></div>
      </div>
      <div class="field-group compact-field">
        <label for="source-template">Batch template</label>
        <select id="source-template" name="source_template_id" data-template-picker>
          <option value="">No template</option>
          <?php foreach ($templateItems as $template): ?>
            <?php
            if (!is_array($template)) continue;
            $templateId = isset($template['id']) && is_numeric($template['id']) ? (int) $template['id'] : 0;
            $templateName = isset($template['name']) && is_scalar($template['name']) ? (string) $template['name'] : 'Saved setup';
            $templateValues = $template['formValues'] ?? $template['values'] ?? $template['payload'] ?? [];
            $templateJson = is_array($templateValues) ? json_encode($templateValues, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) : '{}';
            $selectedTemplate = (string) $value('source_template_id') === (string) $templateId;
            ?>
            <option value="<?= $view->escape($templateId) ?>" data-template-values="<?= $view->escape(is_string($templateJson) ? $templateJson : '{}') ?>" <?= $selectedTemplate ? 'selected' : '' ?>><?= $view->escape($templateName) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </section>
  <?php elseif ($editing): ?>
    <input type="hidden" name="source_template_id" value="<?= $view->escape($value('source_template_id')) ?>">
  <?php endif; ?>

  <div class="form-card-grid<?= $editing ? '' : ' form-card-grid--single' ?>">
    <section class="panel form-card batch-info-card<?= $editing ? '' : ' create-batch-info' ?>">
      <header class="panel-header"><div><h2>Batch Information</h2></div></header>
      <div class="panel-content form-fields">
        <fieldset class="repeat-field" data-strain-list>
          <legend>Strains</legend>
          <div class="repeat-list" data-strain-rows>
            <?php foreach (array_values($strains) as $index => $strain): ?>
              <div class="repeat-row" data-strain-row>
                <div class="saved-combobox" data-saved-combobox>
                  <input name="strains[]" type="text" value="<?= $view->escape(is_scalar($strain) ? $strain : '') ?>" placeholder="e.g. Blue Dream" maxlength="120" aria-label="Strain <?= $view->escape($index + 1) ?>" role="combobox" aria-autocomplete="list" aria-haspopup="listbox" aria-expanded="false" autocomplete="off" data-combobox-input <?= $index === 0 ? 'required' : '' ?>>
                  <?php $renderSavedOptions($presetValues('strain'), 'Choose a saved strain'); ?>
                </div>
                <button class="icon-button repeat-remove" type="button" aria-label="Remove strain" data-remove-row><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#close"></use></svg></button>
              </div>
            <?php endforeach; ?>
          </div>
          <button class="button button--ghost button--small" type="button" data-add-strain><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#plus"></use></svg>Add strain</button>
          <?php if ($errorFor('strains') !== ''): ?><span class="field-error"><?= $view->escape($errorFor('strains')) ?></span><?php endif; ?>
        </fieldset>

        <div class="batch-info-details">
        <div class="field-group">
          <label for="start-material">Start Material</label>
          <div class="saved-combobox" data-saved-combobox>
            <input id="start-material" name="start_material" type="text" value="<?= $view->escape($value('start_material')) ?>" placeholder="e.g. Flower" maxlength="120" role="combobox" aria-autocomplete="list" aria-haspopup="listbox" aria-expanded="false" autocomplete="off" data-combobox-input required <?= $errorFor('start_material') !== '' ? 'aria-invalid="true"' : '' ?>>
            <?php $renderSavedOptions(array_values(array_unique(array_merge(['Flower', 'Hash', 'Kief', 'Trim'], $presetValues('start_material')))), 'Choose a saved material'); ?>
          </div>
          <?php if ($errorFor('start_material') !== ''): ?><span class="field-error"><?= $view->escape($errorFor('start_material')) ?></span><?php endif; ?>
        </div>

        <div class="field-group">
          <label for="humidity">Material Humidity <span class="unit-label">%</span></label>
          <input id="humidity" name="humidity" type="number" min="0" max="100" step="0.1" inputmode="decimal" value="<?= $view->escape($value('humidity')) ?>" placeholder="Optional" <?= $errorFor('humidity') !== '' ? 'aria-invalid="true"' : '' ?>>
          <?php if ($errorFor('humidity') !== ''): ?><span class="field-error"><?= $view->escape($errorFor('humidity')) ?></span><?php endif; ?>
        </div>

        <div class="field-row">
          <div class="field-group">
            <label for="start-amount">Start Amount <span class="unit-label"><?= $view->escape($weightUnit) ?></span></label>
            <input id="start-amount" name="start_amount" type="number" min="0" step="0.0001" inputmode="decimal" value="<?= $view->escape($value('start_amount')) ?>" placeholder="0.00" required data-start-amount <?= $errorFor('start_amount') !== '' ? 'aria-invalid="true"' : '' ?>>
            <?php if ($errorFor('start_amount') !== ''): ?><span class="field-error"><?= $view->escape($errorFor('start_amount')) ?></span><?php endif; ?>
          </div>
          <div class="field-group">
            <label for="press-capacity">Press Size <span class="unit-label">tons</span></label>
            <input id="press-capacity" name="press_capacity" type="number" min="0.1" step="0.1" inputmode="decimal" value="<?= $view->escape($value('press_capacity')) ?>" list="press-capacity-options" placeholder="e.g. 10" <?= $errorFor('press_capacity') !== '' ? 'aria-invalid="true"' : '' ?>>
            <?php if ($errorFor('press_capacity') !== ''): ?><span class="field-error"><?= $view->escape($errorFor('press_capacity')) ?></span><?php endif; ?>
          </div>
        </div>

        <div class="strain-amount-grid<?= count($strains) < 2 ? ' is-hidden' : '' ?>" data-strain-amount-rows>
          <?php foreach (array_values($strains) as $index => $strain): ?>
            <?php
            $strainName = is_scalar($strain) ? trim((string) $strain) : '';
            $strainAmountError = $errorFor("strain_amounts.{$index}");
            ?>
            <div class="field-group strain-amount-field" data-strain-amount-row>
              <label for="strain-amount-<?= $view->escape($index) ?>"><span data-strain-amount-label><?= $view->escape($strainName !== '' ? $strainName : 'Strain ' . ($index + 1)) ?></span> <span class="unit-label"><?= $view->escape($weightUnit) ?></span></label>
              <input id="strain-amount-<?= $view->escape($index) ?>" name="strain_amounts[]" type="number" min="0.0001" step="0.0001" inputmode="decimal" value="<?= $view->escape(is_scalar($strainAmounts[$index] ?? null) ? (string) $strainAmounts[$index] : '') ?>" placeholder="Optional" data-strain-amount <?= $hasStrainAmounts ? 'required' : '' ?> <?= $strainAmountError !== '' ? 'aria-invalid="true"' : '' ?>>
              <?php if ($strainAmountError !== ''): ?><span class="field-error"><?= $view->escape($strainAmountError) ?></span><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <?php if ($errorFor('strain_amounts') !== ''): ?><span class="field-error strain-amount-error"><?= $view->escape($errorFor('strain_amounts')) ?></span><?php endif; ?>
        </div>

        <?php if ($editing): ?>
          <input type="hidden" name="pressed_at_mode" value="custom">
          <details class="custom-time-control" <?= $pressedAtError !== '' ? 'open' : '' ?>>
            <summary>Change press date &amp; time</summary>
            <div class="field-group">
              <label for="pressed-at">Press date &amp; time</label>
              <input id="pressed-at" name="pressed_at" type="datetime-local" step="1" value="<?= $view->escape($value('pressed_at')) ?>" required <?= $pressedAtError !== '' ? 'aria-invalid="true"' : '' ?>>
              <?php if ($pressedAtError !== ''): ?><span class="field-error"><?= $view->escape($pressedAtError) ?></span><?php endif; ?>
            </div>
          </details>
        <?php endif; ?>
      </div>
    </section>

    <?php if ($editing): ?>
    <section class="panel form-card">
      <header class="panel-header"><div><h2>Pass 1</h2></div></header>
      <div class="panel-content form-fields">
        <div class="field-row">
          <div class="field-group">
            <label for="temperature">Temperature <span class="unit-label"><?= $view->escape($temperatureUnit) ?></span></label>
            <input id="temperature" name="temperature" type="number" step="0.1" inputmode="decimal" value="<?= $view->escape($value('temperature')) ?>" list="temperature-options" placeholder="90" required <?= $errorFor('temperature') !== '' ? 'aria-invalid="true"' : '' ?>>
            <?php if ($errorFor('temperature') !== ''): ?><span class="field-error"><?= $view->escape($errorFor('temperature')) ?></span><?php endif; ?>
          </div>
          <div class="field-group">
            <label for="pressure">Pressure <span class="unit-label"><?= $view->escape($pressureUnit) ?></span></label>
            <input id="pressure" name="pressure" type="number" min="0" step="0.1" inputmode="decimal" value="<?= $view->escape($value('pressure')) ?>" list="pressure-options" placeholder="Optional" <?= $errorFor('pressure') !== '' ? 'aria-invalid="true"' : '' ?>>
            <?php if ($errorFor('pressure') !== ''): ?><span class="field-error"><?= $view->escape($errorFor('pressure')) ?></span><?php endif; ?>
          </div>
        </div>
        <div class="field-row">
          <div class="field-group">
            <label for="press-duration">Press Duration <span class="unit-label">seconds</span></label>
            <input id="press-duration" name="press_duration" type="number" min="0" max="7200" step="1" inputmode="numeric" value="<?= $view->escape($value('press_duration')) ?>" list="duration-options" placeholder="e.g. 120" <?= $errorFor('press_duration') !== '' ? 'aria-invalid="true"' : '' ?>>
            <?php if ($errorFor('press_duration') !== ''): ?><span class="field-error"><?= $view->escape($errorFor('press_duration')) ?></span><?php endif; ?>
          </div>
          <div class="field-group">
            <label for="preheat">Preheating Time <span class="unit-label">seconds</span></label>
            <input id="preheat" name="preheat" type="number" min="0" max="7200" step="1" inputmode="numeric" value="<?= $view->escape($value('preheat')) ?>" list="preheat-options" placeholder="e.g. 45" <?= $errorFor('preheat') !== '' ? 'aria-invalid="true"' : '' ?>>
            <?php if ($errorFor('preheat') !== ''): ?><span class="field-error"><?= $view->escape($errorFor('preheat')) ?></span><?php endif; ?>
          </div>
        </div>
      </div>
    </section>
    <?php endif; ?>
  </div>

  <section class="panel form-card bag-section" data-bag-list data-next-index="<?= $view->escape(count($bags)) ?>">
    <header class="panel-header panel-header--action"><div><h2>Bag Stack</h2><p>Inner bag first. This stack stays unchanged for every pass in the batch.</p></div><button class="button button--ghost button--small" type="button" data-add-bag><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#plus"></use></svg>Add bag layer</button></header>
    <div class="panel-content">
      <div class="bag-list" data-bag-rows>
        <?php foreach (array_values($bags) as $index => $bag): ?>
          <?php $bag = is_array($bag) ? $bag : []; $selectedBagPresetId = $matchingBagPresetId($bag); ?>
          <div class="bag-row" data-bag-row>
            <span class="row-identity">Layer <span data-layer-label><?= $view->escape($bag['layer'] ?? ($index + 1)) ?></span></span>
            <input type="hidden" name="bags[<?= $view->escape($index) ?>][layer]" value="<?= $view->escape($bag['layer'] ?? ($index + 1)) ?>" data-layer-input>
            <input type="hidden" name="bags[<?= $view->escape($index) ?>][unit]" value="<?= $view->escape($bag['unit'] ?? $lengthUnit) ?>">
            <input type="hidden" name="bags[<?= $view->escape($index) ?>][brand]" value="<?= $view->escape($bagBrand($bag)) ?>" data-bag-brand>
            <div class="field-group bag-saved-size"><label for="bag-<?= $view->escape($index) ?>-preset">Saved bag</label><select id="bag-<?= $view->escape($index) ?>-preset" data-bag-field="preset" data-bag-preset><?php $renderBagPresetOptions($selectedBagPresetId, $bagBrand($bag)); ?></select></div>
            <div class="field-group"><label for="bag-<?= $view->escape($index) ?>-micron">Micron <span class="unit-label">μm</span></label><input id="bag-<?= $view->escape($index) ?>-micron" name="bags[<?= $view->escape($index) ?>][micron]" type="number" min="1" max="500" value="<?= $view->escape($bag['micron'] ?? '') ?>" placeholder="e.g. 90" data-bag-field="micron"></div>
            <div class="field-group"><label for="bag-<?= $view->escape($index) ?>-width">Width <span class="unit-label"><?= $view->escape($lengthUnit) ?></span></label><input id="bag-<?= $view->escape($index) ?>-width" name="bags[<?= $view->escape($index) ?>][width]" type="number" min="0.01" step="0.01" value="<?= $view->escape($bag['width'] ?? '') ?>" placeholder="Width" data-bag-field="width"></div>
            <div class="field-group"><label for="bag-<?= $view->escape($index) ?>-length">Length <span class="unit-label"><?= $view->escape($lengthUnit) ?></span></label><input id="bag-<?= $view->escape($index) ?>-length" name="bags[<?= $view->escape($index) ?>][length]" type="number" min="0.01" step="0.01" value="<?= $view->escape($bag['length'] ?? '') ?>" placeholder="Length" data-bag-field="length"></div>
            <button class="icon-button bag-remove" type="button" aria-label="Remove bag layer" data-remove-row><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#trash"></use></svg></button>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="inline-empty<?= $bags !== [] ? ' is-hidden' : '' ?>" data-bag-empty>No bag layers added. Add the filled inner bag first.</div>
      <template data-bag-template>
        <div class="bag-row" data-bag-row>
          <span class="row-identity">Layer <span data-layer-label>1</span></span>
          <input type="hidden" name="bags[__INDEX__][layer]" value="1" data-layer-input><input type="hidden" name="bags[__INDEX__][unit]" value="<?= $view->escape($lengthUnit) ?>"><input type="hidden" name="bags[__INDEX__][brand]" value="" data-bag-brand>
          <div class="field-group bag-saved-size"><label for="bag-__INDEX__-preset">Saved bag</label><select id="bag-__INDEX__-preset" data-bag-field="preset" data-bag-preset><?php $renderBagPresetOptions(); ?></select></div>
          <div class="field-group"><label for="bag-__INDEX__-micron">Micron <span class="unit-label">μm</span></label><input id="bag-__INDEX__-micron" name="bags[__INDEX__][micron]" type="number" min="1" max="500" placeholder="e.g. 90" data-bag-field="micron"></div>
          <div class="field-group"><label for="bag-__INDEX__-width">Width <span class="unit-label"><?= $view->escape($lengthUnit) ?></span></label><input id="bag-__INDEX__-width" name="bags[__INDEX__][width]" type="number" min="0.01" step="0.01" placeholder="Width" data-bag-field="width"></div>
          <div class="field-group"><label for="bag-__INDEX__-length">Length <span class="unit-label"><?= $view->escape($lengthUnit) ?></span></label><input id="bag-__INDEX__-length" name="bags[__INDEX__][length]" type="number" min="0.01" step="0.01" placeholder="Length" data-bag-field="length"></div>
          <button class="icon-button bag-remove" type="button" aria-label="Remove bag layer" data-remove-row><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#trash"></use></svg></button>
        </div>
      </template>
      <?php if ($errorFor('bags') !== ''): ?><span class="field-error"><?= $view->escape($errorFor('bags')) ?></span><?php endif; ?>
    </div>
  </section>

  <?php if (!$editing): ?>
    <section class="panel form-card pass-section" data-pass-list data-max-passes="20">
      <header class="panel-header panel-header--action">
        <div><h2>Press Passes</h2><p>Each pass is one trip through the press with this same filled bag stack.</p></div>
        <button class="button button--ghost button--small" type="button" data-add-pass <?= count($passes) >= 20 ? 'disabled' : '' ?>><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#plus"></use></svg>Add pass</button>
      </header>
      <div class="panel-content">
        <div class="pass-form-list" data-pass-rows>
          <?php foreach ($passes as $index => $pass): ?>
            <?php
            $pass = is_array($pass) ? $pass : [];
            $temperatureError = $passErrorFor($index, 'temperature');
            $pressureError = $passErrorFor($index, 'pressure');
            $durationError = $passErrorFor($index, 'press_duration');
            $preheatError = $passErrorFor($index, 'preheat');
            ?>
            <article class="pass-form-row" data-pass-row>
              <span class="row-identity">Pass <span data-pass-label><?= $view->escape($index + 1) ?></span></span>
              <div class="field-group">
                <label for="pass-<?= $view->escape($index) ?>-temperature">Temperature <span class="unit-label"><?= $view->escape($temperatureUnit) ?></span></label>
                <input id="pass-<?= $view->escape($index) ?>-temperature" name="passes[<?= $view->escape($index) ?>][temperature]" type="number" step="0.1" inputmode="decimal" value="<?= $view->escape($pass['temperature'] ?? '') ?>" list="temperature-options" placeholder="90" required data-pass-field="temperature" <?= $temperatureError !== '' ? 'aria-invalid="true"' : '' ?>>
                <?php if ($temperatureError !== ''): ?><span class="field-error"><?= $view->escape($temperatureError) ?></span><?php endif; ?>
              </div>
              <div class="field-group">
                <label for="pass-<?= $view->escape($index) ?>-pressure">Pressure <span class="unit-label"><?= $view->escape($pressureUnit) ?></span></label>
                <input id="pass-<?= $view->escape($index) ?>-pressure" name="passes[<?= $view->escape($index) ?>][pressure]" type="number" min="0" step="0.1" inputmode="decimal" value="<?= $view->escape($pass['pressure'] ?? '') ?>" list="pressure-options" placeholder="Optional" data-pass-field="pressure" <?= $pressureError !== '' ? 'aria-invalid="true"' : '' ?>>
                <?php if ($pressureError !== ''): ?><span class="field-error"><?= $view->escape($pressureError) ?></span><?php endif; ?>
              </div>
              <div class="field-group">
                <label for="pass-<?= $view->escape($index) ?>-press_duration">Press Duration <span class="unit-label">seconds</span></label>
                <input id="pass-<?= $view->escape($index) ?>-press_duration" name="passes[<?= $view->escape($index) ?>][press_duration]" type="number" min="0" max="7200" step="1" inputmode="numeric" value="<?= $view->escape($pass['press_duration'] ?? '') ?>" list="duration-options" placeholder="e.g. 120" data-pass-field="press_duration" <?= $durationError !== '' ? 'aria-invalid="true"' : '' ?>>
                <?php if ($durationError !== ''): ?><span class="field-error"><?= $view->escape($durationError) ?></span><?php endif; ?>
              </div>
              <div class="field-group">
                <label for="pass-<?= $view->escape($index) ?>-preheat">Preheating Time <span class="unit-label">seconds</span></label>
                <input id="pass-<?= $view->escape($index) ?>-preheat" name="passes[<?= $view->escape($index) ?>][preheat]" type="number" min="0" max="7200" step="1" inputmode="numeric" value="<?= $view->escape($pass['preheat'] ?? '') ?>" list="preheat-options" placeholder="e.g. 45" data-pass-field="preheat" <?= $preheatError !== '' ? 'aria-invalid="true"' : '' ?>>
                <?php if ($preheatError !== ''): ?><span class="field-error"><?= $view->escape($preheatError) ?></span><?php endif; ?>
              </div>
              <button class="icon-button pass-remove" type="button" aria-label="Remove Pass <?= $view->escape($index + 1) ?>" data-remove-pass <?= count($passes) === 1 ? 'disabled' : '' ?>><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#trash"></use></svg></button>
            </article>
          <?php endforeach; ?>
        </div>
        <p class="field-hint" role="status" aria-live="polite" data-pass-status><?= $view->escape(count($passes)) ?> of 20 passes</p>
        <template data-pass-template>
          <article class="pass-form-row" data-pass-row>
            <span class="row-identity">Pass <span data-pass-label>1</span></span>
            <div class="field-group"><label for="pass-__INDEX__-temperature">Temperature <span class="unit-label"><?= $view->escape($temperatureUnit) ?></span></label><input id="pass-__INDEX__-temperature" name="passes[__INDEX__][temperature]" type="number" step="0.1" inputmode="decimal" list="temperature-options" placeholder="90" required data-pass-field="temperature"></div>
            <div class="field-group"><label for="pass-__INDEX__-pressure">Pressure <span class="unit-label"><?= $view->escape($pressureUnit) ?></span></label><input id="pass-__INDEX__-pressure" name="passes[__INDEX__][pressure]" type="number" min="0" step="0.1" inputmode="decimal" list="pressure-options" placeholder="Optional" data-pass-field="pressure"></div>
            <div class="field-group"><label for="pass-__INDEX__-press_duration">Press Duration <span class="unit-label">seconds</span></label><input id="pass-__INDEX__-press_duration" name="passes[__INDEX__][press_duration]" type="number" min="0" max="7200" step="1" inputmode="numeric" list="duration-options" placeholder="e.g. 120" data-pass-field="press_duration"></div>
            <div class="field-group"><label for="pass-__INDEX__-preheat">Preheating Time <span class="unit-label">seconds</span></label><input id="pass-__INDEX__-preheat" name="passes[__INDEX__][preheat]" type="number" min="0" max="7200" step="1" inputmode="numeric" list="preheat-options" placeholder="e.g. 45" data-pass-field="preheat"></div>
            <button class="icon-button pass-remove" type="button" aria-label="Remove Pass 1" data-remove-pass><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#trash"></use></svg></button>
          </article>
        </template>
        <?php if ($errorFor('passes') !== ''): ?><span class="field-error"><?= $view->escape($errorFor('passes')) ?></span><?php endif; ?>
      </div>
    </section>
  <?php endif; ?>

  <section class="panel form-card">
    <header class="panel-header"><div><h2>Combined Result</h2><p>Enter the total yield from every pass; the percentage is calculated automatically.</p></div></header>
    <div class="panel-content result-grid">
      <div class="field-group"><label for="yield-amount">Yield Amount <span class="unit-label"><?= $view->escape($weightUnit) ?></span></label><input id="yield-amount" name="yield_amount" type="number" min="0" step="0.0001" inputmode="decimal" value="<?= $view->escape($value('yield_amount')) ?>" placeholder="0.00" required data-yield-amount <?= $errorFor('yield_amount') !== '' ? 'aria-invalid="true"' : '' ?>><?php if ($errorFor('yield_amount') !== ''): ?><span class="field-error"><?= $view->escape($errorFor('yield_amount')) ?></span><?php endif; ?></div>
      <div class="field-group"><label for="yield-percentage">Yield Percentage</label><output class="calculated-output" id="yield-percentage" data-yield-percentage>0.0%</output></div>
    </div>
  </section>

  <section class="panel form-card">
    <header class="panel-header"><div><h2>Additional Information</h2></div></header>
    <div class="panel-content form-fields">
      <div class="field-group"><label for="notes">Notes</label><textarea id="notes" name="notes" rows="4" maxlength="10000" placeholder="Observations about this batch..."><?= $view->escape($value('notes')) ?></textarea></div>
      <?php if ($photoItems !== []): ?>
        <div class="existing-photos"><span class="field-label">Current photographs</span><div class="photo-grid">
          <?php foreach ($photoItems as $photo): ?><?php if (!is_array($photo)) continue; $photoId = (int) ($photo['id'] ?? 0); $photoUrl = (string) ($photo['url'] ?? ('/photos/' . $photoId)); ?>
            <label class="photo-tile removable-photo"><img src="<?= $view->escape($photoUrl) ?>" alt="<?= $view->escape($photo['originalName'] ?? $photo['original_name'] ?? 'Batch photograph') ?>" loading="lazy"><span><input type="checkbox" name="remove_photos[]" value="<?= $view->escape($photoId) ?>"> Remove</span></label>
          <?php endforeach; ?>
        </div></div>
      <?php endif; ?>
      <div class="field-group"><label for="photos">Add Pictures <span class="unit-label">up to 5 total</span></label><label class="photo-drop" for="photos" data-photo-drop><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#upload"></use></svg><strong>Drop images here or choose files</strong><span>JPEG, PNG, or WebP</span></label><input class="visually-hidden" id="photos" name="photos[]" type="file" accept="image/jpeg,image/png,image/webp" multiple data-photo-input><div class="photo-preview" data-photo-preview aria-live="polite"></div></div>
    </div>
  </section>

  <?php if (!$editing): ?>
    <section class="save-template-option"><label><input type="checkbox" name="save_as_template" value="1" data-template-save-toggle><span><strong>Remember this setup as a template</strong><small>Strains, their amounts, the bag stack, and all passes are copied. Yield, notes, date, and photographs are not.</small></span></label><div class="field-group is-hidden" data-template-name><label for="template-name">Template name</label><input id="template-name" name="template_name" type="text" maxlength="80" placeholder="e.g. 90µ flower setup"></div></section>
  <?php endif; ?>

  <footer class="form-actions"><a class="button button--ghost" href="<?= $editing && $batchId > 0 ? '/batch/' . $view->escape($batchId) : '/batches' ?>">Cancel</a><button class="button button--primary button--large" type="submit" data-submit-button><?= $view->escape($submitLabel) ?><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#arrow-right"></use></svg></button></footer>

  <?php $renderDatalist('press-capacity-options', $presetValues('press_capacity')); ?>
  <?php $renderDatalist('temperature-options', $presetValues('temperature')); ?>
  <?php $renderDatalist('pressure-options', $presetValues('pressure')); ?>
  <?php $renderDatalist('duration-options', $presetValues('press_duration')); ?>
  <?php $renderDatalist('preheat-options', $presetValues('preheat')); ?>
</form>
