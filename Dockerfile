FROM php:8.2-apache

# Changing BUILD_REV (set in docker-compose.yml) forces every layer below to rebuild.
ARG BUILD_REV=1.7.5
RUN echo "$BUILD_REV" > /build-rev.txt && chmod 644 /build-rev.txt && a2enmod headers

COPY docker/php-overrides.ini /usr/local/etc/php/conf.d/zz-overrides.ini
COPY docker/apache-wedding.conf /etc/apache2/conf-enabled/zz-wedding.conf

# The invitation (a single static page) is the site root.
COPY index.html /var/www/html/index.html

# Guest book: page + API + owner page, and its code, outside the document root.
COPY includes/ /var/www/includes/
COPY guestbook/ /var/www/guestbook/

# Signatures live in a named volume mounted here (see docker-compose.yml).
RUN mkdir -p /var/www/data \
 && chown -R www-data:www-data /var/www/html /var/www/includes /var/www/guestbook /var/www/data

EXPOSE 80
