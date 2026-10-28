# A throwaway SAML identity provider for the DRAWBRIDGE test rig: SimpleSAMLphp 2.x with one fixed test user.
# It exists only to prove the SP flow locally. It is not Academica, and nothing here ever talks to a real IdP.

FROM composer:2.10.3 AS ssp
RUN composer create-project --no-dev --no-interaction --no-progress --ignore-platform-reqs simplesamlphp/simplesamlphp /ssp "^2.5"

FROM php:8.4-apache
RUN apt-get update \
    && apt-get install -y --no-install-recommends libicu-dev \
    && docker-php-ext-install intl \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite headers
COPY --from=ssp /ssp /var/simplesamlphp
COPY config.php /var/simplesamlphp/config/config.php
COPY authsources.php /var/simplesamlphp/config/authsources.php
COPY saml20-idp-hosted.php /var/simplesamlphp/metadata/saml20-idp-hosted.php
COPY saml20-sp-remote.php /var/simplesamlphp/metadata/saml20-sp-remote.php
COPY apache.conf /etc/apache2/sites-available/000-default.conf
ENV SIMPLESAMLPHP_CONFIG_DIR=/var/simplesamlphp/config
