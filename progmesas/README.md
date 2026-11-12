# ProgMesas

Sistema web para subir, controlar y consultar los **programas de examen** de una escuela secundaria técnica.
Los docentes cargan el PDF de cada materia que dictan, los alumnos lo consultan y la asesoría pedagógica ve qué programas faltan.

Proyecto de Práctica Profesional — Escuela Técnica N.º 2 "Independencia".

- **Demo:** <https://alan4352.github.io/progMesas/>
- **Política de privacidad:** <https://alan4352.github.io/progMesas/privacidad.html>
- **Condiciones del servicio:** <https://alan4352.github.io/progMesas/terminos.html>
- **Guía de uso para cada rol e instalación en el servidor de la escuela:** [LEEME.md](LEEME.md)

## Funciones

| Rol | Puede |
|---|---|
| **Visitante / alumno** | Ver y buscar los programas de todos los años (por materia, curso, año o docente), abrirlos en el visor, compartir el enlace de una búsqueda y descargar todos los de un curso en un ZIP. |
| **Docente** | Subir el programa de cualquier curso y materia (las suyas aparecen primero), reemplazarlo (la versión anterior se conserva), reutilizar el del año anterior y ver la fecha límite. |
| **Asesora** (en el campus, *directivo*) | Todo lo anterior para cualquier materia, eliminar programas, corregir la vinculación de los docentes, fijar la fecha límite y usar el dashboard. |
| **Consulta** (preceptor) | Ver el dashboard y el registro de actividad sin modificar nada. |

**Programas**

- **Regular o libre:** cada materia puede tener dos programas de examen, el **regular** y el **libre**. Al cargarlo se
  elige el tipo; uno no reemplaza al otro. En la lista cada programa lleva su etiqueta y se puede filtrar por tipo
  (`?tipo=libre`). En el dashboard, una materia cuenta como entregada con cualquiera de los dos.
- **Historial de versiones:** al reemplazar un programa, la versión anterior queda guardada con su PDF, quién la reemplazó y cuándo.
- **Reutilizar:** si un programa no cambió, se usa el del año anterior para el mismo curso y materia sin volver a subirlo
  (el archivo se comparte, no se duplica).
- **Enlaces por curso:** los filtros quedan en la dirección de la página (`?curso=2° 1°&anio=2026`), así se comparte el
  enlace de un curso. Sin año, muestra **todos los años**: sirve para alumnos que deben materias de años anteriores.
- **ZIP por curso:** todos los programas de un curso en un archivo, ordenados en carpetas por año.

**Dashboard de asesoría**

- Cobertura del año: programas requeridos, entregados, pendientes y porcentaje.
- **Fecha límite** de entrega por año: muestra los atrasados y los entregados fuera de término.
- Avance por curso (con enlace para alumnos y ZIP) y por docente (con **recordatorio por correo** a quien tiene pendientes).
- Lista de pendientes con filtros, exportación a CSV y copia de los correos de los docentes con pendientes.
- **Registro de actividad:** quién subió, reemplazó, reutilizó o eliminó programas, quién cambió vinculaciones o la
  fecha límite, y las tareas automáticas.
- Avisos cuando Google Drive no está disponible o hay programas guardados en el servidor, con el botón **Pasar a Drive ahora**.
- Control y corrección de la vinculación de las cuentas de docentes.
- Vinculación manual de programas viejos que no coinciden con ninguna materia oficial.

**Interfaz**

