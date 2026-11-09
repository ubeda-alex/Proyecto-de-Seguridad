# Etapa 1: Diagnóstico y gobernanza de Gestión de Usuarios

**Proyecto:** Unifiedtransform · **Curso:** IC-8071 Seguridad del Software  
**Responsable del módulo:** Alexander Ubeda · carné 2023086480.  
**Fecha de revisión:** 5 de octubre de 2026.  
**Versión analizada:** commit `634a114e95b1d713dce8ac6593a9f56c5d806cda`.

## 1. Propósito y alcance

Esta sección aporta el diagnóstico de Gestión de Usuarios a la **Matriz General de Gobernanza del Sistema** exigida en la Fase 1. Comprende el inventario de activos, los impactos de riesgo, el mapeo a NIST CSF 2.0 y la línea base de controles. Debe integrarse con Autenticación, Operaciones/Transacciones y Logs/Auditoría en un único documento grupal; no constituye por sí sola la entrega de todo el equipo.

La evaluación se basa en lectura del código, rutas, migraciones, configuración versionada y pruebas existentes. No se ejecutaron ataques, pruebas funcionales ni consultas a los datos de usuarios. Una brecha observada en código no se presenta como una PoC exitosa. Las explotaciones y las remediaciones corresponden a las fases 2 y 3.

El módulo permite crear, listar, consultar y actualizar docentes y estudiantes. Ambos se almacenan en `users`; los estudiantes incorporan información familiar, académica y asignación a clase/sección. El código usa `users.role` y permisos concedidos directamente mediante Spatie: no debe suponerse que el campo `role` y los roles de Spatie son equivalentes.

**Límites entre integrantes:** Gestión de Usuarios es responsable de datos de perfiles, altas y autorización por operación/registro. Autenticación lidera login, sesiones, recuperación y política de contraseñas; ambos coordinan credenciales iniciales y cambios de correo. Operaciones lidera matrícula, promociones, clases y calificaciones; se coordina la creación del estudiante. Auditoría lidera almacenamiento, consulta y protección de bitácoras; este módulo debe asegurar que sus operaciones produzcan eventos completos.

## 2. Componentes, endpoints y flujo

| Componente | Función y evidencia |
|---|---|
| `routes/web.php` | Define rutas HTTP de docentes y estudiantes bajo `auth`. |
| `app/Http/Controllers/UserController.php` | Coordina operaciones; constructor exige `can:view users`. |
| `app/Http/Requests/TeacherStoreRequest.php`, `StudentStoreRequest.php` | Autorizan altas con `create users` y validan entradas. |
| `app/Repositories/UserRepository.php` | Crea y actualiza usuarios, asigna permisos y consulta perfiles. |
| `app/Models/User.php` | Modelo de identidad, atributos y relaciones. |
| `resources/views/teachers/`, `resources/views/students/` | Formularios, listados y perfiles Blade. |
| Repositorios `StudentParentInfo`, `StudentAcademicInfo`, `Promotion` | Persisten información asociada al estudiante. |
| `app/Traits/Base64ToFile.php` | Guarda fotografías en el disco público. |
| `app/Observers/AuditableObserver.php` | Observa cambios de modelos; dependencia compartida con Auditoría. |

| Método y ruta | Operación | Control identificado en código |
|---|---|---|
| GET `/teachers/add` | Formulario de alta | `auth`; la ruta es un closure y no pasa por el constructor de `UserController`. |
| GET `/students/add` | Formulario de alta | `auth` + `view users`. |
| POST `/school/teacher/create`, `/school/student/create` | Alta | `auth` + `view users` + `create users`; FormRequest. |
| GET `/teachers/view/list`, `/students/view/list` | Listados | `auth` + `view users`; estudiantes filtrables por clase/sección. |
| GET `/teachers/view/profile/{id}`, `/students/view/profile/{id}` | Perfil | `auth` + `view users`; no se observa autorización por propietario/asignación. |
| GET `/teachers/edit/{id}`, `/students/edit/{id}` | Formulario de edición | `auth` + `view users`. |
| POST `/school/teacher/update`, `/school/student/update` | Actualización | `auth` + `view users`; Request genérico, sin exigencia específica de `edit users`. |

