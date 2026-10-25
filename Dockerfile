FROM php:8.1-apache
RUN docker-php-ext-install pdo pdo_sqlite
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html && chmod -R 775 /var/www/html/data
EXPOSE 80
