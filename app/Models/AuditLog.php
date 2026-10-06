<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use LogicException;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'actor_name',
        'actor_email',
        'event',
        'auditable_type',
        'auditable_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::updating(function () {
            throw new LogicException('Audit log entries cannot be updated.');
        });

        static::deleting(function () {
            throw new LogicException('Audit log entries cannot be deleted.');
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}