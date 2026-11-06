// Utilidades compartidas por index.html y dashboard.html

// Carpeta raíz de ProgMesas (donde está index.html): "/" en el servidor Node, "/final/progmesas/" dentro del campus.
// Se calcula desde este archivo (frontend/common.js), así funciona igual en las dos páginas.
const RAIZ_APP = new URL('..', document.currentScript?.src || location.href).pathname;

/**
 * Dirección de la API. Se usa siempre la forma "api.php?r=/ruta": dentro del campus la atiende api.php (Apache/XAMPP,
 * sin Node) y el servidor Node la acepta también, así no hace falta saber cuál de los dos está atrás.
 */
function urlApi(ruta) {
    const [camino, query] = String(ruta || '').split('?');
    return `${RAIZ_APP}api.php?r=${encodeURIComponent(camino)}${query ? '&' + query : ''}`;
}

// ================= ÍCONOS =================
// Íconos SVG de trazo (estilo Lucide, licencia ISC). Van dentro del código porque la
// política de seguridad del sitio no permite cargar librerías externas.

const ICONOS = {
    candado: '<rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
    ingresar: '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="m10 17 5-5-5-5"/><path d="M15 12H3"/>',
    salir: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
    tablero: '<rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/>',
    ojo: '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
    ojoTachado: '<path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.53 13.53 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><path d="m2 2 20 20"/>',
    documento: '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/>',
    cerrar: '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
    volver: '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
    abajo: '<path d="m6 9 6 6 6-6"/>',
    docente: '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    calendario: '<rect width="18" height="18" x="3" y="4" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/>',
    eliminar: '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M10 11v6"/><path d="M14 11v6"/>',
    confirmar: '<path d="M20 6 9 17l-5-5"/>',
    descargar: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
    curso: '<path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"/>',
    historial: '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/>',
    enlace: '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
    zip: '<rect width="20" height="5" x="2" y="3" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><path d="M10 12h4"/>',
    correo: '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
    reloj: '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
    nube: '<path d="M12 13v8"/><path d="M4 14.9A7 7 0 1 1 15.71 8h1.79a4.5 4.5 0 0 1 2.5 8.24"/><path d="m8 17 4-4 4 4"/>',
    copiar: '<rect width="14" height="14" x="8" y="8" rx="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
    menu: '<path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h16"/>',
    escuela: '<path d="M14 22v-4a2 2 0 1 0-4 0v4"/><path d="m18 10 3.45 1.97A2 2 0 0 1 22.5 13.7V20a2 2 0 0 1-2 2h-17a2 2 0 0 1-2-2v-6.3a2 2 0 0 1 1.05-1.76L6 10"/><path d="M18 5v17"/><path d="m4 6 7.1-3.79a2 2 0 0 1 1.8 0L20 6"/><path d="M6 5v17"/><circle cx="12" cy="9" r="2"/>',
    escudo: '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>',
    pendiente: '<circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/>',
    porcentaje: '<path d="M19 5 5 19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>',
    lista: '<path d="M8 6h13"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M3 6h.01"/><path d="M3 12h.01"/><path d="M3 18h.01"/>'
};

/** Devuelve el SVG de un ícono para insertar en HTML. */
function icono(nombre) {
    return `<svg class="icono" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ` +
        `stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">${ICONOS[nombre] || ''}</svg>`;
}

/** Reemplaza los <span data-icono="..."> del HTML fijo por su ícono. */
function pintarIconos(raiz = document) {
    for (const el of raiz.querySelectorAll('[data-icono]')) {
        el.outerHTML = icono(el.dataset.icono);
    }
}
pintarIconos();

// ================= TEXTO SEGURO =================

