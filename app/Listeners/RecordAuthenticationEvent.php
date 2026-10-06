<?php

namespace App\Listeners;

use App\Services\AuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

class RecordAuthenticationEvent
{
    public function handle($event)
    {
        if ($event instanceof Login) {
            app(AuditLogger::class)->record('auth.login', null, [], [], $event->user);
        } elseif ($event instanceof Logout) {
            app(AuditLogger::class)->record('auth.logout', null, [], [], $event->user);
        } elseif ($event instanceof Failed) {
            app(AuditLogger::class)->record('auth.login_failed');
        }
    }
}