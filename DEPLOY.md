# Déploiement CINAF v2 en production

Cible : **1 VPS OVH** (backend + frontend + Caddy) + **CloudDB OVH** (PostgreSQL managé).

## Coordonnées de l'infrastructure

| Élément | Valeur |
|---|---|
| VPS — IPv4 | `51.255.164.29` |
| VPS — hostname | `vps-314c3e30.vps.ovh.net` |
| Domaine — front | `https://tnext.be` |
| Domaine — API | `https://api.tnext.be` |
| CloudDB — hôte | `tf216250-001.eu.clouddb.ovh.net` |
| CloudDB — port | `35749` |
| Base / utilisateur | `cinaf_v2` / `cinaf` (à créer) |

---

## Étape 1 — CloudDB (console OVH)

1. **Bases de données** → créer `cinaf_v2`
2. **Utilisateurs et droits** → créer `cinaf`, définir son mot de passe, droits sur `cinaf_v2`
3. **IPs autorisées** → ajouter `51.255.164.29/32` (l'IP du VPS)

## Étape 2 — DNS (zone DNS de `tnext.be` chez OVH)

| Type | Sous-domaine | Cible |
|---|---|---|
| A | `@` (racine `tnext.be`) | `51.255.164.29` |
| A | `api` | `51.255.164.29` |

> Modifier l'enregistrement A existant de `@` (parking OVH) pour le pointer vers le VPS. Ne pas toucher aux MX/mail.
> Propagation : quelques minutes à ~1 h. Caddy n'obtiendra les certificats HTTPS qu'une fois la résolution effective.

## Étape 3 — Préparer le VPS

```bash
ssh ubuntu@51.255.164.29          # sinon root@51.255.164.29 (voir email de livraison OVH)

sudo apt update && sudo apt upgrade -y
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker $USER     # docker sans sudo → se reconnecter ensuite
sudo apt install -y ufw
sudo ufw allow OpenSSH && sudo ufw allow 80 && sudo ufw allow 443 && sudo ufw --force enable
```

## Étape 4 — Récupérer le code

```bash
cd /opt && sudo git clone <URL_DU_REPO> cinaf && sudo chown -R $USER:$USER cinaf && cd cinaf
```

## Étape 5 — Secrets

584f749c247e12bf0e8b49232d95ca7a

VTfDcQsl37fPfej6A0/+PU+CWvxZfxZP
Créer **`backend/.env.local`** sur le serveur (⚠️ sans guillemets — lu par Docker `env_file`) :

```dotenv
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=584f749c247e12bf0e8b49232d95ca7a          # openssl rand -hex 16
DEFAULT_URI=https://api.tnext.be
DATABASE_URL=postgresql://cinaf:MDP_CLOUDDB@tf216250-001.eu.clouddb.ovh.net:35749/cinaf_v2?serverVersion=16&charset=utf8&sslmode=require
CORS_ALLOW_ORIGIN=^https://tnext\.be$
JWT_SECRET_KEY=%kernel.project_dir%/config/jwt/private.pem
JWT_PUBLIC_KEY=%kernel.project_dir%/config/jwt/public.pem
JWT_PASSPHRASE=VTfDcQsl37fPfej6A0/+PU+CWvxZfxZP
MAILER_DSN=null://null
BUNNY_ENDPOINT=https://storage.bunnycdn.com
BUNNY_DEFAULT_ZONE=cinaftv-movies
BUNNY_CATALOGUE_ZONE=cinaftv-movies
CATALOGUE_SOURCE=bunny
BUNNY_STREAM_API_KEY=a21f205e-167e-4c92-b015-d2af48c5fe52
BUNNY_LIBRARY_ID=b59690c4-d942-43b2-85e9-d66bf47285f7
BUNNY_CDN_HOSTNAME=storage.b-cdn.net
BUNNY_ZONE_CINAFTV_MOVIES_KEY=62a46ce9-4181-4c43-8ca5-a10170f07e27
BUNNY_ZONE_CINAFTVFILMS_KEY=REMPLACER
BUNNY_ZONE_CINAFTVSERIES_KEY=REMPLACER
BUNNY_ZONE_BACKUPCINAF_KEY=REMPLACER
BUNNY_STREAM_LIBRARY_ID=b59690c4-d942-43b2-85e9-d66bf47285f7
STRIPE_ENABLED=true
STRIPE_SECRET_KEY=sk_live_REMPLACER
STRIPE_WEBHOOK_SECRET=            # rempli à l'étape 9
STRIPE_PRICE_MONTHLY=price_REMPLACER
STRIPE_PRICE_YEARLY=price_REMPLACER
STRIPE_SUCCESS_URL=https://tnext.be/abonnement/success?session_id={CHECKOUT_SESSION_ID}
STRIPE_CANCEL_URL=https://tnext.be/abonnement/cancel
```

> Pas de Stripe pour l'instant ? `STRIPE_ENABLED=false` et laisser les clés vides (mode mock).

Renseigner aussi les vraies valeurs dans **`frontend/.env.production`** (déjà pré-rempli avec l'URL API).

## Étape 6 — Build & démarrage

```bash
docker compose -f compose.prod.yaml up -d --build
docker compose -f compose.prod.yaml ps          # les 3 services doivent être Up
```

## Étape 7 — Clés JWT

```bash
docker compose -f compose.prod.yaml exec backend \
  php bin/console lexik:jwt:generate-keypair --overwrite --no-interaction
```

## Étape 8 — Migrations + compte admin + plans

```bash
# Migrations (s'appliquent directement sur le CloudDB)
docker compose -f compose.prod.yaml exec backend \
  php bin/console doctrine:migrations:migrate --no-interaction

# Compte admin — 1) générer le hash
docker compose -f compose.prod.yaml exec backend \
  php bin/console security:hash-password 'MDP_ADMIN'

# 2) insérer l'admin (coller le hash)
docker compose -f compose.prod.yaml exec backend php bin/console doctrine:query:sql \
  "INSERT INTO \"user\" (id, email, roles, password, first_name, last_name, is_verified, created_at, updated_at) \
   VALUES (gen_random_uuid(), 'admin@tnext.be', '[\"ROLE_ADMIN\"]', '<HASH>', 'Admin', 'CINAF', true, NOW(), NOW())"

# Plans d'abonnement (si Stripe activé)
docker compose -f compose.prod.yaml exec backend php bin/console app:stripe:sync-plans
```

> Rappel : les fixtures (`doctrine/doctrine-fixtures-bundle`) sont en `require-dev` → absentes en prod. D'où la création manuelle de l'admin.

## Étape 9 — Webhook Stripe (si activé)

Dashboard Stripe → **Developers → Webhooks → Add endpoint** :
- URL : `https://api.tnext.be/api/stripe/webhook`
- Copier le *Signing secret* (`whsec_…`) → dans `backend/.env.local` (`STRIPE_WEBHOOK_SECRET=…`), puis :
  ```bash
  docker compose -f compose.prod.yaml up -d backend
  ```

## Étape 10 — Vérifications

```bash
curl -I https://tnext.be                         # 200
curl https://api.tnext.be/api/catalogue/discover  # JSON Hydra
```
Puis se connecter sur https://tnext.be avec le compte admin.

---

## Exploitation

```bash
# Logs
docker compose -f compose.prod.yaml logs -f backend

# Mise à jour après un push
git pull && docker compose -f compose.prod.yaml up -d --build
docker compose -f compose.prod.yaml exec backend php bin/console doctrine:migrations:migrate --no-interaction
```

> Sauvegardes de la base : gérées automatiquement par le CloudDB OVH (onglet Backup / restauration).
