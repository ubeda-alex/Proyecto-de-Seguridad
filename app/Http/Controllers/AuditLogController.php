<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'event' => ['nullable', 'string', 'max:100'],
            'actor' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $auditLogs = AuditLog::query()
            ->when($filters['event'] ?? null, function ($query, $event) {
                $query->where('event', $event);
            })
            ->when($filters['actor'] ?? null, function ($query, $actor) {
                $query->where(function ($query) use ($actor) {
                    $query->where('actor_name', 'like', '%' . $actor . '%')
                        ->orWhere('actor_email', 'like', '%' . $actor . '%');
                });
            })
            ->when($filters['from'] ?? null, function ($query, $from) {
                $query->whereDate('created_at', '>=', $from);
            })
            ->when($filters['to'] ?? null, function ($query, $to) {
                $query->whereDate('created_at', '<=', $to);
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('audit-logs.index', compact('auditLogs'));
    }
}