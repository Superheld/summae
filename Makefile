COMPOSE = docker compose
PHP     = $(COMPOSE) run --rm php

.PHONY: build install test stan check sync shell fixtures cross

fixtures:     ## Konformitäts-Fixtures gegen den Kern laufen lassen
	$(PHP) php runner/bin/run-fixtures.php

cross:        ## SF-15 Cross-Test (beide Richtungen): PHP <-> Node auf geteilter SQLite
	$(PHP) php runner/bin/cross-export.php
	cd implementations/node && pnpm exec tsx runner/bin/cross-write.ts
	$(PHP) php runner/bin/cross-read.php
	cd implementations/node && pnpm exec tsx runner/bin/cross-read.ts

build:        ## PHP-Image bauen
	$(COMPOSE) build php

install:      ## Composer-Abhängigkeiten installieren
	$(PHP) composer install

test:         ## PHPUnit + Coverage-Gate (Kern-Zeilen >= 88%)
	$(PHP) sh -c 'vendor/bin/phpunit --coverage-text --coverage-clover=coverage.xml && php runner/bin/coverage-gate.php coverage.xml 88'

stan:         ## PHPStan (level max)
	$(PHP) vendor/bin/phpstan analyse

check: stan test  ## Alles, was CI prüft

sync:         ## Testsuite aus der Wissensbasis synchronisieren (Einbahnstraße)
	./bin/sync-testsuite.sh

shell:        ## Shell im PHP-Container
	$(PHP) bash
