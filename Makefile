# docker compose reads only .env by itself; .env.local is passed when it exists, so its values take precedence.
# Checked by the shell, not $(wildcard): make caches the directory listing and misses the file init creates
DC = docker compose --env-file .env $$([ -f .env.local ] && echo --env-file .env.local)

.PHONY: init upd down ps in

init: .env.local
	$(DC) up -d --build --wait
	$(DC) exec php composer install

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

upd:
	$(DC) up -d --wait

down:
	$(DC) down

ps:
	$(DC) ps

in:
	$(DC) exec php sh
