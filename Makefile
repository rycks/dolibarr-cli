MODULE       = dolicli
DOC_HOST     ?= doc.cap-rel.fr
DOC_PATH     ?= /home/webs/doc.cap-rel.fr/marginaliamd/data/documents/$(MODULE)
MARGINALIAMD ?= $(HOME)/dev/marginaliamd
PHPSTAN      = $(if $(wildcard vendor/phpstan/phpstan/phpstan),vendor/phpstan/phpstan/phpstan,phpstan)

.PHONY: help deploy-doc screenshots test lint phpstan phar

help:
	@echo ''
	@echo 'Usage: make <target>'
	@echo ''
	@echo '  test          -  run unit and integration tests (SQLite Dolibarr from composer)'
	@echo '  lint          -  check code style (pint --test) and PHP syntax'
	@echo '  phpstan       -  static analysis (needs .phpstan.local.neon)'
	@echo '  phar          -  build dolicli.phar with box'
	@echo '  deploy-doc    -  publish user documentation to $(DOC_HOST)'
	@echo '  screenshots   -  collect missing screenshots interactively'
	@echo ''

test:
	vendor/bin/pest

lint:
	@for f in dolibarr $$(git ls-files 'app/*.php' 'config/*.php' 'tests/*.php'); do php -l $$f > /dev/null || exit 1; done
	vendor/bin/pint --test

phpstan:
	@test -f .phpstan.local.neon || { \
		echo 'missing .phpstan.local.neon: cp .phpstan.example.neon .phpstan.local.neon, then adjust the paths to this machine'; \
		exit 1; \
	}
	mkdir -p /tmp/$(USER)/$(MODULE)
	TMPDIR=/tmp/$(USER)/$(MODULE) DOLIBARR_VERSION=$${DOLIBARR_VERSION:-18} php $(PHPSTAN) analyse -c .phpstan.local.neon --memory-limit=1G

phar:
	vendor/bin/box compile

deploy-doc:
	rsync -avLP docs/users/ $(DOC_HOST):$(DOC_PATH)/

screenshots:
	php $(MARGINALIAMD)/cli/collect-screenshots.php docs/users/
