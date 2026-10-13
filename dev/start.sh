#!/bin/sh
set -eu
# Prepare the named development volume, including volumes initialized by older setups.
mkdir -p /var/www/html/config
cp /opt/fadenstack-dev/nextcloud.config.php /var/www/html/config/fadenstack-dev.config.php
chown www-data:www-data /var/www/html/config /var/www/html/config/fadenstack-dev.config.php
cp /opt/fadenstack-dev/apache-fadenstack.conf /etc/apache2/conf-enabled/fadenstack-dev.conf
cp /opt/fadenstack-dev/php-dev.ini /usr/local/etc/php/conf.d/zz-fadenstack-dev.ini
exec /entrypoint.sh apache2-foreground
