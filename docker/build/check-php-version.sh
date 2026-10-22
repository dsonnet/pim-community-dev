#!/bin/sh
# Fails the image build if the installed PHP is older than PHP_MIN_VERSION.
#
# PHP < 8.3.36 is vulnerable to PHP2Shell (CVE-2026-109618, CVE-2026-109619):
# unauthenticated remote code execution in the PHP engine itself, independent
# of application code. See https://www.wiz.io/blog/php2shell-php-rce
#
# Usage: check-php-version.sh [minimum-version]
#        (defaults to $PHP_MIN_VERSION)
set -eu

MIN_VERSION="${1:-${PHP_MIN_VERSION:?PHP_MIN_VERSION is not set}}"
ACTUAL_VERSION="$(php -r 'echo PHP_VERSION;')"

if php -r 'exit(version_compare($argv[1], $argv[2], ">=") ? 0 : 1);' "$ACTUAL_VERSION" "$MIN_VERSION"; then
    echo "PHP ${ACTUAL_VERSION} satisfies the required minimum ${MIN_VERSION}"
    exit 0
fi

cat >&2 <<MSG
ERROR: installed PHP ${ACTUAL_VERSION} is older than the required minimum ${MIN_VERSION}.
PHP < 8.3.36 is vulnerable to PHP2Shell (CVE-2026-109618 / CVE-2026-109619).
The apt repository used in the Dockerfile probably served a stale package list.
Rebuild without the layer cache, e.g.:
    docker build --no-cache --pull --target dev -t akeneo/pim-php-dev:8.3 .
MSG
exit 1
