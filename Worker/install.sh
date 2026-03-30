#!/usr/bin/env bash
# ---------------------------------------------------------------
# Install isolated LLPhant dependencies for Perspective_SmartLog.
#
# When installed in app/code/:
#   warden env exec php-fpm bash -c "cd app/code/Perspective/SmartLog/Worker && bash install.sh"
#
# When installed via composer (vendor/):
#   warden env exec php-fpm bash -c "cd vendor/perspective/module-smartlog/Worker && bash install.sh"
# ---------------------------------------------------------------
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

if ! command -v composer &>/dev/null; then
    if [ -f /var/www/html/composer.phar ]; then
        COMPOSER_BIN="php /var/www/html/composer.phar"
    else
        echo "Error: composer not found"
        exit 1
    fi
else
    COMPOSER_BIN="composer"
fi

echo "Installing isolated SmartLog worker dependencies..."
$COMPOSER_BIN install --no-dev --optimize-autoloader --no-interaction
echo "Done. Worker vendor directory: ${SCRIPT_DIR}/vendor/"
