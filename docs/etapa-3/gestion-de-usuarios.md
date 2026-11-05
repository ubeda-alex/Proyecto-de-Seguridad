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
| Responsable | Alexander Ubeda *(completar carné)* |
| Módulo | Gestión de Usuarios |
| Rama | `fix/gestion-usuarios-hardening` |
| Pull Request | *(completar enlace al abrir el PR)* |
| Commits | `e1bc255` autorización y validación de updates (UM-01, UM-03a) · `ae702ed` auditoría por evento de modelo (UM-03b) · `d8cccf6` cabeceras de seguridad y CSP · `2da1206` documentación fases 1–3 |

**Archivos modificados / creados:**

| Archivo | Tipo | Propósito |
|---|---|---|
| `app/Http/Requests/TeacherUpdateRequest.php` | nuevo | Autorización `edit users` + validación de la edición de docentes |
| `app/Http/Requests/StudentUpdateRequest.php` | nuevo | Autorización `edit users` + validación de la edición de estudiantes |
| `app/Http/Controllers/UserController.php` | modificado | `updateTeacher`/`updateStudent` usan FormRequest y `validated()` |
| `app/Repositories/UserRepository.php` | modificado | Update por instancia de modelo (auditoría) + filtro por rol |
| `app/Http/Middleware/SecurityHeaders.php` | nuevo | CSP y cabeceras de seguridad (defensa en profundidad) |
| `app/Http/Kernel.php` | modificado | Registro del middleware en el grupo `web` |
| `tests/Feature/UserManagementSecurityTest.php` | nuevo | Pruebas de regresión del módulo (UM-01, UM-03b) |
| `tests/Feature/SecurityHeadersTest.php` | nuevo | Pruebas de regresión de la CSP (CDN permitidos + directivas restrictivas) |

---

## 2. Mapeo de la vulnerabilidad y el parche

| Hallazgo | OWASP | NIST CSF 2.0 | Archivo(s) / línea(s) | Técnica aplicada |
|---|---|---|---|---|
| UM-01 Broken Access Control / IDOR | A01 | PR.AA-05 | `UserController.php:150,169`; `Teacher/StudentUpdateRequest.php` | Autorización por operación (`edit users`) y por registro (scope de rol); validación estricta de entradas |
| UM-03a Manipulación de parámetro HTTP | A08 | PR.DS, DE.AE | `Teacher/StudentUpdateRequest.php` | `exists:users,id` con `where role`; lista blanca de campos vía `validated()` |
| UM-03b Audit Poisoning / evasión | A08/A09 | DE.CM-09, DE.AE | `UserRepository.php:128,167` | Update sobre instancia del modelo → dispara `AuditableObserver::updated` |
| UM-04 Inyección SQL (ya mitigada) | A03 | PR.PS-06 | `UserRepository.php`, `PromotionRepository.php` | Se preserva el acceso vía query builder de Eloquent (consultas parametrizadas); sin SQL crudo |
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
// Los CDN se permiten dominio por dominio (nunca con comodín) porque
// componentes compartidos cargan librerías externas:
//   cdnjs.cloudflare.com -> event-calendar (jQuery, moment, FullCalendar, toastr)
//   cdn.ckeditor.com     -> editor de avisos (CKEditor 5)
'Content-Security-Policy' =>
    "default-src 'self'; "
  . "script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://cdn.ckeditor.com; "
  . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; "
  . "font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; "
  . "object-src 'none'; base-uri 'self'; frame-ancestors 'self'; form-action 'self'",
'X-Content-Type-Options' => 'nosniff',
'X-Frame-Options'        => 'SAMEORIGIN',
'Referrer-Policy'        => 'same-origin',
```

Cabecera verificada en vivo (`curl -I http://localhost:8080/login`): la CSP se emite con esos orígenes y se confirmó en el navegador que el calendario (`/home`, `/calendar-event`) y el editor de avisos (`/notice/create`) cargan sin bloqueos de `script-src`.

Cookies de sesión (ya presentes en `config/session.php`, se preservan): `HttpOnly` activo y `SameSite=Lax`; `secure` queda atado a `SESSION_SECURE_COOKIE` para despliegues con TLS.

---

## 4. Verificación de no-reincidencia (prueba de regresión)

### 4.1 Prueba manual (misma PoC de la Fase 2)

| Escenario | Antes (Fase 2) | Después (Fase 3) |
|---|---|---|
| Docente sin `edit users` hace `POST /school/teacher/update teacher_id=1` | **302**, admin modificado (toma de cuenta) | **403 Forbidden**, admin **sin cambios** |
| Admin con `edit users` edita a un docente | 302, pero **0** filas de auditoría | **302**, **1** fila `model.updated` (actor=admin) |
| Cabeceras de seguridad | ausentes | `Content-Security-Policy`, `X-Frame-Options`, etc. presentes |
| Calendario y editor de avisos tras la CSP | — | cargan sin bloqueos (`/home`, `/calendar-event`, `/notice/create`) |

