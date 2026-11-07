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
- Datos que guarda `login.php` y usan los módulos (no cambiar el formato):

  | Clave | Contenido |
  |---|---|
  | `rol` | `alumno`, `profesor` o `directivo` |
  | `nombre`, `correo` | Datos de la cuenta |
  | `id` | `alumno:<DNI>`, `profesor:<correo>` o `directivo:<correo>`. Lo usan ProgMesas y proyectores |
  | `usuario_id`, `dni` | ID numérico de la tabla y DNI. Los usa preinscripciones |

## Inicio de sesión y cuentas

- **Límite de intentos** (`campus/login.php`): 5 fallos seguidos por cuenta o 30 por IP bloquean 15 minutos. Los
  contadores se guardan en la carpeta temporal del sistema (`campus_login/`). La IP sale del último valor de
  `X-Forwarded-For`, que es el que agrega el proxy.
- **Recuperación de contraseña:** solo un directivo puede restablecerla, desde `campus/restablecer.php`, con token CSRF.
  La contraseña nueva es aleatoria (10 caracteres), se muestra solo al directivo y cada cambio queda en el log del
  servidor (`[CAMPUS AUDITORIA]`).
- Contraseñas y códigos se guardan con `password_hash`.

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
| Formularios del campus | Token con `csrf_campo()` dentro del `<form>` y `csrf_valido()` antes de procesar el POST |

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

1. Toda página privada empieza con `requerirLogin()` o revisa el rol con `tienePermiso()` / `$_SESSION['rol']`.
2. Todo endpoint revisa el rol **en el servidor** antes de leer o modificar datos.
3. Nunca usar como identidad lo que manda el navegador (nombre, DNI, correo, ID de usuario): tomarlo de `$_SESSION`.
4. Lo que modifica datos va por POST (o PUT/DELETE) y exige la cabecera o el token CSRF del módulo.
5. Consultas siempre con sentencias preparadas (`?`), nunca pegando variables en el SQL.
6. Escapar al mostrar: `e()` en PHP, `esc()` en JavaScript.
7. Identificar a las personas por DNI, correo o ID, nunca por nombre.
8. No mandar `$e->getMessage()` al navegador; usar `error_log()`.
9. No subir al repositorio `config.php`, `.env`, `campus/data/`, bases `.sqlite` ni PDF de alumnos.

## Pendientes conocidos

- **Registro abierto de alumnos:** cualquiera puede registrarse con el DNI de otra persona. La propuesta es que la
  escuela cargue el padrón y el preceptor entregue un código de alta por alumno.
- **Clave compartida para profesores:** quien la tenga puede crearse una cuenta de profesor. Conviene cambiarla
  periódicamente y, a futuro, usar un código de invitación por profesor.
- **Bloqueo por cuenta:** cualquiera que conozca un DNI puede bloquear esa cuenta 15 minutos con 5 intentos fallidos.
- **`MODO_PRUEBA`:** mientras esté en `true`, las contraseñas nuevas se muestran en pantalla y los códigos de profesor
  quedan en `campus/data/correos_simulados.txt`.