Las solicitudes web pasan por middleware de sesión y CSRF. Los formularios revisados incluyen `@csrf`. No se identificó una ruta de eliminación o desactivación de usuarios en este módulo, aunque el catálogo contiene `delete users`.

Flujo de alta: navegador → ruta → autenticación/autorización → FormRequest → controlador → repositorio → transacción de base de datos → respuesta Blade. En estudiantes, la transacción incorpora los registros familiares, académicos y la asignación escolar. El archivo de fotografía se escribe fuera de la base de datos; no debe asumirse que una reversión SQL elimina ese archivo.

## 3. Inventario de activos e impacto

Las clasificaciones son propuestas para el escenario escolar, pendientes de aprobación grupal. C/I/D significan confidencialidad, integridad y disponibilidad; A = alta, M = media. No se presupone que el entorno local contenga datos reales.

| ID | Activo, ubicación y datos | C/I/D | Impacto operacional y de negocio | Responsable propuesto |
|---|---|---|---|---|
| GU-A01 | `users`: nombres, correo, teléfono, dirección, fotografía, fecha de nacimiento, religión y grupo sanguíneo | A/A/M | Exposición de información personal; perfiles incorrectos; pérdida de confianza de estudiantes y familias. | Gestión de Usuarios |
| GU-A02 | `users.password`, `remember_token`, `role` y tablas de permisos | A/A/A | Uso indebido de cuentas y facultades; interrupción de acceso al sistema escolar. | Usuarios + Autenticación |
| GU-A03 | `student_parent_infos`: contactos y domicilio familiar | A/A/M | Divulgación de datos familiares; comunicaciones dirigidas a personas equivocadas. | Gestión de Usuarios |
| GU-A04 | `student_academic_infos`, `promotions` y relaciones con clases/secciones | A/A/A | Asociación de un estudiante a registros incorrectos; reprocesos de matrícula y reportes. | Usuarios + Operaciones |
| GU-A05 | Fotografías en disco `public`, carpeta `photos` | A/M/M | Exposición de imágenes y consumo de almacenamiento por entradas no limitadas. | Usuarios + infraestructura |
| GU-A06 | Servicio Laravel/PHP, rutas, vistas y MySQL; `docker-compose.yml`, `composer.lock` | M/A/A | Interrupción de altas y consultas; costos de recuperación y trabajo administrativo manual. | Equipo técnico |
| GU-A07 | `audit_logs`: actor, entidad, valores anteriores/nuevos, IP y agente de usuario | A/A/M | Dificultad para atribuir modificaciones; la bitácora puede duplicar información personal. | Usuarios + Auditoría |

El inventario representa el diseño del repositorio. Volumen de registros, ubicación efectiva de despliegue, retención, respaldos y responsables institucionales quedan por verificar con el equipo.

## 4. Evidencia del estado actual

