FROM php:8.2-apache
RUN docker-php-ext-install mysqli
COPY . /var/www/html/
CMD ["bash", "-lc", "PORT=${PORT:-10000}; sed -i \"s/Listen 80/Listen ${PORT}/\" /etc/apache2/ports.conf; sed -i \"s/:80>/:${PORT}>/\" /etc/apache2/sites-available/000-default.conf; exec apache2-foreground"]
