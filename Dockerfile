# Use the official PHP image with Apache
FROM php:8.1-apache

# Install system dependencies and PHP extensions
RUN apt-get update && apt-get install -y \
    libzip-dev \
    unzip \
    libxml2-dev \
    && docker-php-ext-install zip pdo pdo_mysql soap

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . /var/www/html

# Install Composer
COPY --from=composer:2.5 /usr/bin/composer /usr/bin/composer

# Install PHP dependencies
WORKDIR /app
COPY . .
RUN composer install --no-dev --optimize-autoloader

# Set permissions for Yii runtime and assets
RUN mkdir -p /var/www/html/runtime /var/www/html/web/assets \
    && chown -R www-data:www-data /var/www/html/runtime /var/www/html/web/assets

# Expose port 80
EXPOSE 80

# Start Apache in the foreground
CMD ["apache2-foreground"] 