.PHONY: help phpstan phpcs phpcs-fix pest test install clean

help: ## Show this help message
	@echo 'Usage: make [target]'
	@echo ''
	@echo 'Available targets:'
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "  \033[36m%-15s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

install: ## Install composer dependencies
	composer install

phpstan: ## Run PHPStan static analysis
	vendor/bin/phpstan analyse --memory-limit=1024M

phpcs: ## Run PHP CS Fixer in dry-run mode
	vendor/bin/php-cs-fixer fix --dry-run --diff --verbose

phpcs-fix: ## Run PHP CS Fixer and fix issues
	vendor/bin/php-cs-fixer fix

pest: ## Run Pest tests
	php -d extension=igbinary -d extension=redis vendor/bin/pest

test: ## Run all tests and checks
	@echo "Running PHPStan..."
	@make phpstan
	@echo ""
	@echo "Running PHP CS Fixer check..."
	@make phpcs
	@echo ""
	@echo "Running Pest tests..."
	@make pest

clean: ## Clean vendor directory
	rm -rf vendor
