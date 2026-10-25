FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libonig-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql mbstring \
    && a2enmod rewrite \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

# Copiar solamente la aplicación; la configuración privada se recibe al arrancar.
COPY bootstrap.php ./
COPY bin/ ./bin/
COPY config/ ./config/
COPY database/migrations/ ./database/migrations/
COPY public/ ./public/
COPY src/ ./src/
COPY views/ ./views/
COPY deploy/apache.conf /etc/apache2/sites-available/000-default.conf
COPY deploy/ports.conf /etc/apache2/ports.conf
COPY deploy/php.ini /usr/local/etc/php/conf.d/vida-salud.ini
COPY deploy/start.sh /usr/local/bin/vida-salud-start

RUN mkdir -p storage /tmp/vida-salud-sessions \
    && chown www-data:www-data storage /tmp/vida-salud-sessions \
    && chmod 700 /tmp/vida-salud-sessions \
    && sed -i 's/\r$//' /usr/local/bin/vida-salud-start

ENV PORT=10000 \
    APP_ENV=production \
    APP_TIMEZONE=America/La_Paz \
    SESSION_SECURE=true \
    DB_SSLMODE=require

EXPOSE 10000
CMD ["sh", "/usr/local/bin/vida-salud-start"]
