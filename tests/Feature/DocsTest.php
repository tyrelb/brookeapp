<?php

use App\Models\User;
use App\Support\UserGuide;

beforeEach(function () {
    $this->trainer = User::factory()->create();
    $this->guide = app(UserGuide::class);
});

it('redirects guests to the login page', function () {
    $this->get(route('docs.show'))->assertRedirect(route('login'));
    $this->get(route('docs.pdf'))->assertRedirect(route('login'));
});

it('renders every chapter for a trainer, with the admin chapter held back', function () {
    $this->actingAs($this->trainer);

    $this->get(route('docs.show'))->assertOk()->assertSee('Documentation');

    foreach ($this->guide->chapters($this->trainer) as $chapter) {
        $this->get(route('docs.show', $chapter['slug']))
            ->assertOk()
            ->assertSee($chapter['title'])
            ->assertSee(route('docs.show', $chapter['slug']), false);
    }

    expect(collect($this->guide->chapters($this->trainer))->pluck('slug'))->not->toContain('platform-admin');

    $this->get(route('docs.show', 'platform-admin'))->assertNotFound();
    $this->get(route('docs.show', 'no-such-chapter'))->assertNotFound();
});

it('shows the platform admin chapter to admins', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)
        ->get(route('docs.show', 'platform-admin'))
        ->assertOk()
        ->assertSee('Platform administration');
});

it('links to the documentation from the user menu', function () {
    $this->actingAs($this->trainer)
        ->get(route('dashboard'))
        ->assertSee('Documentation')
        ->assertSee(route('docs.show'), false);
});

it('gives every heading an id so in-chapter links work', function () {
    $chapter = $this->guide->chapters()[0];

    expect($this->guide->headings($chapter))->not->toBeEmpty()
        ->and((string) $this->guide->render($chapter))->toContain('<h2 id="');
});

it('only refers to screenshots that exist', function () {
    $missing = [];

    foreach ($this->guide->all() as $chapter) {
        foreach ($this->guide->images($chapter) as $image) {
            if (! is_file(public_path($image))) {
                $missing[] = $chapter['file'].' → '.$image;
            }
        }
    }

    expect($missing)->toBe([]);
});

it('serves the PDF to a logged-in trainer', function () {
    expect(is_file($this->guide->pdfPath()))->toBeTrue('resources/docs/BrookeApp-User-Guide.pdf is missing; run scripts/docs/build-pdf.sh');

    $this->actingAs($this->trainer)
        ->get(route('docs.pdf'))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});
