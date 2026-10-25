DC   := docker compose
EXEC := $(DC) exec -T php

.PHONY: build up down sh test qa coverage guard-running

build:
	HOST_UID=$$(id -u) HOST_GID=$$(id -g) $(DC) build

up:
	HOST_UID=$$(id -u) HOST_GID=$$(id -g) $(DC) up -d --build

# No -v: the pgdata volume survives `make down` (acceptance criterion 5).
down:
	$(DC) down

sh: guard-running
	$(DC) exec php bash

test: guard-running
	$(EXEC) composer test

qa: guard-running
	$(EXEC) composer qa

coverage: guard-running
	$(EXEC) composer coverage

guard-running:
	@$(DC) ps --status running --services | grep -qx php \
		|| { echo "php container is not running - run 'make up' first"; exit 1; }