| ID | Observación | Evidencia reproducible de lectura | Estado |
|---|---|---|---|
| GU-E01 | Las altas validan correo único y contraseña mínima de ocho caracteres; almacenan hash y fijan el tipo de usuario en servidor. | `TeacherStoreRequest::rules`, `StudentStoreRequest::rules`; `UserRepository::createTeacher/createStudent`. | Control parcial observado; no cubre todo el ciclo de vida. |
| GU-E02 | Las actualizaciones exigen `view users`, pero no `edit users`. Docentes y estudiantes reciben `view users` al crearse. | `UserController.php:29`, `:148`, `:167`; `UserRepository.php:53`, `:109`. | Brecha de autorización observada; validar comportamiento HTTP en Fase 2. |
| GU-E03 | El identificador de destino procede de `student_id` o `teacher_id` y las actualizaciones filtran solo por `id`. No se observa comprobación de propietario, asignación o tipo del destinatario. | `UserRepository.php:124`, `:156`; campos ocultos en formularios de edición. | Riesgo de modificación de cuentas ajenas; alcance efectivo pendiente de prueba. |
| GU-E04 | La edición usa `$request->toArray()` sin reglas equivalentes a las del alta. El repositorio enumera los campos escritos: esto no prueba asignación masiva arbitraria de `role`. | `UserController.php:148`, `:167`; `UserRepository::updateStudent/updateTeacher`. | Brecha de validación observada. |
| GU-E05 | Los perfiles se consultan por identificador con permiso general; el perfil del estudiante muestra datos familiares. `findStudent` tampoco filtra por tipo `student`. | `UserController::showStudentProfile/showTeacherProfile`; `UserRepository::findStudent`; vista `students/profile.blade.php`. | Acceso por registro pendiente de contrastar con política aprobada. |
| GU-E06 | Existe un observador de auditoría, pero las actualizaciones del repositorio usan `where(...)->update(...)`. En el framework instalado, ese camino usa el builder y no el evento `updated` del modelo. | `AuditableObserver::updated`; `UserRepository.php:124`, `:156`; `vendor/laravel/framework/src/Illuminate/Database/Eloquent/Builder.php:908`. | Brecha de cobertura por lectura de código; confirmar ausencia del evento en prueba futura. |
| GU-E07 | La fotografía se valida como cadena nullable y se decodifica a archivo `.png`, sin límite propio de tamaño ni comprobación del contenido de imagen en ese trait. | FormRequests y `Base64ToFile::convert`. | Control insuficiente en esta capa; límites externos de PHP/proxy pendientes. |
| GU-E08 | Hay protección CSRF y escape Blade en campos de perfiles revisados. | `Kernel` grupo `web`, `VerifyCsrfToken`, formularios y vistas. | Controles observados; no equivalen a demostrar ausencia total de CSRF/XSS. |

`AuthTest` y `AuditLogTest` contienen pruebas existentes. La prueba de auditoría modifica una instancia con `save()`, por lo que no demuestra cobertura de las rutas de actualización. No se ejecutaron estas pruebas durante este diagnóstico.

## 5. Método de valoración del riesgo

Se propone una escala local, **no una fórmula prescrita por NIST**: probabilidad P de 1 (baja: requiere condiciones no observadas), 2 (media: ruta plausible con controles parciales) o 3 (alta: ruta directa identificada con barreras insuficientes). Impacto I de 1 (local y recuperable), 2 (afecta un proceso limitado) o 3 (datos personales, múltiples cuentas o continuidad escolar). R = P × I: 1–2 bajo, 3–4 medio, 6 alto, 9 crítico.

La tabla estima riesgo inicial considerando el diseño observado; no es riesgo residual post-parche ni cuantificación estadística. Se asume un escenario con cuentas escolares y datos personales. Cada valor debe revisarse con evidencia de ejecución y la escala común del grupo.

| Riesgo | Activos / evidencia | Escenario e impacto | P × I | Tratamiento y dueño |
|---|---|---|---|---|
| GU-R01 | A01–A04 / E02–E03 | Cuenta con permiso de lectura intenta modificar otro usuario mediante endpoints de edición. Podría alterar identidad/contactos y datos vinculados. | 3 × 3 = **9, crítico**; existe una ruta de escritura sin autorización específica. | Priorizar autorización por acción y destinatario. Usuarios; coordinar cambios de correo con Autenticación. |
| GU-R02 | A01, A03 / E05 | Usuario consulta perfiles de terceros fuera de su necesidad escolar. Expone información personal y familiar. | 2 × 3 = **6, alto**; lectura amplia observada, alcance permitido aún por acordar. | Aprobar política de visibilidad, reducir campos y consultas. Usuarios. |
| GU-R03 | A01, A03, A04 / E04 | Edición introduce formatos o referencias inválidos, provocando registros inconsistentes o errores administrativos. | 2 × 2 = **4, medio**; persisten restricciones SQL, pero falta validación de negocio. | Validar edición y relaciones en servidor; probar atomicidad. Usuarios + Operaciones. |
| GU-R04 | A07 / E06 | Modificación de perfil no produce evento de auditoría del modelo; dificulta reconstruir responsabilidades. | 3 × 3 = **9, crítico**; escritura y observación siguen caminos distintos en el código. | Cubrir rutas reales y verificar trazabilidad extremo a extremo. Usuarios + Auditoría. |
| GU-R05 | A05, A06 / E07 | Carga de datos no correspondientes a una imagen o demasiado grandes consume almacenamiento/recursos. | 2 × 2 = **4, medio**; límites de infraestructura desconocidos. | Validar contenido, tamaño y acceso a fotografías. Usuarios + infraestructura. |
| GU-R06 | A01, A02 / ciclo de vida | Cuenta que ya no debería acceder conserva sus permisos al no existir un flujo de baja identificado. | 2 × 3 = **6, alto**; ausencia de flujo en el módulo, procedimientos externos desconocidos. | Definir baja, revocación y retención con Autenticación. |

