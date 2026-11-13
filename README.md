# Wingora

**People. Progress. Performance.** Multi-country HR SaaS (USA, UAE, Canada, Mexico) by App Wings LLC.

| Path | What |
|---|---|
| `backend/` | Laravel 13 / PHP 8.4 modular monolith, versioned REST API (`/api/v1`), MySQL 8, Redis |
| `apps/web/` | React + TypeScript + Vite + Tailwind single-page app |
| `packages/` | Shared TypeScript: `design-tokens`, `localization`, `permissions` |
| `deploy/` | Non-container hosting: AWS CloudFormation (`aws/`) and server scripts (`server/`) |
| `docs/architecture/` | Architecture, security, module and environment documentation |
| `.github/workflows/` | CI (`backend`, `web`, `secret-scan`) and CD (`deploy`) |

Start with [`AGENTS.md`](AGENTS.md) (the development contract) and
[`docs/architecture/environments.md`](docs/architecture/environments.md) (environments and deployment).

## Local development

Requires PHP 8.4, Composer, Node 24 and pnpm 10.

```bash
pnpm install
cd backend && composer install && cp .env.example .env && php artisan key:generate && php artisan migrate --seed
php artisan serve                       # API on :8000
pnpm --filter @wingora/web dev          # web on :5173, proxies /api and /sanctum to :8000
```

Checks: `cd backend && vendor/bin/pint --test && php artisan test`, then from the root `pnpm lint && pnpm typecheck && pnpm test && pnpm build`.

Proprietary. © App Wings LLC.
