#!/usr/bin/env bash
# Fails if the short, non-unique "crc_" / "CRC_" prefix is still used anywhere in the project.
# Compiled third-party bundles are not excluded on purpose: they must not contain it either.
set -euo pipefail
repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$repo_dir"
if grep -rInE "crc_|CRC_" --exclude-dir=node_modules --exclude-dir=.git --exclude-dir=build --exclude=verify-prefix.sh . ; then
  echo "Préfixe interdit crc_ ou CRC_ encore présent." >&2
  exit 1
fi
echo "Aucun crc_ ni CRC_ restant (préfixe actuel : crcal_ / CRCAL_)."
