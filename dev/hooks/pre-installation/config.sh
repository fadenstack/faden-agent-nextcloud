#!/bin/sh
set -eu
mkdir -p /var/www/html/config
cp /opt/fadenstack-dev/nextcloud.config.php /var/www/html/config/fadenstack-dev.config.php
chown www-data:www-data /var/www/html/config /var/www/html/config/fadenstack-dev.config.php
