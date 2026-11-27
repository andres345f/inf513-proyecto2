#!/usr/bin/env bash

set -Eeuo pipefail

readonly REPO_URL="https://github.com/andres345f/inf513-proyecto2.git"
readonly APP_DIR="${HOME}/proyecto2"
readonly PUBLIC_DIR="${APP_DIR}/public"
readonly CURRENT_DIR="${PUBLIC_DIR}/app"
readonly NEW_DIR="${PUBLIC_DIR}/app_nueva"
readonly BACKUP_DIR="${PUBLIC_DIR}/app_anterior"

if [[ ! -d "${PUBLIC_DIR}" ]]; then
  echo "Error: no existe ${PUBLIC_DIR}." >&2
  exit 1
fi

BUILD_DIR="$(mktemp -d "${HOME}/feder-frontend.XXXXXX")"

cleanup() {
  rm -rf -- "${BUILD_DIR}" "${NEW_DIR}"
}
trap cleanup EXIT

echo "Descargando la última versión de main..."
git clone --quiet --depth 1 --branch main "${REPO_URL}" "${BUILD_DIR}"

echo "Compilando el frontend de producción..."
cd "${BUILD_DIR}/frontend"
npm ci
npm run build:produccion

if [[ ! -f "${BUILD_DIR}/backend/public/app/index.html" ]]; then
  echo "Error: la compilación no generó backend/public/app/index.html." >&2
  exit 1
fi

echo "Instalando el frontend sin tocar Laravel, .env, vendor ni storage..."
rm -rf -- "${NEW_DIR}"
cp -a "${BUILD_DIR}/backend/public/app" "${NEW_DIR}"

rm -rf -- "${BACKUP_DIR}"
if [[ -d "${CURRENT_DIR}" ]]; then
  mv -- "${CURRENT_DIR}" "${BACKUP_DIR}"
fi

if ! mv -- "${NEW_DIR}" "${CURRENT_DIR}"; then
  echo "Error: no se pudo activar el frontend nuevo; restaurando el anterior." >&2
  if [[ -d "${BACKUP_DIR}" && ! -d "${CURRENT_DIR}" ]]; then
    mv -- "${BACKUP_DIR}" "${CURRENT_DIR}"
  fi
  exit 1
fi

echo "Frontend actualizado correctamente."
echo "Respaldo anterior: ${BACKUP_DIR}"
