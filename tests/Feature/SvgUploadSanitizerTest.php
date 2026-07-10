<?php

use App\Filament\Pages\Settings;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());
    Storage::fake('public');
});

it('strips scripts and event handlers from an uploaded SVG logo', function (): void {
    Client::factory()->create(['is_default' => true]);

    $malicious = <<<'SVG'
        <svg xmlns="http://www.w3.org/2000/svg" width="100" height="100">
            <script>document.title = 'XSS-EXECUTED'</script>
            <rect width="100" height="100" fill="teal" onclick="alert(1)"/>
            <a xlink:href="javascript:alert(2)">click</a>
        </svg>
        SVG;

    Livewire::test(Settings::class)
        ->fillForm([
            'client.logo' => UploadedFile::fake()->createWithContent('logo.svg', $malicious),
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $stored = Storage::disk('public')->allFiles('logos');
    expect($stored)->toHaveCount(1);

    $content = (string) Storage::disk('public')->get($stored[0]);
    expect($content)->not->toContain('<script');
    expect($content)->not->toContain('onclick');
    expect($content)->not->toContain('javascript:');
    // Benign SVG content is preserved.
    expect($content)->toContain('<rect');
});

it('leaves a clean SVG upload intact', function (): void {
    Client::factory()->create(['is_default' => true]);

    $clean = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>';

    Livewire::test(Settings::class)
        ->fillForm([
            'client.logo' => UploadedFile::fake()->createWithContent('clean.svg', $clean),
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $stored = Storage::disk('public')->allFiles('logos');
    expect($stored)->toHaveCount(1);
    expect(Storage::disk('public')->get($stored[0]))->toContain('<rect');
});
