MODULE       = dolicli
DOC_HOST     ?= doc.cap-rel.fr
DOC_PATH     ?= /home/webs/doc.cap-rel.fr/marginaliamd/data/documents/$(MODULE)
MARGINALIAMD ?= $(HOME)/dev/marginaliamd

.PHONY: help deploy-doc screenshots

help:
	@echo ''
	@echo 'Usage: make <target>'
	@echo ''
	@echo '  deploy-doc    -  publish user documentation to $(DOC_HOST)'
	@echo '  screenshots   -  collect missing screenshots interactively'
	@echo ''

deploy-doc:
	rsync -avLP docs/users/ $(DOC_HOST):$(DOC_PATH)/

screenshots:
	php $(MARGINALIAMD)/cli/collect-screenshots.php docs/users/
