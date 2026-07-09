<?php

namespace App\Listeners;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Eloquent\Model;

/**
 * Records authentication events (login success/failure, logout) to the audit log
 * so "who signed in, when, from where" is answerable after the fact. Never logs
 * any credential material — only the attempted identifier. See security review
 * issue 19.
 */
class AuthEventLogger
{
    public function handleLogin(Login $event): void
    {
        AuditLog::record(
            AuditAction::LoginSucceeded,
            $event->user instanceof Model ? $event->user : null,
            metadata: ['guard' => $event->guard],
            userId: $event->user->getAuthIdentifier(),
        );
    }

    public function handleFailed(Failed $event): void
    {
        AuditLog::record(
            AuditAction::LoginFailed,
            metadata: [
                'guard' => $event->guard,
                'identifier' => $this->attemptedIdentifier($event->credentials),
            ],
        );
    }

    public function handleLogout(Logout $event): void
    {
        AuditLog::record(
            AuditAction::Logout,
            $event->user instanceof Model ? $event->user : null,
            metadata: ['guard' => $event->guard],
            userId: $event->user->getAuthIdentifier(),
        );
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(): array
    {
        return [
            Login::class => 'handleLogin',
            Failed::class => 'handleFailed',
            Logout::class => 'handleLogout',
        ];
    }

    /**
     * Pull only the login identifier from attempted credentials — never the
     * password or any other credential field.
     *
     * @param  array<string, mixed>  $credentials
     */
    private function attemptedIdentifier(array $credentials): ?string
    {
        foreach (['email', 'username', 'login', 'name'] as $key) {
            if (isset($credentials[$key]) && is_string($credentials[$key])) {
                return $credentials[$key];
            }
        }

        return null;
    }
}