/** Escapa texto para insertarlo en HTML (evita que un título con <script> se ejecute). */
function esc(valor) {
    return String(valor ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

/** Sólo se aceptan PDFs de este servidor o de Google Drive. */
function urlPdfSegura(url) {
    const u = String(url || '');
    // Los PDF guardados en el servidor están en backend/uploads/ (Node también los sirve en esa dirección)
    if (/^\/uploads\/[^/]+$/.test(u)) return `${RAIZ_APP}backend/uploads/${u.slice('/uploads/'.length)}`;
    if (/^https:\/\/drive\.google\.com\/file\/d\/[\w-]+\/preview$/.test(u)) return u;
    return null;
}

/** Muestra "Volver al campus" (el panel del campus, junto a la página de ingreso) si hay un campus configurado. */
function mostrarVolverCampus(config) {
    const enlace = document.getElementById('btnVolverCampus');
    if (!enlace || !config?.loginUrl) return;
    try {
        enlace.href = new URL('dashboard.php', new URL(config.loginUrl, location.href)).href;
        enlace.classList.remove('hidden');
    } catch {
        /* dirección inválida: el enlace queda oculto */
    }
}

function compararCursos(a, b) {
    return String(a).localeCompare(String(b), 'es', { numeric: true, sensitivity: 'base' });
}

// ================= SESIÓN =================
// ProgMesas no tiene login propio: el pase llega desde el campus de la escuela en la dirección
// (/?token=...), se guarda y se quita de la barra.
// Si el sistema de ingreso usa una cookie, no hay token guardado y el servidor la lee solo.
// Quién es el usuario lo responde siempre el servidor (/api/yo): el navegador no interpreta el token.

function leerToken() {
    try {
        return localStorage.getItem('token');
    } catch {
        return null;
    }
}

function guardarSesion(token) {
    try {
        localStorage.setItem('token', token);
    } catch {
        showToast('El navegador no permite guardar la sesión', 'danger');
    }
}

function cerrarSesion() {
    try {
        localStorage.removeItem('token');
    } catch {
        /* nada */
    }
}

function guardarAviso(mensaje) {
    try {
        sessionStorage.setItem('aviso', mensaje);
    } catch {
        /* nada */
    }
}

/** Toma el pase que manda el campus en la dirección y lo saca de la barra. */
function tomarTokenDeLaUrl() {
    const url = new URL(location.href);
    const token = url.searchParams.get('token');
    if (!token) return;
    guardarSesion(token);
    url.searchParams.delete('token');
    history.replaceState(null, '', url.pathname + url.search + url.hash);
}

/** Pregunta al servidor quién es el usuario. Devuelve null si es un visitante. */
async function cargarIdentidad() {
    tomarTokenDeLaUrl();
    const teniaToken = Boolean(leerToken());
    let yo;
    try {
        yo = await api('/yo');
    } catch {
        return null;
    }
    if (!yo.autenticado) {
        if (teniaToken) {
            cerrarSesion();
            showToast('Tu sesión venció. Volvé a ingresar.', 'warning', 6000);
        }
        return null;
    }
    return yo;
}

/**
 * fetch con el token y manejo de errores uniforme.
 * Lanza Error con el mensaje del servidor si la respuesta no es OK.
 */
async function api(ruta, opciones = {}) {
    const token = leerToken();
    const headers = { ...(opciones.headers || {}) };
    if (token) headers.Authorization = 'Bearer ' + token;
    if (opciones.json !== undefined) {
        headers['Content-Type'] = 'application/json';
        opciones.body = JSON.stringify(opciones.json);
    }

    let res;
    try {
        res = await fetch(urlApi(ruta), { ...opciones, headers, credentials: 'same-origin' });
    } catch {
        throw new Error('No se pudo conectar con el servidor');
    }

    const datos = await res.json().catch(() => ({}));
    if (res.status === 401 && ruta !== '/login') {
        cerrarSesion();
        guardarAviso(datos.error || 'Tu sesión venció. Volvé a ingresar.');
        location.reload();
        throw new Error(datos.error || 'Sesión vencida');
    }
    if (!res.ok) throw new Error(datos.error || `Error ${res.status}`);
    return datos;
}

// ================= TOASTS =================

function showToast(mensaje, tipo = 'info', duracion = 4000) {
    let container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        document.body.appendChild(container);
    }
    const toast = document.createElement('div');
    toast.className = 'toast' + (tipo === 'success' ? ' toast-success' : tipo === 'danger' ? ' toast-danger' : tipo === 'warning' ? ' toast-warning' : '');
    toast.setAttribute('role', tipo === 'danger' ? 'alert' : 'status');
    toast.textContent = mensaje;
    container.appendChild(toast);
    setTimeout(() => {
        toast.style.opacity = '0';
        setTimeout(() => toast.remove(), 300);
    }, duracion);
}

/** Muestra un aviso guardado antes de recargar la página (por ejemplo, sesión vencida). */
function mostrarAvisoPendiente() {
    let aviso = null;
    try {
        aviso = sessionStorage.getItem('aviso');
        sessionStorage.removeItem('aviso');
    } catch {
        /* nada */
    }
    if (aviso) showToast(aviso, 'warning', 6000);
}

// ================= DESCARGAS, PORTAPAPELES Y FECHAS =================

/** Descarga un archivo de la API (por ejemplo el ZIP de un curso) con la sesión actual. */
async function descargar(ruta, nombre) {
    const token = leerToken();
    let res;
    try {
        res = await fetch(urlApi(ruta), { headers: token ? { Authorization: 'Bearer ' + token } : {}, credentials: 'same-origin' });
    } catch {
        throw new Error('No se pudo conectar con el servidor');
    }
    if (!res.ok) {
        const datos = await res.json().catch(() => ({}));
        throw new Error(datos.error || `Error ${res.status}`);
    }
    const blob = await res.blob();
    const enlace = document.createElement('a');
    enlace.href = URL.createObjectURL(blob);
    enlace.download = nombre;
    document.body.appendChild(enlace);
    enlace.click();
    enlace.remove();
    setTimeout(() => URL.revokeObjectURL(enlace.href), 2000);
}

/** Copia texto al portapapeles (con alternativa para navegadores viejos o sin HTTPS). */
async function copiarTexto(texto, aviso = 'Copiado') {
    try {
        await navigator.clipboard.writeText(texto);
    } catch {
        const area = document.createElement('textarea');
        area.value = texto;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        const ok = document.execCommand('copy');
        area.remove();
        if (!ok) {
            prompt('Copiá este texto:', texto);
            return;
        }
    }
    showToast(aviso, 'success');
}

/** "2026-11-20" → "20/11/2026" */
function fechaLegible(iso) {
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(iso || ''));
    return m ? `${Number(m[3])}/${Number(m[2])}/${m[1]}` : '';
}

