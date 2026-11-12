import express from 'express';
import cors from 'cors';
import multer from 'multer';

import { PORT, anioLectivo, rangoDeCarga, CORS_ORIGENES, MAX_PDF_MB, RUTAS, AUTH_MODO, EXTERNO, TRUST_PROXY } from './config.js';
import { auth, authOpcional, rol } from './auth.js';
import {
    registrarAcceso, vinculoDe, docenteConfirmado, solicitarVinculo, confirmarVinculo, desvincular, resumenVinculos
} from './docentes.js';
import {
    getProgramas, getPrograma, programaDeAsignacion, programaAnteriorDeAsignacion, programasSinAsignacion,
    agregarPrograma, reemplazarPrograma, vincularAsignacion, eliminarPrograma, versionesDe, cantidadDeVersiones,
    TIPOS, tipoValido
} from './store.js';
import {
    todasLasAsignaciones, asignacionesRequeridas, asignacionPorId, asignacionesDeDocente,
    docenteDictaAsignacion, buscarAsignacionPara, estadoAsignaciones, listaDocentes
} from './asignaciones.js';
import { guardarPdf, eliminarPdfSiNoSeUsa, leerPdf, pasarADrive, cantidadLocales } from './almacenamiento.js';
import { estadoDrive, verificarDrive } from './drive.js';
import { registrar, ultimosRegistros } from './registro.js';
import { fechaLimite, guardarFechaLimite } from './ajustes.js';
import { programarRespaldos } from './respaldos.js';
import { crearZip } from './lib/zip.js';
import { claveTexto, cursoClave } from './lib/normalizar.js';

const app = express();

// ================= SEGURIDAD BÁSICA =================

app.disable('x-powered-by');
if (TRUST_PROXY !== false) app.set('trust proxy', TRUST_PROXY);
app.use((req, res, next) => {
    res.setHeader('X-Content-Type-Options', 'nosniff');
    res.setHeader('Referrer-Policy', 'same-origin');
    res.setHeader('X-Frame-Options', 'SAMEORIGIN');
    res.setHeader(
        'Content-Security-Policy',
        [
            "default-src 'self'",
            "script-src 'self'",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "font-src 'self' https://fonts.gstatic.com",
            "img-src 'self' data:",
            "frame-src 'self' https://drive.google.com",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'"
        ].join('; ')
    );
    next();
});

// Por defecto el frontend se sirve desde este mismo servidor, así que no hace falta CORS.
// Si se aloja en otro dominio, configurá CORS_ORIGIN en el .env.
if (CORS_ORIGENES.length) app.use(cors({ origin: CORS_ORIGENES }));

app.use(express.json({ limit: '100kb' }));

// ================= FRONTEND Y ARCHIVOS =================

app.get(['/', '/index.html'], (req, res) => res.sendFile(RUTAS.index));
app.get('/privacidad.html', (req, res) => res.sendFile(RUTAS.privacidad));
app.get('/terminos.html', (req, res) => res.sendFile(RUTAS.terminos));
app.use('/frontend', express.static(RUTAS.frontend));
// Los PDF se sirven en /uploads/ y también en /backend/uploads/ (la dirección que usa la versión PHP dentro del campus)
const servirPdf = express.static(RUTAS.uploads, {
    setHeaders: (res) => res.setHeader('Content-Type', 'application/pdf')
});
app.use('/uploads', servirPdf);
app.use('/backend/uploads', servirPdf);

// El frontend llama a la API como "api.php?r=/ruta" (igual que dentro del campus, con api.php sobre Apache).
// Aquí se traduce a la ruta /api/ruta de este servidor.
app.use((req, res, next) => {
    if (req.path !== '/api.php') return next();
    const params = new URLSearchParams(req.url.split('?')[1] || '');
    const ruta = params.get('r') || '/';
    if (!ruta.startsWith('/') || ruta.includes('..')) return res.status(400).json({ error: 'Ruta inválida' });
    params.delete('r');
    const query = params.toString();
    req.url = '/api' + ruta + (query ? '?' + query : '');
    next();
});

// ================= SUBIDA (validación del PDF) =================

const upload = multer({
    storage: multer.memoryStorage(),
    limits: { fileSize: MAX_PDF_MB * 1024 * 1024, files: 1, fields: 10 },
    fileFilter: (req, file, cb) => {
        const esPdf = file.mimetype === 'application/pdf' || /\.pdf$/i.test(file.originalname || '');
        cb(esPdf ? null : Object.assign(new Error('Sólo se aceptan archivos PDF'), { status: 400 }), esPdf);
    }
});

