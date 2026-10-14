#!/bin/sh
# Isolated tests only. No shop configuration, credentials or network required.
set -eu
cd "$(dirname "$0")/.."
php -v | head -1
find neurocheckoutconnector -name '*.php' | while IFS= read -r file; do
    php -l "$file" > /dev/null || exit 1
done
for test in neurocheckoutconnector/tests/*_test.php tests/configuration_save_test.php tests/security_boundaries.php; do
    case "$test" in
        *runtime_legacy_bootstrap*|*runtime_bootstrap*) continue ;;
    esac
    php "$test"
done
if php -n -r 'exit(extension_loaded("curl") ? 1 : 0);'; then
    php -n neurocheckoutconnector/tests/runtime_bootstrap_test.php
else
    echo 'Missing-extension bootstrap is covered by the host validation job; this PHP binary compiles cURL in.'
fi
