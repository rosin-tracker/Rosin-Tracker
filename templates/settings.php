<?php

declare(strict_types=1);

$csrf = isset($csrfToken) && is_string($csrfToken) ? $csrfToken : '';
$units = isset($unitSystem) && $unitSystem === 'imperial' ? 'imperial' : 'metric';
$selectedTimezone = isset($timezone) && is_string($timezone) ? $timezone : 'Europe/Copenhagen';
$timezoneItems = isset($timezones) && is_array($timezones) ? $timezones : [];
$analyticsMaterials = isset($analyticsMaterialOptions) && is_array($analyticsMaterialOptions)
    ? array_values(array_filter($analyticsMaterialOptions, 'is_string'))
    : [];
$selectedAnalyticsMaterial = isset($analyticsDefaultMaterial) && is_string($analyticsDefaultMaterial)
    ? $analyticsDefaultMaterial
    : '';
$backupItems = isset($backups) && is_array($backups) ? $backups : [];
$restoreAvailable = isset($restoreEnabled) && $restoreEnabled === true;
$selectedAccent = isset($accent) && in_array($accent, ['blue', 'green', 'red'], true) ? $accent : 'blue';
$icons = $view->asset('/assets/icons.svg');
$ownerName = isset($owner) && is_array($owner) && isset($owner['username']) ? (string) $owner['username'] : 'Owner';
$ownerInitial = function_exists('mb_substr') ? mb_strtoupper(mb_substr($ownerName, 0, 1)) : strtoupper(substr($ownerName, 0, 1));
$authenticationState = isset($authentication) && is_array($authentication) ? $authentication : [];
$activeMethod = ($authenticationState['activeMethod'] ?? 'local') === 'oidc' ? 'oidc' : 'local';
$localAuthentication = isset($authenticationState['local']) && is_array($authenticationState['local'])
    ? $authenticationState['local']
    : ['available' => true, 'totpEnabled' => false, 'recoveryCodesRemaining' => 0];
$provider = isset($authenticationState['provider']) && is_array($authenticationState['provider']) ? $authenticationState['provider'] : null;
$callbackUrl = isset($authenticationState['callbackUrl']) && is_string($authenticationState['callbackUrl'])
    ? $authenticationState['callbackUrl']
    : (isset($oidcCallbackUrl) && is_string($oidcCallbackUrl) ? $oidcCallbackUrl : '');
$composerReady = ($authenticationState['composerReady'] ?? $oidcComposerReady ?? true) === true;
$httpsReady = ($authenticationState['httpsReady'] ?? str_starts_with($callbackUrl, 'https://')) === true;
$authenticationChangeAuthorized = isset($authenticationChangeAuthorized)
    && $authenticationChangeAuthorized === true;
$applicationUrlValue = isset($applicationUrl) && is_string($applicationUrl) ? $applicationUrl : $callbackUrl;
$applicationUrlManagedByServer = isset($applicationUrlManaged) && $applicationUrlManaged === true;
$applicationUrlError = isset($authenticationErrors['application_url'])
    && is_scalar($authenticationErrors['application_url'])
    ? (string) $authenticationErrors['application_url']
    : '';
