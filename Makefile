# Makefile for Evolver
# Manages a long-running compose service with hot caches (JIT, FFI Preload)

COMPOSE ?= docker compose
SERVICE_NAME = evolver
DOCKER_COMPOSE_EXEC_FLAGS ?= $(shell if [ -t 0 ] && [ -t 1 ]; then echo ""; else echo "-T"; fi)
DOCKER_COMPOSE_RUN_FLAGS ?= $(shell if [ -t 0 ] && [ -t 1 ]; then echo "--rm"; else echo "--rm -T"; fi)

# Defaults
PHP_BIN = php85
WORKERS ?= 4
INDEX_PATH ?= /mnt/project/core/modules/user
INDEX_TAG ?= 11.0.0
SCAN_PATH ?= /mnt/project
SCAN_TARGET ?= 11.0.0
EXTRA_HOST_PATH ?=
EXTRA_CONTAINER_PATH ?= /mnt/project
EXTRA_MOUNT_ARG := $(if $(strip $(EXTRA_HOST_PATH)),--volume $(abspath $(EXTRA_HOST_PATH)):$(EXTRA_CONTAINER_PATH):ro,)

# Profiling paths
PROFILE_ROOT = .data/profiles
MEMPROF_SO = /usr/lib/php85/modules/memprof.so
MEMINFO_SO = /usr/lib/php85/modules/meminfo.so
XHPROF_SO = /usr/lib/php85/modules/xhprof.so
SPX_SO = /usr/lib/php85/modules/spx.so

# Command passthrough
PASS_THROUGH_TARGETS := e ev er evr run exec
FIRST_GOAL := $(firstword $(MAKECMDGOALS))
ifneq (,$(filter $(FIRST_GOAL),$(PASS_THROUGH_TARGETS)))
PASSTHRU_ARGS := $(filter-out --,$(wordlist 2,$(words $(MAKECMDGOALS)),$(MAKECMDGOALS)))
$(eval $(PASSTHRU_ARGS):;@:)
endif
CMDLINE_EQ_FLAGS := $(foreach v,$(filter --%,$(.VARIABLES)),$(if $(filter command line,$(origin $(v))),$(v)=$(value $(v))))
CLI_ARGS = $(strip $(PASSTHRU_ARGS) $(CMDLINE_EQ_FLAGS))

# Reusable profiling macro
# Usage: $(call EXEC_PROFILED,name,extension_so,env_vars,php_flags)
define EXEC_PROFILED
	@mkdir -p $(PROFILE_ROOT)/$(1)
	$(COMPOSE) run $(DOCKER_COMPOSE_RUN_FLAGS) --no-deps $(EXTRA_MOUNT_ARG) $(SERVICE_NAME) sh -lc "/usr/bin/time -f 'real=%e user=%U sys=%S maxrss=%M' $(3) $(PHP_BIN) -d extension=$(2) $(4) /app/scripts/$(1)-run.php $(PROFILE_ROOT)/$(1)/index.json index $(INDEX_PATH) --tag=$(INDEX_TAG) --workers=1" > $(PROFILE_ROOT)/$(1)/run.log 2>&1
endef

.PHONY: prepare build up down restart e ev er evr run exec engine-status shell shellr r ffi-check tests clean profile profile-report help

prepare:
	mkdir -p .data .cache/composer .cache/phpunit

build: prepare
	$(COMPOSE) build

up: prepare
	$(COMPOSE) up -d

down:
	$(COMPOSE) down --remove-orphans

restart: down up

e:
	@if [ -z "$(CLI_ARGS)" ]; then echo "Usage: make e -- <shell command>"; exit 2; fi
	@if [ -n "$(EXTRA_HOST_PATH)" ]; then echo "Use 'make er -- ... EXTRA_HOST_PATH=...' for one-off mounted shell commands."; exit 2; fi
	$(COMPOSE) exec $(DOCKER_COMPOSE_EXEC_FLAGS) $(SERVICE_NAME) sh -lc "$(CLI_ARGS)"

ev:
	@if [ -z "$(CLI_ARGS)" ]; then echo "Usage: make ev -- <args>"; exit 2; fi
	@if [ -n "$(EXTRA_HOST_PATH)" ]; then echo "Use 'make evr -- ... EXTRA_HOST_PATH=...' for one-off mounted evolver commands."; exit 2; fi
	$(COMPOSE) exec $(DOCKER_COMPOSE_EXEC_FLAGS) $(SERVICE_NAME) $(PHP_BIN) bin/evolver $(CLI_ARGS)

