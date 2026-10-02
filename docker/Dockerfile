# BaninaPRO – helyi fejlesztői környezet: PHP 8.3 + Apache
# (8.3, mert a 8.4 a kódban lévő implicit nullable paraméterekre deprecation-t ír ki)
FROM php:8.3-apache

# PDO MySQL (az mbstring, json, zlib, xml, curl, openssl az alapképben már benne van)
RUN docker-php-ext-install pdo_mysql

# .htaccess támogatás (a védett mappák „Require all denied” szabályai miatt) + fejlécek
RUN a2enmod headers rewrite \
 && sed -ri 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf

# PHP beállítások: Excel import, adatbázis-mentés / visszaállítás
RUN { \
      echo 'date.timezone = Europe/Budapest'; \
      echo 'upload_max_filesize = 64M'; \
      echo 'post_max_size = 64M'; \
      echo 'memory_limit = 512M'; \
      echo 'max_execution_time = 300'; \
    } > /usr/local/etc/php/conf.d/baninapro.ini

# Napló és mentések helye a konténerben (Docker-kötet, nem a projekt LOG/ és DBBCKP/ mappája)
RUN mkdir -p /var/lib/baninapro/LOG /var/lib/baninapro/DBBCKP \
 && chown -R www-data:www-data /var/lib/baninapro
