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

        $oldValues = $this->sanitizePayload($oldValues);
        $newValues = $this->sanitizePayload($newValues);

        $oldValues['__signature'] = $this->calculatePayloadSignature($oldValues);
        $newValues['__signature'] = $this->calculatePayloadSignature($newValues);

        return AuditLog::create([
            'user_id' => $actor ? $actor->getKey() : null,
            'actor_name' => $this->sanitizeScalar($actorName) ?: null,
            'actor_email' => $this->sanitizeScalar($actor->email ?? null),
            'event' => $this->sanitizeScalar($event),
            'auditable_type' => $auditable ? get_class($auditable) : null,
            'auditable_id' => $auditable ? $auditable->getKey() : null,
            'old_values' => $oldValues ?: null,
            'new_values' => $newValues ?: null,
            'ip_address' => $request ? $this->sanitizeScalar($request->ip()) : null,
            'user_agent' => $request ? $this->sanitizeScalar(substr((string) $request->userAgent(), 0, 2000)) : null,
            'created_at' => now(),
        ]);
    }

    protected function sanitizePayload(array $values): array
    {
        $sanitized = [];

        foreach ($values as $key => $value) {
            $normalizedKey = strtolower((string) $key);
            if (preg_match('/(password|secret|token|authorization|cookie|session|api[_-]?key|remember_token)/i', $normalizedKey)) {
                continue;
            }

            $sanitized[$key] = $this->sanitizeValue($value);
        }

        return $sanitized;
    }

    protected function sanitizeValue($value)
    {
        if (is_array($value)) {
            return $this->sanitizePayload($value);
        }

        if (is_bool($value) || is_int($value) || is_float($value) || is_null($value)) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        $string = (string) $value;
        $string = preg_replace('/[\x00-\x1F\x7F]+/', '', $string);
        $string = str_replace(["\r", "\n"], ' ', $string);
        $string = preg_replace('/[<>]/', '', $string);

        return mb_substr($string, 0, 2000);
    }

    protected function sanitizeScalar($value)
    {
        return is_scalar($value) ? $this->sanitizeValue($value) : null;
    }

    protected function calculatePayloadSignature(array $payload): string
    {
        $key = config('app.key');
        $data = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash_hmac('sha256', (string) $data, (string) $key);
    }
}
