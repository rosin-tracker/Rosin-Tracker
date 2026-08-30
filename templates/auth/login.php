<?php

declare(strict_types=1);

$formValues = isset($values) && is_array($values) ? $values : [];
$errorItems = isset($errors) && is_array($errors) ? $errors : [];
$csrf = isset($csrfToken) && is_string($csrfToken) ? $csrfToken : '';
$usernameValue = isset($formValues['username']) && is_scalar($formValues['username']) ? (string) $formValues['username'] : '';
$blockedSeconds = isset($blockedFor) && is_numeric($blockedFor) ? max(0, (int) $blockedFor) : 0;
$noticeText = isset($notice) && is_string($notice) ? $notice : '';
$authenticationState = isset($authentication) && is_array($authentication) ? $authentication : [];
$activeMethod = ($authenticationState['activeMethod'] ?? 'local') === 'oidc' ? 'oidc' : 'local';
$provider = isset($authenticationState['provider']) && is_array($authenticationState['provider']) ? $authenticationState['provider'] : null;
$totpEnabled = isset($authenticationState['local']) && is_array($authenticationState['local'])
    && ($authenticationState['local']['totpEnabled'] ?? false) === true;
$fieldError = static function (string $field) use ($errorItems): string {
    $error = $errorItems[$field] ?? '';
    if (is_array($error)) {
        $error = reset($error);
    }
    return is_scalar($error) ? (string) $error : '';
};
$generalErrors = [];
foreach ($errorItems as $error) {
    foreach (is_array($error) ? $error : [$error] as $message) {
        if (is_scalar($message) && trim((string) $message) !== '') {
            $generalErrors[] = (string) $message;
        }
    }
}
?>
<div class="auth-heading auth-heading--compact">
  <h1 id="auth-title">Sign in to Rosin Tracker</h1>
</div>

<?php if ($noticeText !== ''): ?>
  <div class="notice notice--success" role="status"><p><?= $view->escape($noticeText) ?></p></div>
<?php endif; ?>

<?php if ($generalErrors !== []): ?>
  <div class="error-summary" role="alert" tabindex="-1" data-error-summary><strong>Sign-in failed.</strong><ul><?php foreach (array_unique($generalErrors) as $message): ?><li><?= $view->escape($message) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?php if ($blockedSeconds > 0): ?>
  <div class="notice notice--warning" role="status" data-login-block data-blocked-for="<?= $view->escape($blockedSeconds) ?>"><span class="notice-icon"><svg aria-hidden="true"><use href="<?= $view->escape($view->asset('/assets/icons.svg')) ?>#warning-circle"></use></svg></span><p>Too many attempts. Try again in <strong data-block-countdown><?= $view->escape($blockedSeconds) ?> seconds</strong>.</p></div>
<?php endif; ?>

<?php if ($activeMethod === 'oidc' && $provider !== null): ?>
  <form class="auth-form" action="/login/oidc" method="post">
    <input type="hidden" name="_csrf" value="<?= $view->escape($csrf) ?>">
    <button class="button button--primary button--large button--full" type="submit" <?= $blockedSeconds > 0 ? 'disabled' : '' ?>>Continue with <?= $view->escape((string) $provider['displayName']) ?><svg aria-hidden="true"><use href="<?= $view->escape($view->asset('/assets/icons.svg')) ?>#arrow-right"></use></svg></button>
  </form>
  <p class="auth-recovery">If the provider is unavailable, the server operator can restore local sign-in from the terminal.</p>
<?php else: ?>
  <form class="auth-form" action="/login" method="post" data-login-form>
    <input type="hidden" name="_csrf" value="<?= $view->escape($csrf) ?>">
    <div class="field-group">
      <label for="username">Username</label>
      <input id="username" name="username" type="text" value="<?= $view->escape($usernameValue) ?>" autocomplete="username" maxlength="64" required autofocus aria-describedby="<?= $fieldError('username') !== '' ? 'username-error' : '' ?>" <?= $fieldError('username') !== '' ? 'aria-invalid="true"' : '' ?>>
      <?php if ($fieldError('username') !== ''): ?><span class="field-error" id="username-error"><?= $view->escape($fieldError('username')) ?></span><?php endif; ?>
    </div>
    <div class="field-group">
      <label for="password">Password</label>
      <div class="input-with-action input-with-action--password"><input id="password" name="password" type="password" autocomplete="current-password" maxlength="128" required aria-describedby="<?= $fieldError('password') !== '' ? 'password-error' : '' ?>" <?= $fieldError('password') !== '' ? 'aria-invalid="true"' : '' ?>><button class="input-action input-action--password" type="button" data-password-toggle="password" data-password-visible="false" aria-controls="password" aria-label="Show password" title="Show password"><svg class="password-toggle__icon password-toggle__icon--show" aria-hidden="true"><use href="<?= $view->escape($view->asset('/assets/icons.svg')) ?>#eye"></use></svg><svg class="password-toggle__icon password-toggle__icon--hide" aria-hidden="true"><use href="<?= $view->escape($view->asset('/assets/icons.svg')) ?>#eye-slash"></use></svg></button></div>
      <?php if ($fieldError('password') !== ''): ?><span class="field-error" id="password-error"><?= $view->escape($fieldError('password')) ?></span><?php endif; ?>
    </div>
    <?php if ($totpEnabled): ?><p class="field-help auth-factor-note">Your authenticator code is requested on the next step.</p><?php endif; ?>
    <button class="button button--primary button--large button--full" type="submit" data-login-submit <?= $blockedSeconds > 0 ? 'disabled' : '' ?>>Sign in<svg aria-hidden="true"><use href="<?= $view->escape($view->asset('/assets/icons.svg')) ?>#arrow-right"></use></svg></button>
  </form>
<?php endif; ?>
