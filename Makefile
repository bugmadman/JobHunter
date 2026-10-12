# docker compose reads only .env by itself; .env.local is passed when it exists, so its values take precedence.
# Checked by the shell, not $(wildcard): make caches the directory listing and misses the file init creates
DC = docker compose --env-file .env $$([ -f .env.local ] && echo --env-file .env.local)
PHP = $(DC) exec php

.PHONY: init encryption-key upd updb down ps in cs fix stan test test-db lint

init: .env.local encryption-key
	$(DC) build
	$(DC) run --rm --no-deps php composer install
	$(DC) up -d --wait
	$(PHP) bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
	$(MAKE) test-db

# The host's UID goes into the image, so that files the container creates in the project belong to the host user.
# UID 0 would clash with the image's root and fail the build with an unclear "adduser: uid '0' in use"
.env.local:
	@uid=$$(id -u); \
	if [ "$$uid" -eq 0 ]; then \
		echo "Run make init as a regular user, not root: the container user takes the host's UID" >&2; \
		exit 1; \
	fi; \
	printf '# Machine-specific overrides of .env, not committed\nAPP_USERID=%s\n' "$$uid" > $@; \
	echo "Created $@ with APP_USERID=$$uid"

# Never replaces an existing key: the LLM API keys stored with it would become undecryptable.
# tail -c1 guards a .env.local without a final newline, where the new line would be glued to the last one
encryption-key: .env.local
	@if grep -Eq '^LLM_KEY_ENCRYPTION_KEY=[A-Za-z0-9+/]' .env.local; then \
		echo "LLM_KEY_ENCRYPTION_KEY is already set in .env.local"; \
	else \
		[ -z "$$(tail -c1 .env.local)" ] || echo >> .env.local; \
		printf 'LLM_KEY_ENCRYPTION_KEY=%s\n' "$$(head -c 32 /dev/urandom | base64)" >> .env.local; \
		chmod 600 .env.local; \
		echo "Added LLM_KEY_ENCRYPTION_KEY to .env.local"; \
	fi

upd:
	$(DC) up -d --wait

updb:
	$(DC) up -d --build --wait

down:
	$(DC) down

ps:
	$(DC) ps

in:
	$(DC) exec php sh

cs:
	$(PHP) vendor/bin/php-cs-fixer fix --dry-run --diff
	$(PHP) vendor/bin/phpcs

# Rector first: CS-Fixer then formats whatever Rector rewrote. Line length is still fixed by hand
fix:
	$(PHP) vendor/bin/rector process
	$(PHP) vendor/bin/php-cs-fixer fix

# lint:container compiles the dev container: without its XML phpstan-symfony silently loses the service types
stan:
	$(PHP) bin/console lint:container
	$(PHP) vendor/bin/phpstan analyse

test:
	$(PHP) bin/phpunit

# Not run by test, to keep it fast: rerun after a new migration or a fixture change, or tests hit a stale schema
test-db:
	$(PHP) bin/console doctrine:database:drop --env=test --force --if-exists
	$(PHP) bin/console doctrine:database:create --env=test
	$(PHP) bin/console doctrine:migrations:migrate --env=test --no-interaction --allow-no-migration
	$(PHP) bin/console doctrine:fixtures:load --env=test --no-interaction

lint: cs stan test
	$(PHP) vendor/bin/rector process --dry-run