$applicationUrlIsHttps = str_starts_with($applicationUrlValue, 'https://');
$authValues = isset($authenticationValues) && is_array($authenticationValues) ? $authenticationValues : [];
$authErrors = isset($authenticationErrors) && is_array($authenticationErrors) ? $authenticationErrors : [];
$providerType = (string) ($authValues['provider_type'] ?? $provider['type'] ?? 'entra');
$providerType = in_array($providerType, ['entra', 'generic'], true) ? $providerType : 'entra';
$providerValue = static function (string $formKey, string $providerKey) use ($authValues, $provider): string {
    $value = $authValues[$formKey] ?? ($provider !== null ? ($provider[$providerKey] ?? '') : '');
    return is_scalar($value) ? (string) $value : '';
};
$authErrorMessages = [];
foreach ($authErrors as $errorKey => $error) {
    if ($errorKey === 'application_url') {
        continue;
    }
    foreach (is_array($error) ? $error : [$error] as $message) {
        if (is_scalar($message) && trim((string) $message) !== '') {
            $authErrorMessages[] = (string) $message;
        }
    }
}
$formatSize = static function (int $bytes): string {
    return $bytes >= 1048576 ? number_format($bytes / 1048576, 1) . ' MB'
        : ($bytes >= 1024 ? number_format($bytes / 1024, 1) . ' KB' : $bytes . ' B');
};
?>
<div class="page-stack settings-page">
  <header class="page-header">
    <div><h1>Settings</h1></div>
  </header>

  <section class="panel settings-card">
    <header class="panel-header"><div class="settings-title"><span class="section-icon"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#settings"></use></svg></span><div><h2>Appearance &amp; Units</h2></div></div></header>
    <div class="panel-content settings-rows">
      <div class="settings-row"><div><strong>Theme</strong></div><span class="status-chip">Dark theme</span></div>
      <div class="settings-row settings-row--accent">
        <div><strong>Accent</strong></div>
        <form class="accent-choices" action="/settings/accent" method="post" aria-label="Accent color">
          <input type="hidden" name="_csrf" value="<?= $view->escape($csrf) ?>">
          <?php foreach (['blue' => 'Blue', 'green' => 'Green', 'red' => 'Red'] as $accentValue => $accentLabel): ?>
            <button class="accent-choice<?= $selectedAccent === $accentValue ? ' is-active' : '' ?>" type="submit" name="accent" value="<?= $view->escape($accentValue) ?>" aria-pressed="<?= $selectedAccent === $accentValue ? 'true' : 'false' ?>"><span class="accent-choice__swatch accent-choice__swatch--<?= $view->escape($accentValue) ?>" aria-hidden="true"></span><span><?= $view->escape($accentLabel) ?></span></button>
          <?php endforeach; ?>
        </form>
      </div>
      <div class="settings-row"><div><strong>Unit System</strong><p>Switching display units never changes stored measurements.</p></div><form class="segmented-control" action="/settings/units" method="post"><input type="hidden" name="_csrf" value="<?= $view->escape($csrf) ?>"><button type="submit" name="unit_system" value="metric" class="<?= $units === 'metric' ? 'is-active' : '' ?>">Metric</button><button type="submit" name="unit_system" value="imperial" class="<?= $units === 'imperial' ? 'is-active' : '' ?>">Imperial</button></form></div>
      <div class="settings-row"><div><strong>Default material for Dashboard &amp; Analytics</strong></div><form class="settings-inline-form settings-inline-form--timezone" action="/settings/analytics-material" method="post"><input type="hidden" name="_csrf" value="<?= $view->escape($csrf) ?>"><label class="visually-hidden" for="analytics-default-material">Default material for Dashboard and Analytics</label><select id="analytics-default-material" name="analytics_default_material"><option value="" <?= $selectedAnalyticsMaterial === '' ? 'selected' : '' ?>>All materials</option><?php foreach ($analyticsMaterials as $material): ?><option value="<?= $view->escape($material) ?>" <?= strcasecmp($material, $selectedAnalyticsMaterial) === 0 ? 'selected' : '' ?>><?= $view->escape($material) ?></option><?php endforeach; ?></select><button class="button button--ghost button--small" type="submit">Save</button></form></div>
      <div class="settings-row"><div><strong>Reporting Timezone</strong><p>Batch dates and daily analytics are shown in this timezone.</p></div><form class="settings-inline-form settings-inline-form--timezone" action="/settings" method="post"><input type="hidden" name="_csrf" value="<?= $view->escape($csrf) ?>"><input type="hidden" name="unit_system" value="<?= $view->escape($units) ?>"><label class="visually-hidden" for="reporting-timezone">Reporting timezone</label><select id="reporting-timezone" name="timezone"><?php foreach ($timezoneItems as $timezoneItem): ?><?php if (!is_scalar($timezoneItem)) { continue; } ?><option value="<?= $view->escape($timezoneItem) ?>" <?= (string) $timezoneItem === $selectedTimezone ? 'selected' : '' ?>><?= $view->escape($timezoneItem) ?></option><?php endforeach; ?></select><button class="button button--ghost button--small" type="submit">Save</button></form></div>
    </div>
  </section>

  <section class="panel settings-card authentication-card">
    <header class="panel-header"><div class="settings-title"><span class="section-icon"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#shield"></use></svg></span><div><h2>Authentication</h2></div></div></header>
    <div class="panel-content authentication-stack">
      <?php if ($authErrorMessages !== []): ?><div class="error-summary" role="alert" tabindex="-1"><strong>Authentication settings were not changed.</strong><ul><?php foreach (array_unique($authErrorMessages) as $message): ?><li><?= $view->escape($message) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
      <div class="owner-summary authentication-summary"><span class="owner-avatar"><?= $view->escape($ownerInitial) ?></span><div><small>Signed in as</small><strong><?= $view->escape($ownerName) ?></strong></div><span class="status-chip"><?= $activeMethod === 'oidc' ? $view->escape((string) ($provider['displayName'] ?? 'OpenID Connect')) : 'Local password' ?></span></div>

      <div class="authentication-method application-url-method">
        <div class="authentication-method__heading"><div><strong>Application URL</strong></div><span class="status-chip"><?= $applicationUrlIsHttps ? 'HTTPS' : 'HTTP' ?></span></div>
        <code class="application-url-current"><?= $view->escape($applicationUrlValue) ?></code>
        <?php if ($applicationUrlManagedByServer): ?>
          <p class="settings-note">Managed by the server configuration.</p>
        <?php elseif ($activeMethod === 'local'): ?>
          <details class="settings-details application-url-details" <?= $applicationUrlError !== '' ? 'open' : '' ?>>
            <summary>Change URL</summary>
            <form class="settings-form application-url-form" action="/settings/application-url" method="post">
              <input type="hidden" name="_csrf" value="<?= $view->escape($csrf) ?>">
              <div class="field-group application-url-form__wide"><label for="application-url">Application URL</label><input id="application-url" name="application_url" type="url" maxlength="2048" inputmode="url" value="<?= $view->escape($applicationUrlValue) ?>" aria-describedby="application-url-help<?= $applicationUrlError !== '' ? ' application-url-error' : '' ?>" aria-invalid="<?= $applicationUrlError !== '' ? 'true' : 'false' ?>" required><span class="field-help" id="application-url-help">Use the HTTPS origin without a path, for example <code>https://rosin-tracker.com</code>.</span><?php if ($applicationUrlError !== ''): ?><span class="field-error" id="application-url-error" role="alert" tabindex="-1" data-error-summary><?= $view->escape($applicationUrlError) ?></span><?php endif; ?></div>
              <div class="authentication-confirmation application-url-form__wide"><div class="field-group"><label for="application-url-password">Current local password</label><div class="input-with-action input-with-action--password"><input id="application-url-password" name="current_password" type="password" autocomplete="current-password" required><button class="input-action input-action--password" type="button" data-password-toggle="application-url-password" data-password-name="current password" data-password-visible="false" aria-controls="application-url-password" aria-label="Show current password" title="Show current password"><svg class="password-toggle__icon password-toggle__icon--show" aria-hidden="true"><use href="<?= $view->escape($icons) ?>#eye"></use></svg><svg class="password-toggle__icon password-toggle__icon--hide" aria-hidden="true"><use href="<?= $view->escape($icons) ?>#eye-slash"></use></svg></button></div></div><?php if (($localAuthentication['totpEnabled'] ?? false) === true): ?><div class="field-group"><label for="application-url-code">Current authenticator or recovery code</label><input id="application-url-code" name="current_code" autocomplete="one-time-code" required></div><?php endif; ?></div>
              <div class="application-url-form__actions application-url-form__wide"><button class="button button--primary" type="submit">Save URL</button></div>
            </form>
          </details>
        <?php else: ?>
          <p class="settings-note">Switch to local sign-in before changing this URL.</p>
        <?php endif; ?>
      </div>

      <?php if ($activeMethod === 'local'): ?>
        <div class="authentication-method">
          <div class="authentication-method__heading"><div><strong>Local password</strong></div><span class="status-chip">Active</span></div>
          <details class="settings-details"><summary>Change password</summary><form class="settings-form settings-form--accent" action="/settings/password" method="post"><input type="hidden" name="_csrf" value="<?= $view->escape($csrf) ?>"><div class="field-group"><label for="current-password">Current password</label><input id="current-password" name="current_password" type="password" autocomplete="current-password" maxlength="128" required></div><div class="field-group"><label for="new-password">New password</label><input id="new-password" name="new_password" type="password" minlength="8" maxlength="128" autocomplete="new-password" required><span class="field-help">Use at least 8 characters; a longer unique passphrase is better.</span></div><div class="field-group"><label for="confirm-new-password">Confirm new password</label><input id="confirm-new-password" name="new_password_confirmation" type="password" minlength="8" maxlength="128" autocomplete="new-password" required></div><button class="button button--primary" type="submit">Change password</button></form></details>
          <div class="authentication-subsection">
            <div><strong>Authenticator app</strong><p><?= ($localAuthentication['totpEnabled'] ?? false) ? 'A code is required after the password.' : 'Optionally require a six-digit code after the password.' ?></p></div>
            <?php if (($localAuthentication['totpEnabled'] ?? false) === true): ?>
              <span class="status-chip">Enabled</span><p class="settings-note"><?= $view->escape((int) ($localAuthentication['recoveryCodesRemaining'] ?? 0)) ?> unused recovery codes remain.</p>
              <details class="settings-details"><summary>Recovery and removal</summary><form class="settings-form" action="/settings/authentication/totp/recovery-codes" method="post"><input type="hidden" name="_csrf" value="<?= $view->escape($csrf) ?>"><div class="field-group"><label for="recovery-current-code">Current authenticator or recovery code</label><input id="recovery-current-code" name="code" autocomplete="one-time-code" required></div><button class="button button--ghost" type="submit">Create new recovery codes</button></form><form class="settings-form danger-zone" action="/settings/authentication/totp/disable" method="post"><input type="hidden" name="_csrf" value="<?= $view->escape($csrf) ?>"><div class="field-group"><label for="disable-totp-code">Current authenticator or recovery code</label><input id="disable-totp-code" name="code" autocomplete="one-time-code" required></div><button class="button button--danger" type="submit">Disable authenticator</button></form></details>
            <?php else: ?>
              <details class="settings-details"><summary>Set up authenticator</summary><form class="settings-form" action="/settings/authentication/totp/start" method="post"><input type="hidden" name="_csrf" value="<?= $view->escape($csrf) ?>"><div class="field-group"><label for="totp-start-password">Current password</label><input id="totp-start-password" name="current_password" type="password" autocomplete="current-password" required></div><button class="button button--ghost" type="submit">Continue setup</button></form></details>
            <?php endif; ?>
          </div>
        </div>
      <?php else: ?>
        <div class="notice" role="status"><span class="notice-icon"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#shield"></use></svg></span><p>Local password and authenticator controls are dormant while OpenID Connect is active. Terminal recovery can restore local sign-in.</p></div>
      <?php endif; ?>

      <div class="authentication-method authentication-method--oidc">
        <div class="authentication-method__heading"><div><strong>OpenID Connect</strong><p>Microsoft Entra ID or another standards-compatible provider.</p></div><span class="status-chip"><?= $provider === null ? 'Not configured' : (($provider['active'] ?? false) ? 'Active' : (($provider['linked'] ?? false) ? 'Linked' : 'Configured')) ?></span></div>
        <?php if (!$composerReady): ?><div class="notice notice--warning" role="alert"><span class="notice-icon"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#warning-circle"></use></svg></span><p>OpenID dependencies are not installed. Run <code>composer install --no-dev --classmap-authoritative</code> first.</p></div><?php endif; ?>
        <?php if (!$httpsReady): ?><div class="notice notice--warning" role="status"><span class="notice-icon"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#warning-circle"></use></svg></span><p>The current external URL uses HTTP. Provider setup can be reviewed here, but validation requires HTTPS outside localhost.</p></div><?php endif; ?>
        <?php if ($provider !== null): ?>
          <ol class="authentication-progress" aria-label="OpenID Connect setup progress"><li class="is-complete"><strong>1</strong><span>Validated</span></li><li class="<?= ($provider['linked'] ?? false) ? 'is-complete' : 'is-current' ?>"><strong>2</strong><span>Linked</span></li><li class="<?= ($provider['active'] ?? false) ? 'is-complete' : (($provider['linked'] ?? false) ? 'is-current' : '') ?>"><strong>3</strong><span>Active</span></li></ol>
          <div class="provider-summary"><div><small>Provider</small><strong><?= $view->escape((string) $provider['displayName']) ?></strong></div><div><small>Issuer</small><code><?= $view->escape((string) $provider['verifiedIssuer']) ?></code></div><?php if (($provider['linked'] ?? false) === true): ?><div><small>Linked subject</small><code><?= $view->escape((string) ($provider['identity']['subject'] ?? '')) ?></code></div><?php endif; ?></div>
          <div class="authentication-actions">
            <?php if (($provider['linked'] ?? false) !== true): ?><form action="/settings/authentication/oidc/link" method="post"><input type="hidden" name="_csrf" value="<?= $view->escape($csrf) ?>"><button class="button button--primary" type="submit">Connect owner account</button></form>
            <?php elseif (($provider['active'] ?? false) !== true): ?><form action="/settings/authentication/oidc/activate" method="post"><input type="hidden" name="_csrf" value="<?= $view->escape($csrf) ?>"><button class="button button--primary" type="submit">Use provider for sign-in</button></form>
            <?php else: ?><form action="/settings/authentication/local" method="post"><input type="hidden" name="_csrf" value="<?= $view->escape($csrf) ?>"><button class="button button--ghost" type="submit">Switch back to local sign-in</button></form><?php endif; ?>
          </div>
          <?php if (($provider['active'] ?? false) !== true && !$authenticationChangeAuthorized): ?><p class="settings-note">Enter the current local credentials below and select Save &amp; validate before linking or activation.</p><?php endif; ?>
        <?php endif; ?>

        <details class="settings-details provider-configuration" <?= $provider === null || $authErrorMessages !== [] ? 'open' : '' ?>>
          <summary><?= $provider === null ? 'Configure a provider' : 'Edit provider configuration' ?></summary>
          <form class="settings-form settings-form--accent provider-form" action="/settings/authentication/oidc" method="post">
            <input type="hidden" name="_csrf" value="<?= $view->escape($csrf) ?>">
            <fieldset class="provider-type-control provider-form__wide"><legend>Provider type</legend><div class="segmented-control segmented-control--provider"><label><input type="radio" name="provider_type" value="entra" <?= $providerType === 'entra' ? 'checked' : '' ?>><span>Microsoft Entra ID</span></label><label><input type="radio" name="provider_type" value="generic" <?= $providerType === 'generic' ? 'checked' : '' ?>><span>Other OpenID Connect</span></label></div></fieldset>
            <div class="field-group"><label for="oidc-display-name">Name shown on sign-in</label><input id="oidc-display-name" name="display_name" maxlength="80" value="<?= $view->escape($providerValue('display_name', 'displayName')) ?>" placeholder="Microsoft Entra ID"></div>
            <div class="provider-fields provider-fields--entra"><div class="field-group"><label for="oidc-tenant-id">Directory (tenant) ID</label><input id="oidc-tenant-id" name="tenant_id" value="<?= $view->escape($providerValue('tenant_id', 'tenantId')) ?>" placeholder="00000000-0000-0000-0000-000000000000" autocomplete="off"><span class="field-help">Required for Microsoft Entra ID. One specific tenant is used instead of <code>common</code>.</span></div></div>
            <div class="provider-fields provider-fields--generic"><div class="field-group"><label for="oidc-issuer">Issuer URL</label><input id="oidc-issuer" name="issuer" type="url" maxlength="2048" value="<?= $view->escape($providerValue('issuer', 'issuer')) ?>" placeholder="https://id.example.com"><span class="field-help">Required for another provider. Discovery metadata is loaded from this issuer.</span></div></div>
            <div class="field-group"><label for="oidc-client-id">Application (client) ID</label><input id="oidc-client-id" name="client_id" maxlength="512" value="<?= $view->escape($providerValue('client_id', 'clientId')) ?>" autocomplete="off" required></div>
            <div class="field-group"><label for="oidc-client-secret">Client secret</label><input id="oidc-client-secret" name="client_secret" type="password" maxlength="4096" autocomplete="new-password" <?= $provider === null ? 'required' : '' ?>><span class="field-help"><?= $provider === null ? 'Stored encrypted outside the web root.' : 'A secret is stored. Leave this blank to keep it.' ?></span></div>
            <div class="field-group provider-form__wide"><label for="oidc-callback">Redirect URI</label><div class="input-with-action"><input id="oidc-callback" type="url" value="<?= $view->escape($callbackUrl) ?>" readonly><button class="input-action" type="button" data-copy-target="oidc-callback">Copy</button></div><span class="field-help">Register this exact URI as a Web redirect URI. HTTPS is required outside localhost.</span></div>
            <details class="settings-details settings-details--nested provider-form__wide"><summary>Advanced</summary><div class="field-group"><label for="oidc-scopes">Scopes</label><input id="oidc-scopes" name="scopes" value="<?= $view->escape($providerValue('scopes', 'scopes') ?: 'openid profile email') ?>"><span class="field-help"><code>openid</code> is required. Rosin Tracker does not request Microsoft Graph access.</span></div><div class="field-group"><label for="oidc-token-auth">Token endpoint authentication</label><select id="oidc-token-auth" name="token_auth_method"><option value="client_secret_post" <?= $providerValue('token_auth_method', 'tokenAuthMethod') !== 'client_secret_basic' ? 'selected' : '' ?>>Client secret in request body</option><option value="client_secret_basic" <?= $providerValue('token_auth_method', 'tokenAuthMethod') === 'client_secret_basic' ? 'selected' : '' ?>>HTTP Basic</option></select><span class="field-help">Microsoft Entra ID uses the request-body method.</span></div></details>
            <div class="authentication-confirmation provider-form__wide"><div class="field-group"><label for="oidc-current-password">Current local password</label><input id="oidc-current-password" name="current_password" type="password" autocomplete="current-password" required></div><?php if (($localAuthentication['totpEnabled'] ?? false) === true): ?><div class="field-group"><label for="oidc-current-code">Current authenticator or recovery code</label><input id="oidc-current-code" name="current_code" autocomplete="one-time-code" required></div><?php endif; ?></div>
            <div class="provider-form__actions"><button class="button button--primary" type="submit" <?= !$composerReady || !$httpsReady || $activeMethod !== 'local' ? 'disabled' : '' ?>>Save &amp; validate</button><p class="settings-note">Saving validates discovery metadata and confirms this sensitive change, but does not change sign-in. Linking and activation are separate steps.</p></div>
          </form>
        </details>
        <?php if ($provider !== null): ?><details class="settings-details danger-zone"><summary>Remove provider</summary><p>This switches sign-in back to the local password and removes the linked identity.</p><form action="/settings/authentication/oidc/delete" method="post"><input type="hidden" name="_csrf" value="<?= $view->escape($csrf) ?>"><button class="button button--danger" type="submit" <?= !$authenticationChangeAuthorized ? 'disabled' : '' ?>>Remove OpenID provider</button></form></details><?php endif; ?>
      </div>
      <p class="settings-note"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#shield"></use></svg>Provider and authenticator secrets are encrypted with a private key stored outside downloadable backups.</p>
    </div>
  </section>

  <section class="panel settings-card">
    <header class="panel-header panel-header--action"><div class="settings-title"><span class="section-icon"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#archive"></use></svg></span><div><h2>Data Backup</h2><p>ZIP archives include SQLite, photos, settings, presets, and templates.</p></div></div><form action="/settings/backups" method="post"><input type="hidden" name="_csrf" value="<?= $view->escape($csrf) ?>"><button class="button button--primary" type="submit"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#archive"></use></svg>Create backup</button></form></header>
    <div class="panel-content">
      <?php if ($backupItems === []): ?><div class="compact-empty"><span class="empty-icon"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#archive"></use></svg></span><div><strong>No application backups yet</strong><p>Create one before major changes or upgrades.</p></div></div>
      <?php else: ?><div class="backup-list"><?php foreach ($backupItems as $backup): ?><?php if (!is_array($backup)) { continue; } $filename = (string) ($backup['filename'] ?? 'backup.zip'); $modified = $backup['modifiedAt'] ?? $backup['createdAt'] ?? null; try { $modifiedLabel = is_numeric($modified) ? date('M j, Y · H:i', (int) $modified) : (new DateTimeImmutable((string) $modified))->format('M j, Y · H:i'); } catch (Throwable) { $modifiedLabel = 'Created recently'; } ?><article><span class="backup-icon"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#file-zip"></use></svg></span><div><strong><?= $view->escape($filename) ?></strong><small><?= $view->escape($modifiedLabel) ?> · <?= $view->escape($formatSize((int) ($backup['byteSize'] ?? 0))) ?></small></div><a class="button button--ghost button--small" href="/settings/backups/<?= $view->escape(rawurlencode($filename)) ?>"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#download"></use></svg>Download</a></article><?php endforeach; ?></div><?php endif; ?>
      <?php if ($restoreAvailable): ?><details class="settings-details restore-details"><summary>Restore from a backup</summary><form class="settings-form" action="/settings/restore" method="post" enctype="multipart/form-data" data-confirm="Restoring replaces the current application data. Continue?"><input type="hidden" name="_csrf" value="<?= $view->escape($csrf) ?>"><div class="notice notice--warning"><span class="notice-icon"><svg aria-hidden="true"><use href="<?= $view->escape($icons) ?>#warning-circle"></use></svg></span><p>Restore replaces current data. Create a fresh backup first.</p></div><div class="field-group"><label for="restore-file">Rosin Tracker backup ZIP</label><input id="restore-file" name="backup" type="file" accept=".zip,application/zip" required></div><button class="button button--danger" type="submit">Restore backup</button></form></details><?php endif; ?>
    </div>
  </section>
</div>
