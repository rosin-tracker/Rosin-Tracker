# Rosin Tracker

Rosin Tracker is a compact, self-hosted journal for recording filled bag stacks, press passes, combined yield, photographs, and reusable batch setups. It uses server-rendered PHP and SQLite, with no frontend build step or external asset CDN.

## Features

- A single private owner account with local-password or OpenID Connect sign-in
- Optional authenticator-app verification with one-use recovery codes
- Batches containing multiple strains, a fixed multi-layer bag stack, and multiple press passes
- Combined batch yield, searchable history, dashboard summaries, and material-focused analytics
- Private JPEG, PNG, and WebP photographs, normalized before storage
- Saved materials, strains, complete bag options, chart styles, and reusable batch templates
- Metric and US display units backed by canonical stored values
- Versioned SQLite migrations, verified snapshot backups, and a v1 data importer

Curing logs, curing reminders, multiple users, and web-based backup restoration are not currently included.

## Requirements

- PHP 8.4 or newer
- Composer 2
- PHP extensions: Curl, Fileinfo, GD, Iconv, Intl, JSON, Mbstring, OpenSSL, PDO, PDO SQLite, Session, Sodium, SQLite3, and Zip
- A web server with PHP-FPM or another production-capable PHP SAPI

Production dependencies are fixed by `composer.lock`. Install them before serving the application:

```sh
composer install --no-dev --classmap-authoritative
```

Release archives should contain the resulting `vendor/` directory. Production servers should not resolve or download Composer packages during deployment.

## Installation

1. Place the application in a non-public directory, such as `/srv/rosin-tracker/app`.
2. Install the locked Composer dependencies.
3. Give the PHP worker read access to the application and read/write access to a private data directory.
4. Set the web root to `public/` and route requests for nonexistent files to `public/index.php`.
5. Configure the environment variables below for the PHP-FPM service.
6. Run `php bin/check-runtime.php` as the web-server user.
7. Open the application and create the owner account.

The default system data directory is `/var/lib/rosin-tracker-php`. When that directory is absent, local state is created under the ignored `var/` directory. Database files, uploads, backups, and the authentication key must remain outside the public web root.

Until the owner exists, restrict a new installation to loopback or a trusted administrator network. The first browser to complete Setup becomes the sole owner.

### Debian VM helpers

The `ops/` directory contains reviewed setup and deployment helpers for Debian 13. The secure default exposes the origin only on loopback:

```sh
sudo bash ops/setup-vm.sh
```

When a reverse proxy runs on another machine, bind to the VM's LAN address and explicitly allow only the proxy address or CIDR:

```sh
sudo env \
  ROSIN_TRACKER_ORIGIN_BIND=192.168.1.15 \
  ROSIN_TRACKER_PROXY_ALLOWLIST=192.168.1.20 \
  bash ops/setup-vm.sh
```

Keep the public proxy route disabled until the owner account has been created. The setup helper reserves the next loopback port for private deployment health checks; never publish or proxy that port. TLS and HSTS remain the responsibility of the browser-facing reverse proxy.

Deployment accepts only a timestamped release archive with a matching SHA-256 and release ID:

```sh
sudo bash ops/deploy-vm.sh \
  EXPECTED_SHA256 \
  /home/alw/rosin-tracker-YYYYMMDD-HHMMSS.tar.gz \
  YYYYMMDD-HHMMSS
```

The deployment helper enables maintenance mode, checks and snapshots the database, validates the release in isolation, verifies the upgraded live database, and rolls both code and database back on failure. It does not configure the firewall, router, TLS, HSTS, or sudoers.

### Environment variables

- `ROSIN_TRACKER_DATA_DIR` — private database, upload, backup, and security-data root
- `ROSIN_TRACKER_EXTERNAL_URL` — optional operator-managed canonical Application URL; when set, it overrides the saved URL and makes the web setting read-only
- `ROSIN_TRACKER_TIMEZONE` — initial reporting timezone; defaults to `Europe/Copenhagen`
- `ROSIN_TRACKER_SECURITY_KEY_FILE` — optional absolute path to the 32-byte authentication encryption key; defaults to `DATA_DIR/security/auth.key`

