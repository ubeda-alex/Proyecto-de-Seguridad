<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AuditLogger
{
    public function record(string $event, ?Model $auditable = null, array $oldValues = [], array $newValues = [], ?Model $actor = null): AuditLog
    {
        $actor = $actor ?: Auth::user();
        $request = app()->runningInConsole() ? null : request();
        $actorName = $actor
            ? trim(($actor->first_name ?? $actor->name ?? '') . ' ' . ($actor->last_name ?? ''))
            : null;

        return AuditLog::create([
            'user_id' => $actor ? $actor->getKey() : null,
            'actor_name' => $actorName ?: null,
            'actor_email' => $actor->email ?? null,
            'event' => $event,
            'auditable_type' => $auditable ? get_class($auditable) : null,
            'auditable_id' => $auditable ? $auditable->getKey() : null,
            'old_values' => $oldValues ?: null,
            'new_values' => $newValues ?: null,
            'ip_address' => $request ? $request->ip() : null,
            'user_agent' => $request ? substr((string) $request->userAgent(), 0, 2000) : null,
            'created_at' => now(),
        ]);
    }
}