# =============================================================================
# EruoFood AI — developer control surface.
# Run `make help` to list targets. Most targets wrap Docker Compose so the
# stack behaves identically on every machine.
# =============================================================================

# Load root .env if present so port/name vars are available to compose.
-include .env
export

COMPOSE ?= docker compose
API_EXEC = $(COMPOSE) exec api
WEB_DIR  = apps/web
API_DIR  = apps/api

.DEFAULT_GOAL := help

## ----------------------------------------------------------------------------
## Help
## ----------------------------------------------------------------------------
.PHONY: help
help: ## Show this help
	@grep -E '^[a-zA-Z0-9_.-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2}'

## ----------------------------------------------------------------------------
## Setup
## ----------------------------------------------------------------------------
.PHONY: init
init: ## First-time setup: copy env files
	@test -f .env || cp .env.example .env
	@test -f $(API_DIR)/.env || cp $(API_DIR)/.env.example $(API_DIR)/.env
	@test -f $(WEB_DIR)/.env || cp $(WEB_DIR)/.env.example $(WEB_DIR)/.env
	@test -f apps/mobile/.env || cp apps/mobile/.env.example apps/mobile/.env
	@echo "Environment files ready. Next: make up && make install"

.PHONY: install
install: ## Install dependencies for all apps
	$(COMPOSE) run --rm api composer install
	cd $(WEB_DIR) && npm install

## ----------------------------------------------------------------------------
## Docker lifecycle
## ----------------------------------------------------------------------------
.PHONY: build
build: ## Build all images
	$(COMPOSE) build

.PHONY: up
up: ## Start the full stack (detached)
	$(COMPOSE) up -d

.PHONY: down
down: ## Stop the stack
	$(COMPOSE) down

.PHONY: restart
restart: down up ## Restart the stack

.PHONY: logs
logs: ## Tail logs from all services
	$(COMPOSE) logs -f --tail=100

.PHONY: ps
ps: ## Show running services
	$(COMPOSE) ps

.PHONY: shell
shell: ## Open a shell in the API container
	$(API_EXEC) sh

## ----------------------------------------------------------------------------
## Application helpers
## ----------------------------------------------------------------------------
.PHONY: key
key: ## Generate the Laravel app key
	$(API_EXEC) php artisan key:generate

.PHONY: migrate
migrate: ## Run database migrations
	$(API_EXEC) php artisan migrate

.PHONY: fresh
fresh: ## Drop and re-run all migrations (DANGER: dev only)
	$(API_EXEC) php artisan migrate:fresh

## ----------------------------------------------------------------------------
## Quality gates (mirror CI)
## ----------------------------------------------------------------------------
.PHONY: lint
lint: ## Lint API + Web
	$(API_EXEC) composer run lint
	cd $(WEB_DIR) && npm run lint

.PHONY: analyse
analyse: ## Static analysis (API)
	$(API_EXEC) composer run analyse

.PHONY: test
test: ## Run API + Web tests
	$(API_EXEC) composer run test
	cd $(WEB_DIR) && npm run test

.PHONY: check
check: lint analyse test ## Run all quality gates

.PHONY: format
format: ## Auto-format API + Web
	$(API_EXEC) composer run lint:fix
	cd $(WEB_DIR) && npm run format
