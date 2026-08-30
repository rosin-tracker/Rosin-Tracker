<?php

declare(strict_types=1);

$editingPass = true;
?>
<div class="page-stack pass-page">
  <header class="page-header">
    <div>
      <span class="eyebrow">Batch #<?= $view->escape($batchId ?? '') ?></span>
      <h1>Edit Pass <?= $view->escape($position ?? '') ?></h1>
      <p>Only this pass changes; the batch and its bag stack stay untouched.</p>
    </div>
    <a class="button button--ghost" href="/batch/<?= $view->escape($batchId ?? '') ?>">Back to batch</a>
  </header>
  <?php require __DIR__ . '/_pass_form.php'; ?>
</div>
