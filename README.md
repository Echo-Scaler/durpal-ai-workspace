# Enterprise AI Knowledge Hub - Docker Setup

A **Drupal 10** + **PostgreSQL 16 with pgvector** + **Nginx** Docker environment for building a multi-tenant AI Knowledge Platform.

## Stack

| Service    | Image / Technology              | Port   |
|------------|---------------------------------|--------|
| **Drupal** | PHP 8.3-FPM + Drupal 10         | 9000   |
| **Nginx**  | nginx:1.27-alpine               | 8080   |
| **PostgreSQL** | pgvector/pgvector:pg16      | 5432   |

## Quick Start

### 1. Prerequisites
- Docker Desktop 4.x+
- Docker Compose v2.x+

### 2. Clone & Configure
```bash
git clone <repo-url>
cd ai-durpal-workspace
cp .env.example .env    # edit credentials as needed
```

### 3. Start Services
```bash
docker compose up -d
```

### 4. Install Drupal via Drush
```bash
# Run inside the drupal container
docker compose exec drupal drush site:install standard \
  --db-url="pgsql://drupal:drupal_secret@postgres:5432/drupal" \
  --site-name="Enterprise AI Hub" \
  --account-name=admin \
  --account-pass=admin123 \
  -y
```

### 5. Verify pgvector
```bash
docker compose exec postgres psql -U drupal -d drupal -c "\dx"
```
Expected output should include `vector` extension.

### 6. Check Drupal Bootstrap
```bash
docker compose exec drupal drush status
```

## Project Structure
```
ai-durpal-workspace/
├── docker-compose.yml          # Service definitions
├── .env                        # Environment variables (secrets)
├── .env.example                # Template for .env
├── .gitignore
├── docker/
│   ├── php/
│   │   ├── Dockerfile          # PHP 8.3-FPM image with Drupal extensions
│   │   └── php.ini             # PHP config (memory, upload limits)
│   ├── nginx/
│   │   └── default.conf        # Nginx server config for Drupal
│   └── postgres/
│       └── init.sql            # Enables pgvector extension on DB init
└── drupal/                     # Drupal codebase (composer-managed)
    ├── composer.json
    ├── composer.lock
    ├── web/                    # Drupal web root
    │   ├── core/
    │   ├── modules/
    │   └── ...
    └── vendor/
```

## Verification Checklist

- [ ] `docker compose ps` — all 3 services running (Up)
- [ ] `docker compose exec postgres psql -U drupal -d drupal -c "\dx"` — shows `vector` extension
- [ ] `docker compose exec drupal drush status` — Drupal bootstrap successful
- [ ] Visit [http://localhost:8080](http://localhost:8080) — Drupal site loads
# durpal-ai-workspace
