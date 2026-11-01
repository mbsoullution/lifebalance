#!/usr/bin/env bash
# Server-side release steps for Ukraine.com.ua shared hosting.
# The script never deletes www/shared, never prints settings.local.php,
# and never restores or drops the database.
set -euo pipefail

ACTION=${1:?action is required}

if [[ ! "${DEPLOY_ROOT:-}" =~ ^/home/[^/]+/[^/]+/www$ ]]; then
  echo "DEPLOY_ROOT must be the absolute site directory, like /home/USER/mbprotest.site/www."
  exit 1
fi
if [[ "$DEPLOY_ROOT" == *".."* ]]; then
  echo "DEPLOY_ROOT must not contain .."
  exit 1
fi
if [[ ! -d "$DEPLOY_ROOT" ]]; then
  echo "DEPLOY_ROOT does not exist: $DEPLOY_ROOT"
  exit 1
fi

if [[ -z "${PHP_BIN:-}" ]]; then
  PHP_BIN=php
fi
if [[ ! "$PHP_BIN" =~ ^[A-Za-z0-9_./+-]+$ ]]; then
  echo "PHP_BIN contains unsupported characters."
  exit 1
fi

RELEASE_PATTERN='^[0-9]{8}T[0-9]{6}Z-[0-9a-f]{12}$'
SHARED="$DEPLOY_ROOT/shared"
RELEASES="$DEPLOY_ROOT/releases"

release_dir() {
  local id=$1
  if [[ ! "$id" =~ $RELEASE_PATTERN ]]; then
    echo "Release id is not valid."
    exit 1
  fi
  printf '%s\n' "$RELEASES/$id"
}

check_php() {
  if ! command -v bash >/dev/null || ! command -v rsync >/dev/null; then
    echo "The SSH account needs bash and rsync."
    exit 1
  fi
  if ! "$PHP_BIN" -v; then
    echo "PHP binary is not runnable: $PHP_BIN"
    exit 1
  fi
  if ! "$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);'; then
    echo "PHP is older than 8.3."
    exit 1
  fi
}

report_tree() {
  echo "Account: $(id -un) ($(id -u))"
  echo "Deploy root: $DEPLOY_ROOT"
  df -h "$DEPLOY_ROOT" || df -h .
  echo "Top-level names in the site directory:"
  shopt -s nullglob dotglob
  local path base found=0
  for path in "$DEPLOY_ROOT"/*; do
    base=${path##*/}
    [[ "$base" == "." || "$base" == ".." ]] && continue
    found=1
    if [[ -L "$path" ]]; then
      echo "  link ${base} -> $(readlink "$path")"
    elif [[ -d "$path" ]]; then
      echo "  dir  ${base}"
    else
      echo "  file ${base}"
    fi
  done
  shopt -u nullglob dotglob
  if [[ "$found" -eq 0 ]]; then
    echo "  (empty)"
  fi
  if [[ -L "$DEPLOY_ROOT/current" ]]; then
    echo "current -> $(readlink "$DEPLOY_ROOT/current")"
  else
    echo "current symlink is absent"
  fi
  if [[ -d "$RELEASES" ]]; then
    echo "releases-dir=present"
  else
    echo "releases-dir=absent"
  fi
  local item
  for item in \
    "$SHARED/settings.local.php" \
    "$SHARED/.htpasswd" \
    "$SHARED/files" \
    "$SHARED/private" \
    "$SHARED/layout-reviewed"
  do
    if [[ -e "$item" ]]; then
      echo "present ${item#"$DEPLOY_ROOT"/}"
    else
      echo "missing ${item#"$DEPLOY_ROOT"/}"
    fi
  done
}

unexpected_names() {
  shopt -s nullglob dotglob
  local path base
  for path in "$DEPLOY_ROOT"/*; do
    base=${path##*/}
    [[ "$base" == "." || "$base" == ".." ]] && continue
    case "$base" in
      releases|shared|current) ;;
      *) printf '%s\n' "$base" ;;
    esac
  done
  shopt -u nullglob dotglob
}

