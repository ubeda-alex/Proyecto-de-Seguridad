<?php

namespace App\Listeners;

use App\Services\AuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Str;

class RecordAuthenticationEvent
{
    public function handle($event)
    {
        if ($event instanceof Login) {
            app(AuditLogger::class)->record('auth.login', null, [], [], $event->user);
        } elseif ($event instanceof Logout) {
            app(AuditLogger::class)->record('auth.logout', null, [], [], $event->user);
        } elseif ($event instanceof Failed) {
            $email = $event->credentials['email'] ?? null;

            app(AuditLogger::class)->record('auth.login_failed', null, [], [
                'attempted_email' => is_string($email) ? Str::limit($email, 255, '') : null,
                'account_exists' => $event->user !== null,
            ]);
        }
    }
}