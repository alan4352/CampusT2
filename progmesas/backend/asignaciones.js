// Lista oficial de asignaciones (curso + materia + docentes), leída de data/asignaciones.xlsx.
// Se relee sola cuando el archivo cambia, así la asesora puede editarlo sin reiniciar el servidor.

import fs from 'fs';
import { RUTAS } from './config.js';
import { leerAsignaciones } from './lib/asignaciones_excel.js';
import { claveTexto, claveCompacta, cursoClave } from './lib/normalizar.js';
import { normalizarMateria } from './lib/parser_horarios.js';

let cache = { mtime: 0, asignaciones: [], porId: new Map(), error: null };

function cargar() {
    let stat;
    try {
        stat = fs.statSync(RUTAS.asignaciones);
    } catch {
        if (cache.error !== 'falta') {
            console.warn(`[Asignaciones] No existe ${RUTAS.asignaciones}. Generalo con: node scripts/importar_horarios.js <excel mañana> <excel tarde>`);
        }
        cache = { mtime: 0, asignaciones: [], porId: new Map(), error: 'falta' };
        return cache;
    }
    if (stat.mtimeMs === cache.mtime) return cache;

    try {
        const { asignaciones, advertencias } = leerAsignaciones(RUTAS.asignaciones);
        advertencias.forEach((a) => console.warn('[Asignaciones]', a));
        cache = {
            mtime: stat.mtimeMs,
            asignaciones,
            porId: new Map(asignaciones.map((a) => [a.id, a])),
            error: null
        };
        console.log(`[Asignaciones] ${asignaciones.length} asignaciones cargadas`);
    } catch (err) {
        // Si el archivo está abierto/guardándose en Excel puede fallar la lectura: se conserva lo anterior
        console.error('[Asignaciones] No se pudo leer el archivo:', err.message);
        cache.error = err.message;
    }
    return cache;
}

export function estadoAsignaciones() {
    const c = cargar();
    return { cantidad: c.asignaciones.length, error: c.error };
}

/** Todas las asignaciones (incluye las que no requieren programa). */
export function todasLasAsignaciones() {
    return cargar().asignaciones;
}

/** Sólo las que cuentan para la cobertura. */
export function asignacionesRequeridas() {
    return todasLasAsignaciones().filter((a) => a.requiere);
}

export function asignacionPorId(id) {
    return cargar().porId.get(String(id));
}

/**
 * Nombres con los que se identifica a un docente. El campo "docente" de la cuenta
 * puede tener varios separados por ";" (por ejemplo "Fernandez Lucrecia; Fernandez L").
 */
function clavesDocente(docente) {
    return String(docente || '')
        .split(';')
        .map(claveTexto)
        .filter(Boolean);
}

export function asignacionesDeDocente(docente) {
    const claves = clavesDocente(docente);
    if (claves.length === 0) return [];
    return todasLasAsignaciones().filter((a) => a.docentes.some((d) => claves.includes(claveTexto(d))));
}

export function docenteDictaAsignacion(docente, asignacion) {
    const claves = clavesDocente(docente);
    return asignacion.docentes.some((d) => claves.includes(claveTexto(d)));
}

/** Busca la asignación que corresponde a un programa viejo (sin asignacion_id) por curso + materia. */
export function buscarAsignacionPara(curso, materia) {
    const cc = cursoClave(curso);
    const mc = claveCompacta(normalizarMateria(materia));
    return todasLasAsignaciones().find((a) => a.cursoClave === cc && claveCompacta(a.materia) === mc) || null;
}

/** Nombres de docentes que figuran en la lista oficial (sin repetir, ordenados). */
export function listaDocentes() {
    const nombres = new Map();
    for (const a of todasLasAsignaciones()) {
        for (const d of a.docentes) {
            const k = claveTexto(d);
            if (!nombres.has(k)) nombres.set(k, d);
        }
    }
    return [...nombres.values()].sort((a, b) => a.localeCompare(b, 'es'));
}

/** Devuelve el nombre tal como figura en la lista oficial, o null si no existe. */
export function docenteOficial(nombre) {
    const k = claveTexto(nombre);
    return listaDocentes().find((d) => claveTexto(d) === k) || null;
}
