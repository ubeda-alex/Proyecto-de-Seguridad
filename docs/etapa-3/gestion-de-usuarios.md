# Fase 3: Remediación y hardening — Gestión de Usuarios

**Proyecto:** Unifiedtransform · **Curso:** IC-8071 Seguridad del Software
**Fecha de remediación:** 5 de noviembre de 2026.

> Reporte técnico de parches del módulo **Gestión de Usuarios**, con mapeo OWASP/NIST,
> diff de remediación y verificación de no-reincidencia. Las PoC originales están en
> [docs/etapa-2/gestion-de-usuarios.md](../etapa-2/gestion-de-usuarios.md).

---

## 1. Ficha del integrante y trazabilidad de Git

| Campo | Valor |
|---|---|
| Responsable | Alex *(completar nombre y carné)* |
| Módulo | Gestión de Usuarios |
| Rama | `fix/gestion-usuarios-hardening` *(crear al commitear)* |
| Pull Request | *(completar enlace al abrir el PR)* |
| Commits | *(completar IDs)* |

**Archivos modificados / creados:**

| Archivo | Tipo | Propósito |
|---|---|---|
| `app/Http/Requests/TeacherUpdateRequest.php` | nuevo | Autorización `edit users` + validación de la edición de docentes |
| `app/Http/Requests/StudentUpdateRequest.php` | nuevo | Autorización `edit users` + validación de la edición de estudiantes |
| `app/Http/Controllers/UserController.php` | modificado | `updateTeacher`/`updateStudent` usan FormRequest y `validated()` |
| `app/Repositories/UserRepository.php` | modificado | Update por instancia de modelo (auditoría) + filtro por rol |
| `app/Http/Middleware/SecurityHeaders.php` | nuevo | CSP y cabeceras de seguridad (defensa en profundidad) |
| `app/Http/Kernel.php` | modificado | Registro del middleware en el grupo `web` |
| `tests/Feature/UserManagementSecurityTest.php` | nuevo | Pruebas de regresión del módulo |

---

## 2. Mapeo de la vulnerabilidad y el parche

| Hallazgo | OWASP | NIST CSF 2.0 | Archivo(s) / línea(s) | Técnica aplicada |
|---|---|---|---|---|
| UM-01 Broken Access Control / IDOR | A01 | PR.AA-05, PR.AC-04 | `UserController.php:148,167`; `Teacher/StudentUpdateRequest.php` | Autorización por operación (`edit users`) y por registro (scope de rol); validación estricta de entradas |
| UM-03a Manipulación de parámetro HTTP | A08 | PR.DS, DE.AE | `Teacher/StudentUpdateRequest.php` | `exists:users,id` con `where role`; lista blanca de campos vía `validated()` |
| UM-03b Audit Poisoning / evasión | A08/A09 | DE.CM-09, DE.AE | `UserRepository.php:124,156` | Update sobre instancia del modelo → dispara `AuditableObserver::updated` |
| UM-02 XSS almacenado (ya mitigado) | A03 | PR.PS | `SecurityHeaders.php`, `Kernel.php` | Se preserva el escape de Blade; CSP (`object-src 'none'`, `base-uri`, `frame-ancestors`) como defensa en profundidad |

---

## 3. Comparativa de código (diff de remediación)

### 3.1 Autorización por operación — antes

```php
// UserController.php  (ANTES)  — Request genérico, sin 'edit users', sin validación
public function updateTeacher(Request $request) {
    $this->userRepository->updateTeacher($request->toArray());
    ...
}
```

### 3.2 Autorización por operación — después

```php
// UserController.php  (DESPUÉS)
public function updateTeacher(TeacherUpdateRequest $request) {
    $this->userRepository->updateTeacher($request->validated());
    ...
}
```

```php
// TeacherUpdateRequest.php (NUEVO)
public function authorize() {
    return auth()->user()->can('edit users');   // ← cierra UM-01
}
public function rules() {
    return [
        'teacher_id' => ['required',
            Rule::exists('users','id')->where(fn($q)=>$q->where('role','teacher'))], // ← UM-03a
        'email' => ['required','email','max:255', Rule::unique('users','email')->ignore($teacherId)],
        // ...resto de campos validados...
    ];
}
```

### 3.3 Auditoría fiable — antes vs después

```php
// UserRepository.php  (ANTES)  — el query builder NO dispara el observer
User::where('id', $request['teacher_id'])->update([ ... ]);
```

