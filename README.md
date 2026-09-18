# BrookeApp

Billing and **Fitness Wallet** tracking for personal trainers. Built with Laravel 13, Livewire 4 and Flux; requires PHP 8.5.

**Version 1.0.0**, released September 18, 2026. See [CHANGELOG.md](CHANGELOG.md).

Each trainer registers with an email and password and gets a private workspace: multi-tenant on one database, with every row scoped to its trainer. The trainer's clients never log in; each has a private wallet link instead.

## What it does

- **Clients** on one of three kinds of plan:
  - **Pay-as-you-go (Fitness Wallet)**: the client deposits money and each completed session deducts a per-person rate plus GST.
  - **Family**: one client record for a household, up to ten named members sharing a single wallet. Each session charges for the members who attended.
  - **Monthly membership**: a flat fee plus GST posted to the client's ledger every month; every session is included.
- **Services** (e.g. "Personal Training (60 min)") and **Plans** holding a rate grid: Single, Partner, Triple and Quad prices per person, with an optional package size used to suggest payment requests. Clients can be on different plans; a one-off price override is available per attendee.
- **Sessions**: log who trained and the rate tier follows the group size. Each attendee is **Attended**, **Missed / late cancel** (charged at the booked group rate with a reason the client sees, not counted toward gym usage) or **No-show** (not charged). Sessions can be logged one at a time, in bulk for up to 50 dates, or from the calendar on a phone. Completed sessions can be reopened to fix attendance.
- **Calendar and booking**: month, week and day views on a time grid, a phone list view with a bottom sheet to log, edit or cancel, repeating bookings (every 1, 2 or 4 weeks, up to 12 months or 100 sessions), overlap warnings, and `.ics` calendar invites with automatic updates and cancellations.
- **Money**: payments by cash, cheque or e-Transfer; adjustments and refunds; ledger entries editable with a required reason and a full revision history; numbered **invoices** (payment requests) emailed to clients, reminded, marked paid, voided or deleted, and settled automatically by the payments linked to them.
- **GST**: rates are entered before tax and GST (5% by default) is added on top. Money received is GST-inclusive. Reports show GST on both an accrual and a cash basis.
- **Gyms**: up to three gyms per trainer, each billing a monthly rate, hourly usage by group size pro-rated to session length, or both. The gym usage report reconciles what the trainer owes, and **cover sessions** record the gym paying the trainer to train its own clients.
- **Reports**: monthly and annual (tax year) summaries with CSV export, and a per-gym monthly statement that can be finalized as a snapshot.
- **Client emails**: branded booking invites, updates, cancellations, receipts, invoices, reminders, thank-yous and wallet links, all queued.
- **Client wallet page**: a private, password-free page per client with balance, plan, how to pay, outstanding invoices, upcoming sessions and history, in list or calendar form.
- **User guide**: a twelve-chapter illustrated guide inside the app (user menu → Documentation) and as a printable PDF.
- **Platform administration**: an `/admin` area for the platform owner to support trainers without seeing their money.

## Requirements

