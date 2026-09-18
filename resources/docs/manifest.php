<?php

/*
 * The user guide, in reading order. Titles live only here: the Documentation page shows
 * them as headings and scripts/docs/build-pdf.sh emits them as chapter titles, so the
 * Markdown files start at "##". Chapters marked admin are only shown to platform admins.
 */
return [
    ['slug' => 'welcome', 'title' => 'Welcome to BrookeApp', 'summary' => 'What the app does, how money flows, and a tour of the dashboard.', 'file' => '01-welcome.md'],
    ['slug' => 'getting-started', 'title' => 'Getting started', 'summary' => 'Create your account and set things up in the right order.', 'file' => '02-getting-started.md'],
    ['slug' => 'settings', 'title' => 'Settings', 'summary' => 'Your profile, business details, GST, payment methods and gyms.', 'file' => '03-settings.md'],
    ['slug' => 'services-and-plans', 'title' => 'Services and plans', 'summary' => 'What you offer and what each kind of client pays.', 'file' => '04-services-and-plans.md'],
    ['slug' => 'clients', 'title' => 'Clients and Fitness Wallets', 'summary' => 'Add clients, take deposits, fix mistakes and share their private wallet link.', 'file' => '05-clients.md'],
    ['slug' => 'invoices', 'title' => 'Requesting payment and invoices', 'summary' => 'Ask for money with a numbered invoice, chase it, and settle it.', 'file' => '06-invoices.md'],
    ['slug' => 'sessions', 'title' => 'Logging sessions', 'summary' => 'Record who trained and charge the right rate for the group size.', 'file' => '07-sessions.md'],
    ['slug' => 'calendar-and-booking', 'title' => 'Calendar and booking', 'summary' => 'Plan ahead, send invites, and manage repeating bookings.', 'file' => '08-calendar-and-booking.md'],
    ['slug' => 'client-emails', 'title' => 'Emails your clients receive', 'summary' => 'Invites, updates, cancellations, receipts and the wallet link.', 'file' => '09-client-emails.md'],
    ['slug' => 'client-wallet-page', 'title' => "Your client's Fitness Wallet page", 'summary' => 'The private page each client can open without logging in.', 'file' => '10-client-wallet-page.md'],
    ['slug' => 'reports', 'title' => 'Reports', 'summary' => 'Monthly and annual summaries, GST, and what you owe each gym.', 'file' => '11-reports.md'],
    ['slug' => 'platform-admin', 'title' => 'Platform administration', 'summary' => 'Supporting trainers and watching sign-ups as the platform owner.', 'file' => '12-platform-admin.md', 'admin' => true],
    ['slug' => 'faq', 'title' => 'FAQ and glossary', 'summary' => 'Quick answers and the words the app uses.', 'file' => '13-faq.md'],
];
