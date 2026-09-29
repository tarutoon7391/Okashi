# Railway 用（ローカルで Docker を使わなくても開発はできる。README 参照）
FROM php:8.2-apache

# PHP 拡張：MySQL（pdo_mysql）。unzip は composer 用
RUN apt-get update \
 && apt-get install -y --no-install-recommends unzip \
 && docker-php-ext-install pdo_mysql \
 && rm -rf /var/lib/apt/lists/*

# 設定：日本時間・アップロード上限・エラーは画面に出さずログへ
RUN { \
      echo 'date.timezone = Asia/Tokyo'; \
      echo 'upload_max_filesize = 4M'; \
      echo 'post_max_size = 8M'; \
      echo 'display_errors = Off'; \
      echo 'log_errors = On'; \
      echo 'error_log = /dev/stderr'; \
      echo 'expose_php = Off'; \
    } > /usr/local/etc/php/conf.d/app.ini

COPY docker/apache.conf /etc/apache2/conf-enabled/app.conf

# 外部ライブラリ（TCPDF・PHPMailer）を lib/ に入れる（composer.json の vendor-dir = lib）
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY composer.json ./
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader

# アプリ本体（.dockerignore で .git や docs は除外）
COPY . .
RUN mkdir -p img/product && chown -R www-data:www-data img/product

CMD ["bash", "docker/start.sh"]
