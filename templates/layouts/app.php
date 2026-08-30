<?php

declare(strict_types=1);

$pageTitle = isset($title) && is_string($title) && $title !== ''
    ? $title . ' · Rosin Tracker'
    : 'Rosin Tracker';
$currentNav = isset($activeNav) && is_string($activeNav) ? $activeNav : '';
$flashItems = isset($flashes) && is_array($flashes) ? $flashes : [];
$csrf = isset($csrfToken) && is_string($csrfToken) ? $csrfToken : '';
$accentName = isset($accent) && is_string($accent) ? strtolower(trim($accent)) : 'blue';
$accentName = in_array($accentName, ['blue', 'green', 'red'], true) ? $accentName : 'blue';
$ownerName = 'Owner';
if (isset($owner) && is_array($owner) && isset($owner['username']) && is_scalar($owner['username'])) {
    $ownerName = trim((string) $owner['username']) ?: 'Owner';
} elseif (isset($owner) && is_object($owner) && isset($owner->username) && is_scalar($owner->username)) {
    $ownerName = trim((string) $owner->username) ?: 'Owner';
}
$ownerInitial = function_exists('mb_substr')
    ? mb_strtoupper(mb_substr($ownerName, 0, 1))
    : strtoupper(substr($ownerName, 0, 1));
$icons = $view->asset('/assets/icons.svg');
$logo = $view->asset('/assets/logo-old.png');
$navigation = [
    ['key' => 'dashboard', 'label' => 'Dashboard', 'href' => '/', 'icon' => 'dashboard'],
    ['key' => 'new-batch', 'label' => 'New Batch', 'href' => '/batches/new', 'icon' => 'plus'],
    ['key' => 'batches', 'label' => 'All Batches', 'href' => '/batches', 'icon' => 'batches'],
    ['key' => 'analytics', 'label' => 'Analytics', 'href' => '/analytics', 'icon' => 'analytics'],
    ['key' => 'presets', 'label' => 'Presets', 'href' => '/presets', 'icon' => 'presets'],
    ['key' => 'settings', 'label' => 'Settings', 'href' => '/settings', 'icon' => 'settings'],
];
?>
<!doctype html>
<html lang="en" data-accent="<?= $view->escape($accentName) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="dark">
  <meta name="theme-color" content="#1e1e1e">
  <meta name="description" content="A private, self-hosted rosin press tracker.">
  <title><?= $view->escape($pageTitle) ?></title>
  <link rel="icon" href="<?= $view->escape($logo) ?>" type="image/png">
  <link rel="stylesheet" href="<?= $view->escape($view->asset('/assets/app.css')) ?>">
  <script src="<?= $view->escape($view->asset('/assets/app.js')) ?>" defer></script>
</head>
<body class="app-body">
  <a class="skip-link" href="#main-content">Skip to main content</a>

  <header class="mobile-bar">
    <button
      class="icon-button mobile-menu-button"
      type="button"
      aria-label="Open navigation"
      aria-controls="app-sidebar"
      aria-expanded="false"
      data-sidebar-toggle
    >
      <svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#menu"></use></svg>
    </button>
    <a class="mobile-brand" href="/" aria-label="Rosin Tracker dashboard">
      <img class="app-logo" src="<?= $view->escape($logo) ?>" alt="" width="26" height="30">
      <span>Rosin Tracker</span>
    </a>
    <span class="mobile-owner" title="Signed in as <?= $view->escape($ownerName) ?>">
      <?= $view->escape($ownerInitial) ?>
    </span>
  </header>

  <button class="drawer-scrim" type="button" aria-label="Close navigation" data-sidebar-scrim></button>

  <div class="app-shell">
    <aside class="app-sidebar" id="app-sidebar" aria-label="Primary navigation" data-sidebar>
      <div class="sidebar-heading">
        <a class="brand" href="/" aria-label="Rosin Tracker dashboard">
          <span class="brand-mark"><img class="app-logo" src="<?= $view->escape($logo) ?>" alt="" width="38" height="44"></span>
          <span class="brand-copy">
            <strong>Rosin Tracker</strong>
          </span>
        </a>
        <button class="icon-button sidebar-close" type="button" aria-label="Close navigation" data-sidebar-close>
          <svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#close"></use></svg>
        </button>
      </div>

      <nav class="primary-nav" aria-label="Main menu">
        <?php foreach ($navigation as $item): ?>
          <?php $isActive = $currentNav === $item['key']; ?>
          <a
            class="nav-link<?= $isActive ? ' is-active' : '' ?>"
            href="<?= $view->escape($item['href']) ?>"
            <?= $isActive ? 'aria-current="page"' : '' ?>
          >
            <svg class="nav-icon" aria-hidden="true"><use href="<?= $view->escape($icons . '#' . $item['icon']) ?>"></use></svg>
            <span><?= $view->escape($item['label']) ?></span>
          </a>
        <?php endforeach; ?>
      </nav>

      <div class="sidebar-footer">
        <div class="owner-card">
          <span class="owner-avatar" aria-hidden="true"><?= $view->escape($ownerInitial) ?></span>
          <span class="owner-copy">
            <small>Signed in as</small>
            <strong title="<?= $view->escape($ownerName) ?>"><?= $view->escape($ownerName) ?></strong>
          </span>
        </div>
        <form action="/logout" method="post">
          <input type="hidden" name="_csrf" value="<?= $view->escape($csrf) ?>">
          <button class="button button--ghost button--full sidebar-logout" type="submit">
            <svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#logout"></use></svg>
            Log out
          </button>
        </form>
      </div>
    </aside>

    <div class="app-workspace">
      <?php if ($flashItems !== []): ?>
        <div class="flash-stack" aria-live="polite" aria-atomic="true">
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

      <main class="app-main" id="main-content" tabindex="-1">
        <?= $content ?>
      </main>
    </div>
  </div>
</body>
</html>
