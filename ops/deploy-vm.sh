#!/usr/bin/env bash
set -Eeuo pipefail
umask 027

readonly app_user=alw
readonly app_root=/srv/rosin-tracker-php
readonly current=${app_root}/app
readonly data_root=/var/lib/rosin-tracker-php
readonly live_database=${data_root}/database/rosin-tracker.sqlite
readonly deployment_state_root=${data_root}/deploy
readonly php_fpm_service=php8.4-fpm.service
readonly health_url=${ROSIN_TRACKER_HEALTH_URL:-http://127.0.0.1:5001}
readonly release_retention=${ROSIN_TRACKER_RELEASE_RETENTION:-3}
readonly maintenance_file=/run/rosin-tracker-maintenance

incoming=
previous=
failed=
database_snapshot=
verification_directory=
swapped=0
fpm_stopped=0
database_existed=0
deployment_committed=0
retention_validated=0
maintenance_enabled=0
retention_count=0

fail() {
  printf 'Rosin Tracker deployment failed: %s\n' "$*" >&2
  exit 1
}

remove_verification_directory() {
  [[ -n ${verification_directory} ]] || return 0
  case ${verification_directory} in
    /tmp/rosin-tracker-db-check.*) ;;
    *)
      printf 'Refusing to clean an unexpected verification directory: %s\n' \
        "${verification_directory}" >&2
      return 1
      ;;
  esac
  [[ -d ${verification_directory} && ! -L ${verification_directory} ]] || return 1
  rm -f -- "${verification_directory}/rosin-tracker.sqlite"
  rmdir -- "${verification_directory}"
  verification_directory=
}

safe_release_directory() {
  local candidate=$1
  local resolved basename
  [[ -d ${candidate} && ! -L ${candidate} ]] || return 1
  resolved=$(realpath -- "${candidate}") || return 1
  [[ $(dirname -- "${resolved}") == "${app_root}" ]] || return 1
  basename=$(basename -- "${resolved}")
  [[ ${basename} =~ ^\.(incoming|previous|failed)-[0-9]{8}-[0-9]{6}$ ]] || return 1
  printf '%s\n' "${resolved}"
}

remove_release_directory() {
  local candidate=$1
  local resolved
  resolved=$(safe_release_directory "${candidate}") \
    || { printf 'Refusing unsafe release cleanup: %s\n' "${candidate}" >&2; return 1; }
  rm -rf --one-file-system -- "${resolved}"
}

safe_database_snapshot() {
  local candidate=$1
  local resolved basename
  [[ -f ${candidate} && ! -L ${candidate} ]] || return 1
  resolved=$(realpath -- "${candidate}") || return 1
  [[ $(dirname -- "${resolved}") == "${deployment_state_root}" ]] || return 1
  basename=$(basename -- "${resolved}")
  [[ ${basename} =~ ^database-before-[0-9]{8}-[0-9]{6}\.sqlite$ ]] || return 1
  printf '%s\n' "${resolved}"
}

remove_database_snapshot() {
  local candidate=$1
  local resolved
  resolved=$(safe_database_snapshot "${candidate}") \
    || { printf 'Refusing unsafe database-snapshot cleanup: %s\n' "${candidate}" >&2; return 1; }
  rm -f -- "${resolved}"
}

