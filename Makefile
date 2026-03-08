# Makefile for Evolver
# Manages long-running compose services with hot caches (JIT, FFI Preload)

COMPOSE ?= docker compose
SERVICE_NAME = evolver
COMPOSE_BAKE ?= true
DOCKER_COMPOSE_EXEC_FLAGS ?= $(shell if [ -t 0 ] && [ -t 1 ]; then echo ""; else echo "-T"; fi)
DOCKER_COMPOSE_RUN_FLAGS ?= $(shell if [ -t 0 ] && [ -t 1 ]; then echo "--rm"; else echo "--rm -T"; fi)

# User configuration - match host user for proper file permissions
USER_ID ?= $(shell id -u)
GROUP_ID ?= $(shell id -g)

# Defaults
PHP_BIN = php85
WORKERS ?= 4
WEB_HOST ?= 0.0.0.0
WEB_PORT ?= 8080
QUEUE_SLEEP ?= 2
INDEX_PATH ?= /mnt/drupal/core/modules/user
INDEX_TAG ?= 11.0.0
SEARCH_TAG ?= $(INDEX_TAG)
SEARCH_TERM ?=
SEARCH_TYPES ?=
SEARCH_DB ?= .data/evolver.sqlite
SEARCH_LIMIT ?= 50
EXTRA_HOST_PATH ?=
EXTRA_CONTAINER_PATH ?= /mnt/project
EXTRA_MOUNT_ARG := $(if $(strip $(EXTRA_HOST_PATH)),--volume $(abspath $(EXTRA_HOST_PATH)):$(EXTRA_CONTAINER_PATH):ro,)

# Command passthrough logic
PASS_THROUGH_TARGETS := e ev er evr run exec r php sh
FIRST_GOAL := $(firstword $(MAKECMDGOALS))
ifneq (,$(filter $(FIRST_GOAL),$(PASS_THROUGH_TARGETS)))
PASSTHRU_ARGS := $(filter-out $(FIRST_GOAL) --,$(MAKECMDGOALS))
CMDLINE_VAR_NAMES := $(foreach v,$(.VARIABLES),$(if $(filter command line,$(origin $(v))),$(v)))
PASSTHRU_FLAG_ARGS := $(foreach v,$(filter -%,$(CMDLINE_VAR_NAMES)),$(v)=$($(v)))
%:
	@:
endif
CLI_ARGS = $(strip $(PASSTHRU_ARGS) $(PASSTHRU_FLAG_ARGS))

.PHONY: prepare build up down restart e ev er evr run exec engine-status shell sh phpsh r php tests clean help yaml-search

prepare:
	mkdir -p .data .cache/composer .cache/phpunit

build: prepare
	COMPOSE_BAKE=$(COMPOSE_BAKE) $(COMPOSE) build

up: prepare
	$(COMPOSE) up -d

down:
	$(COMPOSE) down --remove-orphans

restart: down up

# Execute shell command in running container (General purpose)
e:
	@if [ -z "$(CLI_ARGS)" ]; then echo "Usage: make e -- <shell command>"; exit 2; fi
	$(COMPOSE) exec $(DOCKER_COMPOSE_EXEC_FLAGS) $(SERVICE_NAME) sh -c "$(CLI_ARGS)"

# Execute evolver command in running container
ev:
	@if [ -z "$(CLI_ARGS)" ]; then echo "Usage: make ev -- <args>"; exit 2; fi
	$(COMPOSE) exec $(DOCKER_COMPOSE_EXEC_FLAGS) $(SERVICE_NAME) $(PHP_BIN) bin/evolver $(CLI_ARGS)

# Run one-off shell command with optional mount
er:
	$(COMPOSE) run $(DOCKER_COMPOSE_RUN_FLAGS) --no-deps $(EXTRA_MOUNT_ARG) $(SERVICE_NAME) sh -c "$(CLI_ARGS)"