require_shared_layout() {
  local missing=0
  if [[ ! -f "$SHARED/settings.local.php" ]]; then
    echo "Missing $SHARED/settings.local.php"
    missing=1
  fi
  if [[ ! -d "$SHARED/files" ]]; then
    echo "Missing $SHARED/files"
    missing=1
  fi
  if [[ ! -d "$SHARED/private" ]]; then
    echo "Missing $SHARED/private"
    missing=1
  fi
  if [[ "$missing" -ne 0 ]]; then
    echo "Create the shared layout from README.md before deploying."
    exit 1
  fi
  chmod 600 "$SHARED/settings.local.php"
  local unexpected
  unexpected=$(unexpected_names || true)
  if [[ -n "$unexpected" && ! -f "$SHARED/layout-reviewed" ]]; then
    echo "The site directory is not empty. Review these names, then create the marker file:"
    printf '%s\n' "$unexpected"
    echo "touch $SHARED/layout-reviewed"
    exit 1
  fi
}

bootstrap_of() {
  local root=$1
  "$PHP_BIN" "$root/vendor/bin/drush" --root="$root/web" status --field=bootstrap 2>/dev/null || true
}

prepare_release() {
  local release
  release=$(release_dir "${RELEASE_ID:-}")
  require_shared_layout
  if [[ -e "$release" ]]; then
    echo "Release already exists: $release"
    exit 1
  fi
  mkdir -p "$release"
  echo "Created $release"
}

require_preview_password() {
  local passwd="$SHARED/.htpasswd"
  if [[ ! -f "$passwd" || -L "$passwd" ]]; then
    echo "Missing preview password file: shared/.htpasswd"
    exit 1
  fi
  local size blank
  size=$(wc -c < "$passwd")
  if [[ "$size" -lt 20 || "$size" -gt 4096 ]]; then
    echo "shared/.htpasswd has an unexpected size."
    exit 1
  fi
  blank=$(grep -cve '^[[:space:]]*$' "$passwd" || true)
  if [[ "$blank" != "1" ]]; then
    echo "shared/.htpasswd must contain one username:hash line."
    exit 1
  fi
  local line
  line=$(tr -d '\r' < "$passwd")
  if [[ ! "$line" =~ ^[A-Za-z0-9._-]{1,64}:\$2[aby]\$.+ ]]; then
    echo "shared/.htpasswd must be a bcrypt line: username:\$2y\$..."
    exit 1
  fi
  # Apache opens this file before PHP starts. Mode 600 is invisible to it
  # and the preview login then ends as HTTP 500.
  chmod 644 "$passwd"
}

append_preview_gate() {
  local release=$1
  local htaccess="$release/web/.htaccess"
  local passwd="$SHARED/.htpasswd"
  if [[ ! -f "$htaccess" ]]; then
    echo "Release has no web/.htaccess."
    exit 1
  fi
  if grep -q 'LifeBalance preview gate' "$htaccess"; then
    echo "Preview gate is already present in this release."
    return
  fi
  cat >> "$htaccess" <<EOF

# LifeBalance preview gate. Publish adds this block; the password stays in shared/.
<IfModule mod_headers.c>
  Header always set X-Robots-Tag "noindex, nofollow, noarchive"
</IfModule>
CGIPassAuth On
<IfModule mod_auth_basic.c>
  AuthType Basic
  AuthName "LifeBalance"
  AuthUserFile "$passwd"
  Require valid-user
</IfModule>
EOF
  echo "Preview gate added to the release."
}

link_shared() {
  local release=$1
  local site="$release/web/sites/default"
  if [[ -e "$site/settings.local.php" && ! -L "$site/settings.local.php" ]]; then
    echo "Refusing to replace a real settings.local.php inside the release."
    exit 1
  fi
  if [[ -e "$site/files" && ! -L "$site/files" ]]; then
    if [[ -n "$(ls -A "$site/files")" ]]; then
      echo "Refusing to replace a non-empty files directory inside the release."
      exit 1
    fi
    rmdir "$site/files"
  fi
  ln -sfn "$SHARED/settings.local.php" "$site/settings.local.php"
  ln -sfn "$SHARED/files" "$site/files"
}

