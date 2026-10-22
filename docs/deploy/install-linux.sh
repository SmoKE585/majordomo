#!/bin/bash

app_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)" || exit 1
cd -- "$app_root" || exit 1

composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader || exit 1

folders=( cached cms debmes modules templates objects saverestore)

for i in "${folders[@]}"
do
    mkdir -p $i
    find $i/ -type d -exec chmod 777 {} \;
    find $i/ -type f -exec chmod 666 {} \;
done

echo "don't forget to run next command with sudo to enable voice in linux"
echo "sudo usermod -a -G audio www-data"
