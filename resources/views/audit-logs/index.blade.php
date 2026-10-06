@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-start">
        @include('layouts.left-menu')
        <div class="col-xs-11 col-sm-11 col-md-11 col-lg-10 col-xl-10 col-xxl-10">
            <div class="row pt-2">
                <div class="col ps-4">
                    <h1 class="display-6 mb-3"><i class="bi bi-shield-check"></i> Logs / Auditoría</h1>

                    <form method="GET" action="{{ route('audit-logs.index') }}" class="row g-2 align-items-end mb-3">
                        <div class="col-sm-6 col-lg-3">
                            <label for="event" class="form-label">Evento</label>
                            <select id="event" name="event" class="form-select">
                                <option value="">Todos</option>
                                @foreach (['model.created' => 'Creación', 'model.updated' => 'Actualización', 'model.deleted' => 'Eliminación', 'auth.login' => 'Inicio de sesión', 'auth.logout' => 'Cierre de sesión', 'auth.login_failed' => 'Inicio fallido'] as $value => $label)
                                    <option value="{{ $value }}" {{ request('event') === $value ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <label for="actor" class="form-label">Usuario</label>
                            <input id="actor" name="actor" class="form-control" value="{{ request('actor') }}" maxlength="100">
                        </div>
                        <div class="col-sm-6 col-lg-2">
                            <label for="from" class="form-label">Desde</label>
                            <input id="from" name="from" type="date" class="form-control" value="{{ request('from') }}">
                        </div>
                        <div class="col-sm-6 col-lg-2">
                            <label for="to" class="form-label">Hasta</label>
                            <input id="to" name="to" type="date" class="form-control" value="{{ request('to') }}">
                        </div>
                        <div class="col-sm-6 col-lg-2 d-flex gap-2">
                            <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filtrar</button>
                            <a href="{{ route('audit-logs.index') }}" class="btn btn-outline-secondary" aria-label="Limpiar filtros" title="Limpiar filtros"><i class="bi bi-x-lg"></i></a>
                        </div>
                    </form>

                    <div class="table-responsive bg-white shadow-sm">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Evento</th>
                                    <th>Usuario</th>
                                    <th>Registro</th>
                                    <th>Origen</th>
                                    <th>Detalle</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($auditLogs as $auditLog)
                                    <tr>
                                        <td class="text-nowrap">{{ $auditLog->created_at->format('Y-m-d H:i:s') }}</td>
                                        <td>{{ $auditLog->event }}</td>
                                        <td>
                                            {{ $auditLog->actor_name ?: 'No autenticado' }}
                                            @if ($auditLog->actor_email)
                                                <div class="small text-muted">{{ $auditLog->actor_email }}</div>
                                            @endif
                                        </td>
                                        <td class="text-break">{{ $auditLog->auditable_type ? class_basename($auditLog->auditable_type) . ' #' . $auditLog->auditable_id : '—' }}</td>
                                        <td>{{ $auditLog->ip_address ?: '—' }}</td>
                                        <td>
                                            @if ($auditLog->old_values || $auditLog->new_values)
                                                <details>
                                                    <summary>Ver cambios</summary>
                                                    <pre class="small mt-2 mb-0">{{ json_encode(['antes' => $auditLog->old_values, 'después' => $auditLog->new_values], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                                </details>
                                            @else
                                                —
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center py-4">No hay eventos para mostrar.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-3">{{ $auditLogs->links() }}</div>
                </div>
            </div>
            @include('layouts.footer')
        </div>
    </div>
</div>
@endsection