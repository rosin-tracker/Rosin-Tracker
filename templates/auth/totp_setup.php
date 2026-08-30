<?php

declare(strict_types=1);

$csrf = isset($csrfToken) && is_string($csrfToken) ? $csrfToken : '';
$setup = isset($enrollment) && is_array($enrollment) ? $enrollment : [];
$qrDataUri = isset($setup['qrDataUri']) && is_string($setup['qrDataUri']) ? $setup['qrDataUri'] : '';
$manualKey = isset($setup['manualKey']) && is_string($setup['manualKey']) ? $setup['manualKey'] : '';
$errorItems = isset($errors) && is_array($errors) ? $errors : [];
$firstError = '';
foreach ($errorItems as $error) {
    $candidate = is_array($error) ? reset($error) : $error;
    if (is_scalar($candidate) && trim((string) $candidate) !== '') { $firstError = (string) $candidate; break; }
}
?>
<div class="page-stack authentication-setup-page">
  <header class="page-header"><div><span class="eyebrow">Authentication</span><h1 id="auth-title">Set up an authenticator</h1><p>Scan once, verify once, then save the recovery codes.</p></div></header>
  <section class="panel authentication-enrollment"><div class="panel-content authentication-enrollment__grid">
    <div class="totp-qr"><?php if ($qrDataUri !== ''): ?><img src="<?= $view->escape($qrDataUri) ?>" width="240" height="240" alt="QR code containing the Rosin Tracker authenticator setup key"><?php endif; ?></div>
    <div class="authentication-enrollment__steps">
      <ol><li>Open your authenticator app and scan the QR code.</li><li>If scanning is unavailable, enter this key manually: <code class="manual-secret"><?= $view->escape($manualKey) ?></code></li><li>Enter the current six-digit code below.</li></ol>
      <?php if ($firstError !== ''): ?><div class="error-summary" role="alert"><strong>Setup was not confirmed.</strong><p><?= $view->escape($firstError) ?></p></div><?php endif; ?>
      <form class="settings-form settings-form--accent" action="/settings/authentication/totp/setup" method="post"><input type="hidden" name="_csrf" value="<?= $view->escape($csrf) ?>"><div class="field-group"><label for="totp-confirmation-code">Six-digit code</label><input id="totp-confirmation-code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,8}" maxlength="8" required autofocus></div><button class="button button--primary" type="submit">Enable authenticator</button><button class="button button--ghost" type="submit" formnovalidate formaction="/settings/authentication/totp/cancel">Cancel</button></form>
    </div>
  </div></section>
</div>
