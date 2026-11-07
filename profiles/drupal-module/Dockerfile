# syntax=docker/dockerfile:1.7
# phorward profile "drupal-module": the sandbox image for validation runs of a Drupal module.
# Mirrors a typical Drupal module CI job: lint tools in an isolated directory, and a CI-shaped Drupal 11
# project with drupal/core-dev, so phpcs, phpstan, php -l and Kernel/Unit phpunit all run with
# --network none.
# Two stages, so the module-independent part is reusable and cached:
#   base    PHP, Composer, the lint tools (phpcs/coder, phpstan), a Drupal 11 project with
#           drupal/core-dev and drush, and with-mariadb. Knows nothing about any module.
#   final   (default target) FROM base, adds one module's composer dependencies and patches, then
#           locks the image down (non-root, read-only trees). Rebuilding it for another module, or
#           after that module's dependencies change, reuses the base layers.
# Everything is baked in so runs work with --network none. The tree under test is supplied at run
# time, never baked in.
#
# Build with server/build-image, which supplies the `module` build context (a checkout of the
# module, used only to resolve its composer dependencies and patches) and the PROJECT_* build args.
#
# Reproducibility: the base images are named by digest (build-image passes them from the profile manifest) and the
# lint tools and the Drupal project are installed from lock files kept in locks/, so two builds of the same inputs
# give the same tool versions. `build-image --refresh-locks` re-resolves them on purpose.
ARG PHP_VERSION=8.3
ARG COMPOSER_IMAGE=docker.io/library/composer:2
# trixie, not bookworm: Drupal 11.4 needs SQLite >= 3.45 and bookworm ships 3.40.
ARG PHP_IMAGE=docker.io/library/php:${PHP_VERSION}-cli-trixie
FROM ${COMPOSER_IMAGE} AS composer

FROM ${PHP_IMAGE} AS php-base

# opcache is compiled into PHP core from 8.5, so it is only installed as an extension before that.
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      git unzip python3 libpng-dev libjpeg-dev libfreetype-dev libzip-dev libsqlite3-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && EXTS="gd zip pdo_mysql" \
 && if [ "$(php -r 'echo PHP_VERSION_ID < 80500 ? 1 : 0;')" = 1 ]; then EXTS="$EXTS opcache"; fi \
 && docker-php-ext-install -j"$(nproc)" $EXTS \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1 COMPOSER_MEMORY_LIMIT=-1

# Target `locks`: resolves the lint tools and the Drupal project afresh and leaves composer.json and composer.lock
# for each in /out. Only `build-image --refresh-locks` builds it. Resolved on the lowest supported PHP, so the locks
# install on every supported version.
FROM php-base AS locks
RUN mkdir -p /out/lint-tools /out/drupal-project
COPY locks/lint-tools/composer.json /out/lint-tools/composer.json
RUN cd /out/lint-tools && rm -f composer.lock && composer update --no-install --no-progress
RUN composer create-project drupal/recommended-project:^11 /out/drupal-project --no-install --no-progress \
 && cd /out/drupal-project \
 && composer config allow-plugins.drupal/core-composer-scaffold true \
 && composer config allow-plugins.drupal/core-project-message true \
 && composer config allow-plugins.cweagans/composer-patches true \
 && composer require drush/drush --no-install --no-progress \
 && composer require --dev "drupal/core-dev:^11" --update-with-all-dependencies --no-install --no-progress \
 && test -f composer.lock
RUN mkdir -p /export/lint-tools /export/drupal-project \
 && cp /out/lint-tools/composer.json /out/lint-tools/composer.lock /export/lint-tools/ \
 && cp /out/drupal-project/composer.json /out/drupal-project/composer.lock /export/drupal-project/

FROM php-base AS base
ARG PHP_IMAGE
ARG COMPOSER_IMAGE

# The lint tools, installed in their own directory so they never constrain the project's own
# dependencies (same layout a CI "install lint tools" step produces), from the committed lock.
# Permissions are set in the same step that creates the files: a chmod in a later step would copy every
# file into a new layer (it once cost 450 MB).
COPY locks/lint-tools/ /opt/lint-tools/
RUN composer install --working-dir=/opt/lint-tools --no-progress --prefer-dist \
 && chmod -R a-w /opt/lint-tools \
 && composer clear-cache

# Module-agnostic Drupal project: core, core-dev (phpunit 11, test traits) and drush, from the committed lock.
COPY locks/drupal-project/ /opt/drupal-project/
RUN cd /opt/drupal-project \
 && composer install --no-progress --prefer-dist \
 && mkdir -p web/sites/simpletest \
 && chmod -R a-w vendor web/core \
 && composer clear-cache