# Run one-off evolver command with optional mount
evr:
	$(COMPOSE) run $(DOCKER_COMPOSE_RUN_FLAGS) --no-deps $(EXTRA_MOUNT_ARG) $(SERVICE_NAME) $(PHP_BIN) bin/evolver $(CLI_ARGS)

run: evr
exec: e
r: e

# Tool-specific shortcuts
php:
	$(COMPOSE) exec $(DOCKER_COMPOSE_EXEC_FLAGS) $(SERVICE_NAME) $(PHP_BIN) $(CLI_ARGS)

sh:
	@if [ -z "$(CLI_ARGS)" ]; then \
		$(COMPOSE) exec $(SERVICE_NAME) sh; \
	else \
		$(COMPOSE) exec $(DOCKER_COMPOSE_EXEC_FLAGS) $(SERVICE_NAME) sh -c "$(CLI_ARGS)"; \
    fi

engine-status:
	@$(COMPOSE) ps
	@if [ -n "$$($(COMPOSE) ps -q $(SERVICE_NAME))" ]; then \
		$(COMPOSE) exec -T $(SERVICE_NAME) $(PHP_BIN) bin/evolver status; \
	fi

shell: sh

shell0:
	@echo "NOTE: shell0 runs as root - use only for system admin (apk, chown, etc.)"
	$(COMPOSE) exec -u root $(SERVICE_NAME) sh

phpsh:
	$(COMPOSE) exec $(SERVICE_NAME) $(PHP_BIN) -a

tests:
	$(COMPOSE) exec -T $(SERVICE_NAME) vendor/bin/phpunit --display-warnings --display-deprecations

yaml-search:
	@if [ -z "$(SEARCH_TERM)" ]; then echo "Usage: make yaml-search SEARCH_TAG=<version> SEARCH_TERM=<term> [SEARCH_TYPES=type1,type2] [SEARCH_DB=<path>] [SEARCH_LIMIT=50]"; exit 2; fi
	$(COMPOSE) exec $(DOCKER_COMPOSE_EXEC_FLAGS) $(SERVICE_NAME) $(PHP_BIN) scripts/semantic-yaml-search.php --db="$(SEARCH_DB)" --tag="$(SEARCH_TAG)" $(if $(strip $(SEARCH_TYPES)),--types="$(SEARCH_TYPES)",) --limit="$(SEARCH_LIMIT)" "$(SEARCH_TERM)"

clean:
	rm -f .data/evolver.sqlite .data/evolver.sqlite-*
	rm -rf .cache/phpunit .cache/composer

help:
	@echo "Evolver Control Commands:"
	@echo "-----------------------"
	@echo "make build          - Build the Docker image"
	@echo "make up             - Start the service (Web UI + Queue Worker)"
	@echo "make down           - Stop the service"
	@echo "make restart        - Full restart of the service"
	@echo ""
	@echo "Execution & Debugging:"
	@echo "-----------------------"
	@echo "make ev -- status   - Execute 'bin/evolver status' in the running container"
	@echo "make evr -- index   - Run evolver in a one-off container (mounts EXTRA_HOST_PATH)"
	@echo "make php -- -v      - Run raw PHP in the running container"
	@echo "make r -- <cmd>     - Alias for 'e' - run any shell command in the container"
	@echo "make sh             - Open interactive shell in the container (as evolver user)"
	@echo "make sh -- <cmd>    - Run a shell command in the container"
	@echo "make phpsh          - Start interactive PHP shell (php -a)"
	@echo "make shell0         - Open root shell (for system admin only - apk, chown, etc.)"
	@echo ""
	@echo "Development & Verification:"
	@echo "-----------------------"
	@echo "make tests          - Run PHPUnit test suite"
	@echo "make yaml-search    - Search indexed semantic YAML and Drupal library symbols"
	@echo "make clean          - Remove database and local caches"
	@echo "make engine-status  - Show container status and internal evolver health"
