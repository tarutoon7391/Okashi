#!/bin/bash
# コンテナ起動時に実行（Railway）
#  1. Railway が決めるポート番号（$PORT）で Apache を待ち受ける
#  2. DB にテーブルが無ければ sql/01 → 02（SEED_TEST_DATA=1 なら 03 も）を流す
#  3. Apache を起動
set -e

PORT="${PORT:-80}"
sed -ri "s/^Listen [0-9]+/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# php:apache イメージで MPM が複数読み込まれて起動しないことがあるので prefork だけにする
a2dismod mpm_event mpm_worker >/dev/null 2>&1 || true
a2enmod mpm_prefork >/dev/null 2>&1 || true

# 商品写真の置き場（Volume をマウントしたときも書き込めるように）
mkdir -p /var/www/html/img/product
chown -R www-data:www-data /var/www/html/img/product

php /var/www/html/tools/db_init.php || echo "[start] DB初期化に失敗しました（アプリは起動します）"

exec apache2-foreground
