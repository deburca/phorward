# syntax=docker/dockerfile:1.7
# phorward profile "php-library": the sandbox image for validation runs of a plain PHP library or application.
# Two stages, like drupal-module:
#   base    PHP, Composer, and the fallback lint tools (phpcs, phpstan, phpunit) in /opt/lint-tools.
#           Knows nothing about any project.
#   final   (default target) FROM base, installs the project's own composer.lock (dev dependencies included)
#           into /opt/vendor, then locks the image down. Rebuilding it for another project, or after the
#           lock changes, reuses the base layers.
# Everything is baked in so runs work with --network none. The tree under test is supplied at run time,
# mounted read-only at /project, and never baked in. Because vendor/ cannot live inside a read-only mount,
# `with-project` makes a writable copy of the tree in /tmp, puts the baked vendor/ in it and regenerates
# the autoloader there, so the project's own namespaces resolve against the tree under test.
#
# Build with server/build-image, which supplies the `module` build context (the project checkout, used
# only for composer.json and composer.lock) and the PROJECT_* build args.
ARG PHP_VERSION=8.3
FROM docker.io/library/composer:2 AS composer

FROM docker.io/library/php:${PHP_VERSION}-cli-trixie AS base

# opcache is compiled into PHP core from 8.5, so it is only installed as an extension before that.
RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip python3 libzip-dev libicu-dev \
 && EXTS="zip bcmath intl pcntl" \
 && if [ "$(php -r 'echo PHP_VERSION_ID < 80500 ? 1 : 0;')" = 1 ]; then EXTS="$EXTS opcache"; fi \
 && docker-php-ext-install -j"$(nproc)" $EXTS \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1 COMPOSER_MEMORY_LIMIT=-1

# Fallback tools, used when the project's own vendor/bin has none. Kept apart so they never constrain
# the project's dependencies.
RUN mkdir -p /opt/lint-tools
COPY <<'JSON' /opt/lint-tools/composer.json
{
  "require": {
    "squizlabs/php_codesniffer": "^3",
    "phpstan/phpstan": "^2",
    "phpunit/phpunit": "^11"
  },
  "minimum-stability": "stable"
}
JSON
RUN composer install --working-dir=/opt/lint-tools --no-progress --prefer-dist

COPY --chmod=755 with-project /usr/local/bin/with-project
COPY --chmod=755 phorward-check /usr/local/bin/phorward-check

RUN { \
      echo "php=$(php -r 'echo PHP_VERSION;')"; \
      echo "composer=$(composer --version | cut -d' ' -f3)"; \
      echo "phpcs=$(/opt/lint-tools/vendor/bin/phpcs --version | cut -d' ' -f3)"; \
      echo "phpstan=$(/opt/lint-tools/vendor/bin/phpstan --version | awk '{print $NF}')"; \
      echo "phpunit=$(/opt/lint-tools/vendor/bin/phpunit --version | awk '{print $2}')"; \
    } > /opt/TOOL-VERSIONS

# Named `final`, not `module`: `COPY --from=module` must resolve to the build context, and a stage
# with the same name would shadow it.
FROM base AS final

#   PROJECT_PACKAGE  composer package name of the project (vendor/name)
#   PROJECT_NAME     short machine name (lowercase letters, digits, underscores); the image label and tag use it
#   PROJECT_REV      revision of the checkout, recorded for humans
#   LOCK_SHA256      hash of composer.lock, recorded in a label so a stale image can be recognised
ARG PROJECT_PACKAGE
ARG PROJECT_NAME
ARG PROJECT_REV=unknown
ARG LOCK_SHA256=unknown
# No scripts and no autoloader here: the autoloader is generated at run time against the tree under test.
COPY --from=module composer.json composer.lock /opt/project-src/
RUN test -n "$PROJECT_PACKAGE" && test -n "$PROJECT_NAME" \
 && cd /opt/project-src \
 && composer install --no-progress --prefer-dist --no-scripts --no-autoloader \
 && { v="$(composer show phpunit/phpunit 2>/dev/null | awk '/^versions/{print $4}')"; [ -z "$v" ] || echo "project-phpunit=$v" >> /opt/TOOL-VERSIONS; } \
 && mv vendor /opt/vendor \
 && cd / && rm -rf /opt/project-src \
 && composer clear-cache \
 && echo "project=${PROJECT_PACKAGE}" >> /opt/TOOL-VERSIONS \
 && echo "project-rev=${PROJECT_REV}" >> /opt/TOOL-VERSIONS

# Non-root; /opt is read-only to it. Runtime writable space is tmpfs mounts, not image layers.
RUN useradd --uid 10001 --no-create-home --shell /usr/sbin/nologin validate \
 && chmod -R a-w /opt/lint-tools /opt/vendor
USER validate
WORKDIR /tmp
ENV PATH="/opt/lint-tools/vendor/bin:${PATH}" HOME=/tmp COMPOSER_HOME=/tmp/.composer
# The scripts that run the image read the mount path from these labels instead of assuming a layout.
LABEL org.opencontainers.image.title="phorward" \
      org.opencontainers.image.description="Sandboxed PHP validation: phpcs, phpstan, php -l, phpunit" \
      org.opencontainers.image.revision="${PROJECT_REV}" \
      phorward.profile="php-library" \
      phorward.module-name="${PROJECT_NAME}" \
      phorward.module-path="/project" \
      phorward.lock-sha256="${LOCK_SHA256}"