```php
// UserRepository.php  (DESPUÉS)  — el update por instancia SÍ dispara el observer
$teacher = User::where('id', $request['teacher_id'])
    ->where('role', 'teacher')   // ← scope de rol
    ->firstOrFail();
$teacher->update([ ... ]);       // ← AuditableObserver::updated() registra el cambio
```

### 3.4 Defensa en profundidad (CSP)

```php
// SecurityHeaders.php (NUEVO) — registrado en el grupo 'web' de Kernel.php
'Content-Security-Policy' => "default-src 'self'; script-src 'self' 'unsafe-inline'; "
  . "object-src 'none'; base-uri 'self'; frame-ancestors 'self'; form-action 'self'; ...",
'X-Content-Type-Options' => 'nosniff',
'X-Frame-Options'        => 'SAMEORIGIN',
'Referrer-Policy'        => 'same-origin',
```

---

## 4. Verificación de no-reincidencia (prueba de regresión)

### 4.1 Prueba manual (misma PoC de la Fase 2)

| Escenario | Antes (Fase 2) | Después (Fase 3) |
|---|---|---|
| Docente sin `edit users` hace `POST /school/teacher/update teacher_id=1` | **302**, admin modificado (toma de cuenta) | **403 Forbidden**, admin **sin cambios** |
| Admin con `edit users` edita a un docente | 302, pero **0** filas de auditoría | **302**, **1** fila `model.updated` (actor=admin) |
| Cabeceras de seguridad | ausentes | `Content-Security-Policy`, `X-Frame-Options`, etc. presentes |

### 4.2 Prueba automatizada

`tests/Feature/UserManagementSecurityTest.php`:

```
✓ user without edit permission cannot update another user   (403, DB intacta)
✓ privileged update succeeds and is audited                 (302 + model.updated)
✓ privileged update cannot target wrong role                (error de validación teacher_id)
Tests: 3 passed
```

Suite completa: **11 passed, 1 failed**. El único fallo (`AuditLogTest::model changes are recorded…`) es **preexistente y ajeno a este módulo**: compara `user_id` con `assertSame` y falla por tipo (`'1'` vs `1`) porque el modelo `AuditLog` no castea `user_id`. Pertenece al módulo de **Logs/Auditoría**; se reporta a su responsable y no se corrige aquí por límite de módulo.

---

## 5. Matriz de riesgo residual (módulo Gestión de Usuarios)

| ID | Riesgo | Riesgo inicial | Control aplicado | Riesgo residual | Justificación |
|---|---|---|---|---|---|
| UM-01 | BAC/IDOR en edición de usuarios → toma de cuenta | **Crítico** | Autorización `edit users` + scope de rol + validación | **Bajo (aceptado)** | La PoC original ya no es ejecutable (403); el vector se neutraliza en el servidor y cumple PR.AA/PR.AC |
| UM-03a | Manipulación de parámetro `teacher_id`/`student_id` | **Alto** | `exists` con filtro de rol + lista blanca `validated()` | **Bajo (aceptado)** | El id destino se valida contra rol; no se puede apuntar a otro tipo de registro |
| UM-03b | Audit Poisoning / evasión de auditoría | **Alto** | Update por instancia → observer registra el cambio | **Bajo (aceptado)** | Toda edición genera `model.updated`; verificado por prueba automatizada |
| UM-02 | XSS almacenado en perfil | **Medio** | Escape de Blade (preservado) + CSP | **Bajo** | Salida escapada; CSP limita blast radius. *Residual:* `script-src 'unsafe-inline'` se mantiene para no romper vistas con scripts inline (refactor futuro para eliminarlo) |

### Riesgos residuales abiertos (tolerables)
- **CSP con `'unsafe-inline'`:** requerido porque varias vistas usan `<script>` inline; mitigación completa exige mover ese JS a archivos y usar nonces (fuera del alcance de esta entrega).
- **Sin rate limiting** en los endpoints de edición (configuración laxa; no es el vector principal).
- **`AuditLog.user_id` sin cast** (módulo Auditoría): no afecta la integridad del registro, pero debe corregirse en ese módulo.

---

## 6. Cómo reproducir la verificación

```bash
docker compose exec app php artisan migrate:fresh --seed --force
docker compose exec app php artisan test --filter=UserManagementSecurityTest
```
