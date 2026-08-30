<?php

declare(strict_types=1);

$record = isset($batch) && (is_array($batch) || is_object($batch)) ? $batch : [];
$photoItems = isset($photos) && is_array($photos) ? $photos : [];
$units = isset($unitSystem) && $unitSystem === 'imperial' ? 'imperial' : 'metric';
$displayZone = new DateTimeZone(isset($timezone) && is_string($timezone) ? $timezone : 'Europe/Copenhagen');
$csrf = isset($csrfToken) && is_string($csrfToken) ? $csrfToken : '';
$icons = $view->asset('/assets/icons.svg');
$read = static function (mixed $row,string ...$keys):mixed{foreach($keys as $key){if(is_array($row)&&array_key_exists($key,$row))return $row[$key];if(is_object($row)&&isset($row->{$key}))return $row->{$key};}return null;};
$num = static fn(mixed $v):float=>is_numeric($v)?(float)$v:0.0;
$id=(int)$num($read($record,'id'));
$start=$num($read($record,'startAmountG','start_amount_g','startAmount'));
$yield=$num($read($record,'yieldAmountG','yield_amount_g','yieldAmount'));
$yieldPercent=$read($record,'yieldPercentage','yield_percentage'); $yieldPercent=is_numeric($yieldPercent)?(float)$yieldPercent:($start>0?$yield/$start*100:0);
$strainValues=$read($record,'strains','strainNames','strain_names','strain');
$strainNames=is_array($strainValues)?array_values(array_map('strval',$strainValues)):(is_scalar($strainValues)&&trim((string)$strainValues)!==''?[(string)$strainValues]:[]);
$strains=$strainNames!==[]?implode(' • ',$strainNames):'Unnamed batch';
$strainAmounts=$read($record,'strainAmountsG','strain_amounts_g'); if(!is_array($strainAmounts))$strainAmounts=[];
$hasStrainBreakdown=count(array_filter($strainAmounts,static fn(mixed $amount):bool=>is_numeric($amount)&&(float)$amount>0))>0;
$material=(string)($read($record,'startMaterial','start_material')??'');
$pressedAt=$read($record,'pressedAt','pressed_at','pressDate'); try{$dateLabel=is_scalar($pressedAt)?(new DateTimeImmutable((string)$pressedAt))->setTimezone($displayZone)->format('M j, Y · H:i'):'Date not recorded';}catch(Throwable){$dateLabel=is_scalar($pressedAt)?(string)$pressedAt:'Date not recorded';}
$weight=static fn(float $g):string=>$units==='imperial'?number_format($g*.03527396195,2).' oz':number_format($g,$g>=100?0:2).' g';
$humidity=$read($record,'humidityPercent','humidity_percent','humidity');
$capacity=$read($record,'pressCapacityTons','press_capacity_tons','pressSize');
$passes=$read($record,'passes'); if(!is_array($passes))$passes=[];
$pressCount=count($passes);
$bags=$read($record,'bags','micronBags','micron_bags'); if(!is_array($bags))$bags=[];
$notes=$read($record,'notes'); $notes=is_scalar($notes)?trim((string)$notes):'';
$formatBag=static function(array $bag,int $index)use($units):string{
    $micron=$bag['micron']??'?';$layer=$bag['layer']??($index+1);
    $brandValue=$bag['brand']??$bag['bagBrand']??$bag['bag_brand']??'';$brand=is_scalar($brandValue)?trim((string)$brandValue):'';
    $isCanonical=array_key_exists('widthMm',$bag)||array_key_exists('width_mm',$bag);
    $width=$bag['width']??$bag['widthMm']??$bag['width_mm']??null;
    $length=$bag['length']??$bag['lengthMm']??$bag['length_mm']??null;
    $unit=(string)($bag['unit']??($isCanonical?'mm':($units==='imperial'?'in':'mm')));
    if($isCanonical&&is_numeric($width)&&is_numeric($length)&&$units==='imperial'){$width=(float)$width/25.4;$length=(float)$length/25.4;$unit='in';}
    elseif($isCanonical){$unit='mm';}
    $width=is_numeric($width)?number_format((float)$width,$unit==='in'?2:0):'?';
    $length=is_numeric($length)?number_format((float)$length,$unit==='in'?2:0):'?';
    $placement=$index===0?'Inner bag':'Outer layer '.$layer;
    return $placement.': '.($brand!==''?$brand.' · ':'').$width.' × '.$length.' '.$unit.' · '.$micron.' μm';
};
$formatTemperature=static function(mixed $value)use($units):string{return !is_numeric($value)?'N/A':($units==='imperial'?number_format(((float)$value*9/5)+32,0).' °F':number_format((float)$value,0).' °C');};
$formatPressure=static function(mixed $value)use($units):string{return !is_numeric($value)?'—':($units==='imperial'?number_format((float)$value/.0689475729,0).' psi':number_format((float)$value,1).' bar');};
$formatDuration=static fn(mixed $value):string=>is_numeric($value)?sprintf('%d:%02d',intdiv((int)$value,60),(int)$value%60):'—';
?>
<div class="page-stack batch-detail-page">
  <header class="detail-header"><div class="detail-heading"><a class="back-link" href="/batches"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#arrow-left"></use></svg>Back to Batches</a><div><span class="eyebrow">Batch #<?= $view->escape($id) ?></span><h1><?= $view->escape($strains) ?></h1><p><?= $view->escape($dateLabel) ?> <span aria-hidden="true">·</span> <?= $view->escape($material!==''?ucfirst($material):'Material not recorded') ?></p></div></div><div class="detail-actions"><a class="button button--ghost" href="/batch/<?= $view->escape($id) ?>/edit"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#edit"></use></svg>Edit Batch</a><button class="button button--ghost danger-text" type="button" data-dialog-open="delete-batch-dialog"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#trash"></use></svg>Delete</button></div></header>

  <div class="detail-card-grid">
    <section class="panel detail-card"><header class="panel-header"><h2>Batch Contents</h2></header><dl class="detail-list"><div><dt>Strains</dt><dd><?php if(!$hasStrainBreakdown): ?><?= $view->escape($strains) ?><?php else: ?><ul class="strain-summary"><?php foreach($strainNames as $index=>$strainName): ?><li><span><?= $view->escape($strainName) ?></span><strong><?= is_numeric($strainAmounts[$index]??null)?$view->escape($weight((float)$strainAmounts[$index])):'—' ?></strong></li><?php endforeach; ?></ul><?php endif; ?></dd></div><div><dt>Start Material</dt><dd><?= $view->escape($material!==''?ucfirst($material):'N/A') ?></dd></div><div><dt>Bag Stack</dt><dd><?php if($bags===[]): ?>None<?php else: ?><ol class="bag-summary"><?php foreach($bags as $index=>$bag): ?><?php if(!is_array($bag))continue;?><li><?= $view->escape($formatBag($bag,$index)) ?></li><?php endforeach; ?></ol><?php endif; ?></dd></div></dl></section>
    <section class="panel detail-card"><header class="panel-header"><h2>Combined Result</h2></header><dl class="detail-list"><div><dt>Start Amount</dt><dd><?= $view->escape($weight($start)) ?></dd></div><div><dt>Total Yield</dt><dd><?= $view->escape($weight($yield)) ?></dd></div><div class="result-highlight"><dt>Yield Percentage</dt><dd><?= $view->escape(number_format($yieldPercent,1)) ?>%</dd></div></dl></section>
    <section class="panel detail-card detail-card--wide"><header class="panel-header"><h2>Batch Settings</h2></header><dl class="settings-detail-grid"><div><dt>Press Size</dt><dd><?= is_numeric($capacity)?$view->escape(number_format((float)$capacity,1).' tons'):'N/A' ?></dd></div><div><dt>Material Humidity</dt><dd><?= is_numeric($humidity)?$view->escape(number_format((float)$humidity,0).'%'):'N/A' ?></dd></div><div><dt>Passes</dt><dd><?= $view->escape($pressCount) ?></dd></div><div><dt>Bag Layers</dt><dd><?= $view->escape(count($bags)) ?></dd></div></dl></section>
  </div>

  <section class="panel passes-panel">
    <header class="panel-header panel-header--action"><div><h2>Press Passes</h2><p>Each row is one trip through the press with the same bag stack.</p></div><?php if($pressCount<20): ?><a class="button button--primary button--small" href="/batch/<?= $view->escape($id) ?>/passes/new"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#plus"></use></svg>Add Pass</a><?php endif; ?></header>
    <div class="panel-content">
      <ol class="pass-list">
        <?php foreach($passes as $pass): ?><?php if(!is_array($pass))continue;$passId=(int)($pass['id']??0);$position=(int)($pass['position']??1); ?>
          <li class="pass-row">
            <div class="pass-row__number"><span>Pass</span><strong><?= $view->escape($position) ?></strong></div>
            <dl class="pass-row__settings">
              <div><dt>Temperature</dt><dd><?= $view->escape($formatTemperature($pass['temperatureC']??null)) ?></dd></div>
              <div><dt>Pressure</dt><dd><?= $view->escape($formatPressure($pass['pressureBar']??null)) ?></dd></div>
              <div><dt>Preheat</dt><dd><?= $view->escape($formatDuration($pass['preheatSeconds']??null)) ?></dd></div>
              <div><dt>Duration</dt><dd><?= $view->escape($formatDuration($pass['pressDurationSeconds']??null)) ?></dd></div>
            </dl>
            <div class="pass-row__actions"><a class="button button--ghost button--small" href="/batch/<?= $view->escape($id) ?>/passes/<?= $view->escape($passId) ?>/edit">Edit</a><?php if($pressCount>1): ?><form method="post" action="/batch/<?= $view->escape($id) ?>/passes/<?= $view->escape($passId) ?>/delete" data-confirm="Remove Pass <?= $view->escape($position) ?>? Remaining passes will be renumbered."><input type="hidden" name="_csrf" value="<?= $view->escape($csrf) ?>"><button class="icon-button danger-text" type="submit" aria-label="Delete Pass <?= $view->escape($position) ?>"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#trash"></use></svg></button></form><?php endif; ?></div>
          </li>
        <?php endforeach; ?>
      </ol>
    </div>
  </section>

  <?php if($notes!==''): ?><section class="panel"><header class="panel-header"><h2>Notes</h2></header><div class="panel-content prose-notes"><?= nl2br($view->escape($notes)) ?></div></section><?php endif; ?>
  <?php if($photoItems!==[]): ?><section class="panel"><header class="panel-header"><h2>Images</h2></header><div class="panel-content photo-grid detail-photos"><?php foreach($photoItems as $photo): ?><?php if(!is_array($photo))continue;$photoId=(int)($photo['id']??0);$url=(string)($photo['url']??('/photos/'.$photoId));?><a class="photo-tile" href="<?= $view->escape($url) ?>" target="_blank" rel="noopener"><img src="<?= $view->escape($url) ?>" alt="<?= $view->escape($photo['originalName']??$photo['original_name']??'Batch photograph') ?>" loading="lazy"><span>Open full size</span></a><?php endforeach; ?></div></section><?php endif; ?>

  <section class="panel save-setup-card"><div><span class="save-setup-icon"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#copy"></use></svg></span><div><h2>Reuse this batch setup</h2><p>Remember the strains and amounts, material defaults, complete bag stack, shared conditions, and every pass in order. Yield, notes, date, and photos stay out.</p></div></div><button class="button button--ghost" type="button" data-dialog-open="save-template-dialog">Save as template</button></section>

  <dialog class="modal-dialog" id="save-template-dialog"><form method="post" action="/batch/<?= $view->escape($id) ?>/template"><input type="hidden" name="_csrf" value="<?= $view->escape($csrf) ?>"><header><div><h2>Save batch settings</h2></div><button class="icon-button" type="button" data-dialog-close aria-label="Close"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#close"></use></svg></button></header><div class="field-group"><label for="new-template-name">Template name</label><input id="new-template-name" name="name" type="text" maxlength="80" required placeholder="e.g. 90µ flower setup"></div><footer><button class="button button--ghost" type="button" data-dialog-close>Cancel</button><button class="button button--primary" type="submit">Save template</button></footer></form></dialog>
  <dialog class="modal-dialog modal-dialog--danger" id="delete-batch-dialog"><form method="post" action="/batch/<?= $view->escape($id) ?>/delete"><input type="hidden" name="_csrf" value="<?= $view->escape($csrf) ?>"><header><div><span class="eyebrow danger-text">Permanent action</span><h2>Delete Batch #<?= $view->escape($id) ?>?</h2></div><button class="icon-button" type="button" data-dialog-close aria-label="Close"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#close"></use></svg></button></header><p>This removes the batch, its notes, and stored photographs. Presets and templates are not affected.</p><footer><button class="button button--ghost" type="button" data-dialog-close>Keep batch</button><button class="button button--danger" type="submit">Delete permanently</button></footer></form></dialog>
</div>
