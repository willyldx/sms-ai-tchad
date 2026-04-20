# SMS AI Tchad - Deploy VPS (Docker Compose)

Ce projet contient:

- `backend`: API Laravel qui recoit les SMS et applique le quota.
- `ai-service`: microservice FastAPI qui interroge Gemini.
- `db`: MariaDB pour stocker les utilisateurs SMS et leur quota.
- `nginx`: reverse proxy HTTP.

## 1) Prerequis VPS

- Ubuntu 22.04+ recommande
- Docker Engine + Docker Compose plugin
- Port 80 ouvert (et 443 plus tard pour TLS)

## 2) Initialisation

Copier les exemples d'environnement:

```bash
cp .env.example .env
cp backend/.env.example backend/.env
cp ai-service/.env.example ai-service/.env
```

Configurer les secrets:

- `backend/.env`
  - `SMS_WEBHOOK_SECRET`
  - `AI_INTERNAL_TOKEN` (doit correspondre a `ai-service/.env`)
  - `DB_PASSWORD`
  - `PYTHON_AI_SERVICE_URL` (laisser `http://ai-service:8000/ask` en compose)
- `ai-service/.env`
  - `GEMINI_API_KEY`
  - `AI_INTERNAL_TOKEN` (meme valeur que backend)

## 3) Lancer les services

```bash
docker compose up -d --build
```

## 4) Migrations Laravel

Si le backend est un Laravel complet (artisan/composer.json present):

```bash
docker compose exec backend php artisan key:generate
docker compose exec backend php artisan migrate --force
```

## 5) Test de sante

AI service:

```bash
curl http://localhost/ai/health
```

Webhook SMS (simulation):

```bash
curl -X POST http://localhost/api/sms/incoming \
  -H "Content-Type: application/json" \
  -H "X-SMS-Webhook-Secret: change_me" \
  -d '{"from":"+23566000000","message":"Bonjour"}'
```

Smoke test automatique (sans telephone):

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\smoke-test.ps1 -BaseUrl http://localhost -WebhookSecret change_me
```

## 6) IMPORTANT avant prod

- Remplacer tous les `change_me`
- Activer HTTPS (Nginx + Certbot)
- Sauvegarde quotidienne de la base
- Rotation des logs Docker

## 7) Checklist go-live rapide

- `SMS_WEBHOOK_SECRET` fort et unique
- `AI_INTERNAL_TOKEN` fort et identique entre backend et ai-service
- `APP_DEBUG=false`
- Backup DB journalier configure
- HTTPS actif avant exposition publique

## 8) Activer HTTPS (Let's Encrypt)

Prerequis:

- Le domaine pointe vers l'IP du VPS
- Port 80 ouvert

Commande (PowerShell):

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\enable-https.ps1 -Domain sms.example.com -Email admin@example.com
```

Renouvellement manuel:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\renew-certs.ps1
```

Astuce prod: planifier `renew-certs.ps1` chaque semaine (cron/systemd timer).

## 9) Backup / Restore MariaDB

Backup:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\backup-db.ps1 -BackupDir .\backups
```

Restore:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\restore-db.ps1 -DumpFile .\backups\sms_ai-20260101-120000.sql
```

## Notes

- Le webhook SMS peut etre teste sans telephone via `curl`.
- Le controle quota est journalier et se reinitialise automatiquement par date.
- Le systeme ignore les doublons de SMS dans une fenetre courte (`SMS_DEDUP_WINDOW_SECONDS`).
