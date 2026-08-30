#!/usr/bin/env bash
set -Eeuo pipefail
umask 027

fail() {
  printf 'Rosin Tracker setup failed: %s\n' "$*" >&2
  exit 1
}

readonly app_user=alw
readonly app_root=/srv/rosin-tracker-php
readonly public_root=${app_root}/app/public
readonly data_root=/var/lib/rosin-tracker-php
readonly php_version=8.4
readonly php_fpm_service=php${php_version}-fpm.service
readonly php_fpm_socket=/run/php/php${php_version}-fpm.sock
readonly nginx_site=/etc/nginx/sites-available/rosin-tracker
readonly nginx_enabled=/etc/nginx/sites-enabled/rosin-tracker
readonly nginx_app_snippet=/etc/nginx/snippets/rosin-tracker-app.conf
readonly maintenance_file=/run/rosin-tracker-maintenance
readonly origin_bind=${ROSIN_TRACKER_ORIGIN_BIND:-127.0.0.1}
readonly origin_port=${ROSIN_TRACKER_ORIGIN_PORT:-5000}
readonly raw_proxy_allowlist=${ROSIN_TRACKER_PROXY_ALLOWLIST:-}

validate_ipv4() {
  local address=$1
  local first second third fourth extra
  IFS=. read -r first second third fourth extra <<<"${address}"
  [[ -z ${extra:-} && -n ${first:-} && -n ${second:-} \
      && -n ${third:-} && -n ${fourth:-} ]] || return 1
  local octet
  for octet in "${first}" "${second}" "${third}" "${fourth}"; do
    [[ ${octet} =~ ^[0-9]{1,3}$ ]] || return 1
    (( 10#${octet} <= 255 )) || return 1
  done
}

validate_ipv4_network() {
  local network=$1
  local address=${network%%/*}
  local prefix=
  validate_ipv4 "${address}" || return 1
  if [[ ${network} == */* ]]; then
    prefix=${network#*/}
    [[ ${prefix} =~ ^[0-9]{1,2}$ ]] || return 1
    (( 10#${prefix} >= 1 && 10#${prefix} <= 32 )) || return 1
  fi
  [[ ${address} != 0.0.0.0 ]] || return 1
}

[[ ${EUID} -eq 0 ]] || fail 'run this script with sudo'
[[ ${app_root} == /srv/rosin-tracker-php ]] || fail 'unsafe application root'
[[ ${data_root} == /var/lib/rosin-tracker-php ]] || fail 'unsafe data root'
[[ ${origin_port} =~ ^[0-9]{1,5}$ ]] || fail 'the origin port is invalid'
(( 10#${origin_port} >= 1024 && 10#${origin_port} <= 65534 )) \
  || fail 'the origin port must be between 1024 and 65534'
readonly health_port=$((10#${origin_port} + 1))
validate_ipv4 "${origin_bind}" || fail 'ROSIN_TRACKER_ORIGIN_BIND must be an IPv4 address'

declare -a proxy_allowlist=()
if [[ -n ${raw_proxy_allowlist//[[:space:],]/} ]]; then
  normalized_allowlist=${raw_proxy_allowlist//,/ }
  read -r -a proxy_allowlist <<<"${normalized_allowlist}"
  ((${#proxy_allowlist[@]} > 0)) || fail 'the reverse-proxy allowlist is empty'
  for network in "${proxy_allowlist[@]}"; do
    validate_ipv4_network "${network}" \
      || fail "invalid IPv4 address or CIDR in ROSIN_TRACKER_PROXY_ALLOWLIST: ${network}"
  done
fi

if [[ ${origin_bind} == 127.0.0.1 ]]; then
  ((${#proxy_allowlist[@]} == 0)) \
    || fail 'a proxy allowlist requires a non-loopback ROSIN_TRACKER_ORIGIN_BIND'
else
  ((${#proxy_allowlist[@]} > 0)) \
    || fail 'a non-loopback origin requires ROSIN_TRACKER_PROXY_ALLOWLIST'
fi

id "${app_user}" >/dev/null 2>&1 \
  || fail "the deployment user does not exist: ${app_user}"
readonly app_group=$(id -gn "${app_user}")

source /etc/os-release
[[ ${ID:-} == debian && ${VERSION_ID:-} == 13 ]] \
  || fail 'this setup supports Debian 13'

for command_name in apt-get flock getent install ln realpath runuser systemctl; do
  command -v "${command_name}" >/dev/null \
    || fail "required command is unavailable: ${command_name}"
done

export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install --yes --no-install-recommends \
  ca-certificates \
  curl \
  iproute2 \
  nginx \
  php-cli \
  php-curl \
  php-fpm \
  php-gd \
  php-intl \
  php-mbstring \
  php-sqlite3 \
  php-zip \
  sqlite3 \
  util-linux

for command_name in curl ip nginx php sqlite3; do
  command -v "${command_name}" >/dev/null \
    || fail "required command is unavailable after package installation: ${command_name}"
done
getent group www-data >/dev/null 2>&1 || fail 'the www-data group does not exist'

exec 9>/run/lock/rosin-tracker-deploy.lock
flock --nonblock 9 || fail 'a Rosin Tracker setup or deployment is already running'

installed_php_version=$(php -r 'printf("%d.%d", PHP_MAJOR_VERSION, PHP_MINOR_VERSION);')
[[ ${installed_php_version} == "${php_version}" ]] \
  || fail "expected PHP ${php_version}, found ${installed_php_version}"
systemctl list-unit-files "${php_fpm_service}" >/dev/null \
  || fail "the PHP-FPM service is unavailable: ${php_fpm_service}"

if [[ ${origin_bind} != 127.0.0.1 && ${origin_bind} != 0.0.0.0 ]]; then
  ip -o -4 address show | awk -v target="${origin_bind}" '
    {
      split($4, address, "/")
      if (address[1] == target) {
        found = 1
      }
    }
    END { exit(found ? 0 : 1) }
  ' \
    || fail "the requested origin address is not assigned to this VM: ${origin_bind}"
fi

install -d -o root -g root -m 0755 "${app_root}"
install -d -o root -g www-data -m 2750 "${app_root}/app"
install -d -o root -g www-data -m 2750 "${public_root}"
install -d -o www-data -g "${app_group}" -m 2750 "${data_root}"
install -d -o www-data -g "${app_group}" -m 2750 "${data_root}/database"
install -d -o www-data -g "${app_group}" -m 2750 "${data_root}/uploads"
install -d -o www-data -g "${app_group}" -m 2750 "${data_root}/backups"
install -d -o root -g root -m 0700 "${data_root}/deploy"
chown root:root "${data_root}/deploy"
chmod g-s "${data_root}/deploy"
chmod 0700 "${data_root}/deploy"
[[ $(stat -c '%U:%G:%a' -- "${data_root}/deploy") == root:root:700 ]] \
  || fail 'the deployment state directory permissions could not be secured'

work_directory=$(mktemp -d /tmp/rosin-tracker-setup.XXXXXXXX)
cleanup() {
  local status=$?
  trap - EXIT INT TERM
  set +e
  if [[ ${work_directory} == /tmp/rosin-tracker-setup.* \
      && -d ${work_directory} && ! -L ${work_directory} ]]; then
    rm -rf --one-file-system -- "${work_directory}"
  else
    printf 'Refusing unsafe setup cleanup: %s\n' "${work_directory}" >&2
    status=1
  fi
  exit "${status}"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

cat >"${work_directory}/rosin-tracker.ini" <<'PHP_INI'
date.timezone = Europe/Copenhagen
display_errors = Off
expose_php = Off
log_errors = On
max_execution_time = 120
max_file_uploads = 12
memory_limit = 256M
post_max_size = 2M
session.cookie_httponly = 1
session.cookie_samesite = Lax
session.use_only_cookies = 1
session.use_strict_mode = 1
upload_max_filesize = 2M
PHP_INI
install -o root -g root -m 0644 \
  "${work_directory}/rosin-tracker.ini" \
  "/etc/php/${php_version}/fpm/conf.d/99-rosin-tracker.ini"
install -o root -g root -m 0644 \
  "${work_directory}/rosin-tracker.ini" \
  "/etc/php/${php_version}/cli/conf.d/99-rosin-tracker.ini"

# Remove the superseded configuration created by pre-production setup scripts.
# The exact paths are fixed and the replacement files above have already been
# installed, so this cannot affect another PHP version or application.
rm -f -- \
  "/etc/php/${php_version}/fpm/conf.d/99-rosin-tracker-php.ini" \
  "/etc/php/${php_version}/cli/conf.d/99-rosin-tracker-php.ini"

cat >"${work_directory}/nginx-app-snippet" <<'NGINX_APP'
root /srv/rosin-tracker-php/app/public;
index index.php;
charset utf-8;
server_tokens off;

# Most application requests contain only small forms. Photograph uploads get
# a larger limit only on the two batch routes below.
client_max_body_size 2m;

location ^~ /assets/ {
    if ($uri ~ "/\.") {
        return 404;
    }
    try_files $uri =404;
    access_log off;
    add_header Cache-Control "public, max-age=31536000, immutable" always;
    add_header X-Content-Type-Options "nosniff" always;
}

location = /batches/new {
    client_max_body_size 128m;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root/index.php;
    fastcgi_param SCRIPT_NAME /index.php;
    fastcgi_param DOCUMENT_ROOT $document_root;
    fastcgi_param HTTP_PROXY "";
    fastcgi_param PHP_VALUE "post_max_size=128M\nupload_max_filesize=24M";
    fastcgi_pass unix:/run/php/php8.4-fpm.sock;
}

location ~ ^/batch/[1-9][0-9]*/edit$ {
    client_max_body_size 128m;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root/index.php;
    fastcgi_param SCRIPT_NAME /index.php;
    fastcgi_param DOCUMENT_ROOT $document_root;
    fastcgi_param HTTP_PROXY "";
    fastcgi_param PHP_VALUE "post_max_size=128M\nupload_max_filesize=24M";
    fastcgi_pass unix:/run/php/php8.4-fpm.sock;
}

location / {
    try_files $uri $uri/ /index.php?$query_string;
}

location = /index.php {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root/index.php;
    fastcgi_param SCRIPT_NAME /index.php;
    fastcgi_param DOCUMENT_ROOT $document_root;
    fastcgi_param HTTP_PROXY "";
    fastcgi_pass unix:/run/php/php8.4-fpm.sock;
}

location ~ \.php(?:/|$) {
    return 404;
}

location ~ /\. {
    deny all;
}
NGINX_APP
install -o root -g root -m 0644 \
  "${work_directory}/nginx-app-snippet" "${nginx_app_snippet}"

{
  if [[ ${origin_bind} == 0.0.0.0 ]]; then
    printf 'server {\n    listen 0.0.0.0:%s default_server;\n' "${origin_port}"
  else
    cat <<NGINX_START
server {
    listen 127.0.0.1:${origin_port} default_server;
NGINX_START
    if [[ ${origin_bind} != 127.0.0.1 ]]; then
      printf '    listen %s:%s;\n' "${origin_bind}" "${origin_port}"
    fi
  fi
  cat <<'NGINX_ACCESS'
    server_name _;

    allow 127.0.0.1;
NGINX_ACCESS
  for network in "${proxy_allowlist[@]}"; do
    printf '    allow %s;\n' "${network}"
  done
  cat <<'NGINX_SITE'
    deny all;

    if (-f /run/rosin-tracker-maintenance) {
        return 503;
    }

    include /etc/nginx/snippets/rosin-tracker-app.conf;
}
NGINX_SITE
  printf '\nserver {\n    listen 127.0.0.1:%s;\n' "${health_port}"
  cat <<'NGINX_HEALTH'
    server_name _;
    allow 127.0.0.1;
    deny all;
    access_log off;
    include /etc/nginx/snippets/rosin-tracker-app.conf;
}
NGINX_HEALTH
} >"${work_directory}/nginx-site"

install -o root -g root -m 0644 "${work_directory}/nginx-site" "${nginx_site}"
ln -sfn -- "${nginx_site}" "${nginx_enabled}"

# Disable only the known older Rosin Tracker site. Leave any unrelated site
# untouched. Also disable Debian's unchanged default-site symlink so it does
# not expose a welcome page on port 80.
old_nginx_enabled=/etc/nginx/sites-enabled/rosin-tracker-php
if [[ -L ${old_nginx_enabled} \
    && $(realpath -- "${old_nginx_enabled}") == /etc/nginx/sites-available/rosin-tracker-php ]]; then
  rm -f -- "${old_nginx_enabled}"
fi
debian_default_enabled=/etc/nginx/sites-enabled/default
if [[ -L ${debian_default_enabled} \
    && $(realpath -- "${debian_default_enabled}") == /etc/nginx/sites-available/default ]]; then
  rm -f -- "${debian_default_enabled}"
fi

if [[ ! -e ${public_root}/index.php ]]; then
  cat >"${work_directory}/index.php" <<'PHP_PLACEHOLDER'
<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Rosin Tracker</title>
</head>
<body>
  <main>
    <h1>Rosin Tracker</h1>
    <p>The server is ready for its first application release.</p>
  </main>
</body>
</html>
PHP_PLACEHOLDER
  install -o root -g www-data -m 0640 \
    "${work_directory}/index.php" "${public_root}/index.php"
fi

nginx -t
systemctl enable --now "${php_fpm_service}"
[[ -S ${php_fpm_socket} ]] || fail 'the PHP-FPM socket was not created'
systemctl enable --now nginx.service
systemctl restart "${php_fpm_service}"
# A restart is required when replacing the legacy wildcard listener: old workers
# retain 0.0.0.0:5000 during a reload and block the new scoped listeners.
systemctl restart nginx.service

curl --fail --silent --show-error --max-time 10 \
  "http://127.0.0.1:${health_port}/" >/dev/null \
  || fail 'the local origin did not respond'
rm -f -- "${maintenance_file}"

printf '\nRosin Tracker origin is ready on 127.0.0.1:%s.\n' "${origin_port}"
printf 'Deployment health checks use loopback-only port %s.\n' "${health_port}"
if [[ ${origin_bind} != 127.0.0.1 ]]; then
  printf 'The origin also listens on %s:%s and permits only the configured proxy IPv4 ranges.\n' \
    "${origin_bind}" "${origin_port}"
else
  printf '%s\n' 'The origin is loopback-only. A reverse proxy on another host cannot reach it.'
fi
printf 'Application code: %s\n' "${app_root}/app"
printf 'Private data: %s (not web-accessible)\n' "${data_root}"
printf '%s\n' 'Create the owner account before publishing a first-run instance.'
printf '%s\n' 'The TLS terminator is responsible for HTTPS and the HSTS header.'
printf '%s\n' 'This script did not alter the firewall, router, TLS, HSTS, or sudoers.'
