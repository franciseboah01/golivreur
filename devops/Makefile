# GoLivreur — Makefile
# Toutes les commandes du projet en une seule lettre ou mot mémorisable.
#
# UTILISATION :
#   make help     → Afficher toutes les commandes disponibles
#   make up       → Démarrer l'environnement de développement
#   make test     → Lancer tous les tests
#   make deploy   → Déployer en staging (depuis la machine locale)
#
# PRÉREQUIS : Docker Desktop installé et démarré

.PHONY: help up down restart logs shell migrate seed fresh test test-coverage \
        test-payments test-permissions lint analyze routes cache-clear \
        horizon reverb backup rollback deploy-staging smoke-test

# Couleurs pour les messages
CYAN  := \033[36m
GREEN := \033[32m
YELLOW := \033[33m
RED   := \033[31m
RESET := \033[0m

# ── Aide ──────────────────────────────────────────────────────────────────────

help: ## Afficher toutes les commandes disponibles
	@echo ""
	@echo "$(CYAN)GoLivreur v5.0 — Commandes disponibles$(RESET)"
	@echo "════════════════════════════════════════"
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  $(GREEN)%-20s$(RESET) %s\n", $$1, $$2}'
	@echo ""

# ── Docker — Environnement ────────────────────────────────────────────────────

up: ## Démarrer tous les services (PHP, PostgreSQL, Redis, Nginx)
	@echo "$(CYAN)Démarrage de l'environnement GoLivreur...$(RESET)"
	@docker compose up -d
	@echo "$(GREEN)✅ Environnement démarré$(RESET)"
	@echo "   API    : http://localhost:8000/api/v1/health"
	@echo "   Admin  : http://localhost:8000/admin"
	@echo "   Docs   : http://localhost:8000/docs"

down: ## Arrêter tous les services
	@echo "$(YELLOW)Arrêt des services...$(RESET)"
	@docker compose down
	@echo "$(GREEN)✅ Services arrêtés$(RESET)"

restart: ## Redémarrer tous les services
	@docker compose restart
	@echo "$(GREEN)✅ Services redémarrés$(RESET)"

logs: ## Voir les logs en temps réel (Ctrl+C pour quitter)
	@docker compose logs -f golivreur_app golivreur_nginx

logs-db: ## Voir les logs PostgreSQL
	@docker compose logs -f golivreur_db

shell: ## Ouvrir un terminal dans le container PHP
	@echo "$(CYAN)Connexion au container PHP...$(RESET)"
	@docker compose exec golivreur_app bash

ps: ## Voir l'état des containers
	@docker compose ps

# ── Horizon + Reverb ──────────────────────────────────────────────────────────

horizon: ## Démarrer Laravel Horizon (supervision queues)
	@echo "$(CYAN)Démarrage Horizon...$(RESET)"
	@docker compose --profile horizon up -d golivreur_horizon
	@echo "$(GREEN)✅ Horizon démarré — http://localhost:8000/horizon$(RESET)"

reverb: ## Démarrer Laravel Reverb (WebSocket GPS)
	@echo "$(CYAN)Démarrage Reverb WebSocket...$(RESET)"
	@docker compose --profile reverb up -d golivreur_reverb
	@echo "$(GREEN)✅ Reverb démarré — ws://localhost:8080$(RESET)"

# ── Base de données ───────────────────────────────────────────────────────────

migrate: ## Exécuter les migrations
	@echo "$(CYAN)Exécution des migrations...$(RESET)"
	@docker compose exec golivreur_app php artisan migrate
	@echo "$(GREEN)✅ Migrations exécutées$(RESET)"

seed: ## Peupler la base avec les données Daloa (commerces, livreurs, clients)
	@echo "$(CYAN)Peuplement de la base de données Daloa...$(RESET)"
	@docker compose exec golivreur_app php artisan db:seed
	@echo "$(GREEN)✅ Base peuplée$(RESET)"
	@echo "   Admin: admin@golivreur.ci / GoLivreur2026!"

fresh: ## Réinitialiser la BDD et repeupler (⚠️ EFFACE TOUTES LES DONNÉES)
	@echo "$(RED)⚠️  Cette commande efface toutes les données. Êtes-vous sûr ? (Ctrl+C pour annuler)$(RESET)"
	@sleep 3
	@docker compose exec golivreur_app php artisan migrate:fresh --seed
	@echo "$(GREEN)✅ Base réinitialisée et peuplée$(RESET)"

migrate-status: ## Voir le statut des migrations
	@docker compose exec golivreur_app php artisan migrate:status

rollback-migration: ## Annuler la dernière migration (⚠️ développement uniquement)
	@echo "$(RED)⚠️  Annulation de la dernière migration...$(RESET)"
	@docker compose exec golivreur_app php artisan migrate:rollback

# ── Tests ─────────────────────────────────────────────────────────────────────

test: ## Lancer tous les tests Pest
	@echo "$(CYAN)Lancement des tests...$(RESET)"
	@docker compose exec golivreur_app php artisan test --parallel

test-coverage: ## Lancer les tests avec rapport de couverture (min 70%)
	@echo "$(CYAN)Tests avec couverture de code...$(RESET)"
	@docker compose exec golivreur_app php artisan test \
		--coverage \
		--min=70 \
		--coverage-html=coverage/

test-payments: ## Tests critiques — paiements et wallets (min 90%)
	@echo "$(CYAN)Tests paiements (couverture 90% requise)...$(RESET)"
	@docker compose exec golivreur_app php artisan test \
		--filter=WalletServiceTest \
		--coverage \
		--min=90

test-permissions: ## Tests critiques — permissions par rôle (min 90%)
	@echo "$(CYAN)Tests permissions...$(RESET)"
	@docker compose exec golivreur_app php artisan test \
		--filter=PermissionsTest \
		--coverage \
		--min=90

test-gocolis: ## Tests GoColis — double code de confirmation (min 90%)
	@echo "$(CYAN)Tests GoColis...$(RESET)"
	@docker compose exec golivreur_app php artisan test \
		--filter=GoColisTest \
		--coverage \
		--min=90

test-unit: ## Lancer uniquement les tests unitaires
	@docker compose exec golivreur_app php artisan test --testsuite=Unit

# ── Qualité de code ───────────────────────────────────────────────────────────

lint: ## Analyse statique Larastan niveau 5
	@echo "$(CYAN)Analyse statique (Larastan niveau 5)...$(RESET)"
	@docker compose exec golivreur_app ./vendor/bin/phpstan analyse \
		--level=5 \
		--memory-limit=512M
	@echo "$(GREEN)✅ Analyse statique OK$(RESET)"

format: ## Formater le code avec Laravel Pint
	@docker compose exec golivreur_app ./vendor/bin/pint

format-check: ## Vérifier le formatage sans modifier
	@docker compose exec golivreur_app ./vendor/bin/pint --test

# ── Laravel ───────────────────────────────────────────────────────────────────

cache-clear: ## Vider tous les caches Laravel
	@docker compose exec golivreur_app php artisan optimize:clear
	@echo "$(GREEN)✅ Caches vidés$(RESET)"

cache-warm: ## Préchauffer les caches (config + routes + vues)
	@docker compose exec golivreur_app php artisan optimize
	@echo "$(GREEN)✅ Caches préchauffés$(RESET)"

routes: ## Lister toutes les routes API
	@docker compose exec golivreur_app php artisan route:list --path=api

jwt-secret: ## Générer un nouveau secret JWT
	@docker compose exec golivreur_app php artisan jwt:secret

queue-work: ## Démarrer un worker de queue (développement)
	@docker compose exec golivreur_app php artisan queue:work \
		--queue=high,default,low \
		--tries=3

queue-restart: ## Redémarrer les workers Horizon
	@docker compose exec golivreur_app php artisan queue:restart
	@echo "$(GREEN)✅ Workers redémarrés$(RESET)"

tinker: ## Ouvrir Laravel Tinker (console interactive)
	@docker compose exec golivreur_app php artisan tinker

# ── Backup ────────────────────────────────────────────────────────────────────

backup: ## Lancer un backup PostgreSQL manuel vers DO Spaces
	@echo "$(CYAN)Backup PostgreSQL en cours...$(RESET)"
	@docker compose exec golivreur_app php artisan golivreur:backup-now
	@echo "$(GREEN)✅ Backup terminé$(RESET)"

backup-list: ## Lister les backups disponibles sur DO Spaces
	@ssh ${VPS_USER}@${VPS_HOST} "rclone ls spaces:golivreur/backups/daily/ | sort -r | head -10"

# ── Déploiement ───────────────────────────────────────────────────────────────

deploy-staging: ## Déployer manuellement en staging (via SSH)
	@echo "$(CYAN)Déploiement en staging...$(RESET)"
	@ssh ${VPS_USER}@${VPS_HOST} "bash /var/www/scripts/deploy.sh staging"
	@echo "$(GREEN)✅ Staging déployé$(RESET)"

smoke-test: ## Vérifier que l'API répond après déploiement
	@echo "$(CYAN)Test de smoke...$(RESET)"
	@curl -sf https://staging.golivreur.ci/api/v1/health | grep -q '"status":"ok"' \
		&& echo "$(GREEN)✅ API staging OK$(RESET)" \
		|| (echo "$(RED)❌ API staging NE RÉPOND PAS$(RESET)" && exit 1)

rollback: ## Rollback du dernier déploiement production
	@echo "$(RED)⚠️  ROLLBACK PRODUCTION en cours...$(RESET)"
	@read -p "Confirmez le rollback (oui/non) : " confirm; \
	[ "$$confirm" = "oui" ] && \
		ssh ${VPS_USER}@${VPS_HOST} "bash /var/www/scripts/rollback.sh" || \
		echo "Rollback annulé."

# ── Utilitaires ───────────────────────────────────────────────────────────────

install: ## Installation complète du projet (première fois)
	@echo "$(CYAN)Installation GoLivreur...$(RESET)"
	@cp -n .env.example .env || true
	@docker compose up -d
	@sleep 5
	@docker compose exec golivreur_app composer install
	@docker compose exec golivreur_app php artisan key:generate
	@docker compose exec golivreur_app php artisan jwt:secret
	@docker compose exec golivreur_app php artisan migrate --seed
	@docker compose exec golivreur_app php artisan storage:link
	@echo ""
	@echo "$(GREEN)✅ GoLivreur installé !$(RESET)"
	@echo "   API    : http://localhost:8000/api/v1/health"
	@echo "   Admin  : http://localhost:8000/admin"
	@echo "   Login  : admin@golivreur.ci / GoLivreur2026!"
	@echo ""
	@echo "$(RED)⚠️  Changez le mot de passe admin immédiatement !$(RESET)"

sms-count: ## Voir le compteur SMS du mois en cours
	@docker compose exec golivreur_app php artisan tinker \
		--execute="echo app(App\Services\SmsService::class)->compteurMensuel().' SMS ce mois';"

health: ## Vérifier que tous les services sont opérationnels
	@echo "$(CYAN)Vérification de l'état des services...$(RESET)"
	@docker compose ps
	@echo ""
	@curl -sf http://localhost:8000/api/v1/health \
		&& echo "$(GREEN)✅ API : OK$(RESET)" \
		|| echo "$(RED)❌ API : KO$(RESET)"
	@docker compose exec golivreur_redis redis-cli ping \
		&& echo "$(GREEN)✅ Redis : OK$(RESET)" \
		|| echo "$(RED)❌ Redis : KO$(RESET)"

# Variable par défaut — surchargeables en ligne de commande
# Ex: make deploy-staging VPS_USER=ubuntu VPS_HOST=1.2.3.4
VPS_USER ?= ubuntu
VPS_HOST ?= CHANGE_ME_VPS_IP
