.PHONY: dev stop migrate logs install install-local deploy

# Configuration O2Switch - À personnaliser
PROD_HOST ?= o2switch
PROD_PATH ?= ~/stopclope.votredomaine.fr
REPO_URL ?= https://github.com/ZeTilt/stop-clope.git

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

# Première installation locale
install-local:
	@echo "==> Installation locale..."
	composer install
	@echo ""
	@echo "==> Créer la base de données MySQL :"
	@echo "    mysql -u root -p"
	@echo "    CREATE DATABASE stopclope_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
	@echo "    CREATE USER 'stopclope'@'localhost' IDENTIFIED BY 'St0pCl0pe2024!';"
	@echo "    GRANT ALL PRIVILEGES ON stopclope_dev.* TO 'stopclope'@'localhost';"
	@echo "    FLUSH PRIVILEGES;"
	@echo ""
	@echo "==> Puis lancer : make migrate && make dev"

# ===== Production O2Switch =====

# Première installation sur le serveur
install:
	@echo "==> Première installation sur O2Switch..."
	@echo "==> Clonage du repo..."
	ssh $(PROD_HOST) 'cd $$(dirname $(PROD_PATH)) && \
		git clone $(REPO_URL) $$(basename $(PROD_PATH))'
	@echo ""
	@echo "==> IMPORTANT : Créer le fichier .env.local sur le serveur :"
	@echo "    ssh $(PROD_HOST)"
	@echo "    cd $(PROD_PATH)"
	@echo "    cat > .env.local << EOF"
	@echo "    APP_ENV=prod"
	@echo "    APP_SECRET=$$(openssl rand -hex 16)"
	@echo "    DATABASE_URL=\"mysql://USER:PASSWORD@localhost:3306/DATABASE?serverVersion=8.0\""
	@echo "    EOF"
	@echo ""
	@echo "==> Puis lancer : make install-deps"

# Installation des dépendances et migration (après création du .env.local)
install-deps:
	@echo "==> Installation des dépendances..."
	ssh $(PROD_HOST) 'cd $(PROD_PATH) && \
		composer install --no-dev --optimize-autoloader && \
		php bin/console doctrine:migrations:migrate --no-interaction && \
		php bin/console cache:clear'
	@echo "==> Installation terminée !"

# Mise à jour du serveur
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
