MODULE       = dolicli
DOC_HOST     ?= doc.cap-rel.fr
DOC_PATH     ?= /home/webs/doc.cap-rel.fr/marginaliamd/data/documents/$(MODULE)
MARGINALIAMD ?= $(HOME)/dev/marginaliamd
PHPSTAN      = $(if $(wildcard vendor/phpstan/phpstan/phpstan),vendor/phpstan/phpstan/phpstan,phpstan)
PHPSTAN_LOCAL ?= .phpstan.local.neon
TMP          = /tmp/$(USER)/$(MODULE)
# PHP binaries of the compatibility matrix present on this machine (syntax check of the body).
PHP_MATRIX   ?= $(foreach v,7.0 7.1 7.2 7.3 7.4 8.0 8.1 8.2 8.3 8.4 8.5,$(wildcard /usr/bin/php$(v)))

.PHONY: help deploy-doc screenshots build fleet-phar test lint lint-server phpstan phpstan-all

help:
	@echo ''
	@echo 'Usage: make <target>'
	@echo ''
	@echo '  build         -  build the dolicli body and launcher in dist/'
	@echo '  fleet-phar    -  build dist/dolifleet.phar (pilot, with the dolicli body)'
	@echo '  test          -  run unit and integration tests (SQLite Dolibarr from composer)'
	@echo '  lint          -  PHP syntax, code style (phpcs for server/, pint for the rest)'
	@echo '  lint-server   -  php -l of the built body under every PHP of the matrix, PHP 7.0 compatibility'
	@echo '  phpstan       -  static analysis (needs .phpstan.local.neon, DOLIBARR_VERSION=18 by default)'
	@echo '  phpstan-all   -  server analysis against every Dolibarr from 10 to 24'
	@echo '  deploy-doc    -  publish user documentation to $(DOC_HOST)'
	@echo '  screenshots   -  collect missing screenshots interactively'
	@echo ''

build:
	php build/build-server.php

fleet-phar: build
	vendor/bin/box compile

test: build
	vendor/bin/pest

lint: lint-server
	@for f in $$(git ls-files 'tests/*.php' 'build/*.php'); do php -l $$f > /dev/null || exit 1; done
	vendor/bin/pint --test

lint-server: build
	@for php in $(PHP_MATRIX); do $$php -l dist/dolicli.php > /dev/null || { echo "$$php: syntax error in dist/dolicli.php"; exit 1; }; echo "$$php -l dist/dolicli.php: ok"; done
	vendor/bin/phpcs
	vendor/bin/phpcs --standard=PHPCompatibility --runtime-set testVersion 5.3- server/launcher.php

phpstan:
	@test -f $(PHPSTAN_LOCAL) || { \
		echo 'missing $(PHPSTAN_LOCAL): cp .phpstan.example.neon .phpstan.local.neon, then adjust the paths to this machine'; \
		exit 1; \
	}
	@mkdir -p $(TMP)
	@for cfg in .phpstan.dist.neon .phpstan.tools.dist.neon; do \
		printf 'includes:\n\t- %s\n\t- %s\n' "$(CURDIR)/$$cfg" "$(CURDIR)/$(PHPSTAN_LOCAL)" > $(TMP)/$$cfg; \
		echo "phpstan $$cfg"; \
		TMPDIR=$(TMP) DOLIBARR_VERSION=$${DOLIBARR_VERSION:-18} php $(PHPSTAN) analyse -c $(TMP)/$$cfg --memory-limit=1G --no-progress || exit 1; \
	done

phpstan-all:
	@for v in 10 11 12 13 14 15 16 17 18 19 20 21 22 23 24; do \
		printf 'includes:\n\t- %s\n\t- %s\n' "$(CURDIR)/.phpstan.dist.neon" "$(CURDIR)/$(PHPSTAN_LOCAL)" > $(TMP)/phpstan-server.neon; \
		echo "Dolibarr $$v"; \
		TMPDIR=$(TMP) DOLIBARR_VERSION=$$v php $(PHPSTAN) analyse -c $(TMP)/phpstan-server.neon --memory-limit=1G --no-progress --error-format=raw || exit 1; \
	done

deploy-doc:
	rsync -avLP docs/users/ $(DOC_HOST):$(DOC_PATH)/

screenshots:
	php $(MARGINALIAMD)/cli/collect-screenshots.php docs/users/
