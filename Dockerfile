FROM php:8.2-apache
RUN a2enmod rewrite headers
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf
# The document root is /public, so expose /api through an alias
RUN printf 'Alias /api /var/www/html/api\n<Directory /var/www/html/api>\n    Require all granted\n    SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1\n</Directory>\n<Directory /var/www/html/api/config>\n    Require all denied\n</Directory>\n' > /etc/apache2/conf-available/api-alias.conf \
    && a2enconf api-alias
COPY . /var/www/html/
RUN mkdir -p /var/www/html/database && chown -R www-data:www-data /var/www/html/database
EXPOSE 80
