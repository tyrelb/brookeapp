#!/usr/bin/env bash
# Captures every screenshot used by the user guide (resources/docs/*.md) from the locally
# running app, using the gstack `browse` headless browser.
#
# Prerequisites (see README "User guide"):
#   php artisan migrate:fresh --seed        # the demo data the guide is written against
#   https://brookeapp.test up (Valet), or DOCS_BASE_URL=http://localhost:8000 with artisan serve
#   ~/.claude/skills/gstack/browse/dist/browse built (gstack), ImageMagick `magick` on PATH
#
# Usage:
#   scripts/docs/screenshots.sh                 # everything, in order
#   scripts/docs/screenshots.sh clients reports # only the named chapter functions
#
# The script changes demo data as it goes (sends invites, reopens and re-completes a session,
# finalizes and reopens a gym month, registers and deletes a throwaway account). Run it
# against the seeded local database only.

set -euo pipefail
cd "$(git rev-parse --show-toplevel)"
source scripts/docs/lib.sh

TRAINER_EMAIL="brooke@example.com"
ADMIN_EMAIL="admin@example.com"
PASSWORD="password"
THROWAWAY_EMAIL="sam.taylor@example.com"

# Seeded records the walkthroughs use (ids follow DatabaseSeeder insertion order).
AVA=1; BEN=2; FINN=6
COMPLETED_SESSION=34   # Thu Sep 3, Ben + Chloe, completed
SERIES_SESSION=37      # first session of Ava's Tue/Thu repeat

open_profile_menu() {
    js "document.querySelector('button[data-flux-profile]').click();'opened'" >/dev/null
    sleep 0.6
}

# wire:confirm uses a native confirm(); auto-accept it for the next click.
accept_confirms() {
    js "window.confirm=function(){return true;};'ok'" >/dev/null
}

work_queue() {
    php artisan queue:work --stop-when-empty --quiet 2>/dev/null || true
}

artisan_eval() {
    php artisan tinker --execute="$1" 2>/dev/null | grep -v -E '^(Deprecated|Warning|Notice)' || true
}

# ─── Chapter 1: Welcome ────────────────────────────────────────────────────────
chapter_welcome() {
    vp 1440x900
    go /dashboard; tidy
    shot dashboard

    go /dashboard
    open_profile_menu
    hl_init; hl_text "Documentation"
    shot menu-documentation-full
    magick "$IMG/menu-documentation-full.png" -crop 1000x620+0+1180 +repage "$IMG/menu-documentation.png"
    rm -f "$IMG/menu-documentation-full.png"
}

# ─── Chapter 2: Getting started (logged out; registers and removes a throwaway account) ──
chapter_getting_started() {
    logout
    vp 1440x900
    go /login; tidy; shot login

    go /register
    "$B" fill 'input[name=name]' "Sam Taylor" >/dev/null
    "$B" fill 'input[name=email]' "$THROWAWAY_EMAIL" >/dev/null
    "$B" fill 'input[name=password]' "correct-horse-battery" >/dev/null
    "$B" fill 'input[name=password_confirmation]' "correct-horse-battery" >/dev/null
    shot register
    "$B" click 'button[type=submit]' >/dev/null
    settle 30000 1
    tidy; shot verify-email

    logout
    artisan_eval "App\\Models\\User::where('email', '$THROWAWAY_EMAIL')->delete();"
    login "$TRAINER_EMAIL" "$PASSWORD" >/dev/null
}

# ─── Chapter 3: Settings ───────────────────────────────────────────────────────
chapter_settings() {
    vp 1440x900
    go /settings/profile; tidy; shot settings-profile
    vp 1440x1150
    go /settings/business; tidy; shot settings-business
    vp 1440x900
    go /settings/gyms; tidy; shot settings-gyms
    vp 1440x1500
    click_text "Edit"; sleep 0.5
    shot_modal settings-gym-form
    close_modal
    vp 1440x900
    go /settings/appearance; tidy; shot settings-appearance
}

# ─── Chapter 4: Services and plans ─────────────────────────────────────────────
chapter_services_and_plans() {
    vp 1440x900
    go /services; tidy; shot services
    click_text "Add service"; sleep 0.5
    shot_modal service-form
    close_modal
    go /plans; tidy; shot plans
    vp 1440x1100
    go /plans/create; tidy; shot plan-form-rates
    # Viewport changes reload the page, so set it before interacting.
    vp 1440x900
    go /plans/create
    click_ref "Monthly membership"; tidy
    shot plan-form-monthly
}

# ─── Chapter 5: Clients ────────────────────────────────────────────────────────
chapter_clients() {
    vp 1440x900
    go /clients; tidy; shot clients
    vp 1440x1100
    go /clients/create; tidy; shot client-form
    go "/clients/$AVA"; tidy; shot client-page

    shot_clip client-wallet-link "$(rect_of_card 'Client wallet link')"

    click_text "Record payment"; sleep 0.5
    "$B" fill 'dialog[open] input[type=number]' "200" >/dev/null
    "$B" fill 'dialog[open] input[placeholder="Cheque # or e-Transfer reference"]' "INT-48812" >/dev/null
    shot_modal client-record-payment
    close_modal

    click_text "Adjustment"; sleep 0.5
    shot_modal client-adjustment
    close_modal

    vp 1440x900
    go "/clients/$FINN"; tidy
    hl_init; hl_text "Post monthly fee"
    shot client-monthly-fee
}

