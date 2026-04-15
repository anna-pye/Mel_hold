#!/usr/bin/env bash
#
# One-time server setup for myeventlane.com.au (Debian 12+ or Ubuntu 22.04+).
# Run ON THE SERVER as root: sudo bash deploy/bootstrap-debian-ubuntu.sh
#
# Before running:
#   1. Point DNS A/AAAA for myeventlane.com.au (and www) to this host.
#   2. Add your SSH public key so you can log in as mel (this script does not configure SSH).
#   3. Review paths: PROJECT_PARENT defaults to /var/www/myeventlane_hold
#
# After this script:
#   - Copy deploy/production.env.example to /etc/myeventlane/production.env and fill secrets.
#   - Copy deploy/nginx-myeventlane.conf to /etc/nginx/sites-available/myeventlane and adjust root if needed.
#   - Deploy code (git clone or rsync), then run deploy/site-deploy.sh as the deploy user.
#

set -euo pipefail

PROJECT_PARENT="${PROJECT_PARENT:-/var/www/myeventlane_hold}"
DEPLOY_USER="${DEPLOY_USER:-mel}"

if [[ "${EUID:-0}" -ne 0 ]]; then
  echo "Run as root (sudo)." >&2
  exit 1
fi

. /etc/os-release

export DEBIAN_FRONTEND=noninteractive

apt-get update -y
apt-get install -y ca-certificates curl gnupg lsb-release software-properties-common \
  nginx mariadb-server mariadb-client unzip git rsync acl

PHP_PKGS=(php8.3-fpm php8.3-cli php8.3-mysql php8.3-xml php8.3-mbstring php8.3-gd php8.3-curl php8.3-zip php8.3-intl php8.3-opcache)

install_php_83() {
  if dpkg -l php8.3-fpm &>/dev/null; then
    return 0
  fi
  echo "Installing PHP 8.3…"
  if [[ "${ID}" == "debian" ]]; then
    apt-get install -y apt-transport-https gnupg
    curl -sSL https://packages.sury.org/php/apt.gpg | gpg --dearmor -o /usr/share/keyrings/deb.sury.org-php.gpg
    echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" > /etc/apt/sources.list.d/php.list
    apt-get update -y
  elif [[ "${ID}" == "ubuntu" ]]; then
    if ! apt-cache show php8.3-fpm &>/dev/null; then
      add-apt-repository -y ppa:ondrej/php
      apt-get update -y
    fi
  else
    echo "Unsupported OS: ${ID}. Install PHP 8.3 manually." >&2
    exit 1
  fi
  apt-get install -y "${PHP_PKGS[@]}"
}

install_php_83

echo "Installing Composer…"
if [[ ! -f /usr/local/bin/composer ]]; then
  curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php
  php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
fi

echo "Installing Certbot…"
apt-get install -y certbot python3-certbot-nginx

install -d -m 0755 -o root -g root /etc/myeventlane
if [[ ! -f /etc/myeventlane/production.env ]]; then
  echo "Creating /etc/myeventlane/production.env from example — EDIT THIS FILE before running Drush."
  install -m 0640 -o root -g www-data /dev/null /etc/myeventlane/production.env
  echo "# Paste contents from deploy/production.env.example and set strong passwords + salts." >> /etc/myeventlane/production.env
fi

echo "Configuring PHP-FPM environment inheritance…"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
install -d /etc/php/8.3/fpm/pool.d
if [[ -f "${SCRIPT_DIR}/php-fpm-99-clear-env.conf" ]]; then
  cp "${SCRIPT_DIR}/php-fpm-99-clear-env.conf" /etc/php/8.3/fpm/pool.d/99-clear-env.conf
fi

install -d /etc/systemd/system/php8.3-fpm.service.d
if [[ -f "${SCRIPT_DIR}/systemd/php8.3-fpm.service.d/override.conf" ]]; then
  cp "${SCRIPT_DIR}/systemd/php8.3-fpm.service.d/override.conf" /etc/systemd/system/php8.3-fpm.service.d/override.conf
fi

systemctl daemon-reload
systemctl restart php8.3-fpm

echo "MariaDB: create database and user (set variables or run manually)."
DB_NAME="${DRUPAL_DB_NAME:-drupal}"
DB_USER="${DRUPAL_DB_USER:-drupal}"
DB_PASS="${DRUPAL_DB_PASSWORD:-}"

if [[ -z "${DB_PASS}" ]]; then
  DB_PASS="$(openssl rand -base64 24 | tr -d '\n' | tr '/+' 'Aa')"
  echo "Generated DRUPAL_DB_PASSWORD for mysql user (save into /etc/myeventlane/production.env):"
  echo "${DB_PASS}"
fi

mysql -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
mysql -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost'; FLUSH PRIVILEGES;"

echo "Deploy user and project directory…"
if ! id -u "${DEPLOY_USER}" &>/dev/null; then
  useradd -m -s /bin/bash "${DEPLOY_USER}" || true
fi

install -d -o "${DEPLOY_USER}" -g www-data -m 0775 "${PROJECT_PARENT}"
usermod -aG www-data "${DEPLOY_USER}" || true

echo "Nginx site (copy from repo if this file is missing)…"
if [[ -f "${SCRIPT_DIR}/nginx-myeventlane.conf" ]]; then
  sed "s|root /var/www/myeventlane_hold/web|root ${PROJECT_PARENT}/web|g" "${SCRIPT_DIR}/nginx-myeventlane.conf" > /etc/nginx/sites-available/myeventlane
else
  echo "Missing ${SCRIPT_DIR}/nginx-myeventlane.conf — copy deploy/nginx-myeventlane.conf manually." >&2
fi

if [[ -f /etc/nginx/sites-available/myeventlane ]]; then
  ln -sf /etc/nginx/sites-available/myeventlane /etc/nginx/sites-enabled/myeventlane
  rm -f /etc/nginx/sites-enabled/default
fi

nginx -t
systemctl reload nginx
systemctl enable nginx php8.3-fpm mariadb

echo ""
echo "=== Next steps (manual) ==="
echo "1. Edit /etc/myeventlane/production.env — set DRUPAL_DATABASE_* (password above), DRUPAL_BASE_URL, DRUPAL_HASH_SALT, WAITLIST_TOKEN_SECRET (openssl rand -hex 32 each)."
echo "2. As ${DEPLOY_USER}: clone or upload code to ${PROJECT_PARENT}"
echo "3. Copy web/sites/default/settings.production.example.php → settings.production.php"
echo "4. Run: bash deploy/site-deploy.sh ${PROJECT_PARENT}"
echo "5. TLS: certbot --nginx -d myeventlane.com.au -d www.myeventlane.com.au"
echo "6. Cron: 0 * * * * ${DEPLOY_USER} cd ${PROJECT_PARENT} && set -a && . /etc/myeventlane/production.env && set +a && ./vendor/bin/drush cron -q"
echo ""
