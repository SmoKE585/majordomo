# syntax=docker/dockerfile:1.7

# ---------------------------------------------------------------------------
#  MajorDoMo — образ ТОЛЬКО для локальной отладки и тестирования.
#  Это не production-развёртывание: nginx, php-fpm и циклы живут в одном
#  контейнере, MQTT-брокер разрешает анонимные подключения, база защищена
#  только на уровне приложения (DB_PROTECT_DROP).
# ---------------------------------------------------------------------------

# Зависимости Composer собираются отдельно: слой не пересобирается, пока не
# изменились composer.json/composer.lock. В рантайм попадает только vendor/.
FROM composer:2 AS dependencies

WORKDIR /app
COPY composer.json composer.lock ./
COPY packages ./packages

RUN composer install \
        --no-dev \
        --no-interaction \
        --no-scripts \
        --prefer-dist \
        --optimize-autoloader

# path-repository по умолчанию ставит symlink на /app/packages/phpmqtt.
# В рантайме этого каталога нет, поэтому заменяем ссылку реальными файлами.
RUN if [ -L vendor/majordomo/phpmqtt ]; then \
        rm vendor/majordomo/phpmqtt && cp -a packages/phpmqtt vendor/majordomo/phpmqtt; \
    fi


FROM php:8.4-fpm-bookworm

ARG TZ=Europe/Moscow
ENV DEBIAN_FRONTEND=noninteractive \
    TZ=${TZ} \
    COMPOSER_ALLOW_SUPERUSER=1

# nginx, supervisor, tini и клиенты для диагностики. mariadb-client нужен для
# mysqldump, проверки готовности БД и ручных запросов из контейнера.
RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx \
        supervisor \
        tini \
        curl \
        ca-certificates \
        tzdata \
        mariadb-client \
        mosquitto-clients \
        iputils-ping \
        procps \
        less \
        unzip \
    && ln -snf /usr/share/zoneinfo/${TZ} /etc/localtime \
    && echo ${TZ} > /etc/timezone \
    # В образе nginx Debian уже есть sites-enabled/default с default_server.
    # Оставляем только наш конфиг из conf.d/default.conf.
    && rm -f /etc/nginx/sites-enabled/default \
    && rm -rf /var/lib/apt/lists/*

# PHP-расширения, которые нужны ядру, циклам, WebSocket и MQTT-быстрому пути.
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions \
        pdo_mysql \
        mysqli \
        mbstring \
        intl \
        bcmath \
        gd \
        zip \
        sockets \
        pcntl \
        redis \
        opcache \
        exif \
        gettext

WORKDIR /var/www/html

COPY docker/app/nginx.conf /etc/nginx/conf.d/default.conf
COPY docker/app/php.ini /usr/local/etc/php/conf.d/99-majordomo.ini
COPY docker/app/php-fpm.conf /usr/local/etc/php-fpm.d/zz-majordomo.conf
COPY docker/app/supervisord.conf /etc/supervisor/majordomo.conf
COPY docker/app/entrypoint.sh /usr/local/bin/majordomo-entrypoint
RUN chmod 0755 /usr/local/bin/majordomo-entrypoint

COPY --from=dependencies /app/vendor /var/www/html/vendor
COPY . /var/www/html

# Каталоги runtime. В образе они пустые; в compose поверх них монтируются
# named volumes, чтобы база, логи и состояние циклов переживали перезапуск.
RUN mkdir -p \
        /var/www/html/backup \
        /var/www/html/cms/cached \
        /var/www/html/cms/debmes \
        /var/www/html/cms/cycles \
        /var/www/html/cms/deploy \
        /var/www/html/cms/files \
        /var/www/html/cms/html \
        /var/www/html/cms/images \
        /var/www/html/cms/layouts \
        /var/www/html/cms/modules_installed \
        /var/www/html/cms/products \
        /var/www/html/cms/saverestore \
        /var/www/html/cms/sessions \
        /var/www/html/cms/sounds \
        /var/www/html/cms/templates \
        /var/www/html/cms/texts \
        /var/www/html/cms/weather \
        /var/www/html/var/events \
        /var/www/html/var/security \
        /var/www/html/var/sessions \
        /var/lib/php/sessions \
        /var/log/supervisor \
        /run/php \
    && chown -R www-data:www-data \
        /var/www/html/cms \
        /var/www/html/var \
        /var/www/html/backup \
        /var/lib/php/sessions \
    && chmod -R 0775 \
        /var/www/html/cms \
        /var/www/html/var \
        /var/www/html/backup \
        /var/lib/php/sessions

EXPOSE 80

STOPSIGNAL SIGTERM

ENTRYPOINT ["/usr/bin/tini", "--", "/usr/local/bin/majordomo-entrypoint"]
CMD ["serve"]
