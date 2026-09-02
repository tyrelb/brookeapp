# BrookeApp

Billing and **Fitness Wallet** tracking for personal trainers. Built with Laravel 12, Livewire 4 and Flux.

Each trainer registers with an email and password and gets a private workspace (multi-tenant, one database, every row scoped to its trainer). Inside it they manage:

- **Clients** on one of two kinds of plan:
  - **Monthly membership** — a flat fee plus GST is posted to the client's ledger each month; every session is included.
  - **Pay-as-you-go (Fitness Wallet)** — the client deposits money (like a Compass card) and each completed session deducts a per-person rate plus GST.
- **Services** (e.g. "Personal Training (60 min)") and **Plans** that hold the rate grid: Single / Partner / Triple / Quad prices per person. Different clients can be on different plans, so different people pay different rates; a one-off price override is available per attendee.
- **Sessions** — log who trained; the rate tier follows how many people showed up. One session can be assigned to several clients. Completed sessions can be reopened to fix attendance, which voids and re-posts the charges.
- **Payments** by cash, cheque or e-Transfer (card methods are intentionally not offered yet). Adjustments and refunds are posted to the same append-only ledger.
- **GST** — rates are entered before tax and GST (5% default) is added on top. Money received is treated as GST-inclusive. Reports show GST both on an accrual basis (what was charged) and a cash basis (what was received) so you and your accountant can pick the remittance basis.
- **Reports** — monthly and annual (tax year) summaries with a CSV export.

## Local setup

```bash
composer install
npm install && npm run build
cp .env.example .env          # SQLite by default
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed    # demo trainer: brooke@example.com / password
php artisan serve             # http://localhost:8000
```

`composer run dev` starts the server, queue listener, log tail and Vite together.

The seeder also creates `other@example.com` / `password` so you can confirm trainers never see each other's data.

## Platform administration

A separate admin area at `/admin` is for you, the platform owner, to support trainers and watch sign-ups. Admins see account status and activity counts only; trainers' clients, ledgers, payments and reports stay private.

- Overview: trainers signed up, new this month, active in the last 30 days, verified vs. unverified, sessions completed, and a 12-month sign-up chart.
- Trainers: search, filter (unverified, suspended, admins), and per-trainer support actions: send a password reset email, resend the verification email, activate the account by marking the email verified, suspend or reinstate, grant or revoke admin.

Make yourself an admin after registering:

```bash
php artisan admin:grant you@example.com
```

The seeder creates `admin@example.com` / `password` for local use.

## Tests

```bash
vendor/bin/pest
vendor/bin/pint --test
```

## Monthly fees

Membership fees are posted automatically by the scheduler each morning (Pacific time) for every active monthly client whose billing day has arrived. Posting is idempotent per client and month, and there is a **Post monthly fee** button on the client page as a manual fallback.

```bash
php artisan billing:post-monthly-fees            # run now
php artisan billing:post-monthly-fees --date=2026-10-01
```

## Production (Laravel Forge, MySQL 8)

1. Create a site with PHP 8.2+ and a MySQL 8 database.
2. In `.env` set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://brookeapp.com`, `APP_TIMEZONE=America/Vancouver`, and the `DB_CONNECTION=mysql` block (host, database, username, password).
3. Configure a real mailer (`MAIL_MAILER=smtp` with your provider) so registration emails and password resets send. Email verification is required before the dashboard opens.
4. Deploy script: `composer install --no-dev --optimize-autoloader && npm ci && npm run build && php artisan migrate --force && php artisan optimize`.
5. Enable Forge's scheduler (it runs `php artisan schedule:run` every minute) so monthly fees post.
6. Optional but recommended: a queue worker (`php artisan queue:work`) — Phase 2 emails will use it.

## Roadmap

- **Phase 1 (this release)** — auth, tenancy, clients, plans and services, Fitness Wallet ledger, session logging, payments, GST, monthly and annual reports.
- **Phase 2** — scheduling calendar, booking emails with `.ics` invites ("contact Brooke to change"), optional session-completed emails showing the charge and remaining balance. The schema already stores scheduled sessions and the trainer's booking instructions.
- **Phase 3** — client magic-link portal to view deposits, session history and balance. The `clients.portal_token` column is already in place.