/** Días desde hoy hasta la fecha (negativo si ya pasó). La fecha límite vale hasta el final de ese día. */
function diasHasta(iso) {
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(iso || ''));
    if (!m) return null;
    const hoy = new Date();
    const inicioHoy = new Date(hoy.getFullYear(), hoy.getMonth(), hoy.getDate());
    const limite = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
    return Math.round((limite - inicioHoy) / 86400000);
}

/** Texto sobre la fecha límite: "Faltan 5 días", "Vence hoy", "Venció hace 3 días". */
function textoFechaLimite(iso) {
    const dias = diasHasta(iso);
    if (dias === null) return '';
    if (dias > 1) return `faltan ${dias} días`;
    if (dias === 1) return 'vence mañana';
    if (dias === 0) return 'vence hoy';
    return dias === -1 ? 'venció ayer' : `venció hace ${-dias} días`;
}

/** Dirección de la página principal con filtros, para compartir (por ejemplo, la de un curso). */
function enlaceListado(filtros, raiz = './') {
    const url = new URL(raiz, location.href);
    url.search = '';
    url.hash = '';
    for (const [clave, valor] of Object.entries(filtros)) if (valor) url.searchParams.set(clave, valor);
    return url.href;
}

// ================= PERFIL EN EL MENÚ LATERAL =================

const NOMBRES_DE_ROL = {
    teacher: 'Docente',
    advisor: 'Asesora pedagógica',
    viewer: 'Preceptor / directivo',
    student: 'Alumno'
};

/** Muestra en el menú quién inició sesión (o "Visitante"). */
function mostrarPerfil(usuario) {
    const nombre = document.getElementById('perfilNombre');
    const rol = document.getElementById('perfilRol');
    if (nombre) nombre.textContent = usuario ? usuario.nombre || usuario.email || 'Usuario' : 'Visitante';
    if (rol) rol.textContent = usuario ? NOMBRES_DE_ROL[usuario.role] || '' : 'Consulta pública';
}
