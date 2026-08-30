<?php

declare(strict_types=1);

$pageTitle = isset($title) && is_string($title) && $title !== ''
    ? $title . ' · Rosin Tracker'
    : 'Rosin Tracker';
$flashItems = isset($flashes) && is_array($flashes) ? $flashes : [];
$accentName = isset($accent) && is_string($accent) ? strtolower(trim($accent)) : 'blue';
$accentName = in_array($accentName, ['blue', 'green', 'red'], true) ? $accentName : 'blue';
$widePanel = isset($wideAuth) && $wideAuth === true;
$logo = $view->asset('/assets/logo-old.png');
?>
<!doctype html>
<html lang="en" data-accent="<?= $view->escape($accentName) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="dark">
  <meta name="theme-color" content="#1e1e1e">
  <meta name="description" content="Sign in to your private Rosin Tracker.">
  <title><?= $view->escape($pageTitle) ?></title>
  <link rel="icon" href="<?= $view->escape($logo) ?>" type="image/png">
  <link rel="stylesheet" href="<?= $view->escape($view->asset('/assets/app.css')) ?>">
  <script src="<?= $view->escape($view->asset('/assets/app.js')) ?>" defer></script>
</head>
<body class="auth-body">
  <a class="skip-link" href="#auth-content">Skip to form</a>
  <main class="auth-shell" id="auth-content">
    <section class="auth-panel<?= $widePanel ? ' auth-panel--wide' : '' ?>" aria-labelledby="auth-title">
      <a class="auth-brand" href="/" aria-label="Rosin Tracker home">
        <span class="auth-brand__mark">
          <img class="app-logo" src="<?= $view->escape($logo) ?>" alt="" width="45" height="52">
        </span>
        <span>
          <strong>Rosin Tracker</strong>
        </span>
      </a>

      <?php if ($flashItems !== []): ?>
        <div class="flash-stack flash-stack--auth" aria-live="polite" aria-atomic="true">
          <?php foreach ($flashItems as $flash): ?>
            <?php
            $flashType = is_array($flash) && isset($flash['type']) && is_scalar($flash['type'])
                ? strtolower((string) $flash['type'])
                : 'info';
            $flashType = in_array($flashType, ['success', 'error', 'warning', 'info'], true) ? $flashType : 'info';
            $flashMessage = is_array($flash) && isset($flash['message']) && is_scalar($flash['message'])
                ? (string) $flash['message']
                : (is_scalar($flash) ? (string) $flash : '');
            ?>
            <?php if ($flashMessage !== ''): ?>
              <div class="flash flash--<?= $view->escape($flashType) ?>" role="<?= $flashType === 'error' ? 'alert' : 'status' ?>">
                <span class="flash-dot" aria-hidden="true"></span>
                <span><?= $view->escape($flashMessage) ?></span>
              </div>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?= $content ?>
    </section>
  </main>
</body>
</html>
