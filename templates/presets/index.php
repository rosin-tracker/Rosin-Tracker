<?php

declare(strict_types=1);

$csrf = isset($csrfToken) && is_string($csrfToken) ? $csrfToken : '';
$units = isset($unitSystem) && $unitSystem === 'imperial' ? 'imperial' : 'metric';
$icons = $view->asset('/assets/icons.svg');
$templateItems = isset($templates) && is_array($templates) ? $templates : [];
$allPresets = isset($presets) && is_array($presets) ? $presets : [];
$groups = isset($presetGroups) && is_array($presetGroups) ? $presetGroups : [];
if ($groups === []) {
    foreach ($allPresets as $preset) if (is_array($preset)) $groups[(string)($preset['fieldKey'] ?? $preset['field_key'] ?? 'other')][] = $preset;
}
$fieldDefinitions = isset($presetFields) && is_array($presetFields) ? $presetFields : [];
$standalonePresetFields = ['start_material', 'strain', 'bag'];
$materialMarkerOptions = [
    'burst' => 'Burst',
    'dot' => 'Dot',
    'diamond' => 'Diamond',
    'square' => 'Square',
    'triangle' => 'Triangle',
    'cross' => 'Cross',
];
$materialColorOptions = [
    '#75beff' => 'Sky',
    '#73c991' => 'Leaf',
    '#dcdcaa' => 'Sand',
    '#c586c0' => 'Orchid',
    '#ce9178' => 'Clay',
    '#4ec9b0' => 'Aqua',
    '#d16d9e' => 'Rose',
    '#b5cea8' => 'Sage',
];
$materialStyleItems = isset($materialChartStyles) && is_array($materialChartStyles) ? $materialChartStyles : [];
$resolveMaterialStyle = static function (array $preset, int $presetId, string $label) use ($materialMarkerOptions, $materialColorOptions, $materialStyleItems): array {
    $sources = [];
    $collect = null;
    $collect = static function (mixed $source, int $depth = 0) use (&$collect, &$sources): void {
        if (is_string($source) && $depth < 2) {
            $decoded = json_decode($source, true);
            if (is_array($decoded)) {
                $collect($decoded, $depth + 1);
            }
            return;
        }
        if (!is_array($source)) {
            return;
        }
        $sources[] = $source;
        if ($depth >= 2) {
            return;
        }
        foreach (['chart', 'chartStyle', 'chart_style', 'appearance', 'metadata', 'meta', 'value'] as $nestedKey) {
            if (array_key_exists($nestedKey, $source)) {
                $collect($source[$nestedKey], $depth + 1);
            }
        }
    };

    $collect($preset);
    $normalizedLabel = mb_strtolower(trim($label));
    foreach ($materialStyleItems as $styleKey => $styleItem) {
        if (!is_array($styleItem)) {
            continue;
        }
        $candidateId = $styleItem['presetId'] ?? $styleItem['preset_id'] ?? $styleItem['id'] ?? null;
        $candidateLabel = $styleItem['material'] ?? $styleItem['name'] ?? $styleItem['label'] ?? null;
        $keyMatches = (string) $styleKey === (string) $presetId
            || mb_strtolower(trim((string) $styleKey)) === $normalizedLabel;
        $idMatches = is_numeric($candidateId) && (int) $candidateId === $presetId;
        $labelMatches = is_scalar($candidateLabel)
            && mb_strtolower(trim((string) $candidateLabel)) === $normalizedLabel;
        if ($keyMatches || $idMatches || $labelMatches) {
            $collect($styleItem);
        }
    }

    $marker = 'burst';
    $color = '#75beff';
    foreach ($sources as $source) {
        $candidateMarker = strtolower(trim((string) ($source['chartMarker'] ?? $source['chart_marker'] ?? $source['marker'] ?? '')));
        if (array_key_exists($candidateMarker, $materialMarkerOptions)) {
            $marker = $candidateMarker;
        }
        $candidateColor = strtolower(trim((string) ($source['chartColor'] ?? $source['chart_color'] ?? $source['color'] ?? '')));
        if (array_key_exists($candidateColor, $materialColorOptions)) {
            $color = $candidateColor;
        }
    }

    return ['marker' => $marker, 'color' => $color];
};
$fallbackFields = [
    'start_material'=>['label'=>'Start Materials','description'=>'Material choices shown on the batch form.','placeholder'=>'Flower'],
    'strain'=>['label'=>'Strains','description'=>'Frequently reused strain or cultivar names.','placeholder'=>'Blue Dream'],
    'bag'=>['label'=>'Bags','description'=>'Optional brand, dimensions, and micron saved as one bag option.','placeholder'=>'The Press Club · 2 × 4 in · 90 μm'],
];
if ($fieldDefinitions === []) $fieldDefinitions = $fallbackFields;
$formatTemplateDate = static function (mixed $value): string {
    if (!is_scalar($value) || trim((string)$value) === '') return 'recently';
    try { return (new DateTimeImmutable((string)$value))->format('M j, Y'); }
    catch (Throwable) { return 'recently'; }
};
?>
<div class="page-stack presets-page">
  <header class="page-header"><div><h1>Presets</h1></div><a class="button button--primary" href="/batches/new"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#plus"></use></svg>New Batch</a></header>

  <div class="info-banner"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#shield"></use></svg><div><strong>Historical batches never change.</strong><p>Editing or removing anything here changes future suggestions only.</p></div></div>

  <section class="preset-section" aria-labelledby="template-heading">
    <div class="section-heading"><div><span class="section-icon"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#copy"></use></svg></span><div><h2 id="template-heading">Batch Templates</h2></div></div><span class="count-badge"><?= $view->escape(count($templateItems)) ?></span></div>
    <?php if($templateItems===[]): ?>
      <div class="panel template-empty"><div><h3>No templates yet</h3><p>After saving a batch, choose “Save as template” to remember its reusable setup.</p></div><a class="button button--ghost" href="/batches/new">Create a batch</a></div>
    <?php else: ?>
      <div class="template-grid">
        <?php foreach($templateItems as $template): ?>
          <?php
          if(!is_array($template))continue;
          $templateId=(int)($template['id']??0);
          $name=(string)($template['name']??'Saved setup');
          $formValues=is_array($template['formValues']??null)?$template['formValues']:(is_array($template['values']??null)?$template['values']:[]);
          $payload=is_array($template['payload']??null)?$template['payload']:[];
          $settings=$formValues!==[]?$formValues:$payload;
          $bags=is_array($settings['bags']??null)?$settings['bags']:[];
          $templateTemperatureUnit=$formValues!==[]?(in_array(strtolower((string)($settings['temperature_unit']??'c')),['f','°f','fahrenheit'],true)?'°F':'°C'):'°C';
          $templatePressureUnit=(string)($settings['pressure_unit']??($units==='imperial'?'psi':'bar'));
          $templateLengthUnit=(string)($settings['bag_size_unit']??($units==='imperial'?'in':'mm'));
          $templateWeightUnit=(string)($settings['weight_unit']??($units==='imperial'?'oz':'g'));
          $templatePasses=is_array($settings['passes']??null)&&$settings['passes']!==[]?array_slice(array_values($settings['passes']),0,20):[['temperature'=>$settings['temperature']??$settings['temperatureC']??'','pressure'=>$settings['pressure']??$settings['pressureBar']??'','press_duration'=>$settings['press_duration']??$settings['pressDurationSeconds']??'','preheat'=>$settings['preheat']??$settings['preheatSeconds']??'']];
          $rawTemplateStrains=is_array($settings['strains']??null)?array_values($settings['strains']):[];
          $rawTemplateAmounts=is_array($settings['strain_amounts']??null)?array_values($settings['strain_amounts']):[];
          $templateStrains=[];
          $templateStrainAmounts=[];
          foreach($rawTemplateStrains as $strainIndex=>$strainValue){
              if(!is_scalar($strainValue)||trim((string)$strainValue)==='')continue;
              $templateStrains[]=trim((string)$strainValue);
              $amountValue=$rawTemplateAmounts[$strainIndex]??'';
              $templateStrainAmounts[]=is_scalar($amountValue)?trim((string)$amountValue):'';
          }
          $templateBlendParts=[];
          foreach($templateStrains as $strainIndex=>$strainName){
              $amount=$templateStrainAmounts[$strainIndex]??'';
              $templateBlendParts[]=$strainName.($amount!==''?' · '.$amount.' '.$templateWeightUnit:'');
          }
          $templateBlendLabel=$templateBlendParts!==[]?implode(' + ',$templateBlendParts):'Not saved';
          $templateHasCompleteAmounts=count($templateStrains)>0
              && count($templateStrainAmounts)===count($templateStrains)
              && count(array_filter($templateStrainAmounts,static fn(string $amount):bool=>$amount!==''&&is_numeric($amount)))===count($templateStrains);
          $templateStartingAmount=$templateHasCompleteAmounts
              ? rtrim(rtrim(number_format(array_sum(array_map('floatval',$templateStrainAmounts)),4,'.',''),'0'),'.')
              : '';
          ?>
          <article class="panel template-card">
            <header><div><span class="template-mark"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#presets"></use></svg></span><div><h3><?= $view->escape($name) ?></h3><p>Updated <?= $view->escape($formatTemplateDate($template['updatedAt']??$template['updated_at']??null)) ?></p></div></div><a class="button button--primary button--small" href="/batches/new?template=<?= $view->escape($templateId) ?>">Use template</a></header>
            <dl class="template-summary"><div><dt>Strains</dt><dd><?= $view->escape($templateBlendLabel) ?></dd></div><div><dt>Material</dt><dd><?= $view->escape($settings['start_material']??$settings['startMaterial']??'—') ?></dd></div><div><dt>Passes</dt><dd><?= $view->escape(count($templatePasses)) ?> <?= count($templatePasses)===1?'pass':'passes' ?></dd></div><div><dt>Pass 1 temperature</dt><dd><?= $view->escape((is_array($templatePasses[0]??null)?($templatePasses[0]['temperature']??$templatePasses[0]['temperatureC']??'—'):'—')) ?> <?= $view->escape($templateTemperatureUnit) ?></dd></div><div><dt>Bag layers</dt><dd><?= $view->escape(count($bags)) ?></dd></div></dl>
            <details class="inline-editor"><summary>Edit template</summary><form action="/templates/<?= $view->escape($templateId) ?>/edit" method="post" data-template-editor><input type="hidden" name="_csrf" value="<?= $view->escape($csrf) ?>"><input type="hidden" name="unit_system" value="<?= $view->escape($units) ?>"><input type="hidden" name="weight_unit" value="<?= $units==='imperial'?'oz':'g' ?>"><input type="hidden" name="temperature_unit" value="<?= $units==='imperial'?'f':'c' ?>"><input type="hidden" name="pressure_unit" value="<?= $units==='imperial'?'psi':'bar' ?>"><input type="hidden" name="bag_size_unit" value="<?= $units==='imperial'?'in':'mm' ?>">
              <input type="hidden" name="template_strains_present" value="1">
              <div class="field-group"><label>Template name</label><input name="name" type="text" maxlength="80" value="<?= $view->escape($name) ?>" required></div>
              <div class="editor-grid"><div class="field-group"><label>Start material</label><input name="start_material" type="text" value="<?= $view->escape($settings['start_material']??$settings['startMaterial']??'') ?>" required></div><div class="field-group"><label>Press size (tons)</label><input name="press_capacity" type="number" min="0.1" step="0.1" value="<?= $view->escape($settings['press_capacity']??$settings['pressCapacityTons']??'') ?>"></div><div class="field-group"><label>Material humidity (%)</label><input name="humidity" type="number" min="0" max="100" step="0.1" value="<?= $view->escape($settings['humidity']??$settings['humidityPercent']??'') ?>"></div></div>
              <div class="template-bag-editor template-strain-editor" data-template-strain-list data-max-strains="20">
                <div class="template-bag-editor__heading"><strong>Strains and amounts</strong><button class="button button--ghost button--small" type="button" data-template-add-strain <?= count($templateStrains)>=20?'disabled':'' ?>><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#plus"></use></svg>Add strain</button></div>
                <div class="template-strain-rows" data-template-strain-rows>
                  <?php foreach($templateStrains as $strainIndex=>$strainName): ?><div class="template-strain-row" data-template-strain-row><strong>Strain <span data-template-strain-label><?= $view->escape($strainIndex+1) ?></span></strong><input aria-label="Strain <?= $view->escape($strainIndex+1) ?> name" name="strains[]" type="text" maxlength="120" value="<?= $view->escape($strainName) ?>" placeholder="Strain name" data-template-strain-name required><input aria-label="Strain <?= $view->escape($strainIndex+1) ?> amount in <?= $view->escape($templateWeightUnit) ?>" name="strain_amounts[]" type="number" min="0.0001" step="0.0001" value="<?= $view->escape($templateStrainAmounts[$strainIndex]??'') ?>" placeholder="<?= count($templateStrains)===1?'Start amount':'Amount' ?> (<?= $view->escape($templateWeightUnit) ?>)" data-template-strain-amount><button class="icon-button danger-text" type="button" data-remove-template-strain aria-label="Remove Strain <?= $view->escape($strainIndex+1) ?>"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#trash"></use></svg></button></div><?php endforeach; ?>
                </div>
                <p class="inline-empty<?= $templateStrains!==[]?' is-hidden':'' ?>" data-template-strain-empty>No strains saved in this template.</p>
                <p class="field-hint" role="status" aria-live="polite" data-template-strain-status><?= $templateStartingAmount!==''?$view->escape('Starting amount: '.$templateStartingAmount.' '.$templateWeightUnit):(count($templateStrains)===0?'Add a strain to save a starting amount.':(count($templateStrains)===1?'Starting amount is optional.':'Amounts are optional for mixed batches.')) ?></p>
                <template data-template-strain-template><div class="template-strain-row" data-template-strain-row><strong>Strain <span data-template-strain-label>1</span></strong><input aria-label="Strain 1 name" name="strains[]" type="text" maxlength="120" placeholder="Strain name" data-template-strain-name required><input aria-label="Strain 1 start amount in <?= $view->escape($templateWeightUnit) ?>" name="strain_amounts[]" type="number" min="0.0001" step="0.0001" placeholder="Start amount (<?= $view->escape($templateWeightUnit) ?>)" data-template-strain-amount><button class="icon-button danger-text" type="button" data-remove-template-strain aria-label="Remove Strain 1"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#trash"></use></svg></button></div></template>
              </div>
              <div class="template-bag-editor template-pass-editor" data-template-pass-list data-max-passes="20">
                <div class="template-bag-editor__heading"><strong>Press passes</strong><button class="button button--ghost button--small" type="button" data-template-add-pass <?= count($templatePasses)>=20?'disabled':'' ?>><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#plus"></use></svg>Add pass</button></div>
                <div class="template-bag-rows template-pass-rows" data-template-pass-rows>
                  <?php foreach($templatePasses as $passIndex=>$templatePass): ?><?php $templatePass=is_array($templatePass)?$templatePass:[]; ?><div class="template-bag-row template-pass-row" data-template-pass-row><strong>Pass <span data-template-pass-label><?= $view->escape($passIndex+1) ?></span></strong><input aria-label="Pass <?= $view->escape($passIndex+1) ?> temperature in <?= $view->escape($templateTemperatureUnit) ?>" name="passes[<?= $view->escape($passIndex) ?>][temperature]" type="number" step="0.1" value="<?= $view->escape($templatePass['temperature']??$templatePass['temperatureC']??'') ?>" data-template-pass-field="temperature" required><input aria-label="Pass <?= $view->escape($passIndex+1) ?> pressure in <?= $view->escape($templatePressureUnit) ?>" name="passes[<?= $view->escape($passIndex) ?>][pressure]" type="number" min="0" step="0.1" value="<?= $view->escape($templatePass['pressure']??$templatePass['pressureBar']??'') ?>" data-template-pass-field="pressure"><input aria-label="Pass <?= $view->escape($passIndex+1) ?> duration in seconds" name="passes[<?= $view->escape($passIndex) ?>][press_duration]" type="number" min="0" max="7200" step="1" value="<?= $view->escape($templatePass['press_duration']??$templatePass['pressDurationSeconds']??'') ?>" data-template-pass-field="press_duration"><input aria-label="Pass <?= $view->escape($passIndex+1) ?> preheat in seconds" name="passes[<?= $view->escape($passIndex) ?>][preheat]" type="number" min="0" max="7200" step="1" value="<?= $view->escape($templatePass['preheat']??$templatePass['preheatSeconds']??'') ?>" data-template-pass-field="preheat"><button class="icon-button danger-text" type="button" data-remove-template-pass aria-label="Remove Pass <?= $view->escape($passIndex+1) ?>" <?= count($templatePasses)===1?'disabled':'' ?>><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#trash"></use></svg></button></div><?php endforeach; ?>
                </div>
                <p class="field-hint" role="status" aria-live="polite" data-template-pass-status><?= $view->escape(count($templatePasses)) ?> of 20 passes</p>
                <template data-template-pass-template><div class="template-bag-row template-pass-row" data-template-pass-row><strong>Pass <span data-template-pass-label>1</span></strong><input aria-label="Pass 1 temperature" name="passes[__INDEX__][temperature]" type="number" step="0.1" data-template-pass-field="temperature" required><input aria-label="Pass 1 pressure" name="passes[__INDEX__][pressure]" type="number" min="0" step="0.1" data-template-pass-field="pressure"><input aria-label="Pass 1 duration in seconds" name="passes[__INDEX__][press_duration]" type="number" min="0" max="7200" step="1" data-template-pass-field="press_duration"><input aria-label="Pass 1 preheat in seconds" name="passes[__INDEX__][preheat]" type="number" min="0" max="7200" step="1" data-template-pass-field="preheat"><button class="icon-button danger-text" type="button" data-remove-template-pass aria-label="Remove Pass 1"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#trash"></use></svg></button></div></template>
              </div>
              <div class="template-bag-editor" data-template-bag-list>
                <div class="template-bag-editor__heading"><strong>Bag layers</strong><button class="button button--ghost button--small" type="button" data-template-add-bag><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#plus"></use></svg>Add layer</button></div>
                <div class="template-bag-rows" data-template-bag-rows>
                  <?php foreach(array_values($bags) as $index=>$bag): ?><?php if(!is_array($bag))continue; ?><div class="template-bag-row" data-template-bag-row><strong>Layer <span data-template-layer-label><?= $view->escape($bag['layer']??($index+1)) ?></span></strong><input type="hidden" name="bags[<?= $view->escape($index) ?>][layer]" value="<?= $view->escape($bag['layer']??($index+1)) ?>" data-template-layer-input><input type="hidden" name="bags[<?= $view->escape($index) ?>][unit]" value="<?= $view->escape($bag['unit']??$templateLengthUnit) ?>"><input aria-label="Bag brand" name="bags[<?= $view->escape($index) ?>][brand]" type="text" maxlength="80" placeholder="Brand (optional)" value="<?= $view->escape($bag['brand']??'') ?>"><input aria-label="Micron" name="bags[<?= $view->escape($index) ?>][micron]" type="number" min="1" max="500" placeholder="Micron" value="<?= $view->escape($bag['micron']??'') ?>" required><input aria-label="Width in <?= $view->escape($templateLengthUnit) ?>" name="bags[<?= $view->escape($index) ?>][width]" type="number" step="0.01" min="0.01" placeholder="Width (<?= $view->escape($templateLengthUnit) ?>)" value="<?= $view->escape($bag['width']??$bag['widthMm']??'') ?>" required><input aria-label="Length in <?= $view->escape($templateLengthUnit) ?>" name="bags[<?= $view->escape($index) ?>][length]" type="number" step="0.01" min="0.01" placeholder="Length (<?= $view->escape($templateLengthUnit) ?>)" value="<?= $view->escape($bag['length']??$bag['lengthMm']??'') ?>" required><button class="icon-button danger-text" type="button" data-remove-template-bag aria-label="Remove bag layer"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#trash"></use></svg></button></div><?php endforeach; ?>
                </div>
                <p class="inline-empty<?= $bags!==[]?' is-hidden':'' ?>" data-template-bag-empty>No bag layers in this template.</p>
                <template data-template-bag-template><div class="template-bag-row" data-template-bag-row><strong>Layer <span data-template-layer-label>1</span></strong><input type="hidden" name="bags[__INDEX__][layer]" value="1" data-template-layer-input><input type="hidden" name="bags[__INDEX__][unit]" value="<?= $view->escape($templateLengthUnit) ?>"><input aria-label="Bag brand" name="bags[__INDEX__][brand]" type="text" maxlength="80" placeholder="Brand (optional)"><input aria-label="Micron" name="bags[__INDEX__][micron]" type="number" min="1" max="500" placeholder="Micron" required><input aria-label="Width in <?= $view->escape($templateLengthUnit) ?>" name="bags[__INDEX__][width]" type="number" min="0.01" step="0.01" placeholder="Width (<?= $view->escape($templateLengthUnit) ?>)" required><input aria-label="Length in <?= $view->escape($templateLengthUnit) ?>" name="bags[__INDEX__][length]" type="number" min="0.01" step="0.01" placeholder="Length (<?= $view->escape($templateLengthUnit) ?>)" required><button class="icon-button danger-text" type="button" data-remove-template-bag aria-label="Remove bag layer"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#trash"></use></svg></button></div></template>
              </div>
              <div class="inline-editor__actions"><button class="button button--primary button--small" type="submit">Save changes</button></div></form></details>
            <footer><form action="/templates/<?= $view->escape($templateId) ?>/delete" method="post" data-confirm="Delete this template? Existing batches will not change."><input type="hidden" name="_csrf" value="<?= $view->escape($csrf) ?>"><button class="text-button danger-text" type="submit"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#trash"></use></svg>Delete template</button></form></footer>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <section class="preset-section" aria-labelledby="options-heading">
    <div class="section-heading"><div><span class="section-icon"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#presets"></use></svg></span><div><h2 id="options-heading">Saved Options</h2><p>Suggestions appear on forms but never restrict what you can type.</p></div></div></div>
    <div class="preset-groups">
      <?php foreach($fieldDefinitions as $fieldKey=>$definition): ?>
        <?php $definition=is_array($definition)?$definition:[];$key=is_string($fieldKey)?$fieldKey:(string)($definition['key']??$definition['fieldKey']??'');if(!in_array($key,$standalonePresetFields,true))continue;$label=(string)($definition['label']??ucwords(str_replace('_',' ',$key)));$description=(string)($definition['description']??'Reusable form suggestions.');$placeholder=(string)($definition['placeholder']??'Add a value');$items=is_array($groups[$key]??null)?$groups[$key]:[]; ?>
        <details class="panel preset-group" <?= in_array($key,['start_material','bag'],true)?'open':'' ?>>
          <summary><span><strong><?= $view->escape($label) ?></strong><small><?= $view->escape($description) ?></small></span><span class="count-badge"><?= $view->escape(count($items)) ?></span></summary>
          <div class="preset-group__body">
            <?php if($key==='bag'): ?>
              <form class="preset-add bag-preset-form" action="/presets" method="post"><input type="hidden" name="_csrf" value="<?= $view->escape($csrf) ?>"><input type="hidden" name="field_key" value="bag"><input type="hidden" name="bag_size_unit" value="<?= $units==='imperial'?'in':'mm' ?>"><div class="field-group"><label for="new-bag-brand">Brand <span class="optional-label">optional</span></label><input id="new-bag-brand" name="brand" type="text" maxlength="80" placeholder="e.g. The Press Club"></div><div class="field-group"><label for="new-bag-width">Width <span class="unit-label"><?= $units==='imperial'?'in':'mm' ?></span></label><input id="new-bag-width" name="width" type="number" min="0.01" step="0.01" placeholder="<?= $units==='imperial'?'2':'50' ?>" required></div><div class="field-group"><label for="new-bag-length">Length <span class="unit-label"><?= $units==='imperial'?'in':'mm' ?></span></label><input id="new-bag-length" name="length" type="number" min="0.01" step="0.01" placeholder="<?= $units==='imperial'?'4':'100' ?>" required></div><div class="field-group"><label for="new-bag-micron">Micron <span class="unit-label">μm</span></label><input id="new-bag-micron" name="micron" type="number" min="1" max="500" step="1" placeholder="90" required></div><input type="hidden" name="sort_order" value="<?= $view->escape((count($items)+1)*10) ?>"><button class="button button--primary button--small" type="submit"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#plus"></use></svg>Add bag</button></form>
              <?php if($items===[]): ?>
                <p class="inline-empty">No bags saved yet. Add a brand if useful, then dimensions and micron.</p>
              <?php else: ?>
                <div class="preset-option-list bag-option-list">
                  <?php foreach($items as $item): ?>
                    <?php if(!is_array($item))continue;$presetId=(int)($item['id']??0);$presetLabel=(string)($item['label']??'Saved bag');$bagValue=is_array($item['value']??null)?$item['value']:[]; ?>
                    <div class="preset-option bag-option" data-bag-preset-entry>
                      <div class="bag-option__header">
                        <div class="bag-option__identity">
                          <span>Saved bag</span>
                          <strong data-bag-preset-label><?= $view->escape($presetLabel) ?></strong>
                        </div>
                        <form action="/presets/<?= $view->escape($presetId) ?>/delete" method="post" data-confirm="Remove this saved bag? Historical batches will not change.">
                          <input type="hidden" name="_csrf" value="<?= $view->escape($csrf) ?>">
                          <button class="icon-button danger-text" type="submit" aria-label="Delete <?= $view->escape($presetLabel) ?>"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#trash"></use></svg></button>
                        </form>
                      </div>
                      <form class="bag-preset-edit" action="/presets/<?= $view->escape($presetId) ?>/edit" method="post">
                        <input type="hidden" name="_csrf" value="<?= $view->escape($csrf) ?>">
                        <input type="hidden" name="field_key" value="bag">
                        <input type="hidden" name="bag_size_unit" value="<?= $view->escape($bagValue['unit']??($units==='imperial'?'in':'mm')) ?>" data-bag-preset-unit>
                        <label><span>Brand</span><input aria-label="Bag brand" name="brand" type="text" maxlength="80" placeholder="Optional" value="<?= $view->escape($bagValue['brand']??'') ?>" data-bag-preset-field="brand"></label>
                        <label><span>Width</span><input aria-label="Bag width" name="width" type="number" min="0.01" step="0.01" value="<?= $view->escape($bagValue['width']??'') ?>" data-bag-preset-field="width" required></label>
                        <label><span>Length</span><input aria-label="Bag length" name="length" type="number" min="0.01" step="0.01" value="<?= $view->escape($bagValue['length']??'') ?>" data-bag-preset-field="length" required></label>
                        <label><span>Micron</span><input aria-label="Bag micron" name="micron" type="number" min="1" max="500" step="1" value="<?= $view->escape($bagValue['micron']??'') ?>" data-bag-preset-field="micron" required></label>
                        <input class="sort-input" aria-label="Sort order" name="sort_order" type="number" value="<?= $view->escape($item['sortOrder']??0) ?>">
                        <button class="button button--ghost button--small" type="submit">Save</button>
                      </form>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            <?php else: ?>
              <form class="preset-add" action="/presets" method="post"><input type="hidden" name="_csrf" value="<?= $view->escape($csrf) ?>"><input type="hidden" name="field_key" value="<?= $view->escape($key) ?>"><div class="field-group"><label for="new-name-<?= $view->escape($key) ?>">Name</label><input id="new-name-<?= $view->escape($key) ?>" name="name" type="text" maxlength="120" placeholder="<?= $view->escape($placeholder) ?>" required></div><input type="hidden" name="sort_order" value="<?= $view->escape((count($items)+1)*10) ?>"><button class="button button--primary button--small" type="submit"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#plus"></use></svg>Add</button></form>
              <?php if($items===[]): ?>
                <p class="inline-empty">No saved options in this group.</p>
              <?php elseif($key==='start_material'): ?>
                <div class="preset-option-list material-preset-list">
                  <?php $materialItemIndex=0;foreach($items as $item): ?>
                    <?php
                    if(!is_array($item))continue;
                    $presetId=(int)($item['id']??0);
                    $presetLabel=(string)($item['label']??'');
                    $materialStyle=$resolveMaterialStyle($item,$presetId,$presetLabel);
                    $selectedMarker=$materialStyle['marker'];
                    $selectedColor=$materialStyle['color'];
                    $materialItemIndex++;
                    ?>
                    <div class="preset-option material-preset-option">
                      <form class="material-preset-edit" action="/presets/<?= $view->escape($presetId) ?>/edit" method="post">
                        <input type="hidden" name="_csrf" value="<?= $view->escape($csrf) ?>">
                        <input type="hidden" name="field_key" value="start_material">
                        <details class="material-style-details">
                          <summary class="material-style-current" title="Edit chart style for <?= $view->escape($presetLabel) ?>">
                            <svg class="material-style-current__marker" viewBox="0 0 24 24" color="<?= $view->escape($selectedColor) ?>" aria-hidden="true" focusable="false"><use href="<?= $view->escape($icons) ?>#marker-<?= $view->escape($selectedMarker) ?>"></use></svg>
                            <span class="visually-hidden">Edit chart style for <?= $view->escape($presetLabel) ?></span>
                          </summary>
                          <div class="material-style-panel">
                            <fieldset class="material-style-fieldset">
                              <legend>Marker</legend>
                              <div class="material-style-choices material-style-choices--markers">
                                <?php foreach($materialMarkerOptions as $markerValue=>$markerLabel): ?>
                                  <label class="material-style-choice material-style-choice--marker">
                                    <input type="radio" name="chart_marker" value="<?= $view->escape($markerValue) ?>" <?= $selectedMarker===$markerValue?'checked':'' ?>>
                                    <svg class="material-style-choice__preview" viewBox="0 0 24 24" color="<?= $view->escape($selectedColor) ?>" aria-hidden="true" focusable="false"><use href="<?= $view->escape($icons) ?>#marker-<?= $view->escape($markerValue) ?>"></use></svg>
                                    <span class="material-style-choice__label"><?= $view->escape($markerLabel) ?></span>
                                    <span class="material-style-choice__check" aria-hidden="true">✓</span>
                                  </label>
                                <?php endforeach; ?>
                              </div>
                            </fieldset>
                            <fieldset class="material-style-fieldset">
                              <legend>Colour</legend>
                              <div class="material-style-choices material-style-choices--colors">
                                <?php foreach($materialColorOptions as $colorValue=>$colorLabel): ?>
                                  <label class="material-style-choice material-style-choice--color">
                                    <input type="radio" name="chart_color" value="<?= $view->escape($colorValue) ?>" <?= $selectedColor===$colorValue?'checked':'' ?>>
                                    <svg class="material-style-choice__preview" viewBox="0 0 24 24" color="<?= $view->escape($colorValue) ?>" aria-hidden="true" focusable="false"><use href="<?= $view->escape($icons) ?>#marker-<?= $view->escape($selectedMarker) ?>"></use></svg>
                                    <span class="material-style-choice__label"><?= $view->escape($colorLabel) ?></span>
                                    <span class="material-style-choice__check" aria-hidden="true">✓</span>
                                  </label>
                                <?php endforeach; ?>
                              </div>
                            </fieldset>
                          </div>
                        </details>
                        <label class="visually-hidden" for="material-name-<?= $view->escape($presetId) ?>-<?= $view->escape($materialItemIndex) ?>">Material name</label>
                        <input class="material-preset-name" id="material-name-<?= $view->escape($presetId) ?>-<?= $view->escape($materialItemIndex) ?>" name="name" type="text" value="<?= $view->escape($presetLabel) ?>" maxlength="120" required>
                        <input class="sort-input" aria-label="Sort order" name="sort_order" type="number" value="<?= $view->escape($item['sortOrder']??$item['sort_order']??0) ?>">
                        <button class="button button--ghost button--small" type="submit">Save</button>
                      </form>
                      <form action="/presets/<?= $view->escape($presetId) ?>/delete" method="post" data-confirm="Remove this saved option? Historical batches will not change.">
                        <input type="hidden" name="_csrf" value="<?= $view->escape($csrf) ?>">
                        <button class="icon-button danger-text" type="submit" aria-label="Delete <?= $view->escape($presetLabel) ?>"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#trash"></use></svg></button>
                      </form>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <div class="preset-option-list">
                  <?php foreach($items as $item): ?>
                    <?php if(!is_array($item))continue;$presetId=(int)($item['id']??0);$presetLabel=(string)($item['label']??''); ?>
                    <div class="preset-option"><form action="/presets/<?= $view->escape($presetId) ?>/edit" method="post"><input type="hidden" name="_csrf" value="<?= $view->escape($csrf) ?>"><input type="hidden" name="field_key" value="<?= $view->escape($key) ?>"><input aria-label="Name" name="name" type="text" value="<?= $view->escape($presetLabel) ?>" maxlength="120" required><input class="sort-input" aria-label="Sort order" name="sort_order" type="number" value="<?= $view->escape($item['sortOrder']??0) ?>"><button class="button button--ghost button--small" type="submit">Save</button></form><form action="/presets/<?= $view->escape($presetId) ?>/delete" method="post" data-confirm="Remove this saved option? Historical batches will not change."><input type="hidden" name="_csrf" value="<?= $view->escape($csrf) ?>"><button class="icon-button danger-text" type="submit" aria-label="Delete <?= $view->escape($presetLabel) ?>"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#trash"></use></svg></button></form></div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </details>
      <?php endforeach; ?>
    </div>
  </section>
</div>