The key is created automatically with owner-only `0600` permissions. It is not stored in SQLite or included in downloadable backups. Preserve a separate protected copy: encrypted authenticator and OpenID client secrets cannot be recovered without it.

## Authentication

Local passwords are 8–128 characters. An authenticator app can be enabled from **Settings → Authentication**. It uses six-digit, 30-second TOTP codes and provides eight one-use recovery codes.

Microsoft Entra ID or another standards-compatible OpenID Connect provider can be configured entirely from the same settings page. Configuration follows three stages: save and validate provider metadata, link the existing owner through a real provider sign-in, and activate provider sign-in. Provider identities are bound by validated issuer and stable subject, not by email address or display name.

Set the browser-facing **Application URL** before configuring OpenID Connect. For example, `https://rosin-tracker.example` produces this redirect URI:

```text
https://rosin-tracker.example/auth/oidc/callback
```

Register the displayed URI exactly with the identity provider. Public deployments require HTTPS.

If provider sign-in is unavailable, restore the dormant local sign-in method from the server terminal:

```sh
php bin/auth-force-local.php
```

If the owner has also lost every authenticator and recovery code, disable the local second factor during recovery:

```sh
php bin/auth-force-local.php --disable-totp
```

These commands do not delete the provider configuration. Authentication-method, password, and second-factor changes revoke older signed-in sessions.

## Reverse proxy and network security

Terminate TLS at the reverse proxy, forward every application path, and overwrite untrusted inbound forwarding headers. Restrict the backend listener so only the proxy and administrators can reach it; do not expose an unencrypted PHP/Nginx origin port to the wider network.

Rosin Tracker deliberately does not infer its Application URL from `Host`, `Forwarded`, or `X-Forwarded-*` headers. Configure the canonical URL in the application or with `ROSIN_TRACKER_EXTERNAL_URL`. A trusted-proxy list is unnecessary unless a future feature begins using original client IP addresses.

Keep first-run Setup inaccessible to untrusted clients, remove temporary passwordless-sudo rules after deployment, and apply security updates to PHP, Composer dependencies, the web server, and the operating system.

## Backups and recovery

Settings can create and download a verified ZIP snapshot containing the SQLite database, private photographs, and a checksum manifest. Backups exclude the authentication encryption key.

Restoration is not exposed in the web interface. Until a tested restore workflow is added, retain both the backup archive and a protected copy of the encryption key, and practice recovery on an isolated system before relying on it. Do not unpack a backup into the live data directory while the application is running.

## Importing v1 data

The command-line importer accepts a complete JSON 2.0 export from Rosin Tracker v1. It imports batches, photographs, presets, and related provenance. It does not import users, sessions, curing logs, or curing reminders. An identical rerun is a no-op.

Validate the export first:

```sh
php bin/import-legacy-v1.php --input /private/path/rosin-tracker-export.json --dry-run
```

After reviewing the reported counts and assumptions, apply it:

```sh
php bin/import-legacy-v1.php --input /private/path/rosin-tracker-export.json --apply
```

Apply mode creates and verifies a normal backup before writing. Imported values are interpreted as grams, Celsius, PSI, millimetres, tons, and seconds; pressure is converted to bar, while zero pressure or humidity becomes “not recorded.” Because v1 stored only one set of press conditions, an imported press count becomes that many ordered passes with identical settings.

Keep source exports outside the web root and remove server copies after reconciliation.

## Verification

Run the checks with production dependencies installed:

```sh
php bin/check-runtime.php
php bin/check-database-baseline.php
php bin/check-external-url.php
php bin/check-application-url-setting.php
php bin/check-analytics.php
php bin/check-normalized-press-storage.php
php bin/smoke-test.php
```

`check-runtime.php` uses the configured data directory and verifies required extensions, migrations, SQLite integrity, and foreign keys. The other integration and smoke checks use isolated temporary data and remove it when finished.

Before a production update, also lint every PHP file, run `composer validate --strict`, run `composer audit --locked --no-dev`, create an off-host backup, and verify that the backend is reachable only through the intended proxy or management network.

## Third-party assets

The interface bundles Phosphor Icons in the Fill weight locally. It uses no icon font or CDN. Attribution is recorded in `THIRD-PARTY-NOTICES.txt`.
