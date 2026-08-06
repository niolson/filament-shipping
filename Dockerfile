# Stage 1: Install Composer dependencies
# Pin digests to prevent supply chain attacks — update with `docker manifest inspect <image>`
FROM php:8.4.24-cli-alpine@sha256:26e3f1de7f6aa3e8ea15584d803c5e088c57df89ff02a3ecf2dc855a4282d8d7 AS vendor

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --ignore-platform-reqs

# Stage 2: Build frontend assets
FROM node:22-alpine@sha256:16e22a550f3863206a3f701448c45f7912c6896a62de43add43bb9c86130c3e2 AS assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY . .
COPY --from=vendor /app/vendor vendor

RUN npm run build

# Stage 3: PHP application
FROM php:8.4.24-fpm@sha256:4bf62afd7d6325d09b3c86af2ae96f64ae7d3f15cc57da814a246441dc4344ad AS app

# Changing this on every build forces apt-get to re-fetch the package index
# instead of reusing a stale cached layer, so OS security patches (e.g. Debian
# security-tracker fixes published after the last build) actually land.
ARG APT_CACHE_BUST=1

# Install system dependencies
RUN apt-get update && apt-get upgrade -y && apt-get install -y --no-install-recommends \
    curl \
    libzip-dev \
    libpng-dev \
    libicu-dev \
    libonig-dev \
    openssh-client \
    unzip \
    && docker-php-ext-install \
        pdo_mysql \
        zip \
        gd \
        intl \
        bcmath \
        pcntl \
        sockets \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

# Ensure PHP-FPM listens on all interfaces (not just localhost)
RUN printf '[global]\ndaemonize = no\n\n[www]\nlisten = 0.0.0.0:9000\n' > /usr/local/etc/php-fpm.d/zz-docker.conf

# Keep function arguments out of exception stack traces — they would carry
# recipient PII (addresses, phones) into logs and error tracking, which
# Amazon's data protection policy forbids.
RUN printf 'zend.exception_ignore_args = 1\n' > /usr/local/etc/php/conf.d/zz-polybag.ini

# Copy application source
COPY . .

# Remove hot file if it was copied from host (forces production manifest)
# Ensure bind-mount destinations exist as empty files. Use rm -rf before touch
# so that if Docker previously created a directory at these paths (due to a
# missing bind-mount source), the build doesn't silently embed that directory.
RUN rm -f public/hot \
    && rm -rf public/qz-certificate.pem && touch public/qz-certificate.pem \
    && rm -rf storage/app/private/qz-private-key.pem && touch storage/app/private/qz-private-key.pem

# Copy vendor from stage 1 and built assets from stage 2
COPY --from=vendor /app/vendor vendor
COPY --from=assets /app/public/build public/build

# Install Composer for autoload optimization
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
RUN composer dump-autoload --optimize

# Set permissions — o+rX normalises source files regardless of host umask
RUN chmod -R o+rX . \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Pre-cache views only (config and routes are cached at runtime via
# entrypoint so they pick up .env values and produce consistent hashes)
RUN php artisan view:cache

# Copy entrypoint
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 9000

ENTRYPOINT ["entrypoint.sh"]

# Stage 4: Nginx with built assets
FROM nginx:alpine@sha256:54f2a904c251d5a34adf545a72d32515a15e08418dae0266e23be2e18c66fefa AS nginx

COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public
