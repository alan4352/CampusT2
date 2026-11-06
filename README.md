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
