// Interpreta las planillas de horarios (una hoja por curso) y extrae
// las asignaciones curso + materia + docentes.
//
// Cada hoja tiene dos bloques:
//   1. Turno: filas de "materia" seguidas de una fila con el "docente" (columnas C a G).
//   2. CONTRATURNO: celdas sueltas con materias, docentes y horarios mezclados.
//      Para este bloque se usan heurísticas, por eso cada fila queda marcada para revisar.
//
// Este módulo no depende de ninguna librería: recibe la hoja como una grilla
// de strings (filas × columnas), tal como la devuelve xlsx.utils.sheet_to_json(ws, { header: 1 }).

import { sinAcentos, claveTexto, claveCompacta, cursoVisible } from './normalizar.js';
import { ALIAS_DOCENTES, ALIAS_MATERIAS, PALABRAS_CON_ACENTO } from './alias.js';

const COL_INICIO = 2; // columna C
const COL_FIN = 6;    // columna G

const RE_HORA = /\d{1,2}\s*[:.,]\s*\d{2}\s*(a|-|hs)\s*\d{1,2}|^de\s+\d{1,2}|^\d{1,2}\s*a\s*\d{1,2}/i;
const RE_NOTA = /cambio|porque|alumno|feriado|modificado|actualizado/i;
const RE_MATERIA_CONTRATURNO = /TALLER|COMPUT|GASTR|COCINA|PANADER|ORGANIZ|ORG\.|ORG |EMPRES|EMP\.|EDUC|FISICA|LABORATORIO|PROY|PRACT|P\.P\.P|^PPP$|CONTABLE|INGLES|S\.A\.C|ESTAD|EST\.|INV\.|NOC\.|ELEM\.|AUDITOR|ADMINIST|^DE$|^\(.*\)$/;

function celda(filas, r, c) {
    return String(filas[r]?.[c] ?? '').replace(/\s+/g, ' ').trim();
}

// ---------------------------------------------------------------- curso

export function interpretarCurso(nombreHoja, tituloA1, turnoArchivo) {
    const fuente = sinAcentos(`${nombreHoja} ${tituloA1}`).toUpperCase();
    const m = sinAcentos(nombreHoja).toUpperCase().match(/(\d)\s*[°º]?\s*(\d)\s*[°º]?\s*([A-Z]{2,3})?/);
    if (!m) return null;
    const [, anio, division, esp] = m;
    let turno = turnoArchivo;
    if (/\bTM\b/.test(fuente)) turno = 'Mañana';
    else if (/\bTT\b/.test(fuente)) turno = 'Tarde';
    const especialidad = esp && !['TM', 'TT'].includes(esp) ? esp : '';
    return { anio, division, especialidad, turno, curso: cursoVisible(anio, division, especialidad) };
}

// ---------------------------------------------------------------- docentes

function tituloSiMayusculas(palabra) {
    // "CAPECHE" → "Capeche"; deja "V", "M." o "Bl." como están
    if (palabra.length > 2 && palabra === palabra.toUpperCase() && /[A-ZÁÉÍÓÚÑÜ]/.test(palabra)) {
        return palabra.charAt(0) + palabra.slice(1).toLowerCase();
    }
    if (/^[a-záéíóúñü]/.test(palabra) && palabra.length > 2) {
        return palabra.charAt(0).toUpperCase() + palabra.slice(1);
    }
    return palabra;
}

export function limpiarNombreDocente(crudo) {
    let n = String(crudo)
        .replace(/\(.*?\)/g, ' ')   // "(jub)"
        .replace(/\s*,\s*/g, ' ')   // "Benitez, Cristina" → "Benitez Cristina"
        .replace(/\s+/g, ' ')
        .replace(/[,;:\s]+$/g, '')
        .trim();
    n = n.split(' ').map(tituloSiMayusculas).join(' ');
    const alias = ALIAS_DOCENTES[claveTexto(n)];
    return alias || n;
}

/** "Acevedo/Grimberg", "Grimberg - Mattiasi" → ["Acevedo", "Grimberg"] ya normalizados. */
export function separarDocentes(texto) {
    if (!texto) return [];
    return String(texto)
        .split(/\/| - /)
        .map((p) => p.trim())
        .filter((p) => p.length >= 2 && /[a-záéíóúñ]/i.test(p))
        .map(limpiarNombreDocente)
        .filter(Boolean);
}

// ---------------------------------------------------------------- materias