## 6. Matriz de gobernanza y mapeo NIST CSF 2.0

Los identificadores se verificaron en el [Apéndice A de NIST CSF 2.0](https://nvlpubs.nist.gov/nistpubs/CSWP/NIST.CSWP.29.pdf). Las aplicaciones y controles siguientes son propuestas específicas para Unifiedtransform, no textos normativos ni una certificación de cumplimiento.

| ID / subcategoría | Aplicación al módulo | Estado de partida y brecha | Evidencia o resultado requerido | Responsable |
|---|---|---|---|---|
| GU-G01 / **GV.RR-02** | Asignar quién aprueba altas, cambios, bajas y aceptación de riesgos. | Asignación académica conocida; autoridad institucional no documentada en material revisado. | Matriz de responsables aprobada; nombres de los cuatro integrantes. | Equipo; Alex por Usuarios |
| GU-G02 / **GV.PO-01**, **GV.RM-06** | Acordar política de visibilidad y escala de riesgo de la sección 5. | Propuestas en este documento; pendientes de consenso. | Política y escala únicas incorporadas a la matriz grupal. | Equipo |
| GU-G03 / **ID.AM-02**, **ID.AM-07** | Mantener inventario de componentes, tablas, fotografías y copias en auditoría. | Inventario estático preparado; despliegue y retención no verificados. | Confirmar A01–A07 contra el entorno del proyecto. | Usuarios + responsables compartidos |
| GU-G04 / **ID.RA-04**, **ID.RA-05** | Priorizar GU-R01–R06 según sus consecuencias escolares. | Estimaciones justificadas, sin explotación. | Equipo revisa supuestos, P/I y orden de atención. | Usuarios + coordinación |
| GU-G05 / **PR.AA-01** | Gestionar alta, modificación y retiro de cuentas. | Altas disponibles; baja no identificada. | C01 y C05 con evidencia del ciclo completo. | Usuarios + Autenticación |
| GU-G06 / **PR.AA-05** | Separar permisos de ver, crear y editar; limitar registros y campos. | E02, E03 y E05 muestran aplicación incompleta. | C01–C02; matriz de acceso y pruebas negativas. | Usuarios |
| GU-G07 / **PR.PS-06** | Exigir revisión y pruebas de validación de entradas y fotografías. | Altas tienen FormRequests; edición y fotos presentan brechas. | C03–C04; pruebas asociadas a cada regla. | Usuarios |
| GU-G08 / **PR.DS-01**, **PR.DS-02**, **PR.DS-11** | Proteger datos y fotografías almacenados, transporte y copias. | Hash observado; TLS, protección de archivos y restauración no verificados. | C06 y evidencia compartida de configuración y restauración. | Equipo técnico + Usuarios |
| GU-G09 / **PR.PS-04** | Registrar operaciones de usuario con actor, destino y resultado. | Observador y servicio presentes; E06 deja dudas de cobertura real. | C07; eventos en altas y actualizaciones de endpoints reales. | Usuarios + Auditoría |
| GU-G10 / **DE.CM-03**, **DE.CM-09**, **DE.AE-02** | Revisar cambios, denegaciones y anomalías del módulo. | Bitácora existente; no se acreditó monitoreo ni análisis operativo. | C08; regla de detección, destinatario y evidencia de análisis. | Auditoría + Usuarios |

## 7. Línea base mínima de controles

Esta es la condición objetivo para futuras verificaciones; no declara controles implementados ni pruebas aprobadas.

| Control | Requisito y criterio de aceptación verificable | Riesgos / NIST |
|---|---|---|
| **GU-C01: autorización de escritura** | Alta exige `create users`; edición exige `edit users` y autorización sobre el destinatario. En una matriz invitado/estudiante/docente/administrador, todo caso prohibido debe impedir cambios en BD, aunque se modifiquen IDs o campos ocultos. | R01; PR.AA-05 |
| **GU-C02: visibilidad por necesidad** | Propuesta: administrador autorizado administra perfiles; estudiante accede a sus datos; docente consulta solo estudiantes asignados y campos necesarios. Consultas ajenas se rechazan u omiten sin revelar datos. La política requiere acuerdo grupal antes de implementarse. | R02; PR.AA-05 |
| **GU-C03: validación consistente** | Alta y edición validan formato, longitud, correo único, IDs existentes y relaciones coherentes; campos permitidos se enumeran. Entradas inválidas producen errores controlados y ninguna escritura parcial. No se permite cambiar privilegios mediante el formulario de perfil. | R01, R03; PR.PS-06 |
| **GU-C04: fotografías** | Validar imagen por contenido y tamaño decodificado, generar nombre propio y definir acceso según política. Propuesta inicial: PNG/JPEG hasta 2 MiB, pendiente de aprobación. Archivo inválido no se guarda; probar también reversión y limpieza de archivos huérfanos. | R05; PR.PS-06 |
| **GU-C05: ciclo de vida** | Documentar quién aprueba altas/bajas, cómo revocar permisos y sesiones, y cuánto conservar datos. Una cuenta dada de baja no puede seguir operando con una sesión anterior; la prueba se comparte con Autenticación. | R06; PR.AA-01 |
| **GU-C06: protección de datos** | Mantener hashes de contraseñas y CSRF; usar escape de salida, transporte protegido y acceso restringido a BD/fotos/copias según el entorno acordado. Evidenciar recuperación de una copia de prueba y ausencia de secretos en respuestas y bitácoras. | R02, R03, R05; PR.DS-01/02/11 |
| **GU-C07: cobertura de auditoría** | Cada alta, edición, baja o cambio de permisos debe registrar actor, objetivo, operación, fecha, resultado e identificador de correlación; registrar denegaciones relevantes. Probar las rutas HTTP reales, sin limitarse a `User::save()`. Excluir contraseñas/tokens y minimizar datos personales. | R01, R04; PR.PS-04 |
| **GU-C08: detección y análisis** | Acordar alertas sobre denegaciones repetidas y modificaciones anómalas. Propuesta de laboratorio: 5 denegaciones por actor en 5 minutos generan un aviso al responsable de Auditoría. Confirmar recepción, análisis y referencia al usuario afectado, sin ejecutar entradas de bitácora como código. | R01, R04; DE.CM-03/09, DE.AE-02 |

## 8. Consolidación y decisiones pendientes

1. Insertar esta sección en el documento único del equipo, conservando identificadores `GU-A`, `GU-E`, `GU-R`, `GU-G` y `GU-C` para trazabilidad.
2. Completar portada, carné, integrantes y responsables compartidos. El nombre completo no se dedujo del nombre de usuario del equipo.
3. Acordar política de acceso, retención, escala de riesgos y parámetros propuestos de fotos/alertas. No presentarlos como políticas ya vigentes.
4. Unificar A02 con Autenticación, A04 con Operaciones y A07 con Auditoría para evitar inventarios y valoraciones contradictorios.
5. Confirmar que Unifiedtransform satisface la selección académica: el enunciado pide una aplicación intencionalmente vulnerable y el README revisado la presenta como software escolar, sin acreditar ese propósito. Los hallazgos estáticos no demuestran todos los vectores exigidos en Fase 2.
6. Conservar este commit como referencia del diagnóstico. Completar evidencia funcional en Fase 2 y riesgo residual solo después de remediar y volver a probar en Fase 3.

## 9. Referencias

- Enunciado local: `Proyectos-proyecto-final-Proyecto_Final_Seguridad_del_Software_2S2026.pdf`, páginas 1–2 (Fase 1) y 4 (entrega integrada).
- [NIST, The Cybersecurity Framework (CSF) 2.0, CSWP 29, 2024](https://doi.org/10.6028/NIST.CSWP.29), Apéndice A. Consulta: 5 de octubre de 2026.
- Código de Unifiedtransform en el commit indicado; rutas, funciones y líneas identificadas en las secciones 2 y 4. Las líneas corresponden a esa revisión y pueden cambiar después.