prune_release_directories() {
  local kind=$1
  local -a releases=()
  local candidate
  while IFS= read -r candidate; do
    [[ -n ${candidate} ]] && releases+=("${candidate}")
  done < <(
    find "${app_root}" -mindepth 1 -maxdepth 1 -type d \
      -name ".${kind}-????????-??????" -printf '%f\n' | sort -r
  )
  local index
  for ((index=retention_count; index<${#releases[@]}; index++)); do
    remove_release_directory "${app_root}/${releases[index]}" || return 1
  done
}

prune_database_snapshots() {
  local -a snapshots=()
  local candidate
  while IFS= read -r candidate; do
    [[ -n ${candidate} ]] && snapshots+=("${candidate}")
  done < <(
    find "${deployment_state_root}" -mindepth 1 -maxdepth 1 -type f \
      -name 'database-before-????????-??????.sqlite' -printf '%f\n' | sort -r
  )
  local index
  for ((index=retention_count; index<${#snapshots[@]}; index++)); do
    remove_database_snapshot "${deployment_state_root}/${snapshots[index]}" || return 1
  done
}

enable_maintenance() {
  local candidate=/run/.rosin-tracker-maintenance.$$
  [[ ${maintenance_file} == /run/rosin-tracker-maintenance ]] || return 1
  [[ ! -e ${candidate} && ! -L ${candidate} ]] || return 1
  install -o root -g root -m 0644 /dev/null "${candidate}"
  printf 'release=%s\n' "${release_id}" >"${candidate}"
  mv -f -- "${candidate}" "${maintenance_file}"
  maintenance_enabled=1
}

disable_maintenance() {
  [[ ${maintenance_file} == /run/rosin-tracker-maintenance ]] || return 1
  rm -f -- "${maintenance_file}"
  maintenance_enabled=0
}

restore_database_snapshot() {
  local restore_file=${deployment_state_root}/.restore-${release_id}.sqlite
  [[ ${data_root} == /var/lib/rosin-tracker-php ]] || return 1
  if [[ ${database_existed} -eq 1 ]]; then
    [[ -f ${database_snapshot} && ! -L ${database_snapshot} ]] || return 1
    [[ ! -e ${restore_file} && ! -L ${restore_file} ]] || return 1
    install -o www-data -g "${app_group}" -m 0660 \
      "${database_snapshot}" "${restore_file}"
    rm -f -- "${live_database}-wal" "${live_database}-shm"
    mv -f -- "${restore_file}" "${live_database}"
  else
    rm -f -- \
      "${live_database}" "${live_database}-wal" "${live_database}-shm"
  fi
}

rollback() {
  local rollback_status=0
  remove_verification_directory || rollback_status=1

  if [[ ${swapped} -eq 1 ]]; then
    if [[ ${fpm_stopped} -eq 0 ]]; then
      systemctl stop "${php_fpm_service}" || rollback_status=1
      fpm_stopped=1
    fi
    if [[ -d ${current} && ! -L ${current} && ! -e ${failed} ]]; then
      mv -- "${current}" "${failed}" || rollback_status=1
    else
      printf 'Could not preserve the failed release during rollback.\n' >&2
      rollback_status=1
    fi
    if [[ -d ${previous} && ! -L ${previous} && ! -e ${current} ]]; then
      mv -- "${previous}" "${current}" || rollback_status=1
    else
      printf 'Could not restore the previous application directory.\n' >&2
      rollback_status=1
    fi
    restore_database_snapshot || rollback_status=1
    swapped=0
  elif [[ -n ${incoming} && -d ${incoming} && ! -L ${incoming} && ! -e ${failed} ]]; then
    mv -- "${incoming}" "${failed}" || rollback_status=1
  fi

  if [[ ${fpm_stopped} -eq 1 ]]; then
    systemctl start "${php_fpm_service}" || rollback_status=1
    fpm_stopped=0
  fi

  if [[ ${maintenance_enabled} -eq 1 ]]; then
    disable_maintenance || rollback_status=1
  fi

  if [[ ${retention_validated} -eq 1 ]]; then
    prune_release_directories failed || rollback_status=1
    prune_release_directories incoming || rollback_status=1
    prune_database_snapshots || rollback_status=1
  fi
  if [[ ${rollback_status} -ne 0 ]]; then
    printf '%s\n' 'Automatic rollback encountered an error; inspect the VM before retrying.' >&2
  else
    printf '%s\n' 'The previous application and database were restored.' >&2
  fi
}

on_exit() {
  local status=$1
  trap - EXIT INT TERM
  set +e
  if [[ ${status} -ne 0 && ${deployment_committed} -eq 0 ]]; then
    rollback
  else
    remove_verification_directory || status=1
    if [[ ${fpm_stopped} -eq 1 ]]; then
      systemctl start "${php_fpm_service}" || status=1
    fi
    if [[ ${maintenance_enabled} -eq 1 ]]; then
      disable_maintenance || status=1
    fi
  fi
  exit "${status}"
}
trap 'on_exit $?' EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

[[ ${EUID} -eq 0 ]] || fail 'run this script with sudo'
[[ $# -eq 3 ]] || fail 'usage: deploy-vm.sh EXPECTED_SHA256 ARCHIVE RELEASE_ID'
readonly expected_hash=$1
readonly archive_argument=$2
readonly release_id=$3

[[ ${expected_hash} =~ ^[a-f0-9]{64}$ ]] || fail 'the expected SHA-256 is invalid'
[[ ${release_id} =~ ^[0-9]{8}-[0-9]{6}$ ]] || fail 'the release ID is invalid'
parsed_release_id=$(date -d \
  "${release_id:0:4}-${release_id:4:2}-${release_id:6:2} ${release_id:9:2}:${release_id:11:2}:${release_id:13:2}" \
  '+%Y%m%d-%H%M%S' 2>/dev/null) || fail 'the release ID is not a real date and time'
[[ ${parsed_release_id} == "${release_id}" ]] || fail 'the release ID is not canonical'
[[ ${release_retention} =~ ^[0-9]{1,2}$ ]] || fail 'release retention is invalid'
(( 10#${release_retention} >= 1 && 10#${release_retention} <= 10 )) \
  || fail 'release retention must be between 1 and 10'
retention_count=$((10#${release_retention}))
retention_validated=1
[[ ${health_url} =~ ^http://127\.0\.0\.1:[0-9]{1,5}$ ]] \
  || fail 'ROSIN_TRACKER_HEALTH_URL must be a loopback HTTP origin without a path'
health_port=${health_url##*:}
(( 10#${health_port} >= 1024 && 10#${health_port} <= 65535 )) \
  || fail 'the health-check port must be between 1024 and 65535'

for command_name in curl date find flock install php realpath runuser sha256sum \
  sqlite3 stat systemctl tar; do
  command -v "${command_name}" >/dev/null \
    || fail "required command is unavailable: ${command_name}"
done

id "${app_user}" >/dev/null 2>&1 \
  || fail "the deployment user does not exist: ${app_user}"
readonly app_group=$(id -gn "${app_user}")
systemctl is-active --quiet "${php_fpm_service}" \
  || fail "PHP-FPM is not active: ${php_fpm_service}"

exec 9>/run/lock/rosin-tracker-deploy.lock
flock --nonblock 9 || fail 'another Rosin Tracker deployment is running'

[[ -f ${archive_argument} && ! -L ${archive_argument} ]] \
  || fail 'the deployment archive is missing or is a symbolic link'
archive=$(realpath -e -- "${archive_argument}") || fail 'the archive path cannot be resolved'
readonly archive
readonly expected_archive=/home/${app_user}/rosin-tracker-${release_id}.tar.gz
[[ ${archive} == "${expected_archive}" ]] \
  || fail "the archive must be named ${expected_archive}"
archive_owner=$(stat -c '%U' -- "${archive}")
[[ ${archive_owner} == "${app_user}" || ${archive_owner} == root ]] \
  || fail 'the deployment archive has an unexpected owner'
archive_mode=$(stat -c '%a' -- "${archive}")
(( (8#${archive_mode} & 2) == 0 )) || fail 'the deployment archive is world-writable'
archive_size=$(stat -c '%s' -- "${archive}")
(( archive_size > 0 && archive_size <= 536870912 )) \
  || fail 'the deployment archive must be between 1 byte and 512 MiB'

actual_hash=$(sha256sum -- "${archive}" | awk '{print $1}')
[[ ${actual_hash} == "${expected_hash}" ]] \
  || fail 'deployment archive checksum mismatch'
tar -tzf "${archive}" >/dev/null || fail 'the deployment archive cannot be read'
while IFS= read -r archive_entry; do
  normalized_entry=${archive_entry#./}
  [[ ${archive_entry} != /* \
      && ${normalized_entry} != .. \
      && ${normalized_entry} != */.. \
      && ${normalized_entry} != ../* \
      && ${normalized_entry} != */../* \
      && ${normalized_entry} != *\\* ]] \
    || fail "the deployment archive contains an unsafe path: ${archive_entry}"
done < <(tar -tzf "${archive}")
while IFS= read -r archive_listing; do
  entry_type=${archive_listing:0:1}
  [[ ${entry_type} == - || ${entry_type} == d ]] \
    || fail 'the deployment archive contains a link or special filesystem entry'
done < <(tar -tvzf "${archive}")
if ! LC_ALL=C tar --list --verbose --numeric-owner --gzip --file "${archive}" \
  | awk '
      $1 ~ /^-/ { files += 1; bytes += $3 }
      END { exit(files <= 50000 && bytes <= 1073741824 ? 0 : 1) }
    '; then
  fail 'the deployment archive exceeds the pre-extraction file or size limit'
fi

[[ ${app_root} == /srv/rosin-tracker-php && -d ${app_root} && ! -L ${app_root} ]] \
  || fail 'the application root is missing or unsafe; run setup-vm.sh first'
[[ $(stat -c '%U' -- "${app_root}") == root ]] \
  || fail 'the application root must be owned by root; rerun setup-vm.sh'
[[ -d ${current} && ! -L ${current} ]] \
  || fail 'the current application directory is missing or unsafe'
[[ ${data_root} == /var/lib/rosin-tracker-php && -d ${data_root} && ! -L ${data_root} ]] \
  || fail 'the private data root is missing or unsafe; run setup-vm.sh first'
[[ -d ${deployment_state_root} && ! -L ${deployment_state_root} \
    && $(stat -c '%U:%G:%a' -- "${deployment_state_root}") == root:root:700 ]] \
  || fail 'the deployment state directory is unsafe; rerun setup-vm.sh'

incoming=${app_root}/.incoming-${release_id}
previous=${app_root}/.previous-${release_id}
failed=${app_root}/.failed-${release_id}
database_snapshot=${deployment_state_root}/database-before-${release_id}.sqlite
[[ ! -e ${incoming} && ! -e ${previous} && ! -e ${failed} \
    && ! -e ${database_snapshot} && ! -L ${incoming} && ! -L ${previous} \
    && ! -L ${failed} && ! -L ${database_snapshot} ]] \
  || fail 'a deployment target for this release ID already exists'

prune_release_directories incoming \
  || printf '%s\n' 'Warning: interrupted release directories could not be fully pruned.' >&2
prune_database_snapshots \
  || printf '%s\n' 'Warning: old database snapshots could not be fully pruned.' >&2

install -d -o "${app_user}" -g www-data -m 2750 "${incoming}"
runuser --user "${app_user}" -- tar \
  --extract --gzip --file "${archive}" --directory "${incoming}" \
  --no-same-owner --no-same-permissions --delay-directory-restore

if find "${incoming}" -type l -print -quit | grep -q .; then
  fail 'the extracted release contains a symbolic link'
fi
if find "${incoming}" ! -type f ! -type d -print -quit | grep -q .; then
  fail 'the extracted release contains a special filesystem entry'
fi
if find "${incoming}" -name .git -print -quit | grep -q .; then
  fail 'the release archive must not contain Git metadata'
fi
extracted_file_count=$(find "${incoming}" -type f -printf . | wc -c)
(( extracted_file_count <= 50000 )) || fail 'the extracted release contains too many files'
extracted_size=$(du -sb -- "${incoming}" | awk '{print $1}')
(( extracted_size <= 1073741824 )) || fail 'the extracted release exceeds 1 GiB'

for required_path in \
  public/index.php \
  src/Application.php \
  bin/check-runtime.php \
  bin/smoke-test.php \
  composer.json \
  composer.lock \
  vendor/autoload.php; do
  [[ -f ${incoming}/${required_path} && ! -L ${incoming}/${required_path} ]] \
    || fail "the release is missing ${required_path}"
done
[[ -d ${incoming}/migrations && ! -L ${incoming}/migrations ]] \
  || fail 'the release is missing its migrations directory'
find "${incoming}/migrations" -maxdepth 1 -type f -name '*.sql' -print -quit \
  | grep -q . || fail 'the release contains no database migration'

chown -R -- root:www-data "${incoming}"
find "${incoming}" -type d -exec chmod 2750 {} +
find "${incoming}" -type f -exec chmod 0640 {} +

while IFS= read -r -d '' php_file; do
  php -l "${php_file}" >/dev/null
done < <(
  find "${incoming}/bin" "${incoming}/public" "${incoming}/src" \
    "${incoming}/templates" -type f -name '*.php' -print0
)
php -l "${incoming}/vendor/autoload.php" >/dev/null
runuser --user www-data -- env -i \
  HOME=/tmp TMPDIR=/tmp PATH=/usr/local/bin:/usr/bin:/bin \
  php "${incoming}/bin/smoke-test.php"
if [[ -f ${incoming}/bin/check-analytics.php ]]; then
  runuser --user www-data -- env -i \
    HOME=/tmp TMPDIR=/tmp PATH=/usr/local/bin:/usr/bin:/bin \
    php "${incoming}/bin/check-analytics.php"
fi

enable_maintenance || fail 'maintenance mode could not be enabled'
systemctl stop "${php_fpm_service}"
fpm_stopped=1
if [[ -e ${live_database} ]]; then
  [[ -f ${live_database} && ! -L ${live_database} ]] \
    || fail 'the live database is not a safe regular file'
  sqlite3 "${live_database}" ".timeout 10000" ".backup '${database_snapshot}'"
  [[ -f ${database_snapshot} && ! -L ${database_snapshot} ]] \
    || fail 'the pre-deployment database snapshot was not created'
  chown root:"${app_group}" "${database_snapshot}"
  chmod 0640 "${database_snapshot}"
  database_existed=1
  [[ $(sqlite3 "${database_snapshot}" 'PRAGMA integrity_check;') == ok ]] \
    || fail 'the pre-deployment database snapshot failed its integrity check'
  [[ -z $(sqlite3 "${database_snapshot}" 'PRAGMA foreign_key_check;') ]] \
    || fail 'the pre-deployment database snapshot contains a foreign-key violation'
fi

mv -- "${current}" "${previous}"
swapped=1
mv -- "${incoming}" "${current}"
systemctl start "${php_fpm_service}"
fpm_stopped=0

entry_html=$(curl --location --fail --silent --show-error --max-time 15 \
  "${health_url}/setup")
if [[ ${entry_html} != *'<title>'*'Rosin Tracker</title>'* ]]; then
  fail 'the deployed application did not render a Rosin Tracker page'
fi
curl --fail --silent --show-error --max-time 10 \
  "${health_url}/assets/app.css" >/dev/null
curl --fail --silent --show-error --max-time 10 \
  "${health_url}/assets/app.js" >/dev/null

runuser --user www-data -- php "${current}/bin/check-runtime.php"
[[ -f ${live_database} && ! -L ${live_database} ]] \
  || fail 'the deployed application did not initialize a safe database file'
verification_directory=$(mktemp -d /tmp/rosin-tracker-db-check.XXXXXXXX)
verification_database=${verification_directory}/rosin-tracker.sqlite
sqlite3 "${live_database}" ".timeout 10000" ".backup '${verification_database}'"
[[ $(sqlite3 "${verification_database}" 'PRAGMA integrity_check;') == ok ]] \
  || fail 'the live database failed its integrity check'
[[ -z $(sqlite3 "${verification_database}" 'PRAGMA foreign_key_check;') ]] \
  || fail 'the live database contains a foreign-key violation'
[[ $(sqlite3 "${verification_database}" \
  'SELECT COUNT(*) FROM schema_migrations;') -ge 1 ]] \
  || fail 'the live database has no applied migration'
remove_verification_directory

swapped=0
deployment_committed=1
disable_maintenance || fail 'maintenance mode could not be disabled'

prune_release_directories previous \
  || printf '%s\n' 'Warning: old previous releases could not be fully pruned.' >&2
prune_release_directories failed \
  || printf '%s\n' 'Warning: old failed releases could not be fully pruned.' >&2
prune_release_directories incoming \
  || printf '%s\n' 'Warning: interrupted release directories could not be fully pruned.' >&2
prune_database_snapshots \
  || printf '%s\n' 'Warning: old database snapshots could not be fully pruned.' >&2

printf 'Deployed Rosin Tracker release %s.\n' "${release_id}"
printf 'Previous releases retained: %s (maximum).\n' "${retention_count}"
printf '%s\n' 'The live database passed integrity and foreign-key checks.'
