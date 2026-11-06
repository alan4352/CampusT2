// Configuración central. Lee el .env de la raíz del proyecto (o de backend/) y
// valida lo imprescindible antes de levantar el servidor.

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import dotenv from 'dotenv';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

export const RAIZ = path.join(__dirname, '..');
export const BACKEND = __dirname;

for (const archivo of [path.join(RAIZ, '.env'), path.join(BACKEND, '.env')]) {
    if (fs.existsSync(archivo)) dotenv.config({ path: archivo, quiet: true });
}

// ================= IDENTIDAD =================
// ProgMesas no tiene login propio: el usuario inicia sesión en el campus de la escuela y llega
// con un pase firmado (token JWT). Acá se configura cómo verificarlo.
export const AUTH_MODO = 'externo';

const lista = (valor, defecto) =>
    String(valor ?? defecto)
        .split(',')
        .map((x) => x.trim().toLowerCase())
        .filter(Boolean);

export const EXTERNO = {
    // Verificación del token: secreto compartido (HS256) o archivo con la clave pública (RS256)
    secreto: process.env.EXTERNO_JWT_SECRET || '',
    clavePublica: process.env.EXTERNO_JWT_CLAVE_PUBLICA
        ? fs.readFileSync(path.resolve(RAIZ, process.env.EXTERNO_JWT_CLAVE_PUBLICA), 'utf-8')
        : '',
    // Nombre de los datos dentro del token
    claimId: process.env.EXTERNO_CLAIM_ID || 'sub',
    claimNombre: process.env.EXTERNO_CLAIM_NOMBRE || 'name',
    claimRol: process.env.EXTERNO_CLAIM_ROL || 'role',
    claimEmail: process.env.EXTERNO_CLAIM_EMAIL || 'email',
    // Si el campus deja el token en una cookie del mismo dominio
    cookie: process.env.EXTERNO_COOKIE || '',
    // Página de inicio de sesión del campus (el botón "Ingresar" lleva ahí)
    loginUrl: process.env.EXTERNO_LOGIN_URL || '',
    // Página que cierra la sesión del campus ("Cerrar sesión" lleva ahí; si falta, al login)
    logoutUrl: process.env.EXTERNO_LOGOUT_URL || ''
};

// Cómo se llaman los roles en el campus → rol interno de ProgMesas
export const ROLES = {
    teacher: lista(process.env.ROL_DOCENTE, 'docente,teacher,profesor'),
    // En el campus de la escuela la asesoría entra como "directivo"
    advisor: lista(process.env.ROL_ASESORA, 'asesora,asesor,advisor,directivo,directiva'),
    viewer: lista(process.env.ROL_CONSULTA, 'preceptor,preceptora,director,directora'),
    student: lista(process.env.ROL_ALUMNO, 'alumno,alumna,student,estudiante')
};

if (!EXTERNO.secreto && !EXTERNO.clavePublica) {
    console.error(
        '\n[Config] Falta EXTERNO_JWT_SECRET en el .env: es la clave compartida con el campus\n' +
        '(la misma que PROGMESAS_SECRETO en includes/config.php del campus).\n' +
        'Generá una con:  node -e "console.log(require(\'crypto\').randomBytes(32).toString(\'hex\'))"\n'
    );
    process.exit(1);
}
if (EXTERNO.secreto && EXTERNO.secreto.length < 32) {
    console.error('[Config] EXTERNO_JWT_SECRET es demasiado corta: tiene que tener 32 caracteres o más.');
    process.exit(1);
}

export const PORT = Number(process.env.PORT) || 3000;
// Año lectivo: el año en curso en Argentina. Se calcula en cada pedido, así que cambia solo el 1 de enero,
// sin tocar la configuración ni reiniciar el servidor. ANIO_LECTIVO en el .env lo fija a mano (opcional; lo usan las pruebas).
const ANIO_FIJO = Number(process.env.ANIO_LECTIVO) || null;
const formatoAnio = new Intl.DateTimeFormat('en-US', { timeZone: 'America/Argentina/Buenos_Aires', year: 'numeric' });

export function anioLectivo() {
    return ANIO_FIJO || Number(formatoAnio.format(new Date()));
}

// Años en los que se puede cargar un programa (o fijar la fecha límite): el anterior, el actual y el siguiente.
// Así nadie carga por error un programa para 2030. Buscar y ver programas de otros años sigue funcionando.
export function rangoDeCarga() {
    const anio = anioLectivo();
    return { minimo: anio - 1, maximo: anio + 1 };
}

// Detrás de un proxy (Render, Nginx, etc.) la IP real del visitante llega en X-Forwarded-For.
// TRUST_PROXY=1 confía en un proxy (así los registros y los límites usan la IP real del visitante).
export const TRUST_PROXY = (() => {
    const v = (process.env.TRUST_PROXY || '').trim();
    if (!v || v === '0' || v === 'false') return false;
    return /^\d+$/.test(v) ? Number(v) : v;
})();