# Optional in-container MariaDB (no network needed): `with-mariadb <cmd>` starts a throwaway server
# on loopback with its datadir in /tmp (tmpfs at run time), runs <cmd> with SIMPLETEST_DB pointing at
# it, then stops it. Kernel tests use it as the database.
RUN apt-get update \
 && apt-get install -y --no-install-recommends mariadb-server \
 && rm -rf /var/lib/apt/lists/*
COPY --chmod=755 with-mariadb /usr/local/bin/with-mariadb

RUN { \
      echo "php=$(php -r 'echo PHP_VERSION;')"; \
      echo "composer=$(composer --version | cut -d' ' -f3)"; \
      echo "phpcs=$(/opt/lint-tools/vendor/bin/phpcs --version | cut -d' ' -f3)"; \
      echo "coder=$(composer --working-dir=/opt/lint-tools show drupal/coder | awk '/^versions/{print $4}')"; \
      echo "phpstan=$(/opt/lint-tools/vendor/bin/phpstan --version | awk '{print $NF}')"; \
      echo "phpunit=$(/opt/drupal-project/vendor/bin/phpunit --version | awk '{print $2}')"; \
      echo "drupal-core=$(composer --working-dir=/opt/drupal-project show drupal/core | awk '/^versions/{print $4}')"; \
      echo "mariadb=$(mariadbd --version | awk '{print $3}')"; \
      echo "base-image=${PHP_IMAGE}"; \
      echo "composer-image=${COMPOSER_IMAGE}"; \
    } > /opt/TOOL-VERSIONS

# Named `final`, not `module`: `COPY --from=module` must resolve to the build context, and a stage
# with the same name would shadow it.
FROM base AS final

# The module's dependencies and patches resolve as in CI: it is installed via a path repository
# (symlink:false), then its copy is replaced by an empty mount point for the tree under test.
#   PROJECT_PACKAGE  composer package name of the module (vendor/name)
#   PROJECT_NAME     Drupal machine name; the tree under test is mounted at web/modules/contrib/<name>
#   DEV_PACKAGES     extra dev dependencies the module's tests need, space separated
#   PROJECT_REV      revision of the checkout, recorded for humans
#   LOCK_SHA256      hash of the module's composer.json, recorded in a label so a stale image can be recognised
ARG PROJECT_PACKAGE
ARG PROJECT_NAME
ARG DEV_PACKAGES=""
ARG PROJECT_REV=unknown
ARG LOCK_SHA256=unknown
COPY --from=module / /tmp/module-src
RUN test -n "$PROJECT_PACKAGE" && test -n "$PROJECT_NAME" \
 && rm -rf /tmp/module-src/.git \
 && cd /opt/drupal-project \
 && composer config repositories.module '{"type":"path","url":"/tmp/module-src","options":{"symlink":false}}' \
 && composer require "${PROJECT_PACKAGE}:@dev" --no-progress --prefer-dist \
 && if [ -n "$DEV_PACKAGES" ]; then composer require --dev $DEV_PACKAGES --no-progress --prefer-dist; fi \
 && INSTALLED="$(composer show --path "$PROJECT_PACKAGE" | awk '{print $2}')" \
 && test -n "$INSTALLED" && test -d "$INSTALLED" \
 && rm -rf "$INSTALLED" "web/modules/contrib/${PROJECT_NAME}" /tmp/module-src \
 && mkdir -p "web/modules/contrib/${PROJECT_NAME}" \
 && composer clear-cache \
 && echo "module=${PROJECT_PACKAGE}" >> /opt/TOOL-VERSIONS \
 && echo "module-rev=${PROJECT_REV}" >> /opt/TOOL-VERSIONS

# Non-root; /opt is read-only to it (the files belong to root and the root filesystem is read-only at run time).
# Runtime writable space is tmpfs mounts, not image layers.
RUN useradd --uid 10001 --no-create-home --shell /usr/sbin/nologin validate \
 && chmod 1777 /opt/drupal-project/web/sites/simpletest
USER validate
WORKDIR /opt/drupal-project
ENV PATH="/opt/lint-tools/vendor/bin:/opt/drupal-project/vendor/bin:${PATH}" \
    BROWSERTEST_OUTPUT_DIRECTORY=/tmp
# The scripts that run the image read the mount path from these labels instead of assuming a module.
LABEL org.opencontainers.image.title="phorward" \
      org.opencontainers.image.description="Sandboxed PHP/Drupal validation: phpcs, phpstan, php -l, phpunit" \
      org.opencontainers.image.revision="${PROJECT_REV}" \
      phorward.profile="drupal-module" \
      phorward.module-name="${PROJECT_NAME}" \
      phorward.module-path="/opt/drupal-project/web/modules/contrib/${PROJECT_NAME}" \
      phorward.lock-sha256="${LOCK_SHA256}"