const MINUSCULAS = new Set(['y', 'de', 'del', 'la', 'las', 'los', 'e', 'a', 'por', 'en', 'el', 'al']);

function embellecerMateria(texto) {
    const palabras = String(texto).replace(/^\*+/, '').replace(/:+$/, '').replace(/\s+/g, ' ').trim().split(' ');
    return palabras.map((p, i) => {
        if (/^(I|II|III|IV|V)$/i.test(p)) return p.toUpperCase();
        const base = sinAcentos(p).toLowerCase();
        if (i > 0 && MINUSCULAS.has(base)) return base;
        const conAcento = PALABRAS_CON_ACENTO[base.replace(/[^a-zñ]/g, '')];
        if (conAcento && base.replace(/[^a-zñ]/g, '') === base) {
            return conAcento.charAt(0).toUpperCase() + conAcento.slice(1);
        }
        const l = p.toLowerCase();
        return l.charAt(0).toUpperCase() + l.slice(1);
    }).join(' ');
}

export function normalizarMateria(cruda) {
    const clave = claveCompacta(String(cruda).replace(/^\*+/, ''));
    if (!clave) return '';
    if (ALIAS_MATERIAS[clave]) return ALIAS_MATERIAS[clave];
    return embellecerMateria(cruda);
}

// ---------------------------------------------------------------- bloques

function filaDe(filas, texto, desde = 0) {
    for (let r = desde; r < filas.length; r++) {
        for (let c = 0; c < (filas[r]?.length || 0); c++) {
            if (sinAcentos(celda(filas, r, c)).toUpperCase() === texto) return r;
        }
    }
    return -1;
}

function bloqueTurno(filas, rCabecera, rContraturno) {
    const resultado = [];
    const fin = rContraturno === -1 ? filas.length : rContraturno;
    for (let r = rCabecera + 1; r < fin; r++) {
        // Una fila de materias tiene un horario en la columna B
        if (!RE_HORA.test(celda(filas, r, 1))) continue;
        for (let c = COL_INICIO; c <= COL_FIN; c++) {
            const materia = celda(filas, r, c);
            if (!materia || RE_HORA.test(materia) || RE_NOTA.test(materia)) continue;
            const docente = celda(filas, r + 1, c);
            resultado.push({ materiaCruda: materia, docentes: separarDocentes(docente), origen: 'Turno' });
        }
    }
    return resultado;
}

function bloqueContraturno(filas, rContraturno) {
    if (rContraturno === -1) return [];
    const materias = []; // { r, c, texto, filas: Set }
    const docentes = []; // { r, c, texto }
    const fin = Math.min(filas.length, rContraturno + 25);

    for (let c = COL_INICIO; c <= COL_FIN; c++) {
        let anterior = null;
        for (let r = rContraturno + 1; r < fin; r++) {
            const t = celda(filas, r, c);
            if (!t) { anterior = null; continue; }
            if (RE_HORA.test(t) || RE_NOTA.test(t) || t.startsWith('*')) { anterior = null; continue; }
            const mayus = sinAcentos(t).toUpperCase();
            const esMateria = RE_MATERIA_CONTRATURNO.test(mayus);
            if (esMateria) {
                const prev = anterior && anterior.r + anterior.alto === r ? anterior : null;
                const unir = prev && (/\bDE:?$/.test(sinAcentos(prev.texto).toUpperCase()) || /^TALLER$/i.test(prev.texto) || mayus === 'DE' || t.startsWith('('));
                if (unir) {
                    prev.texto = `${prev.texto.replace(/:$/, '')} ${t}`;
                    prev.alto += 1;
                } else {
                    anterior = { r, c, texto: t, alto: 1 };
                    materias.push(anterior);
                }
            } else {
                anterior = null;
                docentes.push({ r, c, texto: t });
            }
        }
    }

    // Asociar cada docente con la materia más cercana
    const usadas = new Set();
    const resultado = [];
    for (const d of docentes) {
        let mejor = null;
        let puntaje = Infinity;
        for (const m of materias) {
            let p = Infinity;
            if (m.c === d.c && m.r < d.r && d.r - m.r <= 6) p = d.r - m.r;            // misma columna, arriba
            else if (Math.abs(m.c - d.c) === 1 && m.r >= d.r - 2 && m.r <= d.r + 1) p = 10 + Math.abs(m.r - d.r); // columna vecina
            if (p < puntaje) { puntaje = p; mejor = m; }
        }
        if (mejor) {
            usadas.add(mejor);
            resultado.push({
                materiaCruda: mejor.texto,
                docentes: separarDocentes(d.texto),
                origen: 'Contraturno',
                dudoso: puntaje >= 10 // el docente estaba en otra columna
            });
        }
    }
    for (const m of materias) {
        if (!usadas.has(m)) resultado.push({ materiaCruda: m.texto, docentes: [], origen: 'Contraturno' });
    }
    return resultado;
}

