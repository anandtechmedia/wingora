# Wingora backend

Laravel 13 / PHP 8.4 / MySQL 8 API for Wingora (App Wings LLC). Currently contains the security foundation only; HR modules are not implemented yet.

Security architecture: [`docs/architecture/security.md`](../docs/architecture/security.md).

## Local setup (no Docker)

```bash
cp .env.example .env          # fill DB_* for your local MySQL 8 database
composer install
php artisan key:generate
php artisan migrate --seed    # seeds platform roles only
php artisan wingora:platform-user you@example.com "Your Name" --role=platform-owner
php artisan serve
```

The platform user receives a password-reset link (mail goes to the log in local dev) and must enrol MFA at first sign-in. Organizations are created through `POST /api/v1/platform/tenants`.

## Checks

```bash
vendor/bin/pint --test
composer audit
php artisan test              # SQLite in-memory by default
DB_CONNECTION=mysql DB_DATABASE=wingora_test DB_USERNAME=... DB_PASSWORD=... php artisan test
```

## Environments

Development, staging and production each use their own `.env`, database, Redis, storage bucket and `APP_KEY`. Never commit `.env` files or secrets. In staging/production set `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, explicit `TRUSTED_PROXIES`, `TRUSTED_HOSTS`, `CORS_ALLOWED_ORIGINS` and `SANCTUM_STATEFUL_DOMAINS`.
