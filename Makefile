.PHONY: dev stop migrate

dev:
	symfony serve --port=8014 -d
	@echo "App disponible sur https://127.0.0.1:8014"

stop:
	symfony server:stop

migrate:
	php bin/console doctrine:migrations:migrate --no-interaction

logs:
	symfony server:log
