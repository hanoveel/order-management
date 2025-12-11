FROM ubuntu:jammy

ENV COMPOSER_ALLOW_SUPERUSER 1
ENV DEBIAN_FRONTEND noninteractive
ENV NODE_MAJOR 18

RUN apt-get update -y \
    && apt upgrade -y \
    && apt install -y libc6 libc-bin \
    && apt-get install -y apt-utils git-core curl wget gnupg software-properties-common ca-certificates \
    && mkdir -p /etc/apt/keyrings \
    && echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_$NODE_MAJOR.x nodistro main" | tee /etc/apt/sources.list.d/nodesource.list \
    && curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor -o /etc/apt/keyrings/nodesource.gpg \
    && apt-get update -y \
    && wget -qO - https://www.mongodb.org/static/pgp/server-5.0.asc | apt-key add - \
    && wget -qO ./mongodb-database-tools-ubuntu1804-x86_64-100.5.1.deb https://fastdl.mongodb.org/tools/db/mongodb-database-tools-ubuntu1804-x86_64-100.5.1.deb \
    && apt install ./mongodb-database-tools-ubuntu1804-x86_64-100.5.1.deb \
    && add-apt-repository ppa:ondrej/php -y \
    && echo "deb [ arch=amd64,arm64 ] https://repo.mongodb.org/apt/ubuntu bionic/mongodb-org/5.0 multiverse" | tee /etc/apt/sources.list.d/mongodb-org-5.0.list \
    && apt-get update -y \
    && apt-get install -y nodejs build-essential \
    && npm install --global npm \
    && npm install --global mjml \
    && apt-get install -y mongodb-mongosh \
    && apt-get install -y --fix-missing \
          supervisor \
          nginx \
          nginx-extras \
          gettext-base \
          cron \
          git \
          zip \
          unzip \
          iputils-ping \
          openssl \
          telnet \
          php8.4-igbinary \
          php8.4-msgpack \
          php8.4-curl \
          php8.4-memcached \
          php8.4-redis \
          php8.4-imagick \
          php8.4-zip \
          php8.4-apcu \
          php8.4-gd \
          php8.4-dom \
          php8.4-mbstring \
          php8.4-mysql \
          php8.4-sqlite \
          php8.4-intl \
          php8.4-bcmath \
          php8.4-bz2 \
          php8.4-dba \
          php8.4-gettext \
          php8.4-soap \
          php8.4-mongodb \
          mysql-client \
          apache2-utils \
          redis-tools \
    && update-alternatives --set php /usr/bin/php8.4 \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && composer selfupdate \
    && mkdir -p /run/php \
    && apt-get upgrade -y \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install FrankenPHP
RUN curl https://frankenphp.dev/install.sh | sh \
    && mv frankenphp /usr/local/bin/

#Configurations
COPY docker/etc/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/etc/nginx.conf /etc/nginx/nginx.conf
COPY docker/etc/php.ini /etc/php/8.4/cli/conf.d/zzz_custom.ini

# Prepare commands
RUN mkdir -p /run/php
COPY docker/etc/run.sh /run.sh
RUN chmod +x /run.sh

WORKDIR /var/www/html

EXPOSE 80
EXPOSE 3000

CMD ["/run.sh"]