#!/usr/bin/env bash
# Install pdo_duckdb for Homebrew PHP on Apple Silicon (macOS).
set -euo pipefail

PHP_VER="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
EXT_DIR="$(php -r 'echo ini_get("extension_dir");')"
INI_DIR="$(php --ini | awk -F': ' '/Scan for additional/{print $2; exit}')"
ZIP_URL="https://github.com/iliaal/pdo_duckdb/releases/download/0.7.1/php_pdo_duckdb-0.7.1_php${PHP_VER}-arm64-darwin-bsdlibc.zip"

if [[ "$(uname -s)" != "Darwin" || "$(uname -m)" != "arm64" ]]; then
  echo "This script is for macOS arm64. On Linux/Windows see:"
  echo "  https://github.com/iliaal/pdo_duckdb/releases"
  echo "  or: pie install iliaal/pdo_duckdb"
  exit 1
fi

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
echo "Downloading $ZIP_URL"
curl -fsSL -o "$TMP/pdo.zip" "$ZIP_URL"
unzip -qo "$TMP/pdo.zip" -d "$TMP/out"
SO="$(find "$TMP/out" -name 'pdo_duckdb.so' | head -1)"
if [[ -z "$SO" ]]; then
  echo "pdo_duckdb.so not found in zip"; find "$TMP/out" -type f; exit 1
fi

mkdir -p "$EXT_DIR" "$INI_DIR"
cp "$SO" "$EXT_DIR/pdo_duckdb.so"
printf 'extension=pdo_duckdb.so\n' > "$INI_DIR/20-pdo_duckdb.ini"
echo "Installed: $EXT_DIR/pdo_duckdb.so"
echo "Enabled:   $INI_DIR/20-pdo_duckdb.ini"
php -m | grep -i pdo_duckdb
php -r 'echo "PDO drivers: ".implode(",", PDO::getAvailableDrivers()).PHP_EOL;'
