FROM drupal:11-apache

RUN apt-get update && apt-get install -y unzip && rm -rf /var/lib/apt/lists/*

RUN composer require drush/drush --no-interaction --working-dir=/opt/drupal
