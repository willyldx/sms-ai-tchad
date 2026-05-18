# SMS AI Tchad

Assistant IA accessible par SMS au Tchad. Un téléphone Android sert de gateway SMS : il reçoit les SMS, les transmet au backend via HTTP, et renvoie la réponse IA par SMS.

## Architecture

```
┌──────────────┐     HTTP POST      ┌─────────┐    FastCGI    ┌─────────┐
│  Téléphone   │ ──────────────────► │  Nginx  │ ────────────► │ Laravel │
│  Android     │ ◄────── JSON ────── │  :80    │               │ Backend │
│  (Gateway)   │                     └─────────┘               └────┬────┘
└──────────────┘                                                    │
       ▲                                                    HTTP POST /ask
       │ SMS                                                        │
       ▼                                                     ┌─────▼─────┐
┌──────────────┐                                             │  FastAPI  │
│  Utilisateur │                                             │ AI Service│
│  Tchadien    │                                             │ (NVIDIA)  │
└──────────────┘                                             └───────────┘
                                                                    │
                                                             ┌──────▼──────┐
                                                             │  MariaDB    │
                                                             │  (quotas)   │
                                                             └─────────────┘
```

## Services

| Service | Rôle |
|---------|------|
| `nginx` | Reverse proxy, rate limiting, TLS |
| `backend` | API Laravel — webhook SMS, quota journalier, déduplication |
| `ai-service` | Microservice FastAPI — interroge NVIDIA NIM via l'API compatible OpenAI, retourne une réponse ≤150 car. |
| `db` | MariaDB — stocke les utilisateurs SMS et leur quota |
| `certbot` | Certificats Let's Encrypt (profil `ops`) |

## Prérequis VPS

- Ubuntu 22.04+ (ou tout Linux avec Docker)
- Docker Engine + Docker Compose plugin
- Port 80 ouvert (et 443 pour HTTPS)

## Installation

### 1. Cloner le projet

```bash
git clone <repo-url> sms-ai-tchad
cd sms-ai-tchad
```

### 2. Configurer les variables d'environnement

```bash
cp .env.example .env
cp backend/.env.example backend/.env
cp ai-service/.env.example ai-service/.env
```

Éditer les fichiers `.env` :

**`backend/.env`** :
- `APP_KEY` → généré automatiquement au premier démarrage, ou manuellement :
  ```bash
  docker compose exec backend php artisan key:generate
  ```
- `SMS_WEBHOOK_SECRET` → secret fort et unique (partagé avec l'app Android)
- `AI_INTERNAL_TOKEN` → doit correspondre à `ai-service/.env`
- `DB_PASSWORD` → doit correspondre à `.env` racine
- `ADMIN_EMAIL` et `ADMIN_PASSWORD` → compte admin créé automatiquement au démarrage

**`ai-service/.env`** :
- `NVIDIA_API_KEY` → ta clé API NVIDIA NIM
- `NVIDIA_BASE_URL` → URL de l'API NVIDIA compatible OpenAI
- `AI_MODEL_NAME` → modèle utilisé par le service IA
- `AI_INTERNAL_TOKEN` → même valeur que dans `backend/.env`

**`.env` (racine)** :
- `DB_PASSWORD` → même valeur que dans `backend/.env`
- `DB_ROOT_PASSWORD` → mot de passe root MariaDB

### 3. Lancer les services

```bash
docker compose up -d --build
```

L'entrypoint du backend exécute automatiquement :
- `composer install` (si nécessaire)
- `php artisan config:cache` (en production)
- `php artisan migrate --force`
- `php artisan db:seed --class=AdminUserSeeder --force` si `ADMIN_EMAIL` et `ADMIN_PASSWORD` sont renseignés

Le dashboard admin Filament est disponible sur :

```text
http://<IP_VPS>/admin
```

Sur le VPS, le premier `composer install` dans le conteneur créera `backend/composer.lock` si le fichier n'existe pas encore. Vérifie ensuite :

```bash
ls -l backend/composer.lock
```

Commit ensuite `backend/composer.lock` dans le dépôt pour rendre les prochains déploiements reproductibles.

### 4. Vérifier que tout fonctionne

```bash
# Health check AI
curl http://localhost/ai/health

# Health check Nginx
curl http://localhost/health

# Test webhook SMS (simulation)
curl -X POST http://localhost/api/sms/incoming \
  -H "Content-Type: application/json" \
  -H "X-SMS-Webhook-Secret: <ton_secret>" \
  -d '{"from":"+23566000000","message":"Bonjour"}'
```

### 5. Smoke test complet

```bash
chmod +x scripts/*.sh
./scripts/smoke-test.sh http://localhost <ton_webhook_secret>
```

## Configuration de l'app Android Gateway

L'app Android SMS Gateway doit être configurée pour :

1. **Webhook URL** : `http://<IP_VPS>/api/sms/incoming`
2. **Headers** :
   - `Content-Type: application/json`
   - `X-SMS-Webhook-Secret: <ton_secret>`
3. **Body (JSON)** :
   ```json
   {"from": "<sender_phone>", "message": "<sms_body>"}
   ```
4. **Réponse attendue** : le champ `reply` contient le texte à renvoyer par SMS :
   ```json
   {"reply": "N'Djaména est la capitale du Tchad."}
   ```

## HTTPS (Let's Encrypt)

Prérequis : le domaine doit pointer vers l'IP du VPS.

```bash
./scripts/enable-https.sh sms.example.com admin@example.com
```

Renouvellement (à planifier en cron hebdomadaire) :

```bash
./scripts/renew-certs.sh
```

## Backup / Restore

```bash
# Backup
./scripts/backup-db.sh ./backups

# Restore
./scripts/restore-db.sh ./backups/sms_ai-20260101-120000.sql
```

## Checklist avant production

- [ ] `SMS_WEBHOOK_SECRET` fort et unique
- [ ] `AI_INTERNAL_TOKEN` fort et identique entre backend et ai-service
- [ ] `APP_KEY` généré
- [ ] `APP_DEBUG=false`
- [ ] `DB_PASSWORD` et `DB_ROOT_PASSWORD` forts
- [ ] HTTPS actif avant exposition publique
- [ ] Backup DB journalier configuré (cron)
- [ ] Rotation des logs Docker activée (déjà dans docker-compose)

## Notes techniques

- Le quota est journalier (reset automatique par date) — configurable via `SMS_DAILY_LIMIT`
- La déduplication ignore les SMS identiques dans une fenêtre de `SMS_DEDUP_WINDOW_SECONDS`
- Si l'IA plante, le quota est remboursé automatiquement
- Les réponses sont tronquées intelligemment au dernier espace (jamais en plein mot)
- Le service IA utilise des appels asynchrones à NVIDIA NIM (ne bloque pas l'event loop)
- Rate limiting Nginx : 5 req/s par IP sur le webhook SMS
