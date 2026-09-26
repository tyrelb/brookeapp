# Changelog

## 1.0.0 — September 18, 2026

First production release, and the first public release: free software under the GNU AGPL v3 (or later).

- Trainer workspaces with email verification, tenancy, and a platform admin area.
- Clients on pay-as-you-go (Fitness Wallet), family (one wallet, up to ten members) and monthly membership plans; services and rate grids by group size with per-attendee overrides and package sizes.
- Session logging with Attended / Missed late cancel / No-show, client notes, bulk logging for up to 50 dates, and Reopen to correct a charged session.
- Calendar with month, week and day time grids, a phone list view with a sheet to log, edit or cancel, repeating bookings, overlap warnings, and `.ics` invites with updates and cancellations.
- Ledger with payments by cash, cheque or e-Transfer, adjustments, refunds, voids, in-place edits with a reason, and a revision history.
- Numbered invoices: request payment from a plan-based suggestion, remind, mark paid, void or delete; paid status derived from linked payments; listed on the Invoices page and the client's wallet page.
- Gyms with monthly and hourly billing pro-rated to session length, cover sessions paid by the gym, and a monthly gym statement with exclusions, finalize and reopen, CSV and print.
- Monthly and annual reports with accrual and cash GST, CSV export.
- Branded client emails through Resend in production: invites, receipts, invoices, reminders, thank-yous and wallet links.
- Private client wallet page with balance, outstanding invoices, upcoming sessions and history.
- In-app illustrated user guide with a printable PDF.

Security and self-hosting, ahead of the public release:

- A suspended trainer, or an administrator whose access was removed, is stopped at their next click, not their next page load. Suspended accounts can no longer log in at all.
- `REGISTRATION_ENABLED=false` closes trainer sign-ups on a private install. Sign-ups and verification-email resends are rate limited.
- Links and images typed into business settings, invoice messages or notes arrive in client emails as plain text, so they cannot hide a link.
- Security headers on every page. The client wallet page is never cached, indexed or sent as a referrer.
- The demo seeder refuses to run in production, destructive database commands are blocked there, and production passwords are checked against known breaches.
- Gym statement and annual report CSVs neutralise spreadsheet formulas in names, and calendar invites quote names that contain punctuation.
- A session can only be moved to one of the trainer's own gyms.
- README rewritten for self-hosting (nginx, PHP-FPM, MySQL, Supervisor, cron). Added LICENSE and SECURITY.md. CI runs Pest and fails on style issues.
