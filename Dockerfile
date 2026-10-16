FROM php:8.2-apache

ENV NEXGEN_PRIVATE_UPLOAD_DIR=/data/nexgen-private \
    NEXGEN_UPLOAD_QUARANTINE_DIR=/tmp/nexgen-upload-quarantine \
    NEXGEN_PUBLIC_UPLOAD_DIR=/var/www/html/uploads

# Install Node.js, Python, and the HEIF/PDF runtime dependencies.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates curl libonig-dev libheif1 python3 python3-venv \
    && curl -fsSL https://deb.nodesource.com/setup_18.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY . .

# Stop the build if NexGen's required application folders are missing.
RUN test -f /app/CODE/PHP/index.php \
    && test -f /app/CODE/PHP/health.php \
    && test -d /app/CODE/JS \
    && test -d /app/CODE/STYLE \
    && test -d /app/IMAGES/captcha \
    && test -f /app/IMAGES/introbg.png \
    && test -f /app/IMAGES/default-product.svg \
    && test -f /app/requirements-upload.txt

# Stop the image build if any PHP source file has a syntax error.
RUN find /app/CODE/PHP -type f -name '*.php' -exec php -l {} \;

# Install the PHP extensions used by the application.
RUN docker-php-ext-install -j"$(nproc)" mysqli pdo pdo_mysql mbstring \
    && php -m | grep -qx mbstring \
    && php -m | grep -qx sodium

# Keep upload image/PDF decoders isolated in a dedicated Python environment.
RUN python3 -m venv /opt/nexgen-upload-venv \
    && /opt/nexgen-upload-venv/bin/pip install --no-cache-dir \
        -r /app/requirements-upload.txt

# Configure PHP request-size limits for the two signup document slots.
COPY docker/php-upload-limits.ini /usr/local/etc/php/conf.d/zz-upload-limits.ini
ENV NEXGEN_PYTHON_BIN=/opt/nexgen-upload-venv/bin/python
ENV NEXGEN_UPLOAD_SANITIZER_PATH=/app/CODE/PHP/upload_sanitizer.py

# Install production Node dependencies.
RUN npm install --omit=dev