publish() {
  local id=${RELEASE_ID:-}
  # These stay global. The EXIT trap runs after this function returns, and
  # local variables are already gone by then.
  release=$(release_dir "$id")
  require_shared_layout
  if [[ ! -d "$release" ]]; then
    echo "Release directory is absent: $release"
    exit 1
  fi
  if [[ ! -f "$release/web/index.php" || ! -f "$release/vendor/bin/drush" ]]; then
    echo "Release is incomplete. index.php or Drush is missing."
    exit 1
  fi
  require_preview_password
  if ! grep -q '"name": "lifebalance/lifebalance"' "$release/composer.json"; then
    echo "Release composer.json is not this project."
    exit 1
  fi

  lock="$SHARED/deploy.lock"
  if ! mkdir "$lock"; then
    echo "A deploy lock already exists at $lock"
    exit 1
  fi

  current_target=""
  old_id=""
  maint_on=0
  switched=0
  cleanup() {
    local status=$?
    trap - EXIT
    if [[ "$switched" -eq 1 ]]; then
      "$PHP_BIN" "$release/vendor/bin/drush" --root="$release/web" maint:set 0 || true
    elif [[ "$maint_on" -eq 1 && -n "$current_target" ]]; then
      "$PHP_BIN" "$current_target/vendor/bin/drush" --root="$current_target/web" maint:set 0 || true
    fi
    rmdir "$lock" 2>/dev/null || true
    exit "$status"
  }
  trap cleanup EXIT

  link_shared "$release"
  append_preview_gate "$release"
  if [[ -L "$DEPLOY_ROOT/current" ]]; then
    current_target=$(readlink "$DEPLOY_ROOT/current")
    old_id=${current_target##*/}
  fi

  local bootstrap=""
  if [[ -n "$current_target" && -f "$current_target/vendor/bin/drush" ]]; then
    bootstrap=$(bootstrap_of "$current_target")
  else
    bootstrap=$(bootstrap_of "$release")
  fi

  if [[ "$bootstrap" == "Successful" ]]; then
    if [[ -n "$current_target" ]]; then
      "$PHP_BIN" "$current_target/vendor/bin/drush" --root="$current_target/web" maint:set 1
      maint_on=1
    fi
    "$PHP_BIN" "$release/vendor/bin/drush" --root="$release/web" deploy -y
  else
    echo "Drupal is not installed yet. Publishing the code and skipping drush deploy."
    echo "Install from the new release, then run this workflow again. See README.md."
  fi

  if [[ -n "$old_id" && "$old_id" =~ $RELEASE_PATTERN ]]; then
    printf '%s\n' "$old_id" > "$SHARED/previous_release"
  fi
  ln -sfn "$release" "$DEPLOY_ROOT/current"
  switched=1
  echo "current -> $release"
}

rollback() {
  require_shared_layout
  local previous_file="$SHARED/previous_release"
  if [[ ! -f "$previous_file" ]]; then
    echo "No previous release is recorded."
    exit 1
  fi
  local id
  id=$(tr -d '[:space:]' < "$previous_file")
  local release
  release=$(release_dir "$id")
  if [[ ! -f "$release/web/index.php" ]]; then
    echo "Previous release is not on disk: $release"
    exit 1
  fi
  local current_id=""
  if [[ -L "$DEPLOY_ROOT/current" ]]; then
    current_id=$(basename "$(readlink "$DEPLOY_ROOT/current")")
  fi
  if [[ -n "$current_id" && "$current_id" =~ $RELEASE_PATTERN ]]; then
    printf '%s\n' "$current_id" > "$previous_file.next"
  fi
  ln -sfn "$release" "$DEPLOY_ROOT/current"
  if [[ -f "$previous_file.next" ]]; then
    mv "$previous_file.next" "$previous_file"
  fi
  echo "Rolled the code symlink back to $release"
  echo "Database updates already applied by drush deploy stay in place."
}

case "$ACTION" in
  preflight)
    check_php
    report_tree
    ;;
  prepare-release)
    check_php
    prepare_release
    ;;
  publish)
    check_php
    publish
    ;;
  rollback)
    check_php
    rollback
    ;;
  *)
    echo "Unknown action: $ACTION"
    exit 1
    ;;
esac