er:
	@if [ -z "$(CLI_ARGS)" ]; then echo "Usage: make er -- <shell command> EXTRA_HOST_PATH=../path"; exit 2; fi
	@if [ -z "$(EXTRA_HOST_PATH)" ]; then echo "EXTRA_HOST_PATH is required for 'make er'."; exit 2; fi
	$(COMPOSE) run $(DOCKER_COMPOSE_RUN_FLAGS) --no-deps $(EXTRA_MOUNT_ARG) $(SERVICE_NAME) sh -lc "$(CLI_ARGS)"

evr:
	@if [ -z "$(CLI_ARGS)" ]; then echo "Usage: make evr -- <args> EXTRA_HOST_PATH=../path"; exit 2; fi
	@if [ -z "$(EXTRA_HOST_PATH)" ]; then echo "EXTRA_HOST_PATH is required for 'make evr'."; exit 2; fi
	$(COMPOSE) run $(DOCKER_COMPOSE_RUN_FLAGS) --no-deps $(EXTRA_MOUNT_ARG) $(SERVICE_NAME) $(PHP_BIN) bin/evolver $(CLI_ARGS)

run: evr
exec: e

engine-status:
	@$(COMPOSE) ps
	@if [ -n "$$($(COMPOSE) ps -q $(SERVICE_NAME))" ]; then \
		$(COMPOSE) exec -T $(SERVICE_NAME) $(PHP_BIN) bin/evolver status; \
	fi

shell:
	@if [ -n "$(EXTRA_HOST_PATH)" ]; then echo "Use 'make shellr EXTRA_HOST_PATH=../path' for a one-off mounted shell."; exit 2; fi
	$(COMPOSE) exec $(SERVICE_NAME) sh

shellr:
	@if [ -z "$(EXTRA_HOST_PATH)" ]; then echo "EXTRA_HOST_PATH is required for 'make shellr'."; exit 2; fi
	$(COMPOSE) run --rm --no-deps $(EXTRA_MOUNT_ARG) $(SERVICE_NAME) sh

r:
	$(COMPOSE) exec $(DOCKER_COMPOSE_EXEC_FLAGS) $(SERVICE_NAME) sh /app/file.sh

ffi-check: r

tests:
	$(COMPOSE) exec -T $(SERVICE_NAME) sh -lc "vendor/bin/phpunit --display-warnings --display-deprecations"

profile:
	@echo "Running profiling suite..."
	$(call EXEC_PROFILED,memprof,$(MEMPROF_SO),env MEMPROF_PROFILE=1,)
	$(call EXEC_PROFILED,meminfo,$(MEMINFO_SO),,)
	$(call EXEC_PROFILED,spx,$(SPX_SO),env SPX_ENABLED=1 SPX_REPORT=full SPX_AUTO_START=0,-d opcache.enable_cli=0)
	$(call EXEC_PROFILED,xhprof,$(XHPROF_SO),,)
	@$(MAKE) profile-report

profile-report:
	@$(COMPOSE) exec -T $(SERVICE_NAME) $(PHP_BIN) /app/scripts/profile-report.php /app/.data/profiles | tee $(PROFILE_ROOT)/summary.md

clean:
	rm -f .data/evolver.sqlite .data/evolver.sqlite-*
	rm -rf .cache/phpunit .cache/composer $(PROFILE_ROOT)/*

help:
	@echo "Evolver Commands:"
	@echo "-----------------------"
	@echo "make build          - Build the compose service image"
	@echo "make up             - Start the compose service"
	@echo "make down           - Stop the compose service"
	@echo "make e -- <cmd>     - Exec a shell command in the running service"
	@echo "make ev -- <args>   - Exec evolver in the running service"
	@echo "make er -- <cmd>    - Run one-off shell command with EXTRA_HOST_PATH mounted at $(EXTRA_CONTAINER_PATH)"
	@echo "make evr -- <args>  - Run one-off evolver command with EXTRA_HOST_PATH mounted at $(EXTRA_CONTAINER_PATH)"
	@echo "make tests          - Run PHPUnit"
	@echo "make profile        - Run all profilers"
	@echo "make profile-report - Show profiling summary"
	@echo "make shell          - Enter the running service"
	@echo "make shellr         - Start a one-off shell with EXTRA_HOST_PATH mounted"
