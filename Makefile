COMPOSE = docker compose
PHP     = $(COMPOSE) run --rm php
NODE    = cd implementations/node &&

.PHONY: build install test stan check shell fixtures fixtures-strict fixtures-db cross \
        node-install node-test node-fixtures node-fixtures-db check-node check-all

# ---------------------------------------------------------------------------
# PHP (in Docker — no local PHP needed)
# ---------------------------------------------------------------------------

fixtures:     ## Conformance fixtures against the core (fast, while developing)
	$(PHP) php runner/bin/run-fixtures.php

fixtures-strict: ## The same fixtures, but a newly green one without an entry is an error
	$(PHP) php runner/bin/run-fixtures.php --strict

fixtures-db:  ## The same fixtures against the DATABASE adapter (SQLite)
	# The run the in-memory gates cannot stand in for: the adapters build the tenant
	# themselves, and what they leave out is invisible to a test running against fakes.
	# That is exactly how a missing AuditWriter in DatabaseTenantFactory went unnoticed
	# until CI found it — everything was green locally, because `check` did not know this run.
	$(PHP) php runner/bin/run-fixtures.php --strict --subject=database

build:        ## Build the PHP image
	$(COMPOSE) build php

install:      ## Install the Composer dependencies
	$(PHP) composer install

test:         ## PHPUnit + coverage gate (floor per package, see coverage-gate.php)
	$(PHP) sh -c 'vendor/bin/phpunit --coverage-text --coverage-clover=coverage.xml && php runner/bin/coverage-gate.php coverage.xml'

stan:         ## PHPStan (level max)
	$(PHP) vendor/bin/phpstan analyse

check: stan test fixtures-strict fixtures-db  ## Everything CI checks for PHP — really everything

shell:        ## A shell in the PHP container
	$(PHP) bash

# ---------------------------------------------------------------------------
# Node (local pnpm, no Docker)
#
# These exist because the two halves used to be reachable differently: `make check` ran the
# whole PHP gate in one word, while the Node gate lived as five commands in a CLAUDE.md — and
# the list there was short by exactly the database-subject run that CI does perform. A gate
# that is more convenient on one side gets run more on that side.
# ---------------------------------------------------------------------------

node-install: ## Install the pnpm dependencies
	$(NODE) pnpm install --frozen-lockfile

node-test:    ## Typecheck + lint + vitest incl. the coverage floors
	$(NODE) pnpm typecheck && pnpm lint && pnpm test

node-fixtures: ## Conformance fixtures against the core (strict)
	$(NODE) pnpm fixtures --strict

node-fixtures-db: ## The same fixtures against the Knex adapter (SQLite) — see fixtures-db
	$(NODE) pnpm fixtures --subject=database --strict

check-node: node-test node-fixtures node-fixtures-db  ## Everything CI checks for Node

# ---------------------------------------------------------------------------
# Both, plus what only exists between them
# ---------------------------------------------------------------------------

cross:        ## SF-15 cross-test (both directions): PHP <-> Node on one shared SQLite
	$(PHP) php runner/bin/cross-export.php
	$(NODE) pnpm exec tsx runner/bin/cross-write.ts
	$(PHP) php runner/bin/cross-read.php
	$(NODE) pnpm exec tsx runner/bin/cross-read.ts

check-all: check check-node cross  ## The full Definition of Green: both gates and the cross-test
