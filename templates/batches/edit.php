<?php $resolvedBatchId = isset($batchId) ? $batchId : (is_array($batch ?? null) ? ($batch['id'] ?? 0) : 0); ?>
<div class="page-stack">
  <header class="page-header"><div><span class="eyebrow">Batch #<?= $view->escape($resolvedBatchId) ?></span><h1>Edit Batch</h1></div><a class="button button--ghost" href="/batch/<?= $view->escape($resolvedBatchId) ?>">Back to batch</a></header>
  <?php $isEdit = true; $batchId = $resolvedBatchId; require __DIR__ . '/_form.php'; ?>
</div>