function esPdfValido(buffer) {
    return buffer && buffer.length > 5 && buffer.subarray(0, 5).toString('latin1') === '%PDF-';
}

// ================= HELPERS =================

/** Vínculo del docente en formato para el frontend. */
function vinculoPublico(v) {
    return v ? { docente: v.docente, estado: v.estado } : null;
}

/** Quita datos internos antes de mandar un programa a quien no es asesora. */
function programaPublico(p, esAsesora) {
    const { uploaded_by, archivo_ref, ...resto } = p;
    return esAsesora ? { ...resto, uploaded_by } : resto;
}

/** El usuario puede ver el historial de un programa: asesora, o docente de esa materia (o que lo subió). */
function puedeGestionar(usuario, programa) {
    if (usuario?.role === 'advisor') return true;
    if (usuario?.role !== 'teacher') return false;
    const docente = docenteConfirmado(usuario.id);
    const asignacion = programa.asignacion_id ? asignacionPorId(programa.asignacion_id) : null;
    return (asignacion && docente && docenteDictaAsignacion(docente, asignacion)) || programa.uploaded_by === usuario.id;
}

/** Título de un programa: siempre se genera solo (tipo, curso, materia y año). */
function tituloAutomatico(asignacion, anio, tipo = 'regular') {
    return `Programa ${tipo} de ${asignacion.materia} - ${asignacion.curso} - ${anio}`;
}

const NOMBRE_TIPO = { regular: 'Regular', libre: 'Libre' };
const errorTipo = { error: 'El tipo de programa tiene que ser regular o libre' };

/** Resumen de un programa para el formulario de carga. */
function resumenPrograma(p, conAnio = false) {
    if (!p) return null;
    return { id: p.id, title: p.title, fecha: p.fecha, tipo: p.tipo, ...(conAnio ? { year: p.year } : {}) };
}