### 4.2 Prueba automatizada

`tests/Feature/UserManagementSecurityTest.php`:

```
✓ user without edit permission cannot update another user   (403, DB intacta)
✓ privileged update succeeds and is audited                 (302 + model.updated)
✓ privileged update cannot target wrong role                (error de validación teacher_id)
Tests: 3 passed
```

`tests/Feature/SecurityHeadersTest.php`:

```
✓ csp allows cdn libraries used by shared components  (cdnjs + cdn.ckeditor permitidos)
✓ csp keeps restrictive directives                    (default-src/object-src/base-uri/frame-ancestors/form-action = 'self'/'none')
Tests: 2 passed
```

Suite completa: **13 passed, 1 failed**. El único fallo (`AuditLogTest::test_model_changes_are_recorded_without_secret_fields`) es **preexistente y ajeno a este módulo**: compara `user_id` con `assertSame` y falla por tipo (`'1'` vs `1`) porque el modelo `AuditLog` no castea `user_id`. Pertenece al módulo de **Logs/Auditoría**; se reporta a su responsable y no se corrige aquí por límite de módulo.

---

## 5. Matriz de riesgo residual (módulo Gestión de Usuarios)

| ID | Riesgo | Riesgo inicial | Control aplicado | Riesgo residual | Justificación |
|---|---|---|---|---|---|
| UM-01 | BAC/IDOR en edición de usuarios → toma de cuenta | **Crítico** | Autorización `edit users` + scope de rol + validación | **Bajo (aceptado)** | La PoC original ya no es ejecutable (403); el vector se neutraliza en el servidor y cumple PR.AA/PR.AC |
| UM-03a | Manipulación de parámetro `teacher_id`/`student_id` | **Alto** | `exists` con filtro de rol + lista blanca `validated()` | **Bajo (aceptado)** | El id destino se valida contra rol; no se puede apuntar a otro tipo de registro |
| UM-03b | Audit Poisoning / evasión de auditoría | **Alto** | Update por instancia → observer registra el cambio | **Bajo (aceptado)** | Toda edición genera `model.updated`; verificado por prueba automatizada |
| UM-04 | Inyección SQL en entradas del módulo | **Alto** (potencial) | Query builder de Eloquent (parametrizado); sin SQL crudo | **Bajo (aceptado)** | El payload viaja como valor ligado (`?`); la PoC no devuelve datos ajenos ni filtra errores de BD y la tabla queda intacta |
| UM-02 | XSS almacenado en perfil | **Medio** | Escape de Blade (preservado) + CSP | **Bajo** | Salida escapada; CSP limita blast radius. *Residual:* `script-src 'unsafe-inline'` se mantiene para no romper vistas con scripts inline (refactor futuro para eliminarlo) |

### Riesgos residuales abiertos (tolerables)
- **CSP con `'unsafe-inline'`:** requerido porque varias vistas usan `<script>` inline; mitigación completa exige mover ese JS a archivos y usar nonces (fuera del alcance de esta entrega).
- **CDN permitidos sin SRI:** `cdnjs.cloudflare.com` y `cdn.ckeditor.com` se habilitan a nivel de host, sin *Subresource Integrity*. Sirven muchas librerías, así que el ideal es alojar esos archivos en `public/` o añadir hashes `integrity`; implica tocar vistas de otros módulos y queda para un refactor.
- **Desinfección/firma de auditoría (DETECT) parcial:** las ediciones ya generan `model.updated` (cierra la evasión UM-03b) y el observer excluye secretos y normaliza claves, pero los campos aún admiten saltos de línea y caracteres de control, y las entradas no se firman ni encadenan. Riesgo **Medio tolerable**: la integridad del registro no se ve comprometida en el flujo actual; el *hardening* completo (strip de caracteres de control + firma/encadenado) queda propuesto.
- **Datos asociados del estudiante sin auditar:** la información de padres (`StudentParentInfoRepository::update`) y el `id_card_number` (`PromotionRepository::update`) se siguen actualizando con el query builder, por lo que no generan evento de auditoría. No afecta al vector de toma de cuenta, pero conviene migrarlos al update por instancia.
- **Lectura amplia de perfiles:** cualquier cuenta con `view users` puede abrir perfiles ajenos (GU-R02 de Fase 1); la política de visibilidad por necesidad queda pendiente de acuerdo grupal.
- **Sin rate limiting** en los endpoints de edición (configuración laxa; no es el vector principal).
- **`AuditLog.user_id` sin cast** (módulo Auditoría): no afecta la integridad del registro, pero debe corregirse en ese módulo.

---

## 6. Cómo reproducir la verificación

```bash
docker compose exec app php artisan migrate:fresh --seed --force
docker compose exec app php artisan test --filter=UserManagementSecurityTest
```
