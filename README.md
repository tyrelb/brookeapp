# BrookeApp

Billing and **Fitness Wallet** tracking for personal trainers. Built with Laravel 13, Livewire 4 and Flux. Requires PHP 8.5.

Each trainer registers with an email and password and gets a private workspace (multi-tenant, one database, every row scoped to its trainer). Inside it they manage:

- **Clients** on one of two kinds of plan:
  - **Monthly membership** — a flat fee plus GST is posted to the client's ledger each month; every session is included.
  - **Pay-as-you-go (Fitness Wallet)** — the client deposits money (like a Compass card) and each completed session deducts a per-person rate plus GST.
- **Services** (e.g. "Personal Training (60 min)") and **Plans** that hold the rate grid: Single / Partner / Triple / Quad prices per person. Different clients can be on different plans, so different people pay different rates; a one-off price override is available per attendee.
- **Sessions** — log who trained; the rate tier follows how many people showed up. One session can be assigned to several clients. Each attendee is **Attended**, **Missed / late cancel** (charged at the group's booked rate, with a required reason the client sees, but not counted toward the gym's usage charge) or **No-show** (not charged). A per-client note appears on their receipt, wallet activity and training history. Completed sessions can be reopened to fix attendance, which voids and re-posts the charges.
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

Outbound email (registration verification, password resets) goes to SMTP on `127.0.0.1:1025` by default, which is where [MailHog](https://github.com/mailhog/MailHog) and [Mailpit](https://mailpit.axllent.org) listen; open http://127.0.0.1:8025 to read it. Set `MAIL_MAILER=log` in `.env` to write mail to the log instead.

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

## Requesting payment

The client page has **Request payment** beside Record payment. It raises a numbered invoice
(`INV-0001`, counted per trainer), emails it to the client, and lists it under **Payment
requests** where it can be resent or voided.

The amount is suggested from the client's plan and can be edited before sending:

- **Monthly membership** — the monthly fee plus GST, whatever the current balance.
- **Pay-as-you-go** — the plan's **package size** × its single-person rate, plus GST. Set the
  package size on the plan (Pricing → Plans); leave it blank for plans that aren't sold in
  blocks and the amount starts empty.

Whether an invoice is paid is never stored — it is the sum of the non-voided payments linked
to it. Record a payment and the **Against a request** box offers the oldest open one, so the
invoice settles itself; void or edit that payment later and the invoice follows. A part
payment leaves it partly paid, an overpayment settles it and the surplus stays as wallet
credit. Voiding a request cancels the ask only; money that already arrived stays on the ledger.

The email shows the breakdown, the trainer's accepted payment methods and e-Transfer address,
and a link to the client's wallet page, which lists anything still outstanding. Emails are
queued, so a worker must be running.

## Production (Laravel Forge, MySQL 8)

1. Create a site with PHP 8.5 and a MySQL 8 database.
2. In `.env` set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://brookeapp.com`, `APP_TIMEZONE=America/Vancouver`, and the `DB_CONNECTION=mysql` block (host, database, username, password).
3. Configure a real mailer (`MAIL_MAILER=smtp` with your provider) so registration emails and password resets send. Email verification is required before the dashboard opens.
4. Deploy script: `composer install --no-dev --optimize-autoloader && npm ci && npm run build && php artisan migrate --force && php artisan optimize`.
5. Enable Forge's scheduler (it runs `php artisan schedule:run` every minute) so monthly fees post.
6. Optional but recommended: a queue worker (`php artisan queue:work`) — Phase 2 emails will use it.

## Scheduling and client emails (Phase 2)

- **Calendar** (`/sessions/calendar`): month and week views of every session, colour-coded by status. Click a day's **+** to book, click a session to open it.
- **Book session** (`/sessions/book`): schedule a future session for one or more clients. With *Email attendees a calendar invite* ticked, each attendee with an email address receives a message with an `.ics` invite they can accept. The email says to contact the trainer directly for changes and includes the trainer's booking instructions from Settings → Business.
- **Reschedule / cancel**: from a scheduled session, *Reschedule* emails an updated invite (same calendar event, higher sequence) and *Cancel* or *Delete* emails a cancellation that removes it from the client's calendar. Only attendees who were sent an invite are notified.
- **Receipts**: when completing a session (from Log session or a scheduled session), tick *Email attendees a receipt* to send each attendee what was deducted, their remaining Fitness Wallet balance (or amount owing on a monthly plan), and how to book next time. *Email receipts* on a completed session resends them.
- **Defaults**: Settings → Business has two switches that pre-tick these options.
- **Repeats**: *Book session* can repeat every 1, 2 or 4 weeks on chosen weekdays up to a required end date (at most 12 months and 100 sessions). Each date becomes a real session sharing the same attendees, and each client gets one invite email whose `.ics` holds every date as its own event. From any scheduled session in a repeat, *Reschedule* and *Cancel* can apply to "only this session" or "this and following"; following sessions shift by the same number of days and take the new time, length, service and gym, while earlier, completed and cancelled sessions are never changed. *Apply attendees to following* copies the attendee list forward.

Emails are queued, so run a worker (`php artisan queue:work`) locally and on Forge. The `.env.example` uses `QUEUE_CONNECTION=database`; set it to `sync` if you would rather send inline while developing.

## Client wallet link (Phase 3)

Every client has a private magic link (`/wallet/{token}`) that opens a read-only Fitness Wallet page with no login: balance, plan, upcoming bookings, every deposit and session, and how to reach the trainer to book or pay. The trainer's client page shows the link with **Copy link**, **Email link** and **Reset link** (which invalidates the old one). The link is also included in booking invites and receipts.

The page offers a **list** view (upcoming sessions, activity and history, each paginated so long repeats don't overwhelm) and a **calendar** view (month grid with prev/next), switchable with the pills in the header.

Tokens are 48 random characters, the route is rate-limited, the page is `noindex`, and the link stops working if the trainer's account is suspended.

## Gyms and the gym usage report

Trainers can set up to three **gyms** (Settings → Gyms), each with its own billing model: monthly rate only, monthly rate plus hourly usage, or usage only. Usage is charged **by the hour** at a rate that depends on group size, with an editable rate card for 1–10 people (pre-filled $18 / $26 / $35 / $41 / $52 and $70 for 6–10; 11+ uses the 10-person rate). Each session bills pro-rata by its length, so a 60-minute session for one person costs $18 and a 90-minute one costs $27; the log and booking forms show what the gym will charge as you fill them in. Each gym can charge GST or not. Each client can have a default gym (shown on the client form when there is more than one gym). When logging or booking, the session's gym is pre-filled from the first client added, or the trainer's default gym, and is required once any gym exists so every session reconciles against a gym invoice. Sessions also carry a "counts toward gym usage" flag, which can be unticked right on the log and bulk-log forms. Usage counts only the people who were in the room: late cancels are left out, and a session nobody turned up to is never charged.

**Reports → Gym usage** shows, per gym and month, a summary on top (sessions, people, usage charges, monthly rate, GST, total owed) and a detail table underneath with Date, Time, # of people, length, rate/hour and $ for the session, with a total at the bottom. Until the month is finalized, any row can be unticked to ignore it. **Finalize** locks the month and stores a snapshot so later edits don't change it; **Reopen** unlocks it. CSV export and a print-friendly layout are included, and completed sessions with no gym can be assigned to the selected gym in one click.

## Roadmap

- **Phase 1** — auth, tenancy, clients, plans and services, Fitness Wallet ledger, session logging, payments, GST, monthly and annual reports, platform admin area.
- **Phase 2** — scheduling calendar, booking emails with `.ics` invites, reschedule and cancellation updates, session receipts with remaining balance.
- **Phase 3** — client magic-link Fitness Wallet page with balance, bookings and history, shareable from the client page and included in emails.
- **Gym usage** — gyms with billing models and hourly rate cards, sessions tagged by gym, usage pro-rated by session length, monthly usage report with exclusions, finalize/reopen, CSV and print.
- **Payment requests** — numbered invoices suggested from the client's plan, emailed with payment methods and a wallet link, settled by the payments linked to them.

## User guide

Logged-in trainers get a **Documentation** entry in the user menu (bottom of the sidebar) that opens a step-by-step guide with screenshots, plus a **Download PDF** button. The guide is written for the trainer, not for developers.

- Chapters are Markdown in `resources/docs/` and listed, in order, by `resources/docs/manifest.php`. They render in-app through `App\Support\UserGuide` at `/docs/{chapter}`; the `platform-admin` chapter is only shown to administrators.
- Screenshots live in `public/images/docs/` and are captured from the seeded demo trainer by `scripts/docs/screenshots.sh`, which drives the [gstack](https://github.com/garrytan/gstack) headless browser against `https://brookeapp.test` (set `DOCS_BASE_URL` for another host). Run `php artisan migrate:fresh --seed` first; the script sends invites, reopens a session and finalizes a gym month as it goes, so only point it at the demo database.
- The PDF at `resources/docs/BrookeApp-User-Guide.pdf` is built by `scripts/docs/build-pdf.sh` (gstack `make-pdf`: cover, table of contents, page numbers) and committed, then served behind login at `/docs/download`.

After editing a chapter, rebuild the PDF and commit both. `tests/Feature/DocsTest.php` fails if a chapter refers to a screenshot that does not exist or if the PDF is missing.
