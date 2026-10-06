<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;

class AuditableObserver
{
    private const REDACTED_KEYS = [
        'password',
        'remember_token',
        'token',
        'api_token',
        'secret',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    public function created(Model $model)
    {
        app(AuditLogger::class)->record('model.created', $model, [], $this->safeValues($model->getAttributes()));
    }

    public function updated(Model $model)
    {
        $newValues = $this->safeValues($model->getChanges());
        if (!$newValues) {
            return;
        }

        $oldValues = [];
        foreach ($newValues as $key => $value) {
            $oldValues[$key] = $model->getOriginal($key);
        }

        app(AuditLogger::class)->record('model.updated', $model, $oldValues, $newValues);
    }

    public function deleted(Model $model)
    {
        app(AuditLogger::class)->record('model.deleted', $model, $this->safeValues($model->getOriginal()));
    }

    private function safeValues(array $values): array
    {
        foreach ($values as $key => $value) {
            $normalizedKey = strtolower((string) $key);
            if (in_array($normalizedKey, self::REDACTED_KEYS, true)
                || preg_match('/password|secret|token|api_key|authorization|cookie/i', $normalizedKey)
                || in_array($normalizedKey, ['created_at', 'updated_at'], true)) {
                unset($values[$key]);
            }
        }

        return $values;
    }
}