| Component | Version | Notes |
|---|---|---|
| PHP | 8.5 | with the `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `mbstring`, `openssl`, `session`, `tokenizer`, `xml` and `pdo_sqlite` or `pdo_mysql` extensions |
| Composer | 2.x | |
| Node.js | 22 or newer | to build the Tailwind and Livewire assets with Vite |
| Database | SQLite 3 (development) or MySQL 8 (production) | |
| Mail | any SMTP server locally; [Resend](https://resend.com) in production | every client email is queued |

## Installation (local development)

```bash
git clone git@github.com:tyrelb/brookeapp.git
cd brookeapp
composer install
npm install && npm run build
cp .env.example .env          # SQLite by default
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed    # demo trainer: brooke@example.com / password
php artisan serve             # http://localhost:8000
```

Then log in at http://localhost:8000 as `brooke@example.com` / `password`. The seeder also creates `admin@example.com` (a platform administrator) and `other@example.com` (a second trainer, to confirm trainers never see each other's data), both with password `password`.

`composer run dev` starts the web server, a queue listener, the log tail and Vite together, which is the most convenient way to develop: queued emails send straight away and CSS changes appear without a rebuild.

### Email in development

Outbound email (registration verification, password resets, every client email) goes to SMTP on `127.0.0.1:1025` by default, which is where [MailHog](https://github.com/mailhog/MailHog) and [Mailpit](https://mailpit.axllent.org) listen. Open http://127.0.0.1:8025 to read it. Set `MAIL_MAILER=log` in `.env` to write mail to `storage/logs/laravel.log` instead.

Client emails are queued. With `QUEUE_CONNECTION=database` (the default) run `php artisan queue:work` or use `composer run dev`; set `QUEUE_CONNECTION=sync` to send inline while developing.

### Using MySQL locally

Uncomment the `DB_CONNECTION=mysql` block in `.env`, create the database, and run `php artisan migrate --seed`. Nothing else changes.

### Tests and code style

```bash
vendor/bin/pest          # feature and unit tests, on an in-memory SQLite database
vendor/bin/pint --test   # code style
```

CI runs both on pushes and pull requests to `main` and `develop`.

## Configuration reference

| Key | Purpose |
|---|---|
| `APP_URL` | Every link in a client email and every wallet link is built from this, including from the queue worker. Set it to the public address and run `php artisan optimize` after changing it. |
| `APP_TIMEZONE` | `America/Vancouver` by default. Monthly fees post at 06:00 in this zone and session times display in it. |
| `DB_CONNECTION` | `sqlite` for development, `mysql` for production. |
| `QUEUE_CONNECTION` | `database` needs a worker; `sync` sends emails during the request. |
| `MAIL_MAILER` | `smtp` (MailHog locally), `log`, or `resend` in production with `RESEND_API_KEY`. |
| `MAIL_FROM_ADDRESS` | Must be on a domain verified in Resend when using the Resend mailer. Client replies go to the trainer's own email address. |

## Deploying to production (Laravel Forge, MySQL 8)

1. Create a Forge site with PHP 8.5 and a MySQL 8 database, pointed at this repository's default branch. Forge deploys on every push.
2. In the site's `.env` set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://your-domain`, `APP_TIMEZONE=America/Vancouver`, the `DB_CONNECTION=mysql` block, and a real `APP_KEY` (`php artisan key:generate --show`).
3. Send mail through Resend: `MAIL_MAILER=resend`, `RESEND_API_KEY`, and a `MAIL_FROM_ADDRESS` on a domain verified in Resend. Email verification is required before a new trainer's dashboard opens, so mail must work before the first sign-up.
4. Deploy script:

   ```bash
   composer install --no-dev --optimize-autoloader
   npm ci && npm run build
   php artisan migrate --force
   php artisan optimize
   php artisan queue:restart
   ```

   `queue:restart` makes the worker pick up new code and `.env` changes; a server reboot does not clear the cached configuration on its own, so run `php artisan optimize` after every `.env` edit.
5. Enable Forge's **scheduler** (it runs `php artisan schedule:run` every minute) so monthly fees post.
6. Add a **queue worker** (Forge → Queue, `php artisan queue:work --tries=3`). Without one, no client email is ever sent.
7. Turn on Forge's daily **database backups**, and keep at least a month of them.
8. After the first deploy, make yourself an administrator:

   ```bash
   php artisan admin:grant you@example.com
   ```

To confirm a deploy landed without logging in, request a page that only exists in the new code: an authenticated route answers `302` to `/login` once deployed and `404` before.

## Operations

- **Monthly fees** post automatically each morning (Pacific time) for every active monthly member whose billing day has arrived. Posting is idempotent per client and month, and **Post monthly fee** on the client page is a manual fallback.

  ```bash
  php artisan billing:post-monthly-fees               # run now
  php artisan billing:post-monthly-fees --date=2026-10-01
  ```

