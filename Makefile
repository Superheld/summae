COMPOSE = docker compose
PHP     = $(COMPOSE) run --rm php

.PHONY: build install test stan check sync shell

build:        ## PHP-Image bauen
	$(COMPOSE) build php

install:      ## Composer-Abhängigkeiten installieren
	$(PHP) composer install

test:         ## PHPUnit
	$(PHP) vendor/bin/phpunit

stan:         ## PHPStan (level max)
	$(PHP) vendor/bin/phpstan analyse

check: stan test  ## Alles, was CI prüft

sync:         ## Testsuite aus der Wissensbasis synchronisieren (Einbahnstraße)
	./bin/sync-testsuite.sh

shell:        ## Shell im PHP-Container
	$(PHP) bash
