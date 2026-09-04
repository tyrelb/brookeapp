<?php

/*
 * Renders the client-facing emails to static HTML so the user guide can screenshot them
 * without a mail server. Run through tinker so the app is booted:
 *
 *   php artisan tinker --execute="require 'scripts/docs/render-emails.php';"
 *
 * Uses the seeded demo trainer's data (php artisan migrate:fresh --seed).
 */

use App\Mail\SessionBookedMail;
use App\Mail\SessionCompletedMail;
use App\Mail\WalletLinkMail;
use App\Models\Client;
use App\Models\SessionAttendee;
use App\Models\TrainingSession;
use App\Models\User;

$trainer = User::where('email', 'brooke@example.com')->firstOrFail();
auth()->login($trainer); // tenant scopes key off the logged-in trainer

$dir = storage_path('app/docs-build');
if (! is_dir($dir)) {
    mkdir($dir, 0755, true);
}

$ava = Client::where('first_name', 'Ava')->firstOrFail();
$upcoming = TrainingSession::where('status', 'scheduled')->whereHas('clients', fn ($q) => $q->whereKey($ava->id))->orderBy('starts_at')->firstOrFail();
$receiptFor = SessionAttendee::whereHas('trainingSession', fn ($q) => $q->where('status', 'completed'))
    ->where('client_id', $ava->id)->where('attended', true)->latest('id')->firstOrFail();

file_put_contents("$dir/email-invite.html", (new SessionBookedMail($upcoming, $ava))->render());
file_put_contents("$dir/email-receipt.html", (new SessionCompletedMail($receiptFor))->render());
file_put_contents("$dir/email-wallet-link.html", (new WalletLinkMail($ava))->render());

echo "rendered 3 emails to $dir\n";
