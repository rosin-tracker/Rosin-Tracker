<?php

declare(strict_types=1);

$codes = isset($recoveryCodes) && is_array($recoveryCodes) ? array_values(array_filter($recoveryCodes, 'is_string')) : [];
?>
<div class="page-stack recovery-codes-page">
  <header class="page-header"><div><span class="eyebrow">Authentication</span><h1 id="auth-title">Save your recovery codes</h1><p>Each code works once if your authenticator is unavailable.</p></div></header>
  <section class="panel"><div class="panel-content recovery-codes-content">
    <div class="notice notice--warning" role="status"><span class="notice-icon"><svg aria-hidden="true"><use href="<?= $view->escape($view->asset('/assets/icons.svg')) ?>#warning-circle"></use></svg></span><p>This is the only time the full codes are shown. Store them somewhere separate from this server.</p></div>
    <ol class="recovery-code-list"><?php foreach ($codes as $code): ?><li><code><?= $view->escape($code) ?></code></li><?php endforeach; ?></ol>
    <a class="button button--primary" href="/settings">I saved the codes</a>
  </div></section>
</div>
