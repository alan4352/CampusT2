# CampusT2

Campus de la Escuela Técnica N.º 2 "Independencia", hecho por varios alumnos del último año. El **campus** es la página
principal y da el inicio de sesión; los demás sistemas son subpáginas que usan esa misma sesión.

| Carpeta | Qué es |
|---|---|
| `campus/` | Campus virtual: portal, registro, inicio de sesión unificado y panel con los módulos de cada rol |
| `preinscripciones/` | Mesas de examen: preinscripción de alumnos y gestión administrativa |
| `proyectores/` | Reserva de proyectores para profesores y directivos |
| `progmesas/` | ProgMesas: carga, control y consulta de los programas de examen ([repositorio propio](https://github.com/alan4352/progMesas)) |
| `index.php` | Entrada: manda a cada persona a su panel según el rol, o al portal si no inició sesión |
| `docs/` | Seguridad del sistema y configuración de nginx para un servidor propio |
| `docker/`, `Dockerfile`, `render.yaml` | Publicación en Render (no se copian a un servidor propio) |
| `herramientas/` | Scripts de línea de comandos (por ejemplo, importar bases `.sql` en Aiven) |

## Usuarios y acceso

| Rol | Cómo se crea la cuenta | Con qué entra | Módulos |
|---|---|---|---|
| **Alumno** | Se registra en `campus/registro.php` con nombre, correo y DNI; el sistema le genera la contraseña | DNI o correo + contraseña | Preinscripción a mesa, programas de examen |
| **Profesor** | Se registra con nombre, DNI, correo y la clave de la escuela (`CLAVE_REGISTRO_PROFESOR`); recibe un código `PROF-…` | Código `PROF-…`, o DNI/correo + código | Reserva de proyectores, subir programas de examen |
| **Directivo** | Lo agrega la escuela en `campus/includes/datos.php` (no se registra solo) | Correo + contraseña | Todo lo anterior, gestión de mesas, control de programas y restablecer contraseñas |

- **Un solo formulario de ingreso** (`campus/login.php`): detecta si se escribió un DNI, un correo o un código de profesor.
- **Límite de intentos:** 5 fallos seguidos con la misma cuenta, o 30 desde la misma IP, bloquean el acceso 15 minutos.
- **Olvido de contraseña:** el alumno se la pide a un directivo, que la restablece desde **Restablecer contraseñas**
  (`campus/restablecer.php`). La página «¿Olvidó su contraseña?» solo explica esto: no cambia contraseñas.
- Los permisos de cada módulo están en la tabla `$PERMISOS` de `campus/includes/sesion.php`.
- Con `MODO_PRUEBA` en `true` no se envían correos: la contraseña o el código se muestran en pantalla y el mensaje se
  guarda en `campus/data/correos_simulados.txt`.

## Requisitos

- PHP 8.1 o superior con las extensiones **`pdo_mysql`**, **`pdo_sqlite`** (ProgMesas), **`mbstring`**, **`xml`**
  (SimpleXML, para leer la planilla de asignaciones de ProgMesas) y `zlib`. Si falta `mbstring`, el registro da error 500.
- MySQL 8 o MariaDB.
- Apache (XAMPP) o nginx + PHP-FPM.

## Instalación (XAMPP)

1. Copiar esta carpeta dentro de `htdocs` (o en la raíz del sitio del servidor).
2. Copiar `campus/includes/config.example.php` como `campus/includes/config.php` y completar:
   - los datos de la base MySQL (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`);
   - `CLAVE_REGISTRO_PROFESOR`, la clave que piden los profesores para registrarse;
   - `PROGMESAS_SECRETO`, una clave larga para entrar a ProgMesas con la sesión del campus
     (`php -r "echo bin2hex(random_bytes(32));"`).
3. Las tablas de preinscripciones están en `preinscripciones/database.sql`; las demás se crean solas al usarse
   (ver «Cambios automáticos en la base»).
4. ProgMesas funciona con PHP (`progmesas/api.php`). Si además se inicia su servidor Node (`progmesas/iniciar.bat`),
   el campus lo usa automáticamente. Para Node hace falta `progmesas/.env` con `EXTERNO_JWT_SECRET` igual a
   `PROGMESAS_SECRETO` (ver `progmesas/.env.example`).

`config.php`, `progmesas/.env`, los datos de `campus/data/` (usuarios y correos del modo prueba), la base
`progmesas/backend/database.sqlite` y los PDF de `progmesas/backend/uploads/` no se suben al repositorio: tienen
contraseñas y datos personales.

### Cambios automáticos en la base

Al usarse por primera vez, el código crea las tablas que faltan y agrega columnas nuevas sin borrar nada:

- `profesores.dni` (lo agrega `campus/includes/usuarios.php`);
- `reservas.profesor_id`, `profesores.id` si no existe, y un índice único por proyector + día + hora en `reservas`
  (lo agrega `proyectores/conexion.php`). Si ya hay reservas duplicadas, el índice no se crea y queda anotado en el log.

El usuario de MySQL necesita permiso para `CREATE` y `ALTER`. Antes de actualizar un servidor con datos reales,
conviene respaldar la base.

## Publicar en un servidor propio (Linux con nginx)

Así está publicado el campus de la escuela: Debian con nginx y PHP-FPM.

1. Instalar PHP y sus extensiones:
   `sudo apt-get install php8.4-fpm php8.4-mysql php8.4-sqlite3 php8.4-mbstring php8.4-xml`.
2. Copiar el código a la carpeta del sitio (por ejemplo `/var/www/final`) **sin** `docker/`, `Dockerfile`,
   `render.yaml`, `.dockerignore` ni `herramientas/`: son para Render o para usar desde la línea de comandos, y dentro de
   la carpeta web quedarían a la vista.
3. Crear `campus/includes/config.php` como en la instalación con XAMPP.
4. Configurar nginx con [`docs/nginx-campus.conf`](docs/nginx-campus.conf). **nginx no lee los `.htaccess`**: sin esa
   configuración se pueden descargar desde internet las cuentas de `campus/data/`, la base de ProgMesas y otros archivos
   internos.
5. Comprobar con `sudo nginx -t` y recargar con `sudo systemctl reload nginx`.

**Para actualizar** con una versión nueva: hacer primero una copia de la carpeta del sitio y copiar solo el código. No
pisar `campus/includes/config.php`, `campus/data/`, `progmesas/.env`, `progmesas/backend/database.sqlite`,
`progmesas/backend/uploads/` ni `progmesas/backend/data/`.

## Publicar en Render (gratis, con MySQL en Aiven)

Render no tiene PHP ni MySQL propios: el campus corre en un contenedor Docker (`Dockerfile`) y la base va en otro
proveedor. Con el plan gratis de Render, lo que se guarda en el servidor (la base y los PDF de ProgMesas, los correos
simulados) se borra cada vez que el servicio se reinicia, y el servicio se duerme a los 15 minutos sin visitas.

1. **Base de datos.** En [aiven.io](https://aiven.io) crear un servicio **MySQL** con el plan gratis. Anotar host,
   puerto, usuario, contraseña y base (`defaultdb`), y descargar el certificado **CA certificate**.
2. **Render.** En render.com → **New → Blueprint** → elegir este repositorio. `render.yaml` crea el servicio web y pide:

   | Variable | Valor |
   |---|---|
   | `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` | Los datos de Aiven |
   | `DB_SSL_CA` | El texto completo del certificado de Aiven (opcional: sin él la conexión va cifrada pero sin verificar) |
   | `CLAVE_REGISTRO_PROFESOR` | La clave que piden los profesores para registrarse |

   `PROGMESAS_SECRETO` la genera Render sola; `DB_SSL` y `MODO_PRUEBA` ya vienen en `true`.
3. Al arrancar, el contenedor crea las tablas `alumnos` y `profesores` (`docker/crear_tablas.php`); las de
   preinscripciones, proyectores y ProgMesas se crean solas al usarse.

### Pasar las bases que ya tienen (archivos .sql) a Aiven

`herramientas/importar_sql.php` importa un archivo `.sql` en Aiven con conexión cifrada. Antes lo limpia de lo que un
servicio en la nube no acepta (CREATE DATABASE, USE, CREATE USER, GRANT, DEFINER y comandos sólo de MariaDB/XAMPP).
Todos los módulos usan **una sola base**: si hay varios `.sql` (por ejemplo el del campus y el de preinscripciones), se
importan todos en la misma.

```
C:\xampp\php\php.exe herramientas\importar_sql.php campus.sql --host=HOST --port=PUERTO --user=avnadmin --base=defaultdb --ca=ca.pem --probar
C:\xampp\php\php.exe herramientas\importar_sql.php campus.sql --host=HOST --port=PUERTO --user=avnadmin --base=defaultdb --ca=ca.pem
```

`--probar` sólo prueba la conexión. La contraseña se pide al ejecutarlo. Aiven exige clave primaria en todas las tablas:
si una tabla no tiene, el script lo avisa.

Para probar el contenedor en la compu: `docker build -t campust2 .` y
`docker run -p 8080:80 -e DB_HOST=... -e DB_USER=... -e DB_PASS=... campust2`.

## Seguridad

Las protecciones del sistema (sesión, CSRF, permisos, escape de datos) y las reglas para quien modifique el código
están en [`docs/SEGURIDAD.md`](docs/SEGURIDAD.md). Leelo antes de agregar una página o un endpoint nuevo.
