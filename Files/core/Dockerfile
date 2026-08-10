FROM php:8.3-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libgmp-dev \
    zip \
    unzip \
    git \
    curl \
    nginx \
    supervisor \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_mysql \
        gd \
        bcmath \
        gmp \
        zip \
        exif \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy everything (web root: index.php, assets/ + core/ Laravel app)
COPY . .

# Install PHP dependencies (from core/)
RUN cd /var/www/html/core && composer install --no-dev --no-interaction --optimize-autoloader --no-scripts

# Copy configs
COPY core/docker/nginx/default.conf /etc/nginx/sites-enabled/default
COPY core/docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY core/docker/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p /var/log/supervisor /run/php /run/nginx \
    && sed -i 's/user www-data;/user root;/' /etc/nginx/nginx.conf

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
