<?php

declare(strict_types=1);

$formValues = isset($values) && is_array($values)
    ? $values
    : (isset($form) && is_array($form) ? $form : (isset($old) && is_array($old) ? $old : []));
$errorItems = isset($errors) && is_array($errors) ? $errors : [];
$csrf = isset($csrfToken) && is_string($csrfToken) ? $csrfToken : '';
$usernameValue = isset($formValues['username']) && is_scalar($formValues['username'])
    ? (string) $formValues['username']
    : '';
$fieldError = static function (string $field) use ($errorItems): string {
    $error = $errorItems[$field] ?? '';
    if (is_array($error)) {
        $error = reset($error);
    }
    return is_scalar($error) ? (string) $error : '';
};
$generalErrors = [];
foreach ($errorItems as $key => $error) {
    foreach (is_array($error) ? $error : [$error] as $message) {
        if (is_scalar($message) && trim((string) $message) !== '') {
            $generalErrors[] = (string) $message;
        }
    }
}
?>
<div class="auth-heading auth-heading--compact">
  <h1 id="auth-title">Create your account</h1>
</div>

<?php if ($generalErrors !== []): ?>
  <div class="error-summary" role="alert" tabindex="-1" data-error-summary>
    <strong>We could not create the account.</strong>
    <ul>
      <?php foreach (array_values(array_unique($generalErrors)) as $message): ?>
        <li><?= $view->escape($message) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<form class="auth-form" action="/setup" method="post">
  <input type="hidden" name="_csrf" value="<?= $view->escape($csrf) ?>">

  <div class="field-group">
    <label for="username">Username</label>
    <input
      id="username"
      name="username"
      type="text"
      value="<?= $view->escape($usernameValue) ?>"
      autocomplete="username"
      minlength="2"
      maxlength="64"
      required
      autofocus
      <?= $fieldError('username') !== '' ? 'aria-describedby="username-error"' : '' ?>
      <?= $fieldError('username') !== '' ? 'aria-invalid="true"' : '' ?>
    >
    <?php if ($fieldError('username') !== ''): ?>
      <span class="field-error" id="username-error"><?= $view->escape($fieldError('username')) ?></span>
    <?php endif; ?>
  </div>

  <div class="field-group">
    <label for="password">Password</label>
    <div class="input-with-action input-with-action--password">
      <input
        id="password"
        name="password"
        type="password"
        autocomplete="new-password"
        minlength="8"
        maxlength="128"
        required
        aria-describedby="password-help<?= $fieldError('password') !== '' ? ' password-error' : '' ?>"
        <?= $fieldError('password') !== '' ? 'aria-invalid="true"' : '' ?>
      >
      <button class="input-action input-action--password" type="button" data-password-toggle="password" data-password-visible="false" aria-controls="password" aria-label="Show password" title="Show password">
        <svg class="password-toggle__icon password-toggle__icon--show" aria-hidden="true"><use href="<?= $view->escape($view->asset('/assets/icons.svg')) ?>#eye"></use></svg>
        <svg class="password-toggle__icon password-toggle__icon--hide" aria-hidden="true"><use href="<?= $view->escape($view->asset('/assets/icons.svg')) ?>#eye-slash"></use></svg>
      </button>
    </div>
    <span class="field-help" id="password-help">Use a long, unique passphrase you can keep safely.</span>
    <?php if ($fieldError('password') !== ''): ?>
      <span class="field-error" id="password-error"><?= $view->escape($fieldError('password')) ?></span>
    <?php endif; ?>
  </div>

  <div class="field-group">
    <label for="password-confirmation">Confirm password</label>
    <div class="input-with-action input-with-action--password">
      <input
        id="password-confirmation"
        name="password_confirmation"
        type="password"
        autocomplete="new-password"
        minlength="8"
        maxlength="128"
        required
        <?= $fieldError('password_confirmation') !== '' ? 'aria-describedby="password-confirmation-error"' : '' ?>
        <?= $fieldError('password_confirmation') !== '' ? 'aria-invalid="true"' : '' ?>
      >
      <button class="input-action input-action--password" type="button" data-password-toggle="password-confirmation" data-password-name="confirmed password" data-password-visible="false" aria-controls="password-confirmation" aria-label="Show confirmed password" title="Show confirmed password">
        <svg class="password-toggle__icon password-toggle__icon--show" aria-hidden="true"><use href="<?= $view->escape($view->asset('/assets/icons.svg')) ?>#eye"></use></svg>
        <svg class="password-toggle__icon password-toggle__icon--hide" aria-hidden="true"><use href="<?= $view->escape($view->asset('/assets/icons.svg')) ?>#eye-slash"></use></svg>
      </button>
    </div>
    <?php if ($fieldError('password_confirmation') !== ''): ?>
      <span class="field-error" id="password-confirmation-error"><?= $view->escape($fieldError('password_confirmation')) ?></span>
    <?php endif; ?>
  </div>

  <button class="button button--primary button--large button--full" type="submit">
    Create account
    <svg aria-hidden="true"><use href="<?= $view->escape($view->asset('/assets/icons.svg')) ?>#arrow-right"></use></svg>
  </button>
</form>
