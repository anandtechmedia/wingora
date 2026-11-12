# Environments, hosting and deployment

Wingora runs in three environments: **development**, **staging** and **production**. Each has its own
configuration, keys, database, cache namespace, storage and URL. No containers are used anywhere.

## Initial hosting (shoestring, us-east-1)

To stay within about $10–15 a month, all three environments share **one Lightsail server**
(`small_3_0`: 2 GB RAM, 2 vCPU, 60 GB SSD, static IPv4), defined in `deploy/aws/wingora-lightsail.yml`
and set up by `deploy/server/provision.sh`.

```
                         Lightsail wingora-1 (Ubuntu 24.04, static IP)
 https://dev.<ip>.sslip.io      ─┐   Nginx (TLS: Let's Encrypt)
 https://staging.<ip>.sslip.io  ─┼─► /            → /srv/wingora/<env>/current/web       (React build)
 https://app.<ip>.sslip.io      ─┘   /api /sanctum /up → PHP-FPM pool wingora-<env> → current/backend
                                     │
     per environment ────────────────┼── Unix user wingora-<env>   (0750 home, deploy key, sudo for reload only)
                                     ├── MySQL 8 database + user wingora_<env>   (grants on its own schema only)
                                     ├── Redis ACL user wingora_<env>            (keys wingora-<env>:* only)
                                     ├── queue worker  wingora-queue@<env>       (systemd)
                                     ├── scheduler     /etc/cron.d/wingora-<env>
                                     └── private files /srv/wingora/<env>/shared/storage/app/private

 nightly 03:15 UTC ─► S3 wingora-backups-<account>/<env>/{db,files}/   (versioned, SSE, 35-day lifecycle, put-only writer)
 provisioning     ─► SSM /wingora/<env>/keys  (APP_KEY + AUDIT_HMAC_KEY escrow, SecureString)
 AWS Budget       ─► email at 80% actual / 100% forecast of $15
```

| Item | Development | Staging | Production |
|---|---|---|---|
| URL (no domain yet) | `dev.<ip-dashed>.sslip.io` | `staging.<ip-dashed>.sslip.io` | `app.<ip-dashed>.sslip.io` |
| Template | `backend/.env.development.example` | `backend/.env.staging.example` | `backend/.env.production.example` |
| `APP_DEBUG` / log level | `true` / debug | `false` / info | `false` / warning |
| Database | `wingora_development` | `wingora_staging` | `wingora_production` |
| Redis DBs / prefix | 0–1 / `wingora-development:` | 2–3 / `wingora-staging:` | 4–5 / `wingora-production:` |
| Deploys | manual, any branch | every merge to `main` | `vX.Y.Z` tag on `main` + approval |
| Data | synthetic only | synthetic only | customer data |

Local laptops and Devin sessions use `backend/.env.example` (SQLite or local MySQL, `log` mail) and
`apps/web/.env.example`. The web app is built once per release with `VITE_API_MODE=live` and calls the
API on the same origin, so it needs no per-environment build values.

**Costs** (AWS list prices, us-east-1, checked when this was written): the server is $0.01612/hour,
about $11.80/month, including 3 TB transfer. S3 backups, SSM standard parameters and the budget add
an estimated $1–2/month at launch volumes. That estimate is not a measured bill; the budget alert is the check.

### What the shared server does not give you

- **One failure domain.** A server fault, full disk or bad OS update affects all three environments.
  A bad staging release cannot reach production data (separate users, schemas, Redis ACLs, file
  permissions), but it shares CPU and memory with production.
- **No managed database.** Backups are daily logical dumps: RPO up to 24 hours, not the 15 minutes
  in `AGENTS.md` §16, and stored in the same account and region.
- **Private documents stay on the server disk.** The secure file disk is local; moving it to S3 needs the
  `league/flysystem-aws-s3-v3` package, which requires approval as a new dependency.
- **Email.** Lightsail blocks outbound port 25 and SES needs a verified domain, so production mail
  (password resets, invitations) is not configured until a domain exists. Mail is logged meanwhile.
- **sslip.io names** depend on a third-party DNS service. Move to a real domain before inviting customers.

### Upgrade path (no code changes)

1. Domain in Route 53, SES for mail, HSTS preload.
2. Production on its own Lightsail server; development and staging stay on the shared one.
3. Production MySQL on RDS or Lightsail managed database (point-in-time recovery, cross-region snapshot copy).
4. Private files on S3 once the S3 adapter is approved; then the server holds no customer files.