// ---------------------------------------------------------------- hoja completa

/**
 * @param {{ hoja: string, turno: string, filas: string[][] }} entrada
 * @returns {{ curso, anio, division, especialidad, turno, preceptor, asignaturas: {materia, docentes: string[], origen, revisar: string}[] } | null}
 */
export function interpretarHoja({ hoja, turno, filas }) {
    const info = interpretarCurso(hoja, celda(filas, 0, 0), turno);
    if (!info) return null;
    const rCabecera = filaDe(filas, 'LUNES');
    if (rCabecera === -1) return null;
    const rContraturno = filaDe(filas, 'CONTRATURNO', rCabecera);

    const crudas = [...bloqueTurno(filas, rCabecera, rContraturno), ...bloqueContraturno(filas, rContraturno)];

    // Agrupar por materia normalizada (unión de docentes)
    const porMateria = new Map();
    for (const a of crudas) {
        const materia = normalizarMateria(a.materiaCruda);
        if (!materia) continue;
        const clave = claveCompacta(materia);
        if (!porMateria.has(clave)) {
            porMateria.set(clave, { materia, docentes: [], origen: a.origen, variantes: new Set(), dudoso: false });
        }
        const item = porMateria.get(clave);
        if (a.dudoso && a.docentes.length) item.dudoso = true;
        item.variantes.add(a.materiaCruda);
        if (a.origen === 'Turno') item.origen = 'Turno';
        for (const d of a.docentes) {
            if (!item.docentes.some((x) => claveTexto(x) === claveTexto(d))) item.docentes.push(d);
        }
    }

    const asignaturas = [...porMateria.values()].map((a) => {
        const motivos = [];
        if (a.docentes.length === 0) motivos.push('sin docente');
        if (a.dudoso) motivos.push('contraturno: verificar docente');
        return { materia: a.materia, docentes: a.docentes, origen: a.origen, revisar: motivos.join('; '), variantes: [...a.variantes] };
    });

    // El preceptor está en F1 (en algunas hojas hay fechas en su lugar)
    const f1 = celda(filas, 0, 5);
    const preceptor = /\d/.test(f1) ? '' : f1;

    return { ...info, preceptor, asignaturas };
}

/**
 * Unifica nombres de docentes entre todas las hojas: si aparece sólo el apellido
 * ("Villalba") y existe un único nombre completo que empieza igual ("Villalba Sara"),
 * se reemplaza por el completo. Devuelve el mapa de variantes para la hoja de revisión.
 */
export function unificarDocentes(cursos) {
    const todos = new Map(); // clave → nombre
    for (const c of cursos) for (const a of c.asignaturas) for (const d of a.docentes) todos.set(claveTexto(d), d);

    const reemplazo = new Map();
    const claves = [...todos.keys()];
    for (const k of claves) {
        if (k.length < 4) continue; // abreviaturas muy cortas ("alm") son ambiguas
        const candidatos = claves.filter((o) => o !== k && o.startsWith(k + ' '));
        if (candidatos.length === 1) {
            reemplazo.set(k, todos.get(candidatos[0]));
        } else if (candidatos.length === 0) {
            // "gonzalez c" → "gonzalez claudio" (inicial)
            const m = k.match(/^(.+) ([a-z])$/);
            if (m) {
                const porInicial = claves.filter((o) => o !== k && o.startsWith(`${m[1]} ${m[2]}`) && o.length > k.length);
                if (porInicial.length === 1) reemplazo.set(k, todos.get(porInicial[0]));
            }
        }
    }

    const variantes = new Map(); // nombre final → Set(variantes)
    for (const c of cursos) {
        for (const a of c.asignaturas) {
            const finales = [];
            for (const d of a.docentes) {
                const final = reemplazo.get(claveTexto(d)) || d;
                if (!variantes.has(final)) variantes.set(final, new Set());
                if (final !== d) variantes.get(final).add(d);
                if (!finales.some((x) => claveTexto(x) === claveTexto(final))) finales.push(final);
            }
            a.docentes = finales;
        }
    }
    return variantes;
}
