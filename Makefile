COMPOSE = docker compose
PHP     = $(COMPOSE) run --rm php

.PHONY: build install test stan check sync shell fixtures fixtures-strict fixtures-db cross

fixtures:     ## Konformitäts-Fixtures gegen den Kern (schnell, beim Entwickeln)
	$(PHP) php runner/bin/run-fixtures.php

fixtures-strict: ## Dieselben Fixtures, aber eine neu-grüne ohne Eintrag ist ein Fehler
	$(PHP) php runner/bin/run-fixtures.php --strict

fixtures-db:  ## Dieselben Fixtures gegen den DATENBANK-Adapter (SQLite)
	# Der Lauf, der die in-memory-Gates nicht ersetzen können: die Adapter bauen den Tenant
	# selbst, und was sie dabei weglassen, sieht kein Test, der gegen Fakes läuft. Genau so
	# blieb ein fehlender AuditWriter in DatabaseTenantFactory unbemerkt, bis CI ihn fand —
	# lokal war alles grün, weil `check` diesen Lauf nicht kannte.
	$(PHP) php runner/bin/run-fixtures.php --strict --subject=database

cross:        ## SF-15 Cross-Test (beide Richtungen): PHP <-> Node auf geteilter SQLite
	$(PHP) php runner/bin/cross-export.php
	cd implementations/node && pnpm exec tsx runner/bin/cross-write.ts
	$(PHP) php runner/bin/cross-read.php
	cd implementations/node && pnpm exec tsx runner/bin/cross-read.ts

build:        ## PHP-Image bauen
	$(COMPOSE) build php

install:      ## Composer-Abhängigkeiten installieren
	$(PHP) composer install

test:         ## PHPUnit + Coverage-Gate (Boden je Paket, siehe coverage-gate.php)
	$(PHP) sh -c 'vendor/bin/phpunit --coverage-text --coverage-clover=coverage.xml && php runner/bin/coverage-gate.php coverage.xml'

stan:         ## PHPStan (level max)
	$(PHP) vendor/bin/phpstan analyse

check: stan test fixtures-strict fixtures-db  ## Alles, was CI für PHP prüft — jetzt wirklich

sync:         ## Pack-Library aus der Wissensbasis spiegeln (Einbahnstraße, letzter Spiegel)
	./bin/sync-pack-library.sh

shell:        ## Shell im PHP-Container
	$(PHP) bash
