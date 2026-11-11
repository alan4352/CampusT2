# Seguridad del campus

Qué protege a cada módulo y qué hay que respetar al modificar el código. Si agregás una página o un endpoint nuevo,
revisá la lista de la sección «Reglas para quien programe».

## Sesión

Todos los módulos inician la sesión con `campus/includes/sesion.php` (ProgMesas también, cuando no recibe el pase).

- La cookie sale con **`HttpOnly`** (JavaScript no puede leerla), **`SameSite=Lax`** (no viaja en pedidos que arma
  otro sitio) y **`Secure`** cuando se entra por HTTPS, también detrás de un proxy como ngrok o Render
  (`X-Forwarded-Proto`).
- **`use_strict_mode`**: el servidor no acepta identificadores de sesión inventados.
- Al iniciar sesión se regenera el identificador (`session_regenerate_id`).
- Datos que guarda `iniciarSesionCuenta()` (`campus/includes/cuentas.php`) y usan los módulos (no cambiar el formato):

  | Clave | Contenido |
  |---|---|
  | `rol` | Rol **principal**: el primero de `directivo`, `profesor`, `preceptor`, `alumno` que tenga la persona |
  | `roles` | Todos los roles de la persona. `tienePermiso()` y `tieneRol()` revisan esta lista |
  | `nombre`, `correo` | Datos de la cuenta |
  | `id` | `alumno:<DNI>`, o `profesor:` / `preceptor:` / `directivo:` + correo. Lo usan ProgMesas y proyectores |
  | `usuario_id`, `dni` | ID de la tabla `usuarios` y DNI. Los usa preinscripciones |

## Cuentas e inicio de sesión

- **Una cuenta por persona** en la tabla `usuarios`, con sus roles en `usuario_roles`. `alumnos` y `profesores` son
  fichas enlazadas por `usuario_id`, que siguen usando los demás módulos.
- **No hay registro libre.** El preceptor crea las cuentas de los alumnos de sus cursos y los directivos las de todos.
- **Códigos de acceso** (`codigos_acceso`), para el primer ingreso y para recuperar la cuenta:
  - 10 caracteres al azar con `random_int`, sin letras ni números que se confundan;
  - se guarda solo su hash SHA-256: el código en claro se muestra una vez, al generarlo;
  - sirven una sola vez y vencen (alta: 7 días; recuperación: 48 horas);
  - generar uno nuevo anula los anteriores de esa cuenta;
  - se marca como usado dentro de la misma transacción que guarda la contraseña: no se puede usar dos veces.
- **Recuperación** (`campus/recuperar.php`): registra un pedido y responde lo mismo exista o no la cuenta, para que no
  sirva para averiguar qué DNI o correos están registrados. El preceptor (alumnos de sus cursos) o un directivo
  entrega el código después de verificar quién lo pide.
- **Límite de intentos** (`campus/includes/limite_intentos.php`):
  - ingreso: 5 fallos seguidos por cuenta o 30 por IP;
  - «Activar cuenta»: 10 códigos incorrectos por IP;
  - «Recuperar cuenta»: 10 pedidos por IP.

  Cada uno bloquea 15 minutos. Los contadores se guardan en la carpeta temporal del sistema (`campus_login/`). La IP sale
  del último valor de `X-Forwarded-For`, que es el que agrega el proxy.
- **Cuentas suspendidas:** no entran ni reciben códigos. El mensaje de «suspendida» solo aparece con la contraseña
  correcta, para no revelar el estado de una cuenta ajena.
- **Panel del preceptor:** cada acción verifica en el servidor que el alumno sea de un curso asignado a ese preceptor.
- **Auditoría:** las altas, los códigos, las suspensiones y los cambios de roles y cursos quedan en el log del servidor
  como `[CAMPUS CUENTAS]`.
- Las contraseñas se guardan con `password_hash`, y se actualiza el hash si PHP cambia el algoritmo por defecto.
- **Profesores anteriores:** su código `PROF-…` sirve una sola vez, para elegir la contraseña, y después se borra.

## Permisos

- `campus/includes/sesion.php` → `$PERMISOS`: qué roles entran a cada módulo. Cada endpoint vuelve a revisar el rol en el
  servidor; no alcanza con ocultar un botón.
- **Preinscripciones** (`preinscripciones/api.php`):
  - el **alumno** solo ve sus propias inscripciones (filtradas por el DNI de su sesión);
  - al guardar, el nombre, el DNI y el correo salen de la sesión, no del navegador;
  - el alumno **no puede** cambiar pagos ni notas (se conservan los que cargó el directivo) ni modificar la inscripción de
    otro DNI;
  - mesas, pagos, notas, configuración y vaciado de inscripciones son solo para directivos.
