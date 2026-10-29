#!/usr/bin/env bash
# Runs on the GitHub-hosted runner. Secrets stay in the environment.
# plan is read-only. deploy and rollback are refused without the confirmation phrase.
set -euo pipefail

ACTION=${ACTION:?}
RELEASE_ID=${RELEASE_ID:?}
SOURCE_DIR=${SOURCE_DIR:-}

CONFIRM_PHRASE='deploy-www.mbprotest.site'
if [[ "$ACTION" != "plan" && "${CONFIRM:-}" != "$CONFIRM_PHRASE" ]]; then
  echo "Refusing $ACTION. Set confirm to $CONFIRM_PHRASE."
  exit 1
fi

: "${SSH_HOST:?SSH_HOST is empty}"
: "${SSH_PORT:?SSH_PORT is empty}"
: "${SSH_USER:?SSH_USER is empty}"
: "${SSH_PRIVATE_KEY:?SSH_PRIVATE_KEY is empty}"
: "${SSH_KNOWN_HOSTS:?SSH_KNOWN_HOSTS is empty}"
: "${DEPLOY_ROOT:?DEPLOY_ROOT is empty}"

if [[ ! "$SSH_HOST" =~ ^[A-Za-z0-9.-]+$ ]]; then
  echo "SSH_HOST is not a hostname."
  exit 1
fi
if [[ ! "$SSH_PORT" =~ ^[0-9]+$ ]] || [[ "$SSH_PORT" -lt 1 || "$SSH_PORT" -gt 65535 ]]; then
  echo "SSH_PORT is not a port."
  exit 1
fi
if [[ ! "$SSH_USER" =~ ^[A-Za-z0-9._-]+$ ]]; then
  echo "SSH_USER contains unsupported characters."
  exit 1
fi
if [[ ! "$DEPLOY_ROOT" =~ ^/home/[^/]+/[^/]+/www$ ]]; then
  echo "DEPLOY_ROOT must look like /home/USER/mbprotest.site/www."
  exit 1
fi
if [[ ! "$RELEASE_ID" =~ ^[0-9]{8}T[0-9]{6}Z-[0-9a-f]{12}$ ]]; then
  echo "RELEASE_ID is not valid."
  exit 1
fi
if [[ -z "${PHP_BIN:-}" ]]; then
  PHP_BIN=php
fi
if [[ ! "$PHP_BIN" =~ ^[A-Za-z0-9_./+-]+$ ]]; then
  echo "PHP_BIN contains unsupported characters."
  exit 1
fi

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
umask 077
SSH_DIR=${RUNNER_TEMP:-/tmp}/lifebalance-ssh
mkdir -p "$SSH_DIR"
chmod 700 "$SSH_DIR"
printf '%s\n' "$SSH_PRIVATE_KEY" > "$SSH_DIR/id"
printf '%s\n' "$SSH_KNOWN_HOSTS" > "$SSH_DIR/known_hosts"
chmod 600 "$SSH_DIR/id" "$SSH_DIR/known_hosts"
if ! grep -q . "$SSH_DIR/known_hosts"; then
  echo "SSH_KNOWN_HOSTS is empty."
  exit 1
fi
if ! grep -q 'PRIVATE KEY' "$SSH_DIR/id"; then
  echo "SSH_PRIVATE_KEY does not look like a private key."
  exit 1
fi

SSH_BASE=(
  ssh
  -i "$SSH_DIR/id"
  -o IdentitiesOnly=yes
  -o StrictHostKeyChecking=yes
  -o UserKnownHostsFile="$SSH_DIR/known_hosts"
  -o ConnectTimeout=20
  -o ServerAliveInterval=15
  -o ServerAliveCountMax=4
  -p "$SSH_PORT"
)

remote() {
  local action=$1
  local exports
  exports=$(printf 'DEPLOY_ROOT=%q PHP_BIN=%q RELEASE_ID=%q' "$DEPLOY_ROOT" "$PHP_BIN" "$RELEASE_ID")
  "${SSH_BASE[@]}" "$SSH_USER@$SSH_HOST" "$exports bash -s -- $action" < "$ROOT/scripts/deploy/remote.sh"
}

resolve_source() {
  if [[ -f "$SOURCE_DIR/web/index.php" ]]; then
    return 0
  fi
  if [[ -f "$SOURCE_DIR/release-src/web/index.php" ]]; then
    SOURCE_DIR="$SOURCE_DIR/release-src"
    return 0
  fi
  echo "Release source is missing: $SOURCE_DIR"
  exit 1
}

copy_release() {
  local mode=$1
  local -a flags=(-a --human-readable --stats)
  if [[ "$mode" == "dry-run" ]]; then
    flags+=(--dry-run)
  fi
  rsync "${flags[@]}" \
    --exclude-from="$ROOT/scripts/deploy/rsync-excludes.txt" \
    -e "ssh -i ${SSH_DIR}/id -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile=${SSH_DIR}/known_hosts -o ConnectTimeout=20 -p ${SSH_PORT}" \
    "$SOURCE_DIR/" \
    "$SSH_USER@$SSH_HOST:$DEPLOY_ROOT/releases/$RELEASE_ID/"
}

echo "Action: $ACTION"
echo "Release: $RELEASE_ID"
preflight_log=$(remote preflight)
printf '%s\n' "$preflight_log"

if [[ "$ACTION" == "rollback" ]]; then
  remote rollback
  exit 0
fi

resolve_source
if [[ -e "$SOURCE_DIR/web/sites/default/settings.local.php" || -e "$SOURCE_DIR/.env" ]]; then
  echo "The release source contains a local settings file or .env."
  exit 1
fi

if [[ "$ACTION" == "plan" ]]; then
  if printf '%s\n' "$preflight_log" | grep -qx 'releases-dir=present'; then
    echo "Dry run. Files are listed and not written."
    copy_release dry-run
  else
    echo "releases/ is absent, so no server copy was attempted."
    echo "Deploy creates releases/$RELEASE_ID after the shared layout is in place."
  fi
  echo "Plan finished. No release was published."
  exit 0
fi

if [[ "$ACTION" != "deploy" ]]; then
  echo "Unknown action: $ACTION"
  exit 1
fi

remote prepare-release
echo "Copying the release. Shared files are not the destination."
copy_release real
remote publish
echo "Deploy published $RELEASE_ID"
