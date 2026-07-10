<?php

use App\Enums\AuditAction;
use App\Filament\Pages\Auth\Login;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Events\Logout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('audits a successful password login', function (): void {
    $user = User::factory()->create([
        'email' => 'auditor@example.com',
        'password' => bcrypt('correct-horse'),
    ]);

    Livewire::test(Login::class)
        ->fillForm([
            'login' => 'auditor@example.com',
            'password' => 'correct-horse',
        ])
        ->call('authenticate');

    $log = AuditLog::where('action', AuditAction::LoginSucceeded)->firstOrFail();
    expect($log->user_id)->toBe($user->id)
        ->and((int) $log->auditable_id)->toBe($user->id);
});

it('audits a failed password login without storing credentials', function (): void {
    User::factory()->create([
        'email' => 'auditor@example.com',
        'password' => bcrypt('correct-horse'),
    ]);

    Livewire::test(Login::class)
        ->fillForm([
            'login' => 'auditor@example.com',
            'password' => 'wrong-password',
        ])
        ->call('authenticate');

    $log = AuditLog::where('action', AuditAction::LoginFailed)->firstOrFail();

    expect($log->metadata['identifier'])->toBe('auditor@example.com')
        ->and($log->user_id)->toBeNull();

    // The attempted password must never be persisted anywhere in the entry.
    expect(json_encode($log->toArray()))->not->toContain('wrong-password');
});

it('audits a logout event', function (): void {
    $user = User::factory()->create();

    event(new Logout('web', $user));

    $log = AuditLog::where('action', AuditAction::Logout)->firstOrFail();
    expect($log->user_id)->toBe($user->id);
});