Each step changes `.env` values and `provision.sh` inputs only.

## Secrets

- Nothing secret is committed. The `.env.*.example` files contain placeholders only; `gitleaks` runs on every PR.
- `provision.sh` generates `APP_KEY`, `AUDIT_HMAC_KEY`, database and Redis passwords on the server and
  writes them to `/srv/wingora/<env>/shared/.env` (mode 0600, owned by that environment's user). It never prints them.
- Third-party secrets (Stripe, AI provider, mail) are added to that file by a maintainer over SSH.
  Use test-mode Stripe keys outside production.
- GitHub holds only deployment access, as **environment secrets** in the `development`, `staging` and
  `production` environments: `DEPLOY_HOST` (static IP), `DEPLOY_SSH_KEY` (private key for `wingora-<env>`)
  and `DEPLOY_KNOWN_HOSTS` (the server's host key line). A staging key cannot deploy production.
- `APP_KEY` and `AUDIT_HMAC_KEY` are escrowed in SSM Parameter Store (`/wingora/<env>/keys`), separate
  from the data backups. The server can write but not read them.

## CI/CD

| Workflow | Trigger | Does |
|---|---|---|
| `backend.yml` | PR / push to `main` touching `backend/` | Pint, `composer audit`, tests on SQLite and MySQL 8 |
| `web.yml` | PR / push touching `apps/`, `packages/` or toolchain | ESLint, type check, Vitest, build, `pnpm audit` |
| `secret-scan.yml` | every PR and push to `main` | gitleaks over the new commits (checksum-pinned binary) |
| `deploy.yml` | push to `main` → staging; `vX.Y.Z` tag → production; manual → development or staging | re-runs backend and web tests, builds once, uploads, activates, health check |

A release (`deploy/server/release.sh`, run as `wingora-<env>`):

1. Unpacks to `releases/<utc-time>-<sha>`; links the shared `.env` and `storage`.
2. Production only: runs `wingora-backup production` first.
3. `migrate --force`, idempotent reference-data `db:seed --force`, `optimize`.
4. Switches `current` atomically, reloads PHP-FPM, restarts the queue worker.
5. Keeps the last five releases. The workflow then checks `https://<host>/up`.

Production web builds ship without source maps.

### Add-ons built by Devin

1. Devin works on a `devin/<timestamp>-<slug>` branch from `main` and opens a PR into `main`. The repo's
   Devin environment already installs PHP 8.4, Composer, Node 24 and pnpm, and knows the lint and test commands.
2. `backend`, `web` and `secret-scan` must pass, plus human review. A maintainer may deploy the branch to
   **development** with *Actions → deploy → Run workflow*.
3. Merging deploys to **staging** automatically.
4. A maintainer tags `vX.Y.Z` on `main` and approves the `production` deployment in GitHub.
   Agents never deploy production, approve it or read its secrets (`AGENTS.md` §19).
5. A migration that drops or rewrites data needs an approved migration plan in the PR before merge.

## One-time GitHub settings (repository admin)

The Devin GitHub integration cannot change these; an owner sets them once.

1. **Visibility:** Settings → General → make the repository private.
2. **Environments:** Settings → Environments → create `development`, `staging` and `production`, each with
   the three secrets above. For `production`: *Required reviewers* (a named App Wings maintainer) and
   *Deployment branches and tags* → tags matching `v*.*.*`. For `staging`: branch `main` only.
3. **Branch protection** for `main`: require a PR, one approval, and the `backend`, `web` and `secret-scan`
   checks; block force pushes and deletions.

## Rollback

- **Code:** on the server as `wingora-<env>`: `ln -sfn ~/releases/<previous> ~/current.next && mv -Tf ~/current.next ~/current`,
  then `sudo systemctl reload php8.4-fpm` and `sudo systemctl restart wingora-queue@<env>`. Or re-run `deploy` on the previous tag.
- **Schema:** migrations are additive by policy, so the previous release runs against the newer schema.
  Never run `migrate:rollback` in production without an approved plan.
- **Data:** restore the pre-release dump from `s3://wingora-backups-<account>/production/db/` into an
  isolated database first, verify it, then replace. Restoring needs an admin AWS identity; the server cannot read backups.

## Restore drill (quarterly)

Download the latest dump and file archive, load them into a scratch MySQL database and directory on a
non-production machine, run `php artisan wingora:audit:verify`, record the time taken and the result.
Destroy the scratch copy afterwards. Production data never goes to development or staging.
