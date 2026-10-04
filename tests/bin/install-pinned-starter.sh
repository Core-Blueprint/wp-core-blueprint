#!/usr/bin/env bash
set -euo pipefail

: "${WP_CORE_DIR:?WP_CORE_DIR is required}"

STARTER_SHA="7f23ad4a90b54d92e828ae53620b604ca33420c2"
STARTER_DIR="$WP_CORE_DIR/wp-content/plugins/core-blueprint-starter-plugin"
STARTER_FILE="$STARTER_DIR/core-blueprint-starter.php"

rm -rf -- "$STARTER_DIR"
mkdir -p "$STARTER_DIR"

curl --fail --silent --show-error --location \
  "https://codeload.github.com/Core-Blueprint/wp-core-blueprint-starter-plugin/tar.gz/${STARTER_SHA}" \
  | tar -xz --strip-components=1 -C "$STARTER_DIR"

if [[ ! -f "$STARTER_FILE" ]]; then
  echo "Pinned Core Blueprint Starter was not installed at $STARTER_FILE" >&2
  exit 1
fi

if ! grep -Fq "define( 'CB_STARTER_REQUIRED_API', '1.0' );" "$STARTER_FILE"; then
  echo "Pinned Starter fixture does not expose the expected Core API 1.0 requirement." >&2
  exit 1
fi

printf '[A3] pinned Starter installed: %s\n' "$STARTER_SHA"
