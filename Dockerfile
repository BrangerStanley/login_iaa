FROM php:8.3-apache

# Extensiones PHP necesarias para PostgreSQL, captcha y manejo de texto.
RUN apt-get update && apt-get install -y --no-install-recommends \
      libpq-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_pgsql gd mbstring \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Permitir .htaccess y configurar Apache
RUN printf '<Directory /var/www/html>\n  AllowOverride All\n  Require all granted\n</Directory>\n' > /etc/apache2/conf-available/app.conf \
    && a2enconf app

WORKDIR /var/www/html

# En desarrollo se monta el código como volumen (ver docker-compose.yml).
# Para una imagen "lista para producción", copia el código y ajusta permisos:
# COPY . /var/www/html
# RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
CMD ["apache2-foreground"]
