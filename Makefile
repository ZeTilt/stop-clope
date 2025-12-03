.PHONY: dev stop migrate logs install deploy

# ===== Développement local =====

dev:
	symfony serve --port=8014 -d
	@echo "App disponible sur https://127.0.0.1:8014"

stop:
	symfony server:stop

logs:
	symfony server:log

migrate:
	php bin/console doctrine:migrations:migrate --no-interaction

# ===== Production (à lancer sur le serveur) =====

# Première installation (après git clone et création du .env.local)
install:
	@echo "==> Installation des dépendances..."
	composer install --no-dev --optimize-autoloader
	@echo "==> Compilation des assets..."
	php bin/console asset-map:compile
	@echo "==> Migration de la base de données..."
	php bin/console doctrine:migrations:migrate --no-interaction
	@echo "==> Nettoyage du cache..."
	php bin/console cache:clear
	@echo "==> Installation terminée !"

# Mise à jour (après git pull)
deploy:
	@echo "==> Mise à jour..."
	git pull origin main
	@echo "==> Installation des dépendances..."
	composer install --no-dev --optimize-autoloader
	@echo "==> Compilation des assets..."
	php bin/console asset-map:compile
	@echo "==> Migration de la base de données..."
	php bin/console doctrine:migrations:migrate --no-interaction
	@echo "==> Nettoyage du cache..."
	php bin/console cache:clear
	@echo "==> Déploiement terminé !"
