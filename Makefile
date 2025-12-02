.PHONY: dev stop migrate logs deploy

# Configuration O2Switch - À personnaliser
PROD_HOST ?= o2switch
PROD_PATH ?= ~/stopclope.votredomaine.fr

dev:
	symfony serve --port=8014 -d
	@echo "App disponible sur https://127.0.0.1:8014"

stop:
	symfony server:stop

migrate:
	php bin/console doctrine:migrations:migrate --no-interaction

logs:
	symfony server:log

deploy:
	@echo "==> Déploiement sur O2Switch..."
	@echo "==> Push des changements sur GitHub..."
	git push origin main
	@echo "==> Connexion SSH et mise à jour..."
	ssh $(PROD_HOST) 'cd $(PROD_PATH) && \
		git pull origin main && \
		composer install --no-dev --optimize-autoloader && \
		php bin/console doctrine:migrations:migrate --no-interaction && \
		php bin/console cache:clear'
	@echo "==> Déploiement terminé !"