- **Proyectores:** el profesor reserva a su nombre y cancela solo sus reservas. Se lo identifica por correo e ID
  (`reservas.profesor_id`); las reservas viejas sin ID se comparan por nombre.
- **Estructura académica** (`campus/estructura.php`): solo directivos. La importación vuelve a calcular el plan en el
  servidor y lo aplica solo si coincide con la vista previa que vio el directivo (huella SHA-256), en una transacción.
  Un docente de la planilla solo se vincula con una cuenta que tenga el rol de profesor. «Mis materias»
  (`campus/materias.php`) muestra al profesor solo lo vinculado a su cuenta y al preceptor solo sus cursos. Las
  importaciones y los vínculos quedan en el log como `[CAMPUS ESTRUCTURA]`.
- **ProgMesas:** cada ruta de `progmesas/api.php` exige su rol con `exigirRol()`. El pase de ingreso es un token firmado
  con `PROGMESAS_SECRETO`; sin esa clave configurada no se acepta ningún pase.

## Pedidos falsificados desde otros sitios (CSRF)

Los pedidos que modifican datos tienen que traer una cabecera `X-Requested-With`. Otro sitio no puede agregarla sin
CORS, que este servidor no habilita.

| Módulo | Cómo se protege |
|---|---|
| Preinscripciones | Modificar solo por **POST** y con `X-Requested-With: XMLHttpRequest` (o `preinscripciones`). Todas las llamadas usan `apiFetch()` de `src/js/core.js` |
| Proyectores | `X-Requested-With: reservas` en POST, PUT y DELETE |
| ProgMesas | `X-Requested-With: progmesas` cuando se usa la sesión del campus en lugar del pase |
| Formularios del campus (activar, recuperar, gestión de cuentas, panel del preceptor, estructura académica) | Token con `csrf_campo()` dentro del `<form>` y `csrf_valido()` antes de procesar el POST |

## Datos que se muestran en pantalla (XSS)

- En PHP: todo dato de un usuario se imprime con `e()`.
- En JavaScript: todo dato que se inserta con `innerHTML` pasa por `esc()`, disponible como `window.esc` y en
  `window.AppCore.esc`. Los textos de `alert()`, `confirm()` y `textContent` no necesitan escape.
- El servidor además quita las etiquetas HTML de los textos de las inscripciones antes de guardarlos.

## Errores

Los errores de la base no se envían al navegador: van al log del servidor y el usuario ve un mensaje general.

## Archivos que no tienen que poder descargarse

`campus/includes/`, `campus/data/`, `progmesas/backend/` (salvo los PDF de `uploads/`), los archivos que empiezan con
punto (`.env`, `.git`) y los `.sql`, `.sqlite`, `.zip` y `.xlsx`.

- Con **Apache**, los `.htaccess` del repositorio bloquean `campus/includes/`, `campus/data/`, `progmesas/.env` y la base
  y el código de `progmesas/backend/`. No cubren `preinscripciones/database.sql` (solo tiene la estructura de las tablas)
  ni una carpeta `.git` si se copia junto con el sitio: no copiarla.
- Con **nginx** no se leen los `.htaccess` y hay que configurarlo: ver [`nginx-campus.conf`](nginx-campus.conf).

## Reglas para quien programe

1. Toda página privada empieza con `requerirLogin()` o `requerirPermiso('modulo')`. Para revisar un rol usar
   `tieneRol()` (todos los roles de la persona), no `$_SESSION['rol']` (solo el principal).
2. Todo endpoint revisa el rol **en el servidor** antes de leer o modificar datos.
3. Nunca usar como identidad lo que manda el navegador (nombre, DNI, correo, ID de usuario): tomarlo de `$_SESSION`.
4. Lo que modifica datos va por POST (o PUT/DELETE) y exige la cabecera o el token CSRF del módulo.
5. Consultas siempre con sentencias preparadas (`?`), nunca pegando variables en el SQL.
6. Escapar al mostrar: `e()` en PHP, `esc()` en JavaScript.
7. Identificar a las personas por DNI, correo o ID, nunca por nombre.
8. No mandar `$e->getMessage()` al navegador; usar `error_log()`.
9. No subir al repositorio `config.php`, `.env`, `campus/data/`, bases `.sqlite` ni PDF de alumnos.

## Pendientes conocidos

- **Bloqueo por cuenta:** cualquiera que conozca un DNI puede bloquear esa cuenta 15 minutos con 5 intentos fallidos.
- **Módulos que miran solo el rol principal:** preinscripciones y proyectores revisan `$_SESSION['rol']`. Una persona
  con dos roles usa esos módulos con el principal (por ejemplo, profesor antes que preceptor).
- **Correo:** el sistema no envía correos. Los códigos se entregan en persona; para mandarlos por mail haría falta un
  servicio de correo.
