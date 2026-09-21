#!/bin/sh
set -eu
cd /var/www/html
exec php artisan test --without-tty "$@"