// Orígenes permitidos para CORS (separados por coma). Vacío = sólo el mismo servidor.
export const CORS_ORIGENES = (process.env.CORS_ORIGIN || '')
    .split(',')
    .map((o) => o.trim())
    .filter(Boolean);

export const MAX_PDF_MB = Number(process.env.MAX_PDF_MB) || 10;

// Carpeta de la base y los PDF locales. Por defecto backend/; en un hosting con disco
// persistente (por ejemplo Render) se apunta DATOS_DIR a ese disco. La primera vez se
// copian ahí la base y los PDF del repositorio para no empezar de cero.
const DATOS = process.env.DATOS_DIR ? path.resolve(RAIZ, process.env.DATOS_DIR) : BACKEND;
if (DATOS !== BACKEND && !fs.existsSync(path.join(DATOS, 'database.sqlite'))) {
    fs.mkdirSync(DATOS, { recursive: true });
    for (const nombre of ['database.sqlite', 'uploads']) {
        const origen = path.join(BACKEND, nombre);
        if (fs.existsSync(origen)) fs.cpSync(origen, path.join(DATOS, nombre), { recursive: true });
    }
    console.log(`[Config] Datos iniciales copiados a ${DATOS}`);
}

export const RUTAS = {
    db: path.join(DATOS, 'database.sqlite'),
    uploads: path.join(DATOS, 'uploads'),
    asignaciones: path.join(BACKEND, 'data', 'asignaciones.xlsx'),
    oauth: path.join(BACKEND, 'oauth.json'),
    token: path.join(BACKEND, 'token.json'),
    frontend: path.join(RAIZ, 'frontend'),
    index: path.join(RAIZ, 'index.html'),
    privacidad: path.join(RAIZ, 'privacidad.html'),
    terminos: path.join(RAIZ, 'terminos.html')
};

// En un hosting sin esos archivos (por ejemplo Render), las credenciales de Google Drive pueden
// venir en variables de entorno con el contenido completo de oauth.json y token.json.
for (const [variable, ruta] of [['GOOGLE_OAUTH_JSON', RUTAS.oauth], ['GOOGLE_TOKEN_JSON', RUTAS.token]]) {
    const valor = (process.env[variable] || '').trim();
    if (!valor || fs.existsSync(ruta)) continue;
    try {
        JSON.parse(valor);
        fs.writeFileSync(ruta, valor);
    } catch {
        console.error(`[Config] ${variable} no tiene un JSON válido: pegá el contenido completo del archivo`);
    }
}

// ================= COPIAS DE SEGURIDAD =================
// RESPALDOS_DIR: carpeta donde el servidor guarda una copia por día (vacío = no hace copias automáticas)
export const RESPALDOS = {
    dir: process.env.RESPALDOS_DIR ? path.resolve(RAIZ, process.env.RESPALDOS_DIR) : '',
    hora: Math.min(Math.max(parseInt(process.env.RESPALDO_HORA ?? '3', 10) || 0, 0), 23),
    conservar: Math.max(parseInt(process.env.RESPALDOS_CONSERVAR ?? '14', 10) || 14, 1)
};

// ================= GOOGLE DRIVE =================
// DRIVE_ALCANCE=completo (por defecto) → permiso "drive": usa la carpeta DRIVE_CARPETA_RAIZ creada a mano.
// DRIVE_ALCANCE=archivos → permiso "drive.file": la app sólo ve lo que ella misma crea, así que guarda
//   todo en una carpeta propia (DRIVE_CARPETA_NOMBRE) que crea sola. Google no lo considera un permiso
//   "restringido", así que no hace falta la evaluación de seguridad para pasar la app a producción.
//   Al cambiar de alcance hay que volver a autorizar: node scripts/autorizar_drive.js
export const DRIVE_ALCANCE = (process.env.DRIVE_ALCANCE || 'completo').trim().toLowerCase();
if (!['completo', 'archivos'].includes(DRIVE_ALCANCE)) {
    console.error(`[Config] DRIVE_ALCANCE inválido: "${DRIVE_ALCANCE}" (usá "completo" o "archivos")`);
    process.exit(1);
}
export const DRIVE_PERMISO = DRIVE_ALCANCE === 'archivos'
    ? 'https://www.googleapis.com/auth/drive.file'
    : 'https://www.googleapis.com/auth/drive';

// Carpeta raíz donde se guardan los programas. Con alcance "archivos", si no se indica, la crea la app.
export const DRIVE_CARPETA_RAIZ = process.env.DRIVE_CARPETA_RAIZ ||
    (DRIVE_ALCANCE === 'completo' ? '1EY74DSygnNwaxm6hWhKm5-alO-0X0Lnl' : '');
export const DRIVE_CARPETA_NOMBRE = process.env.DRIVE_CARPETA_NOMBRE || 'ProgMesas - Programas de examen';

// DRIVE_DESACTIVADO=1 guarda todo en el servidor aunque haya credenciales (lo usan las pruebas automáticas)
export const DRIVE_DESACTIVADO = ['1', 'true', 'si', 'sí'].includes(String(process.env.DRIVE_DESACTIVADO || '').toLowerCase());
