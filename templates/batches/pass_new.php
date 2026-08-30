<?php

declare(strict_types=1);

$editingPass = false;
?>
<div class="page-stack pass-page">
  <header class="page-header">
    <div>
      <span class="eyebrow">Batch #<?= $view->escape($batchId ?? '') ?></span>
      <h1>Add Pass <?= $view->escape($position ?? '') ?></h1>
      <p>The same filled bag stack, going through the press again.</p>
    </div>
    <a class="button button--ghost" href="/batch/<?= $view->escape($batchId ?? '') ?>">Back to batch</a>
  </header>
  <?php require __DIR__ . '/_pass_form.php'; ?>
</div>
