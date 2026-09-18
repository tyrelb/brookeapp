<?php

use App\Mail\WalletLinkMail;
use App\Models\Client;
use App\Models\User;

it('brands client emails with the logo badge and the app colours', function () {
    $trainer = User::factory()->create();
    $this->actingAs($trainer);
    $client = Client::factory()->create(['user_id' => $trainer->id, 'email' => 'ava@example.com']);

    $html = (new WalletLinkMail($client))->render();

    // A hosted PNG, not inline SVG: Gmail and Outlook drop SVG, and the file has to exist
    // or every email goes out with a broken image.
    expect($html)
        ->toContain('src="'.asset('images/email/logo.png').'"')
        ->toContain('BrookeApp')
        ->toContain('background-color: #5d42b3')
        ->and(public_path('images/email/logo.png'))->toBeFile();
});
