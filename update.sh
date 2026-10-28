#!/bin/bash

if [ ! -d ".git" ]; then
  echo "Please deploy using Git."
  exit 1
fi

if ! command -v git &> /dev/null; then
    echo "Git is not installed! Please install git and try again."
    exit 1
fi

repo_root="$(pwd)"

add_safe_directory() {
  local dir="$1"

  git config --global --get-all safe.directory | grep -Fx "$dir" > /dev/null ||     git config --global --add safe.directory "$dir"
}

add_safe_directory "$repo_root"
add_safe_directory "$repo_root/public/assets/admin"

# Fix (v1.0.1): back up the database and .env before running this script;
# it resets the working tree. Dependencies are now installed from the
# locked versions (composer install) instead of deleting composer.lock
# and running composer update, which could silently pull unreviewed
# dependency versions on every update.
git fetch --all && git reset --hard origin/master && git pull origin master
if [ ! -f composer.phar ]; then
  wget https://github.com/composer/composer/releases/latest/download/composer.phar -O composer.phar
fi
php composer.phar install --no-dev -vvv
git submodule update --init --recursive --force
php artisan xboard:update

if [ -f "/etc/init.d/bt" ] || [ -f "/.dockerenv" ]; then
  chown -R www:www $(pwd);
fi

if [ -d ".docker/.data" ]; then
  chmod -R 777 .docker/.data
fi