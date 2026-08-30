<?php

declare(strict_types=1);

$csrf = isset($csrfToken) && is_string($csrfToken) ? $csrfToken : '';
$errorItems = isset($errors) && is_array($errors) ? $errors : [];
$blockedSeconds = isset($blockedFor) && is_numeric($blockedFor) ? max(0, (int) $blockedFor) : 0;
$message = '';
foreach ($errorItems as $error) {
    $candidate = is_array($error) ? reset($error) : $error;
    if (is_scalar($candidate) && trim((string) $candidate) !== '') {
        $message = (string) $candidate;
        break;
    }
}
?>
<div class="auth-heading"><span class="eyebrow">Second step</span><h1 id="auth-title">Enter your authenticator code</h1><p>Use the current six-digit code, or one unused recovery code.</p></div>
<?php if ($message !== ''): ?><div class="error-summary" role="alert"><strong>Code not accepted.</strong><p><?= $view->escape($message) ?></p></div><?php endif; ?>
<?php if ($blockedSeconds > 0): ?><div class="notice notice--warning" role="status"><p>Too many attempts. Try again in <?= $view->escape($blockedSeconds) ?> seconds.</p></div><?php endif; ?>
<form class="auth-form" action="/login/totp" method="post">
  <input type="hidden" name="_csrf" value="<?= $view->escape($csrf) ?>">
  <div class="field-group"><label for="authentication-code">Authenticator or recovery code</label><input id="authentication-code" name="code" type="text" inputmode="text" autocomplete="one-time-code" autocapitalize="characters" spellcheck="false" maxlength="23" required autofocus><span class="field-help">Recovery codes work with or without their hyphens.</span></div>
  <button class="button button--primary button--large button--full" type="submit" <?= $blockedSeconds > 0 ? 'disabled' : '' ?>>Verify and continue<svg aria-hidden="true"><use href="<?= $view->escape($view->asset('/assets/icons.svg')) ?>#arrow-right"></use></svg></button>
</form>
<p class="auth-recovery"><a href="/login">Cancel and return to sign-in</a></p>