- Mismo diseño que el sistema de asistencia de la escuela ([PROJECT_ASIST](https://github.com/moshi-azz/PROJECT_ASIST)):
  fondo bordó con el escudo de marca de agua, menú lateral beige con el escudo, el perfil y las secciones,
  encabezado con degradé bordó, tarjetas beige con borde bordó y encabezado degradé, tarjetas de estadísticas
  y tablas con cabecera bordó. En el celular, el menú se abre con el botón **Menú**.
- Modo oscuro como variante del mismo diseño: sigue la preferencia del sistema y se cambia desde el menú (se recuerda).
- Colores en variables al principio de `frontend/styles.css`.
- Íconos SVG propios, sin librerías externas.
- Adaptada a celulares y revisada con axe-core (WCAG 2.1 AA) en los dos modos.

## Cómo funciona

- **Integrado al campus de la escuela** — los usuarios inician sesión en el campus virtual (PHP) y entran a ProgMesas
  desde el módulo de programas de examen: `programas.php` del campus arma un pase firmado (JWT) y ProgMesas lo verifica
  con la misma clave. ProgMesas **no tiene login propio**. Profesor → docente, directivo → asesora, alumno → alumno.
  Los archivos y los cambios para el campus están en [integraciones/campus-escuela](integraciones/campus-escuela/LEEME.md).
  Para probar sin el campus, `scripts/token_prueba.js` genera un pase como el del campus.
- **Vinculación de docentes** — las cuentas vienen verificadas del campus virtual. Si el nombre de la cuenta coincide con
  uno de la lista oficial, se vincula solo; si no, el docente puede elegirlo. Es opcional: sirve para mostrar sus materias
  primero y figurar con ese nombre. Sin vincular puede cargar igual (figura con el nombre de su cuenta del campus).
- **Lista oficial de asignaciones** — `backend/data/asignaciones.xlsx` indica qué programa tiene que entregar cada curso
  (curso + materia + docentes). Se genera a partir de las planillas de horarios y la asesoría la puede editar con Excel:
  el servidor la relee al guardarla.
- **Cobertura exacta** — cada programa queda asociado a una asignación (curso + materia), así el control no depende de
  cómo se escribió la materia, y Matemática de 2° 1° y de 3° 2° son programas distintos.
- **Almacenamiento** — los PDF se guardan en Google Drive (carpetas Año / Curso / Materia). Si Drive no está disponible,
  se guardan en el servidor y, cuando Drive vuelve, **pasan solos a Drive** (al arrancar y cada 6 horas).
- **Copias de seguridad** — con `RESPALDOS_DIR`, el servidor guarda todos los días la base, los PDF del servidor y la
  planilla, y conserva las últimas copias.

## Tecnologías

- **Backend:** Node.js 20+, Express 5, SQLite (better-sqlite3), Multer, JWT, Google Drive API, SheetJS (xlsx 0.20, desde el sitio oficial de SheetJS)
- **Frontend:** HTML, CSS y JavaScript sin frameworks
- **Pruebas:** `node:test`; interfaz con Playwright y axe-core
- **Despliegue:** GitHub Pages (demo), Render (temporal) y servidor de la escuela

## Instalación

Requisitos: Node.js 20 o superior.

```bash
npm install        # en la raíz: instala también las dependencias de backend/
```

Creá el archivo `.env` en la raíz del proyecto a partir de `.env.example` y completá `EXTERNO_JWT_SECRET` con la misma clave
que `PROGMESAS_SECRETO` del campus (para generar una nueva):

```bash
node -e "console.log(require('crypto').randomBytes(32).toString('hex'))"
```

Iniciá el servidor y abrí <http://localhost:3000>:

```bash
npm start
```

### Variables de entorno

| Variable | Para qué sirve |
|---|---|
| `EXTERNO_JWT_SECRET` | **Obligatoria.** Clave compartida con el campus (`PROGMESAS_SECRETO`), 32 caracteres o más. Sin ella el servidor no arranca. |
| `EXTERNO_LOGIN_URL`, `EXTERNO_LOGOUT_URL` | Páginas del campus para ingresar (`programas.php`) y cerrar sesión (`logout.php`). |
| `ANIO_LECTIVO` | Opcional. El año lectivo se calcula solo (el año en curso en Argentina, cambia el 1 de enero); esta variable lo fija a mano. Los programas se cargan sólo para ese año; la fecha límite admite también el anterior y el siguiente. |
| `PORT` | Puerto del servidor (3000 por defecto). |
| `MAX_PDF_MB` | Tamaño máximo de cada PDF (10 por defecto). |
| `TRUST_PROXY` | `1` si el servidor está detrás de un proxy (Nginx, Render). Hace que los registros usen la IP real del visitante. |
| `DATOS_DIR` | Carpeta para la base y los PDF locales, por ejemplo un disco persistente. La primera vez se copian ahí los del repositorio. |
| `RESPALDOS_DIR` | Carpeta donde el servidor guarda una copia de seguridad por día (vacío = sin copias automáticas). |
| `RESPALDO_HORA` | Hora de la copia diaria (0 a 23; 3 por defecto). |
| `RESPALDOS_CONSERVAR` | Cuántas copias se conservan (14 por defecto); las más viejas se borran. |
| `GOOGLE_OAUTH_JSON`, `GOOGLE_TOKEN_JSON` | Contenido de `backend/oauth.json` y `backend/token.json`, para usar Drive en un hosting sin esos archivos. |
| `DRIVE_CARPETA_RAIZ` | ID de la carpeta de Drive donde se guardan los programas. |
| `DRIVE_ALCANCE` | `completo` (permiso `drive`, por defecto) o `archivos` (permiso `drive.file`, carpeta propia de la app). Ver [LEEME.md](LEEME.md#5-google-drive). |
| `DRIVE_CARPETA_NOMBRE` | Nombre de la carpeta propia con `DRIVE_ALCANCE=archivos`. |
| `DRIVE_DESACTIVADO` | `1` guarda todo en el servidor aunque haya credenciales. |
| `DRIVE_SIMULADO` | **Sólo para las pruebas:** carpeta donde un Drive simulado guarda los archivos. |
| `CORS_ORIGIN` | Otros dominios que pueden usar la API (vacío = sólo el mismo servidor). |
| Otros `EXTERNO_*`, `ROL_*` | Nombre de los datos dentro del token y de los roles (ver `.env.example` y [LEEME.md](LEEME.md#4-usuarios-e-ingreso)). |

### Google Drive (opcional)

Colocá las credenciales OAuth de Google en `backend/oauth.json` y autorizá la cuenta de la escuela:

```bash
cd backend
node scripts/autorizar_drive.js
```

Mientras la app de Google esté en modo "Testing", el permiso vence a los 7 días. Para evitarlo, pasala a
"En producción" en la pantalla de consentimiento de OAuth (usa las páginas de privacidad y condiciones de arriba).

## Pruebas automáticas

```bash
npm test             # API y funciones: 53 pruebas, unos segundos (las del campus necesitan PHP)
npm run test:ui      # interfaz en un navegador y accesibilidad: 11 pruebas
```

- **API** (`tests/*.test.js`): utilidades, roles, planilla de asignaciones, ingreso con el pase del campus (y rechazo de pases falsos o vencidos),
  permisos de cada rol, vinculación de docentes, subida, reemplazo y versiones, reutilizar, fecha límite, registro,
  ZIP por curso, copias de seguridad, modo externo (pases del campus y de otros sistemas) y Google Drive **simulado**
  (subida, caída de Drive, paso a Drive cuando vuelve y borrado).
- **Interfaz** (`tests/interfaz.ui.js`): recorre la página en Chromium como visitante, asesora, docente, preceptor y alumno,
  prueba el modo oscuro y el celular, y revisa la accesibilidad con axe-core en los dos modos.
  La primera vez hay que instalar el navegador: `npx playwright install chromium`.

Todas usan el servidor real sobre una **copia temporal** de la base y nunca tocan Google Drive ni los datos del proyecto.
GitHub Actions las corre en cada cambio (`.github/workflows/pruebas.yml`, Node 20 y 22) y antes de publicar la demo:
si alguna falla, no se publica.

## Administración

```bash
cd backend

# Importar las planillas de horarios (una hoja por curso) → data/asignaciones.xlsx
node scripts/importar_horarios.js "Horarios_T.Mañana.xlsx" "Horarios_T.Tarde.xlsx"

# Copia de seguridad ahora (base, PDF del servidor y planilla)
node scripts/respaldo.js D:\Respaldos\ProgMesas

# Simular el campus: genera un pase y el enlace para entrar con esa identidad
node scripts/token_prueba.js --rol profesor --nombre "Valeria Grimberg" --id 1234
```

ProgMesas no tiene cuentas propias: las personas se dan de alta en el campus. Más detalles en [LEEME.md](LEEME.md).

## Despliegue

### Demo en GitHub Pages

<https://alan4352.github.io/progMesas/>

GitHub Pages sólo sirve archivos estáticos, así que la demo no usa el servidor: `demo/demo.js` atiende las
mismas rutas `/api/...` dentro del navegador, con las mismas reglas de permisos, a partir de los datos de la
base y de `asignaciones.xlsx`. Los cambios (subir, borrar, vincular docentes, fecha límite) quedan sólo en el navegador
de quien la usa y se pueden restablecer. Se publica sola en cada push a `main` (`.github/workflows/pages.yml`),
junto con las páginas de privacidad y condiciones. En la demo, el ZIP de un curso no incluye los PDF que están en Google Drive.

En la demo no hay campus: **Entrar como…**, en la barra de demostración, hace de campus y permite entrar como asesora,
docente vinculado, docente nuevo (elige su nombre), docente que se vincula sola, preceptor o alumno.

Para probarla en la compu: `npm install`, después `node demo/construir_sitio.js` y servir la carpeta `_site`.

### Servidor de la escuela (instalación definitiva)

Los pasos (servicio que arranca solo en Linux o Windows, HTTPS con Nginx, copias de seguridad y actualizaciones)
están en [LEEME.md](LEEME.md#2-instalación-en-el-servidor-de-la-escuela).

### Dentro del campus, sobre Apache/XAMPP (sin Node)

En el campus integrado, ProgMesas es la carpeta `progmesas/`, al lado de `campus/`, `proyectores/` y `preinscripciones/`.
Ahí la API la atiende **`api.php`** (PHP + SQLite), con la misma base `backend/database.sqlite`, la misma planilla
`backend/data/asignaciones.xlsx` y las mismas reglas que el servidor Node, así que no hace falta instalar Node.

- `index.php` es la entrada: si la persona ya inició sesión en el campus, la manda a `campus/programas.php`, que arma el
  pase y vuelve; si no, muestra la consulta pública.
- `api.php` toma la clave `PROGMESAS_SECRETO` de `campus/includes/config.php` (sin ella no acepta ningún pase) y también
  reconoce la sesión del campus del mismo navegador.
- Los PDF se guardan en `backend/uploads/`. Los `.htaccess` impiden descargar la base, la planilla, el código y el `.env`.
- El frontend llama siempre a `api.php?r=/ruta`: dentro del campus responde `api.php` y el servidor Node acepta la misma
  forma, así el mismo frontend sirve en los dos casos.
- `campus/includes/progmesas.php` usa el servidor Node si está corriendo en el puerto 3000 y, si no, la carpeta `progmesas/`.
  Para usar Node, `iniciar.bat` (Windows) o `iniciar.sh` arrancan el servidor.
- Requisitos de PHP: las extensiones `pdo_sqlite` y `zip` (vienen con XAMPP). Google Drive sólo funciona con el servidor Node.

### Sistema completo en Render (temporal)

Render se usa mientras tanto, hasta instalarlo en el servidor de la escuela. `render.yaml` configura el servidor real:
en render.com → **New → Blueprint** → elegir este repositorio. Render vuelve a desplegar en cada push a `main`.

Si el servicio se crea a mano: Build Command `npm install`, Start Command `npm start`, y en **Environment**
cargar `EXTERNO_JWT_SECRET`, `EXTERNO_LOGIN_URL`, `EXTERNO_LOGOUT_URL` y `TRUST_PROXY=1`. Para Drive, agregar
`GOOGLE_OAUTH_JSON` y `GOOGLE_TOKEN_JSON` con el contenido de esos archivos.

En el plan gratis el disco se borra al reiniciar: los PDF guardados en el servidor se pierden. Para conservarlos
hace falta Google Drive o un disco persistente (`DATOS_DIR`, ver `render.yaml`).

## Estructura

```
├── index.html               Página principal (listado y subida; el ingreso es desde el campus)
├── index.php, api.php       Entrada y API en PHP para correr dentro del campus sobre Apache/XAMPP (sin Node)
├── .htaccess                Protección de archivos en Apache (también backend/.htaccess)
├── iniciar.bat, iniciar.sh  Arrancan el servidor Node dentro de la carpeta del campus
├── privacidad.html          Política de privacidad (pantalla de consentimiento de Google OAuth)
├── terminos.html            Condiciones del servicio
├── package.json             Arranque desde la raíz (instala backend/ con postinstall) y scripts de pruebas
├── render.yaml              Despliegue en Render
├── .github/workflows/       Pruebas automáticas y publicación de la demo en GitHub Pages
├── tests/                   Pruebas automáticas: API (*.test.js), interfaz (interfaz.ui.js) y servidor de prueba
├── demo/                    Demo estática: datos exportados y API simulada en el navegador
├── integraciones/
│   └── campus-escuela/      Puente de ingreso desde el campus de la escuela (PHP) y cambios a hacer en el campus
├── frontend/
│   ├── styles.css           Estilos y paleta (modo claro y oscuro)
│   ├── tema.js              Cambio de modo claro / oscuro
│   ├── marco.js             Menú lateral (en el celular) y fecha del encabezado
│   ├── img/escudo.png       Escudo de la escuela (menú y marca de agua)
│   ├── common.js            Utilidades compartidas, sesión, íconos, descargas y fechas
│   ├── app.js               Página principal
│   └── dashboard.html/.js   Dashboard de la asesora
└── backend/
    ├── server.js            API y servidor de archivos
    ├── config.js            Configuración (.env y variables de entorno)
    ├── db.js                Base SQLite y migraciones
    ├── auth.js              Identidad del usuario (verifica el pase del campus)
    ├── docentes.js          Vinculación cuenta ↔ nombre en la lista oficial
    ├── asignaciones.js      Lectura de la lista oficial
    ├── store.js             Programas cargados y versiones anteriores
    ├── registro.js          Registro de actividad
    ├── ajustes.js           Fecha límite por año
    ├── almacenamiento.js    Guardado de PDF (Drive o servidor) y paso a Drive
    ├── drive.js             Google Drive
    ├── respaldos.js         Copias de seguridad
    ├── lib/                 Importador de horarios, ZIP, Drive simulado y utilidades
    ├── scripts/             Herramientas de línea de comandos
    ├── data/                asignaciones.xlsx
    └── uploads/             PDF guardados localmente
```

## Seguridad

- ProgMesas no guarda contraseñas ni tiene login propio: el pase del campus se verifica con su firma y su vencimiento
  (se rechazan pases sin firma, firmados con otra clave, vencidos o con roles desconocidos). Las contraseñas las maneja
  el campus.
- Un docente puede cargar el programa de cualquier curso, pero cada carga queda en el **registro de actividad** con su
  cuenta, y si reemplaza un programa la versión anterior se conserva. No puede tomar un nombre de la lista que ya usa
  otra cuenta. El historial de versiones lo ven sólo la asesora y el docente de la materia.
- Sólo se aceptan PDF válidos (se revisa el contenido, no sólo la extensión; hasta 10 MB por defecto). Los programas se cargan
  sólo para el año lectivo actual y con título automático (se controla en el servidor, no sólo en el formulario).
- Todo el contenido cargado por usuarios se escapa antes de mostrarse, con una política CSP que bloquea scripts externos.
- Registro de actividad de todas las acciones que modifican datos.
- La exportación a CSV neutraliza textos que Excel interpretaría como fórmulas.
- Las credenciales (`.env`, `oauth.json`, `token.json`, `clave.json`) están excluidas del repositorio; en un hosting
  se pasan como variables de entorno.
- Dependencias sin vulnerabilidades conocidas (`npm audit`, octubre de 2026).

## Historial de cambios

### 12/11/2026 — Programas regulares y libres

- Al subir un programa se elige **Tipo de programa: Regular o Libre**. Cada materia, curso y año puede tener los dos;
  subir el libre no reemplaza al regular (y al revés). Reemplazar y reutilizar el del año anterior funcionan por tipo.
- El título automático incluye el tipo ("Programa libre de Matemática - 2° 1° - 2026").
- Base de datos: columna `programas.tipo` (`regular` o `libre`). Los programas cargados antes quedan como **regulares**
  y su título pasa a "Programa regular de …". La migración se hace sola al arrancar (Node) o al primer uso (`api.php`).
- API: `tipo` en `POST /programas/subir` y `POST /programas/reutilizar` (sin tipo = regular; otro valor = error 400);
  `/asignaciones/mias` devuelve `programas` y `anteriores` por tipo; `/cobertura` devuelve `programas` por tipo y
  cuenta la materia como entregada con cualquiera de los dos; `/cursos/zip` acepta `tipo` y nombra los PDF
  "Materia - Regular.pdf" / "Materia - Libre.pdf".
- Listado: etiqueta **Regular** / **Libre** en cada programa y filtro por tipo (queda en el enlace compartido).
- Dashboard: **Últimos programas cargados** muestra los dos tipos con su etiqueta; el registro de actividad indica el tipo.
- Igual en `api.php` (versión PHP dentro del campus), en el servidor Node y en la demo. Pruebas nuevas para subir,
  reemplazar, reutilizar, cobertura y ZIP por tipo, y para el formulario y el filtro en la interfaz.

### 06/11/2026 — Versión PHP para correr dentro del campus (prototipo v4)

- Se sumaron los cambios del prototipo v4: `api.php` (la API en PHP + SQLite, para Apache/XAMPP sin Node), `index.php`,
  los `.htaccess`, el enlace **Volver al campus** y la elección automática entre Node y la carpeta en
  `campus/includes/progmesas.php`.
- `api.php` se puso al día con las últimas reglas: año lectivo fijo, título automático y tamaño máximo del PDF. Se quitó
  una clave escrita en el código: ahora sólo usa `PROGMESAS_SECRETO` del campus. La sesión del campus se lee con los
  mismos datos que guarda `login.php` (`id`, `correo`).
- El frontend llama a `api.php?r=/ruta` en todos los casos; el servidor Node acepta esa forma y sirve los PDF también en
  `/backend/uploads/`. "Volver al campus" sale de la dirección de ingreso del campus (oculto si no hay campus).
- Pruebas: la API responde como `api.php?r=/ruta` y los PDF se sirven en `/backend/uploads/`.

### 05/11/2026 — Correcciones de la carpeta del proyecto

- Diagrama de Gantt con las dependencias entre actividades, la superposición de la 11 y la 12 y una franja de carga de
  trabajo (actividades en curso cada día); octubre ampliado por semana.
- Diagrama UML de casos de uso con generalización entre actores y relaciones «include» y «extend», más una tabla con el
  actor y la relación de cada caso.
- Diccionario de datos (campos, tipos, nulos, PK y FK) de las cinco tablas y de la planilla de asignaciones.
- Índice automático con los números de página reales y número de página en el pie. Se agregaron
  `Carpeta del Proyecto — ProgMesas.pdf` y los scripts de exportación en `docs/carpeta/`.

### 05/11/2026 — Año lectivo fijo y título automático

- El formulario de carga ya no tiene **Título**: siempre se genera solo ("Programa de Materia - Curso - Año"), también al
  reutilizar el programa de un año anterior. Si un pedido trae otro título, se ignora.
- El **Año lectivo** se muestra fijo (el actual) y no se puede cambiar. El servidor rechaza subir o reutilizar para otro año.
  La fecha límite la sigue pudiendo fijar la asesora para el año anterior, el actual o el siguiente.
- Pruebas: los programas de años anteriores se cargan directo en la base de prueba (`sembrarPrograma`).

### 05/11/2026 — ProgMesas dentro del campus integrado

- ProgMesas se agregó como subpágina del campus integrado (`final/progmesas`, al lado de proyectores y preinscripciones).
  El campus es la página principal y da el inicio de sesión. Se agregó un módulo para directivos, "Control de programas de examen".
- `integraciones/campus-escuela/LEEME.md` explica la carpeta (`index.php`, `.htaccess`, `iniciar.bat` / `iniciar.sh`) y
  cómo calcular `PROGMESAS_URL` a partir del servidor del campus.

### 04/11/2026 — El año lectivo se actualiza solo

- El año lectivo es el año en curso en Argentina y cambia solo el 1 de enero, sin tocar el `.env` ni reiniciar el servidor.
  Con él se corren los años que se pueden cargar (anterior, actual y siguiente) y el año por defecto del formulario y el dashboard.
- `ANIO_LECTIVO` pasa a ser opcional (sólo para fijarlo a mano) y se quitó de `render.yaml` y `.env.example`. La demo también lo calcula.

### 04/11/2026 — Límite para el año lectivo

- Los programas sólo se pueden cargar (subir o reutilizar) para el año lectivo anterior, el actual o el siguiente
  (con `ANIO_LECTIVO=2026`: 2025, 2026 o 2027). Lo mismo para fijar la fecha límite. Así nadie carga por error un programa para 2030.
- En el formulario, **Año lectivo** pasa a ser una lista con esos tres años en lugar de un campo libre. El servidor también lo controla.
- Buscar y ver programas de cualquier año sigue igual.

### 03/11/2026 — Los docentes cargan programas de cualquier curso

- Un docente puede subir o reutilizar el programa de **cualquier curso y materia**, no sólo de los que tiene asignados
  en la lista de horarios. Sus materias aparecen primero, en **Mis materias**.
- Elegir el nombre de la lista pasa a ser opcional: sin vincular, el programa figura con el nombre de la cuenta del campus.
- Pruebas actualizadas: un docente sube el programa de un curso que no tiene asignado y uno sin vincular también puede subir.

### 03/11/2026 — Sin login propio: el ingreso es sólo desde el campus

- Se eliminó el login de ProgMesas: el botón **Acceso docentes**, el formulario de correo y contraseña, la ruta
  `/api/login`, las cuentas de prueba y el script `scripts/usuarios.js`. **Ingresar con el campus** lleva al campus y
  **Cerrar sesión** cierra también la sesión del campus.
- `EXTERNO_JWT_SECRET` (la clave compartida con el campus) es obligatoria; ya no se usan `AUTH_MODO` ni `JWT_SECRET`.
- Demo: la barra de demostración tiene **Entrar como…** (hace de campus) en lugar de las cuentas con contraseña.
- Pruebas: entran con pases como los del campus; se agregó la comprobación de que la página no pide contraseña.

### 03/11/2026 — Revisión de la carpeta del proyecto

- Carpeta del proyecto revisada: actores y requerimientos coinciden con el diagrama de casos de uso (alumno, docente,
  asesora), cantidad de pruebas actualizada y referencias en orden alfabético (APA). El documento incluye completos la
  guía de uso (Anexo C), la documentación interna (Anexo D) y el código de los archivos principales (Anexo B).
- README, LEEME y comentarios del código nombran al campus virtual como sistema de ingreso.

### 02/11/2026 — Ingreso desde el campus de la escuela

- El login viene del campus virtual de la escuela: `integraciones/campus-escuela/programas.php` arma un pase firmado
  (JWT HS256) con la sesión del campus y abre ProgMesas sin pedir otra contraseña. Si no hay sesión, manda al login del
  campus y vuelve solo.
- Roles: profesor → docente, **directivo → asesora** (antes, consulta), alumno → alumno.
- Nueva variable `EXTERNO_LOGOUT_URL`: **Cerrar sesión** (en la página principal y en el dashboard) cierra también la
  sesión del campus.
- La carpeta del proyecto (`Carpeta del Proyecto — ProgMesas.docx`) describe el ingreso desde el campus virtual.
- Pruebas `tests/campus.test.js`: generan pases con el código PHP del campus y comprueban roles, configuración y el
  rechazo de pases con otra clave o modificados.

### 27/10/2026 — Diseño del sistema principal

- Las páginas usan el mismo diseño que el sistema de asistencia de la escuela (PROJECT_ASIST): menú lateral beige con
  escudo, perfil y secciones; encabezado con degradé bordó; fondo bordó con el escudo de marca de agua; tarjetas beige
  con encabezado degradé; tarjetas de estadísticas con ícono; tablas con cabecera bordó.
- En el celular: encabezado con escudo y botón **Menú** que abre el menú lateral (se cierra con Escape o tocando afuera).
- El botón de modo oscuro y los enlaces a privacidad y condiciones pasan al menú lateral.
- Enlace "Saltar al contenido" para teclado y lectores de pantalla.

### 27/10/2026 — Mejoras de uso, control y mantenimiento

- Historial de versiones de cada programa (la versión reemplazada ya no se borra).
- Reutilizar el programa del año anterior para el mismo curso y materia.
- Fecha límite de entrega por año, con atrasados y entregados fuera de término en el dashboard.
- Recordatorio por correo a los docentes con pendientes y copia de sus correos.
- Enlaces por curso (filtros en la dirección, con todos los años) y descarga de los programas de un curso en un ZIP.
- Registro de actividad en el dashboard.
- Los PDF guardados en el servidor pasan solos a Google Drive cuando vuelve a estar disponible (o con un botón).
- Copias de seguridad diarias (`RESPALDOS_DIR`) y `scripts/respaldo.js`.
- Modo externo: `scripts/token_prueba.js` para simular el sistema principal, y pruebas automáticas del ingreso con token.
- Pruebas: 51 de API (con Google Drive simulado) y 11 de interfaz y accesibilidad con Playwright y axe-core,
  todas en GitHub Actions antes de publicar.
- Accesibilidad: mejor contraste de los colores de estado y barras de avance con nombre para lectores de pantalla.
- Arreglo: las copias de seguridad hechas en el mismo segundo no se borraban al pasar el límite.

### 27/10/2026 — Vinculación de docentes sin aprobación

- La asesora ya no tiene que aprobar a los docentes (las cuentas vienen verificadas del sistema principal):
  - vinculación automática cuando el nombre de la cuenta coincide con uno solo de la lista de horarios;
  - si no coincide, el docente elige su nombre y puede cargar en el momento;
  - la asesora sigue pudiendo asignar, cambiar o desvincular nombres; las solicitudes pendientes se confirmaron solas.
- Demo: cuenta `docente3@test.com` para ver la vinculación automática.
- Política de privacidad y condiciones del servicio actualizadas.

### 27/10/2026 — Pruebas automáticas y guía de uso

- Pruebas automáticas (`npm test`) de utilidades y de la API con cada rol, corridas por GitHub Actions
  en cada cambio y antes de publicar la demo.
- Opción `DRIVE_ALCANCE=archivos` para probar el permiso reducido `drive.file` de Google Drive (carpeta propia de la app).
- `DRIVE_DESACTIVADO` para guardar todo en el servidor aunque haya credenciales.
- LEEME: guía de uso para cada rol, preguntas frecuentes e instalación en el servidor de la escuela
  (systemd / NSSM, Nginx con HTTPS, copias de seguridad y actualizaciones).

### 27/10/2026 — Publicación, diseño y seguridad

- Demo pública en GitHub Pages con la API simulada en el navegador y publicación automática.
- Despliegue del sistema completo en Render (`render.yaml`, `DATOS_DIR`, credenciales de Drive por variables de entorno).
- Páginas de política de privacidad y condiciones del servicio, enlazadas desde el pie.
- Íconos SVG en lugar de emojis.
- Nueva paleta institucional y modo oscuro.
- Mejoras de seguridad: dependencias actualizadas (Express, Multer y SheetJS sin vulnerabilidades conocidas),
  `TRUST_PROXY` para el límite de intentos detrás de un proxy, limpieza del registro de intentos y CSV sin fórmulas.
- Arreglos: los filtros ya no se aplastan en el celular y el botón de ver contraseña queda centrado.