/** Nombre de archivo seguro para dentro del ZIP. */
function nombreArchivoZip(texto) {
    return String(texto).replace(/[\\/:*?"<>|\u0000-\u001f]+/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 90) || 'programa';
}

/** Vincula los programas cargados antes de esta versión con su asignación (por curso + materia). */
function vincularProgramasViejos() {
    let vinculados = 0;
    for (const p of programasSinAsignacion()) {
        const a = buscarAsignacionPara(p.curso, p.materia);
        if (a) {
            vincularAsignacion(p.id, a);
            vinculados++;
        }
    }
    if (vinculados) console.log(`[Programas] ${vinculados} programa(s) anteriores vinculados a su asignación`);
}

function anioValido(valor) {
    const n = parseInt(valor, 10);
    return Number.isInteger(n) && n >= 2000 && n <= 2100 ? n : null;
}

/** Año para cargar un programa o fijar la fecha límite: sólo el anterior, el actual o el siguiente. */
function anioDeCarga(valor) {
    const n = anioValido(valor);
    const { minimo, maximo } = rangoDeCarga();
    return n && n >= minimo && n <= maximo ? n : null;
}
function errorAnio() {
    const { minimo, maximo } = rangoDeCarga();
    return { error: `El año lectivo tiene que estar entre ${minimo} y ${maximo}` };
}

/**
 * Año de un programa que se sube o se reutiliza: siempre el año lectivo actual.
 * Si el pedido trae otro año, se rechaza (devuelve null).
 */
function anioDelPrograma(valor) {
    const anio = anioLectivo();
    if (valor === undefined || valor === null || valor === '') return anio;
    return anioValido(valor) === anio ? anio : null;
}
function errorAnioPrograma() {
    return { error: `Sólo se pueden cargar programas del año lectivo actual (${anioLectivo()})` };
}

// ================= API =================

app.get('/api/config', (req, res) => {
    res.json({
        anioLectivo: anioLectivo(),
        anioMinimo: rangoDeCarga().minimo,
        anioMaximo: rangoDeCarga().maximo,
        maxPdfMb: MAX_PDF_MB,
        authModo: AUTH_MODO,
        loginUrl: EXTERNO.loginUrl || null,
        logoutUrl: EXTERNO.logoutUrl || EXTERNO.loginUrl || null,
        fechaLimite: fechaLimite(anioLectivo())
    });
});

// Quién es el usuario actual. Los docentes quedan registrados para que la asesora pueda vincularlos.
app.get('/api/yo', authOpcional, (req, res) => {
    if (!req.user) return res.json({ autenticado: false });
    const { id, nombre, email, role } = req.user;
    const vinculo = role === 'teacher' ? registrarAcceso(req.user) : null;
    res.json({ autenticado: true, id, nombre, email, role, vinculo: vinculoPublico(vinculo) });
});

// ================= VINCULACIÓN DOCENTE ↔ LISTA OFICIAL =================

// Nombres disponibles para que el docente elija el suyo
app.get('/api/docentes/nombres', auth, rol('teacher', 'advisor'), (req, res) => {
    const tomados = new Set(
        resumenVinculos().usuarios
            .filter((u) => u.estado === 'confirmado' && u.usuarioId !== req.user.id)
            .map((u) => u.docente)
    );
    res.json(listaDocentes().map((d) => ({ nombre: d, disponible: !tomados.has(d) })));
});

// El docente elige su nombre (queda vinculado en el momento: la cuenta ya viene verificada)
app.post('/api/docentes/vinculo', auth, rol('teacher'), (req, res) => {
    const v = solicitarVinculo(req.user, req.body?.docente);
    registrar(req.user, 'docente.vinculado', { docente: v.docente, por: 'el docente' });
    res.json({ ok: true, vinculo: vinculoPublico(v) });
});

app.get('/api/docentes/vinculos', auth, rol('advisor', 'viewer'), (req, res) => {
    res.json(resumenVinculos());
});

// La asesora confirma (opcionalmente con otro nombre) o corrige el vínculo
app.post('/api/docentes/vinculos/:usuarioId/confirmar', auth, rol('advisor'), (req, res) => {
    const antes = vinculoDe(req.params.usuarioId);
    const v = confirmarVinculo(req.params.usuarioId, req.body?.docente, req.user);
    registrar(req.user, 'docente.corregido', {
        cuenta: v.nombre_usuario || v.usuario_id, docente: v.docente, anterior: antes?.estado === 'confirmado' ? antes.docente : null
    });
    res.json({ ok: true, vinculo: vinculoPublico(v) });
});

app.delete('/api/docentes/vinculos/:usuarioId', auth, rol('advisor'), (req, res) => {
    const antes = vinculoDe(req.params.usuarioId);
    desvincular(req.params.usuarioId);
    registrar(req.user, 'docente.desvinculado', { cuenta: antes?.nombre_usuario || req.params.usuarioId, docente: antes?.docente || null });
    res.json({ ok: true });
});

// Lista de programas: los docentes ven los de sus materias; el resto ve todos.
app.get('/api/programas', authOpcional, (req, res) => {
    const programas = getProgramas();
    const esAsesora = req.user?.role === 'advisor';
    // Asesora y docentes ven cuántas versiones anteriores tiene cada programa
    const versiones = esAsesora || req.user?.role === 'teacher' ? cantidadDeVersiones() : null;
    const conVersiones = (p) => (versiones ? { ...p, versiones: versiones.get(p.id) || 0 } : p);

    if (req.user?.role === 'teacher') {
        const ids = new Set(asignacionesDeDocente(docenteConfirmado(req.user.id)).map((a) => a.id));
        const propios = programas.filter(
            (p) => ids.has(p.asignacion_id) || p.uploaded_by === req.user.id || (req.user.email && p.uploaded_by === req.user.email)
        );
        return res.json(propios.map((p) => conVersiones(programaPublico(p, false))));
    }

    res.json(programas.map((p) => conVersiones(programaPublico(p, esAsesora))));
});

// Historial de un programa: la versión actual y las anteriores (asesora o docente de la materia)
app.get('/api/programas/:id/versiones', auth, rol('teacher', 'advisor'), (req, res) => {
    const programa = getPrograma(req.params.id);
    if (!programa) return res.status(404).json({ error: 'Programa no encontrado' });
    if (!puedeGestionar(req.user, programa)) return res.status(403).json({ error: 'No tenés permiso para esta acción' });
    const esAsesora = req.user.role === 'advisor';
    res.json({
        programa: programaPublico(programa, esAsesora),
        versiones: versionesDe(programa.id).map((v) => ({
            id: v.id,
            title: v.title,
            profesor: v.profesor,
            fecha: v.fecha,
            created_at: v.created_at,
            file_url: v.file_url,
            almacenamiento: v.almacenamiento,
            reemplazado_at: v.reemplazado_at,
            reemplazado_por: v.reemplazado_por,
            ...(esAsesora ? { uploaded_by: v.uploaded_by } : {})
        }))
    });
});

// Asignaciones para el formulario de subida: todas (docentes y asesora pueden cargar el programa de
// cualquier curso). A los docentes se les marcan las suyas (propia: true) para mostrarlas primero.
app.get('/api/asignaciones/mias', auth, rol('teacher', 'advisor'), (req, res) => {
    const anio = anioValido(req.query.anio) || anioLectivo();
    const esAsesora = req.user.role === 'advisor';
    const docente = esAsesora ? '' : docenteConfirmado(req.user.id);

    const lista = todasLasAsignaciones();
    const propias = new Set(docente ? asignacionesDeDocente(docente).map((a) => a.id) : []);
    // Clave asignación + tipo ("id|regular"): cada materia puede tener un programa regular y uno libre
    const cargados = new Map();
    const anteriores = new Map(); // el más reciente de un año anterior, para reutilizarlo
    for (const p of getProgramas()) {
        if (!p.asignacion_id) continue;
        const clave = `${p.asignacion_id}|${p.tipo}`;
        if (p.year === anio && !cargados.has(clave)) cargados.set(clave, p);
        if (p.year < anio && !anteriores.has(clave)) anteriores.set(clave, p);
    }
    const porTipo = (mapa, id, conAnio) =>
        Object.fromEntries(TIPOS.map((t) => [t, resumenPrograma(mapa.get(`${id}|${t}`), conAnio)]));

    res.json({
        anio,
        docente,
        fechaLimite: fechaLimite(anio),
        vinculo: esAsesora ? null : vinculoPublico(vinculoDe(req.user.id)),
        asignaciones: lista.map((a) => {
            const programas = porTipo(cargados, a.id, false);
            return {
                id: a.id,
                curso: a.curso,
                turno: a.turno,
                materia: a.materia,
                docentes: a.docentes,
                requiere: a.requiere,
                propia: propias.has(a.id),
                programa: programas.regular || programas.libre, // alguno cargado (compatibilidad)
                programas,                                       // { regular, libre }
                anteriores: porTipo(anteriores, a.id, true)      // { regular, libre } de años anteriores
            };
        })
    });
});

app.post('/api/programas/subir', auth, rol('teacher', 'advisor'), upload.single('pdf'), async (req, res) => {
    const esAsesora = req.user.role === 'advisor';
    // Docentes y asesora pueden cargar el programa de cualquier curso y materia.
    // El docente figura con su nombre de la lista (si está vinculado) o con el de su cuenta del campus.
    const docente = esAsesora ? '' : docenteConfirmado(req.user.id) || req.user.nombre || req.user.id;

    const asignacion = asignacionPorId(req.body?.asignacion_id);
    const anio = anioDelPrograma(req.body?.anio);
    const tipo = tipoValido(req.body?.tipo);

    if (!asignacion) return res.status(400).json({ error: 'Elegí la materia y el curso del programa' });
    if (!anio) return res.status(400).json(errorAnioPrograma());
    if (!tipo) return res.status(400).json(errorTipo);
    if (!req.file) return res.status(400).json({ error: 'Falta el archivo PDF' });
    if (!esPdfValido(req.file.buffer)) return res.status(400).json({ error: 'El archivo no es un PDF válido' });

    const titulo = tituloAutomatico(asignacion, anio, tipo);
    const profesor = esAsesora ? asignacion.docentes.join(' / ') || req.user.nombre : docente;

    const archivo = await guardarPdf(req.file.buffer, {
        anio,
        curso: asignacion.curso,
        materia: asignacion.materia,
        profesor,
        tipo
    });

    const datos = {
        title: titulo,
        materia: asignacion.materia,
        year: anio,
        curso: asignacion.curso,
        profesor,
        uploaded_by: req.user.id,
        asignacion_id: asignacion.id,
        tipo,
        ...archivo
    };

    // Si ya había uno del mismo tipo para ese curso, materia y año, se reemplaza y el anterior queda en el historial
    const anterior = programaDeAsignacion(asignacion.id, anio, tipo);
    let id;
    if (anterior) {
        reemplazarPrograma(anterior.id, datos, req.user.nombre || req.user.id);
        id = anterior.id;
    } else {
        id = agregarPrograma(datos);
    }
    registrar(req.user, anterior ? 'programa.reemplazado' : 'programa.subido', {
        id, curso: asignacion.curso, materia: asignacion.materia, anio, tipo, titulo, almacenamiento: archivo.almacenamiento
    });

    res.json({ ok: true, id, reemplazado: Boolean(anterior), almacenamiento: archivo.almacenamiento, aviso: archivo.aviso });
});

// Usar para este año el programa de un año anterior (mismo curso y materia) sin volver a subirlo
app.post('/api/programas/reutilizar', auth, rol('teacher', 'advisor'), (req, res) => {
    const esAsesora = req.user.role === 'advisor';
    // Docentes y asesora pueden cargar el programa de cualquier curso y materia.
    // El docente figura con su nombre de la lista (si está vinculado) o con el de su cuenta del campus.
    const docente = esAsesora ? '' : docenteConfirmado(req.user.id) || req.user.nombre || req.user.id;
    const asignacion = asignacionPorId(req.body?.asignacion_id);
    const anio = anioDelPrograma(req.body?.anio);
    const tipo = tipoValido(req.body?.tipo);
    if (!asignacion) return res.status(400).json({ error: 'Elegí la materia y el curso del programa' });
    if (!anio) return res.status(400).json(errorAnioPrograma());
    if (!tipo) return res.status(400).json(errorTipo);
    const origen = programaAnteriorDeAsignacion(asignacion.id, anio, tipo);
    if (!origen) return res.status(404).json({ error: `No hay un programa ${tipo} de años anteriores para ese curso y materia` });

    // El archivo se comparte: no se duplica, y sólo se borra cuando ningún programa lo usa
    const datos = {
        title: tituloAutomatico(asignacion, anio, tipo),
        materia: asignacion.materia,
        year: anio,
        curso: asignacion.curso,
        profesor: esAsesora ? origen.profesor || asignacion.docentes.join(' / ') : docente,
        uploaded_by: req.user.id,
        asignacion_id: asignacion.id,
        tipo,
        file_url: origen.file_url,
        almacenamiento: origen.almacenamiento,
        archivo_ref: origen.archivo_ref
    };
    const actual = programaDeAsignacion(asignacion.id, anio, tipo);
    let id;
    if (actual) {
        reemplazarPrograma(actual.id, datos, req.user.nombre || req.user.id);
        id = actual.id;
    } else {
        id = agregarPrograma(datos);
    }
    registrar(req.user, 'programa.reutilizado', {
        id, curso: asignacion.curso, materia: asignacion.materia, anio, tipo, desde: origen.year, titulo: datos.title
    });
    res.json({ ok: true, id, reemplazado: Boolean(actual), desde: origen.year });
});

app.delete('/api/programas/:id', auth, rol('advisor'), async (req, res) => {
    const id = parseInt(req.params.id, 10);
    if (isNaN(id)) return res.status(400).json({ error: 'ID inválido' });

    const borrados = eliminarPrograma(id);
    if (!borrados) return res.status(404).json({ error: 'Programa no encontrado' });
    const [programa] = borrados;

    // Se borran los archivos del programa y de sus versiones, salvo los que otro programa reutiliza
    for (const r of borrados) await eliminarPdfSiNoSeUsa(r);
    registrar(req.user, 'programa.eliminado', {
        id, curso: programa.curso, materia: programa.materia, anio: programa.year, tipo: programa.tipo, titulo: programa.title,
        versiones: borrados.length - 1
    });
    res.json({ ok: true });
});

// Vincular a mano un programa viejo que no se pudo asociar automáticamente
app.patch('/api/programas/:id/asignacion', auth, rol('advisor'), (req, res) => {
    const programa = getPrograma(req.params.id);
    const asignacion = asignacionPorId(req.body?.asignacion_id);
    if (!programa) return res.status(404).json({ error: 'Programa no encontrado' });
    if (!asignacion) return res.status(400).json({ error: 'Asignación inexistente' });
    vincularAsignacion(programa.id, asignacion);
    registrar(req.user, 'programa.vinculado', {
        id: programa.id, titulo: programa.title, curso: asignacion.curso, materia: asignacion.materia, anio: programa.year
    });
    res.json({ ok: true });
});

// ================= CONTROL DE ASESORÍA =================

function calcularCobertura(anio) {
    vincularProgramasViejos();

    // Una materia está completa con cualquiera de los dos programas (regular o libre)
    const delAnio = getProgramas().filter((p) => p.year === anio);
    const porAsignacion = new Map(); // id → { regular, libre }
    for (const p of delAnio) {
        if (!p.asignacion_id) continue;
        const tipos = porAsignacion.get(p.asignacion_id) || {};
        if (!tipos[p.tipo]) tipos[p.tipo] = p;
        porAsignacion.set(p.asignacion_id, tipos);
    }
    const resumen = (p) => p
        ? { id: p.id, title: p.title, tipo: p.tipo, fecha: p.fecha, created_at: p.created_at, file_url: p.file_url, almacenamiento: p.almacenamiento }
        : null;

    const asignaciones = asignacionesRequeridas().map((a) => {
        const tipos = porAsignacion.get(a.id) || {};
        // El programa que se muestra: el más reciente de los cargados
        const p = [tipos.regular, tipos.libre].filter(Boolean)
            .sort((x, y) => String(y.created_at).localeCompare(String(x.created_at)))[0];
        return {
            id: a.id,
            curso: a.curso,
            turno: a.turno,
            materia: a.materia,
            docentes: a.docentes,
            revisar: a.revisar,
            programa: resumen(p),
            programas: Object.fromEntries(TIPOS.map((t) => [t, resumen(tipos[t])]))
        };
    });

    const idsValidos = new Set(todasLasAsignaciones().map((a) => a.id));
    const sinAsignacion = delAnio
        .filter((p) => !p.asignacion_id || !idsValidos.has(p.asignacion_id))
        .map((p) => programaPublico(p, true));

    return { anio, asignaciones, sinAsignacion };
}

app.get('/api/cobertura', auth, rol('advisor', 'viewer'), (req, res) => {
    const anio = anioValido(req.query.anio) || anioLectivo();
    res.json({
        ...calcularCobertura(anio),
        fechaLimite: fechaLimite(anio),
        estado: { drive: estadoDrive(), asignaciones: estadoAsignaciones(), locales: cantidadLocales() }
    });
});

// Fecha límite de entrega de un año (la fija la asesora; vacía = sin fecha)
app.get('/api/fecha-limite', (req, res) => {
    const anio = anioValido(req.query.anio) || anioLectivo();
    res.json({ anio, fecha: fechaLimite(anio) });
});

app.put('/api/fecha-limite', auth, rol('advisor'), (req, res) => {
    const anio = anioDeCarga(req.body?.anio);
    if (!anio) return res.status(400).json(errorAnio());
    const anterior = fechaLimite(anio);
    const fecha = guardarFechaLimite(anio, req.body?.fecha);
    registrar(req.user, 'ajustes.fecha_limite', { anio, fecha, anterior });
    res.json({ ok: true, anio, fecha });
});

// Registro de acciones (asesora y consulta)
app.get('/api/registro', auth, rol('advisor', 'viewer'), (req, res) => {
    res.json(ultimosRegistros(req.query.limite));
});

// Pasar a Drive los programas que quedaron guardados en el servidor
app.post('/api/drive/sincronizar', auth, rol('advisor'), async (req, res) => {
    const r = await pasarADrive();
    if (r.pasados) registrar(req.user, 'drive.sincronizado', { pasados: r.pasados, pendientes: r.pendientes });
    res.json({ ok: r.errores.length === 0, ...r });
});

// Todos los programas de un curso en un ZIP (público, como los programas). Sin año: todos los años, por carpeta.
app.get('/api/cursos/zip', async (req, res) => {
    const curso = String(req.query.curso || '').trim();
    if (!curso) return res.status(400).json({ error: 'Indicá el curso' });
    const anio = req.query.anio ? anioValido(req.query.anio) : null;
    if (req.query.anio && !anio) return res.status(400).json({ error: 'El año lectivo no es válido' });
    const tipo = req.query.tipo ? tipoValido(req.query.tipo) : null;
    if (req.query.tipo && !tipo) return res.status(400).json(errorTipo);

    const clave = cursoClave(curso);
    const programas = getProgramas().filter(
        (p) => cursoClave(p.curso) === clave && (!anio || p.year === anio) && (!tipo || p.tipo === tipo)
    );
    if (programas.length === 0) return res.status(404).json({ error: 'No hay programas cargados para ese curso' });

    const archivos = [];
    const usados = new Set();
    const faltantes = [];
    for (const p of programas) {
        const base = `${p.year}/${nombreArchivoZip(`${p.materia} - ${NOMBRE_TIPO[p.tipo] || 'Regular'}`)}`;
        let nombre = base;
        for (let i = 2; usados.has(claveTexto(nombre)); i++) nombre = `${base} (${i})`;
        usados.add(claveTexto(nombre));
        try {
            archivos.push({ nombre: `${nombre}.pdf`, datos: await leerPdf(p), fecha: new Date(p.created_at || Date.now()) });
        } catch (err) {
            faltantes.push(`${p.year} - ${p.materia} (${p.tipo}): ${err.message}`);
        }
    }
    if (faltantes.length) {
        archivos.push({
            nombre: 'NO INCLUIDOS.txt',
            datos: Buffer.from('Estos programas no se pudieron descargar:\r\n\r\n' + faltantes.join('\r\n') + '\r\n', 'utf8')
        });
    }

    const nombreZip = `Programas ${tipo ? (tipo === 'libre' ? 'libres ' : 'regulares ') : ''}${curso}${anio ? ` ${anio}` : ''}.zip`;
    res.setHeader('Content-Type', 'application/zip');
    res.setHeader(
        'Content-Disposition',
        `attachment; filename="${nombreZip.normalize('NFD').replace(/[^\x20-\x7e]/g, '').replace(/"/g, '')}"; ` +
        `filename*=UTF-8''${encodeURIComponent(nombreZip)}`
    );
    res.send(crearZip(archivos));
});

// Compatibilidad con la versión anterior del dashboard
app.get('/api/programas/faltantes', auth, rol('advisor', 'viewer'), (req, res) => {
    const anio = anioValido(req.query.anio) || anioLectivo();
    const { asignaciones } = calcularCobertura(anio);
    res.json(
        asignaciones
            .filter((a) => !a.programa)
            .map((a) => ({ id: a.id, curso: a.curso, materia: a.materia, profesor: a.docentes.join(' / '), turno: a.turno }))
    );
});

app.use('/api', (req, res) => res.status(404).json({ error: 'Ruta inexistente' }));

// ================= ERRORES =================

// eslint-disable-next-line no-unused-vars
app.use((err, req, res, next) => {
    if (err instanceof multer.MulterError) {
        const mensaje = err.code === 'LIMIT_FILE_SIZE'
            ? `El PDF supera el máximo de ${MAX_PDF_MB} MB`
            : 'No se pudo procesar el archivo enviado';
        return res.status(err.code === 'LIMIT_FILE_SIZE' ? 413 : 400).json({ error: mensaje });
    }
    const status = err.status || err.statusCode || 500;
    if (status >= 500) console.error('[Error]', err);
    res.status(status).json({ error: status >= 500 ? 'Error interno del servidor' : err.message });
});

// ================= INICIO =================

vincularProgramasViejos();

// En Express 5 el callback recibe el error si el puerto no se pudo abrir
app.listen(PORT, async (err) => {
    if (err) {
        console.error(
            err.code === 'EADDRINUSE'
                ? `[Servidor] El puerto ${PORT} ya está en uso: hay otro servidor abierto. Cerralo o cambiá PORT en el .env.`
                : `[Servidor] No se pudo iniciar: ${err.message}`
        );
        process.exit(1);
    }
    console.log(`Servidor corriendo en http://localhost:${PORT}`);
    programarRespaldos((err, r) => registrar(null, err ? 'respaldo.error' : 'respaldo.hecho', err ? { error: err.message } : { carpeta: r.carpeta }));

    const drive = await verificarDrive();
    if (!drive.disponible) console.warn(`[Drive] ${drive.error || 'No disponible'} — los PDF se guardarán en el servidor`);

    // Cada 6 horas (y ahora) se intenta pasar a Drive lo que quedó guardado en el servidor
    const sincronizar = () => pasarADrive()
        .then((r) => r.pasados && registrar(null, 'drive.sincronizado', { pasados: r.pasados, pendientes: r.pendientes }))
        .catch((err) => console.warn('[Drive] No se pudo sincronizar:', err.message));
    if (drive.disponible) sincronizar();
    setInterval(sincronizar, 6 * 60 * 60 * 1000).unref();
});