- **Administrators** are granted with `php artisan admin:grant email` or from the Trainers page in `/admin`. Admins see account status and activity counts only; trainers' clients, ledgers, payments and reports stay private.
- **Suspending a trainer** (from `/admin`) logs them out, blocks logins and disables their clients' wallet links until reinstated. Data is kept.
- **Logs** are in `storage/logs/laravel.log`. Failed queue jobs land in the `failed_jobs` table; `php artisan queue:retry all` re-sends them.

## Best practices

### For the trainer

- **Set up in this order**: Business settings, gyms, services, plans, then clients. Each step feeds the next, and it is the order the in-app guide follows.
- **Start from today.** Do not enter history. Record each client's current balance as one deposit and let the app track everything from there.
- **Log the same day.** Sessions can be logged later, and in bulk, but same-day logging keeps wallet balances and the "running low" list honest.
- **Use the attendance choices, not overrides, for cancellations.** Missed / late cancel charges the client without billing you for gym space nobody used; No-show charges nothing. Overrides are for one-off prices.
- **Correct, do not delete.** Reopen a session to fix attendance; edit a ledger entry with a reason rather than voiding and retyping. Every change is recorded, so the ledger can always be explained.
- **Reconcile the gym monthly.** Compare the gym usage report with the gym's invoice, untick anything the gym did not charge for, then Finalize. The snapshot protects the month from later edits.
- **Use invoices to ask for money.** Request payment suggests the right amount from the plan, emails the client, and settles itself when the payment is recorded against it.
- **Send the wallet link, not statements.** Clients can see their own balance and history any time. Reset the link if it is forwarded.
- **Ask your accountant which GST basis to remit on.** The monthly and annual reports show both; prepaid deposits are why they differ.

### For the operator

- **Never use demo credentials in production.** The seeder is for local databases only; production accounts are created through registration.
- **Keep `APP_URL` correct.** Wallet links and email images are built from it, including by the queue worker. After changing it, run `php artisan optimize` and `php artisan queue:restart`.
- **Watch the queue.** Client emails are the product's main outbound channel. Alert on a stopped worker and on rows in `failed_jobs`.
- **Back up before migrating.** Deploys run migrations automatically; a Forge backup taken before a deploy makes a rollback a restore rather than a rewrite.
- **Do not edit ledger rows in the database.** Balances are sums of the ledger, invoices derive paid status from linked payments, and gym months are snapshots. Change money through the app so the history is written.
- **Update the user guide with the feature.** Chapters live in `resources/docs/`; recapture screenshots and rebuild the PDF (below) in the same change so the documentation never trails the release.

## User guide

Logged-in trainers get a **Documentation** entry in the user menu (bottom of the sidebar) that opens a step-by-step guide with screenshots, plus a **Download PDF** button. The guide is written for the trainer, not for developers, and shows the version from the `VERSION` file.

- Chapters are Markdown in `resources/docs/`, listed in order by `resources/docs/manifest.php`. They render in-app through `App\Support\UserGuide` at `/docs/{chapter}`; the `platform-admin` chapter is only shown to administrators.
- Screenshots live in `public/images/docs/` and are captured from the seeded demo trainer by `scripts/docs/screenshots.sh`, which drives the [gstack](https://github.com/garrytan/gstack) headless browser. Point `DOCS_BASE_URL` at a local server running the seeded database (`php artisan migrate:fresh --seed` first). The script sends invites, reopens a session, finalizes a gym month and registers then deletes a throwaway account, so only run it against a demo database.
- The PDF at `resources/docs/BrookeApp-User-Guide.pdf` is built by `scripts/docs/build-pdf.sh` (gstack `make-pdf`; cover, Contents with page numbers, Letter size) and committed, then served behind login at `/docs/download`.

After editing a chapter, rebuild the PDF and commit both. `tests/Feature/DocsTest.php` fails if a chapter refers to a screenshot that does not exist or if the PDF is missing.

## Releasing a version

1. Update `VERSION` and `config/app.php`'s `released` date, and add the release to `CHANGELOG.md`.
2. Recapture screenshots and rebuild the PDF so the guide shows the release.
3. Run `vendor/bin/pest` and `vendor/bin/pint --test`.
4. Commit, tag (`git tag v1.0.0`) and push; Forge deploys the branch.
