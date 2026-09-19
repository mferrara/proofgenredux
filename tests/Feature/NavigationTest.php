<?php

use App\Models\PhotoIssue;
use App\Models\User;

beforeEach(fn () => $this->actingAs(User::factory()->create()));

it('keeps the top bar to the everyday pages and sends the logo home', function () {
    $nav = str($this->get('/')->assertOk()->getContent())->after('wire:name="navigation-menu"')->before('</nav>');

    expect((string) $nav)
        ->toContain('Home')->toContain('Issues')->toContain('Graveyard')->toContain('Settings')
        ->not->toContain('Storage Profiles')->not->toContain('Legacy SFTP')->not->toContain('Dashboard')
        ->not->toContain('/dashboard');
});

it('no longer serves the empty dashboard or the standalone SFTP page', function () {
    $this->get('/dashboard')->assertNotFound();
    $this->get('/config/server')->assertNotFound();
});

it('reaches storage profiles from Settings', function () {
    $this->get(route('settings'))->assertOk()->assertSee(route('storage-profiles'));
    $this->get(route('storage-profiles'))->assertOk()->assertSee('← Settings');
});

it('shows a loud count on Issues only while issues are open', function () {
    $this->get('/')->assertDontSee('bg-rose-600 px-1.5', false);

    PhotoIssue::create(['status' => 'open', 'issue_type' => 'duplicate_content', 'show_id' => 'X', 'show_class_id' => 'X_1', 'source_path' => 'X/1/a.jpg']);

    $this->get('/')->assertSee('bg-rose-600 px-1.5', false);
});
