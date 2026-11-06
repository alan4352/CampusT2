# CampusT2

Campus de la Escuela Técnica N.º 2 "Independencia", hecho por varios alumnos del último año. El **campus** es la página
principal y da el inicio de sesión; los demás sistemas son subpáginas que usan esa misma sesión.

| Carpeta | Qué es |
|---|---|
| `campus/` | Campus virtual: portal, registro, inicio de sesión (alumno, profesor, directivo) y panel con los módulos |
| `preinscripciones/` | Mesas de examen: preinscripción de alumnos y gestión administrativa |
| `proyectores/` | Reserva de proyectores para profesores y directivos |
| `progmesas/` | ProgMesas: carga, control y consulta de los programas de examen ([repositorio propio](https://github.com/alan4352/progMesas)) |
| `index.php` | Entrada: manda a cada persona a su panel según el rol, o al portal si no inició sesión |

## Instalación (XAMPP)

1. Copiar esta carpeta dentro de `htdocs` (o en la raíz del sitio del servidor).
2. Copiar `campus/includes/config.example.php` como `campus/includes/config.php` y completar:
   - los datos de la base MySQL (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`);
   - `CLAVE_REGISTRO_PROFESOR`, la clave que piden los profesores para registrarse;
   - `PROGMESAS_SECRETO`, una clave larga para entrar a ProgMesas con la sesión del campus
     (`php -r "echo bin2hex(random_bytes(32));"`).
3. Las tablas de preinscripciones están en `preinscripciones/database.sql`; las demás se crean solas al usarse.
4. ProgMesas funciona con PHP (`progmesas/api.php`, necesita las extensiones `pdo_sqlite` y `zip` de XAMPP). Si además
   se inicia su servidor Node (`progmesas/iniciar.bat`), el campus lo usa automáticamente. Para Node hace falta
   `progmesas/.env` con `EXTERNO_JWT_SECRET` igual a `PROGMESAS_SECRETO` (ver `progmesas/.env.example`).

`config.php`, `progmesas/.env` y los datos de `campus/data/` (usuarios y correos del modo prueba) no se suben al
repositorio: tienen contraseñas y datos personales.

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