# ─── Chapter 6: Logging sessions ───────────────────────────────────────────────
chapter_sessions() {
    vp 1440x900
    go /sessions; tidy; shot sessions
    vp 1440x1000
    go /sessions/log; tidy; shot log-session

    vp 1440x1150
    click_text "Ava Nguyen"; click_text "Ben Okafor"; tidy
    shot log-session-partner
    fill_first "input[placeholder='Plan rate']" "50"; tidy
    hl_init; hl_css "input[placeholder='Plan rate']"
    shot log-session-override

    vp 1440x1100
    go "/sessions/$COMPLETED_SESSION"; tidy; shot session-completed
    accept_confirms; click_text "Reopen"; settle 15000 1; tidy
    shot session-reopen
    click_text "Complete & charge"; settle 15000 1
}

# ─── Chapter 7: Calendar and booking ───────────────────────────────────────────
chapter_calendar() {
    vp 1440x1100
    go "/sessions/calendar?view=month&date=2026-09-04"; tidy; shot calendar-month
    vp 1440x900
    go "/sessions/calendar?view=week&date=2026-09-04"; tidy; shot calendar-week
    go "/sessions/calendar?view=day&date=2026-09-03"; tidy; shot calendar-day

    vp 1440x1100
    go /sessions/book; tidy; shot book-session
    vp 1440x1000
    go /sessions/book
    click_text "Ava Nguyen"
    click_ref "Repeat this booking"
    click_ref "Tue"; click_ref "Thu"
    "$B" fill 'input[wire\:model\.live="until"]' "2026-12-18" >/dev/null
    settle; tidy
    scroll_to_card "Repeat this booking" 100
    shot book-session-repeat

    vp 1440x1100
    go "/sessions/$SERIES_SESSION"; tidy
    accept_confirms; click_text "Send invites"; settle 15000 1
    work_queue
    go "/sessions/$SERIES_SESSION"; tidy; shot session-scheduled
    click_text "Reschedule"; sleep 0.6
    shot_modal reschedule-dialog
    close_modal
}

# ─── Chapter 8: Client emails (rendered straight from the mailables) ───────────
chapter_client_emails() {
    mkdir -p public/docs/.build
    artisan_eval "require 'scripts/docs/render-emails.php';"
    vp 900x1150
    for name in invite receipt wallet-link; do
        "$B" goto "file://$PWD/public/docs/.build/email-$name.html" >/dev/null
        sleep 0.8
        shot "email-$name"
    done
}

# ─── Chapter 9: The client's Fitness Wallet page (no login) ────────────────────
chapter_portal() {
    local url
    url=$(artisan_eval "\$c = App\\Models\\Client::withoutGlobalScopes()->find($AVA); \$c->ensurePortalToken(); echo \$c->portalUrl();" | tail -1)
    url="${BASE_URL}/${url#*://*/}"   # swap the APP_URL host for the one we are browsing
    vp 1100x1150
    "$B" goto "$url" >/dev/null; settle; tidy
    shot portal-list
    click_text "Calendar"; settle; tidy
    shot portal-calendar
}

# ─── Chapter 10: Reports (August has a full month of demo sessions) ────────────
chapter_reports() {
    vp 1440x1150
    go /reports/monthly; "$B" click 'button[wire\:click="previous"]' >/dev/null; settle; tidy
    shot report-monthly
    go /reports/annual; tidy; shot report-annual
    go /reports/gym-usage; "$B" click 'button[wire\:click="previous"]' >/dev/null; settle; tidy
    shot report-gym-usage
    accept_confirms; click_text "Finalize"; settle 15000 1; tidy
    shot report-gym-usage-finalized
    accept_confirms; click_text "Reopen"; settle 15000 1
}

# ─── Chapter 11: Platform administration ───────────────────────────────────────
chapter_platform_admin() {
    logout
    login "$ADMIN_EMAIL" "$PASSWORD" >/dev/null
    vp 1440x1000
    go /admin; tidy; shot admin-dashboard
    go /admin/trainers; tidy; shot admin-trainers
    go /admin/trainers/1; tidy; shot admin-trainer
    logout
    login "$TRAINER_EMAIL" "$PASSWORD" >/dev/null
}

optimize() {
    # Palette PNGs are a third of the size with no visible change on flat UI screenshots.
    for f in "$IMG"/*.png; do
        magick "$f" -strip -colors 256 -define png:compression-level=9 "png8:$f"
    done
    du -sh "$IMG"
}

main() {
    "$B" status >/dev/null
    vp 1440x900
    login "$TRAINER_EMAIL" "$PASSWORD" >/dev/null
    chapter_welcome
    chapter_getting_started
    chapter_settings
    chapter_services_and_plans
    chapter_clients
    chapter_sessions
    chapter_calendar
    chapter_client_emails
    chapter_portal
    chapter_reports
    chapter_platform_admin
    optimize
}

if [ $# -gt 0 ]; then
    for fn in "$@"; do "chapter_$fn"; done
else
    main
fi