# Publish the PHP application and browser-accessible assets at root-level URLs.
RUN rm -rf /var/www/html/* \
    && cp -a /app/CODE/PHP/. /var/www/html/ \
    && rm -f /var/www/html/upload_sanitizer.py \
    && cp -a /app/CODE/JS /var/www/html/JS \
    && cp -a /app/CODE/STYLE /var/www/html/STYLE \
    && cp -a /app/IMAGES /var/www/html/IMAGES \
    && rm -rf /var/www/html/uploads/valid_ids \
    && mkdir -p /var/www/html/uploads/products \
    && printf '%s\n' \
        'RewriteEngine On' \
        'LimitRequestBody 23068672' \
        'RewriteRule ^upload_sanitizer\.py$ - [F,L,NC]' \
        'RewriteCond %{THE_REQUEST} \s/+NexGen/CODE/PHP/admin_login\.php(?:[?\s]|$) [NC]' \
        'RewriteRule ^NexGen/CODE/PHP/admin_login\.php$ - [R=404,L,NC]' \
        'RewriteCond %{REQUEST_METHOD} POST' \
        'RewriteRule ^NexGen/CODE/PHP/(.*)$ /$1 [END,NC]' \
        'RewriteCond %{HTTP:X-Forwarded-Host} ^([^,]+)$' \
        'RewriteCond %{HTTP:X-Forwarded-Proto} =https' \
        'RewriteRule ^NexGen/CODE/PHP/(.*)$ https://%1/$1 [R=302,END,NE,NC]' \
        'RewriteRule ^NexGen/CODE/PHP/(.*)$ /$1 [R=302,END,NE,NC]' \
        'RewriteCond %{HTTP:X-Forwarded-Host} ^([^,]+)$' \
        'RewriteCond %{HTTP:X-Forwarded-Proto} =https' \
        'RewriteRule ^NexGen/CODE/STYLE/(.*)$ https://%1/STYLE/$1 [R=302,END,NE,NC]' \
        'RewriteRule ^NexGen/CODE/STYLE/(.*)$ /STYLE/$1 [R=302,END,NE,NC]' \
        'RewriteCond %{HTTP:X-Forwarded-Host} ^([^,]+)$' \
        'RewriteCond %{HTTP:X-Forwarded-Proto} =https' \
        'RewriteRule ^NexGen/CODE/JS/(.*)$ https://%1/JS/$1 [R=302,END,NE,NC]' \
        'RewriteRule ^NexGen/CODE/JS/(.*)$ /JS/$1 [R=302,END,NE,NC]' \
        'RewriteCond %{HTTP:X-Forwarded-Host} ^([^,]+)$' \
        'RewriteCond %{HTTP:X-Forwarded-Proto} =https' \
        'RewriteRule ^NexGen/IMAGES/(.*)$ https://%1/IMAGES/$1 [R=302,END,NE,NC]' \
        'RewriteRule ^NexGen/IMAGES/(.*)$ /IMAGES/$1 [R=302,END,NE,NC]' \
        'RewriteCond %{HTTP:X-Forwarded-Host} ^([^,]+)$' \
        'RewriteCond %{HTTP:X-Forwarded-Proto} =https' \
        'RewriteRule ^NexGen/uploads/(.*)$ https://%1/uploads/$1 [R=302,END,NE,NC]' \
        'RewriteRule ^NexGen/uploads/(.*)$ /uploads/$1 [R=302,END,NE,NC]' \
        'RewriteRule ^nx-control-1407/?$ admin_login.php [END,NC]' \
        'RewriteCond %{THE_REQUEST} \s/+admin_login\.php(?:[?\s]|$) [NC]' \
        'RewriteRule ^admin_login\.php$ - [R=404,L,NC]' \
        'RewriteRule ^uploads/valid_ids(?:/|$) - [R=404,L,NC]' \
        'RewriteRule ^uploads/\.nexgen-private(?:/|$) - [R=404,L,NC]' \
        > /var/www/html/.htaccess \
    && chown -R www-data:www-data /var/www/html

# Enable exactly one Apache MPM plus rewriting.
RUN rm -f /etc/apache2/mods-enabled/mpm_*.load \
          /etc/apache2/mods-enabled/mpm_*.conf \
    && a2enmod mpm_prefork rewrite \
    && sed -ri 's/AllowOverride None/AllowOverride All/g' \
        /etc/apache2/apache2.conf \
    && apache2ctl configtest \
    && apache2ctl -M | grep mpm

EXPOSE 80

CMD ["sh", "-c", "PORT=\"${PORT:-80}\"; UPLOAD_DIR=\"${NEXGEN_PUBLIC_UPLOAD_DIR:-/var/www/html/uploads}\"; PRIVATE_UPLOAD_DIR=\"${NEXGEN_PRIVATE_UPLOAD_DIR:-/data/nexgen-private}\"; QUARANTINE_DIR=\"${NEXGEN_UPLOAD_QUARANTINE_DIR:-/tmp/nexgen-upload-quarantine}\"; mkdir -p \"${UPLOAD_DIR}/products\" \"${PRIVATE_UPLOAD_DIR}/valid_ids\" \"${PRIVATE_UPLOAD_DIR}/business_permits\" \"${QUARANTINE_DIR}\"; chown -R www-data:www-data \"${UPLOAD_DIR}\" \"${PRIVATE_UPLOAD_DIR}\" \"${QUARANTINE_DIR}\"; chmod 0750 \"${PRIVATE_UPLOAD_DIR}\" \"${PRIVATE_UPLOAD_DIR}/valid_ids\" \"${PRIVATE_UPLOAD_DIR}/business_permits\"; chmod 0700 \"${QUARANTINE_DIR}\"; rm -f /etc/apache2/mods-enabled/mpm_*.load /etc/apache2/mods-enabled/mpm_*.conf; a2enmod mpm_prefork; sed -ri \"s/Listen 80/Listen ${PORT}/\" /etc/apache2/ports.conf; sed -ri \"s/<VirtualHost \\\\*:80>/<VirtualHost *:${PORT}>/\" /etc/apache2/sites-available/000-default.conf; apache2ctl configtest; exec apache2-foreground"]
