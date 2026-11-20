#!/usr/bin/env bash
# Builds the image on this machine and ships only what the server needs to run
# it: the image itself, docker-compose.yml and the env examples.
#
# Usage: ./deploy.sh
# Overrides: SSH_HOST (default: oracle), REMOTE_DIR, IMAGE, PLATFORM, ENGINE (podman|docker)
set -euo pipefail

SSH_HOST="${SSH_HOST:-oracle}"
REMOTE_DIR="${REMOTE_DIR:-apps/ai-image-generator}"
IMAGE="${IMAGE:-ai-image-generator:latest}"
PLATFORM="${PLATFORM:-linux/amd64}"

cd "$(dirname "$0")"

ENGINE="${ENGINE:-$(command -v podman >/dev/null && echo podman || echo docker)}"
command -v "${ENGINE}" >/dev/null || { echo "Neither podman nor docker was found on this machine."; exit 1; }

echo "==> Building ${IMAGE} (${PLATFORM}) with ${ENGINE}"
if [ "${ENGINE}" = "podman" ]; then
    podman build --format docker --platform "${PLATFORM}" --tag "${IMAGE}" .
else
    docker buildx build --platform "${PLATFORM}" --tag "${IMAGE}" --load .
fi

echo "==> Preparing ${SSH_HOST}:~/${REMOTE_DIR}"
ssh "${SSH_HOST}" "mkdir -p ~/${REMOTE_DIR}"
scp -q docker-compose.yml deploy.env.example .env.production.example "${SSH_HOST}:~/${REMOTE_DIR}/"

if ! ssh "${SSH_HOST}" "test -f ~/${REMOTE_DIR}/deploy.env && test -f ~/${REMOTE_DIR}/.env.production"; then
    cat <<EOF
First deploy: create the config files on the server, then run this again.
  ssh ${SSH_HOST}
  cd ~/${REMOTE_DIR}
  cp deploy.env.example deploy.env               # set APP_DOMAIN
  cp .env.production.example .env.production     # set APP_URL and APP_KEY
  chmod 600 .env.production
EOF
    exit 1
fi

echo "==> Shipping image (gzip over ssh)"
# Podman exports the name as localhost/<image>; retag so compose finds it.
"${ENGINE}" save "${IMAGE}" | gzip -1 | ssh "${SSH_HOST}" "gunzip | docker load \
    && if docker image inspect localhost/${IMAGE} >/dev/null 2>&1; then \
        docker tag localhost/${IMAGE} ${IMAGE} && docker rmi localhost/${IMAGE} >/dev/null; fi"

echo "==> Starting containers"
ssh "${SSH_HOST}" "cd ~/${REMOTE_DIR} \
    && docker compose --env-file deploy.env up -d --no-build --remove-orphans \
    && docker image prune -f >/dev/null \
    && docker compose --env-file deploy.env ps"

echo "==> Deploy finished"
