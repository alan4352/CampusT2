import db from './db.js';

// Todas las consultas usan parámetros (?), así los datos del usuario nunca se interpretan como SQL.

const CAMPOS = `id, title, materia, year, curso, profesor, file_url, fecha, uploaded_by,
                asignacion_id, almacenamiento, archivo_ref, created_at`;

// ================= VERSIONES ANTERIORES =================
// Al reemplazar un programa, la versión anterior (con su archivo) se guarda acá en lugar de borrarse.

db.exec(`
    CREATE TABLE IF NOT EXISTS programas_versiones (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        programa_id INTEGER NOT NULL,
        title TEXT NOT NULL,
        profesor TEXT NOT NULL DEFAULT '',
        file_url TEXT NOT NULL,
        fecha TEXT NOT NULL DEFAULT '',
        uploaded_by TEXT NOT NULL DEFAULT '',
        almacenamiento TEXT NOT NULL DEFAULT '',
        archivo_ref TEXT NOT NULL DEFAULT '',
        created_at TEXT,
        reemplazado_at TEXT NOT NULL,
        reemplazado_por TEXT NOT NULL DEFAULT ''
    )
`);
db.exec('CREATE INDEX IF NOT EXISTS idx_versiones_programa ON programas_versiones (programa_id)');

const stmtTodos = db.prepare(`SELECT ${CAMPOS} FROM programas ORDER BY year DESC, created_at DESC, id DESC`);
const stmtPorId = db.prepare(`SELECT ${CAMPOS} FROM programas WHERE id = ?`);
const stmtPorAsignacion = db.prepare(
    `SELECT ${CAMPOS} FROM programas WHERE asignacion_id = ? AND year = ? ORDER BY created_at DESC, id DESC LIMIT 1`
);
const stmtAnteriorDeAsignacion = db.prepare(
    `SELECT ${CAMPOS} FROM programas WHERE asignacion_id = ? AND year < ? ORDER BY year DESC, created_at DESC, id DESC LIMIT 1`
);
const stmtSinAsignacion = db.prepare(`SELECT ${CAMPOS} FROM programas WHERE asignacion_id IS NULL OR asignacion_id = ''`);

const stmtInsertar = db.prepare(`
    INSERT INTO programas (title, materia, year, curso, profesor, file_url, fecha, uploaded_by,
                           asignacion_id, almacenamiento, archivo_ref, created_at)
    VALUES (@title, @materia, @year, @curso, @profesor, @file_url, @fecha, @uploaded_by,
            @asignacion_id, @almacenamiento, @archivo_ref, @created_at)
`);

const stmtReemplazar = db.prepare(`
    UPDATE programas SET title = @title, materia = @materia, curso = @curso, profesor = @profesor,
        file_url = @file_url, fecha = @fecha, uploaded_by = @uploaded_by,
        almacenamiento = @almacenamiento, archivo_ref = @archivo_ref, created_at = @created_at
    WHERE id = @id
`);

const stmtGuardarVersion = db.prepare(`
    INSERT INTO programas_versiones (programa_id, title, profesor, file_url, fecha, uploaded_by,
                                     almacenamiento, archivo_ref, created_at, reemplazado_at, reemplazado_por)
    VALUES (@id, @title, @profesor, @file_url, @fecha, @uploaded_by,
            @almacenamiento, @archivo_ref, @created_at, @reemplazado_at, @reemplazado_por)
`);
const stmtVersiones = db.prepare('SELECT * FROM programas_versiones WHERE programa_id = ? ORDER BY reemplazado_at DESC, id DESC');
const stmtCantidadVersiones = db.prepare('SELECT programa_id, COUNT(*) AS cantidad FROM programas_versiones GROUP BY programa_id');
const stmtEliminarVersiones = db.prepare('DELETE FROM programas_versiones WHERE programa_id = ?');

const stmtVincular = db.prepare('UPDATE programas SET asignacion_id = ?, curso = ?, materia = ? WHERE id = ?');
const stmtEliminar = db.prepare('DELETE FROM programas WHERE id = ?');

const stmtUsosArchivo = db.prepare(`
    SELECT (SELECT COUNT(*) FROM programas WHERE almacenamiento = @a AND archivo_ref = @r)
         + (SELECT COUNT(*) FROM programas_versiones WHERE almacenamiento = @a AND archivo_ref = @r) AS usos
`);

// Archivos guardados en el servidor (para pasarlos a Drive cuando vuelve a estar disponible)
const stmtArchivosLocales = db.prepare(`
    SELECT archivo_ref, MIN(year) AS year, MIN(curso) AS curso, MIN(materia) AS materia, MIN(profesor) AS profesor FROM (
        SELECT archivo_ref, year, curso, materia, profesor FROM programas WHERE almacenamiento = 'local' AND archivo_ref <> ''
        UNION ALL
        SELECT v.archivo_ref, p.year, p.curso, p.materia, v.profesor
        FROM programas_versiones v JOIN programas p ON p.id = v.programa_id
        WHERE v.almacenamiento = 'local' AND v.archivo_ref <> ''
    ) GROUP BY archivo_ref
`);
const stmtMoverArchivoProgramas = db.prepare(
    "UPDATE programas SET almacenamiento = @nuevo_a, archivo_ref = @nuevo_r, file_url = @url WHERE almacenamiento = 'local' AND archivo_ref = @r"
);
const stmtMoverArchivoVersiones = db.prepare(
    "UPDATE programas_versiones SET almacenamiento = @nuevo_a, archivo_ref = @nuevo_r, file_url = @url WHERE almacenamiento = 'local' AND archivo_ref = @r"
);

export function getProgramas() {
    return stmtTodos.all();
}

export function getPrograma(id) {
    return stmtPorId.get(Number(id));
}

export function programaDeAsignacion(asignacionId, anio) {
    return stmtPorAsignacion.get(String(asignacionId), Number(anio));
}

/** El programa más reciente de la misma asignación en un año anterior (para reutilizarlo). */
export function programaAnteriorDeAsignacion(asignacionId, anio) {
    return stmtAnteriorDeAsignacion.get(String(asignacionId), Number(anio));
}

export function programasSinAsignacion() {
    return stmtSinAsignacion.all();
}

function datosBase(p) {
    const ahora = new Date();
    return {
        title: String(p.title || '').trim(),
        materia: String(p.materia || '').trim(),
        curso: String(p.curso || '').trim(),
        profesor: String(p.profesor || '').trim(),
        file_url: String(p.file_url || '').trim(),
        fecha: ahora.toLocaleDateString('es-AR'),
        uploaded_by: String(p.uploaded_by || '').trim(),
        almacenamiento: p.almacenamiento || '',
        archivo_ref: p.archivo_ref || '',
        created_at: ahora.toISOString()
    };
}

export function agregarPrograma(p) {
    const info = stmtInsertar.run({
        ...datosBase(p),
        year: parseInt(p.year, 10) || new Date().getFullYear(),
        asignacion_id: p.asignacion_id || null
    });
    return Number(info.lastInsertRowid);
}

/**
 * Reemplaza el archivo y los datos de un programa existente (misma asignación y año).
 * La versión anterior queda en el historial, con su archivo.
 */
export const reemplazarPrograma = db.transaction((id, p, reemplazadoPor = '') => {
    const anterior = getPrograma(id);
    if (anterior) {
        stmtGuardarVersion.run({
            ...anterior,
            profesor: anterior.profesor || '',
            uploaded_by: anterior.uploaded_by || '',
            almacenamiento: anterior.almacenamiento || '',
            archivo_ref: anterior.archivo_ref || '',
            fecha: anterior.fecha || '',
            reemplazado_at: new Date().toISOString(),
            reemplazado_por: String(reemplazadoPor || '')
        });
    }
    stmtReemplazar.run({ ...datosBase(p), id: Number(id) });
});

/** Versiones anteriores de un programa, de la más nueva a la más vieja. */
export function versionesDe(programaId) {
    return stmtVersiones.all(Number(programaId));
}

/** Cantidad de versiones anteriores por programa: Map(programa_id → cantidad). */
export function cantidadDeVersiones() {
    return new Map(stmtCantidadVersiones.all().map((r) => [r.programa_id, r.cantidad]));
}

/** Asocia un programa a una asignación y unifica el curso y la materia con los nombres oficiales. */
export function vincularAsignacion(id, asignacion) {
    stmtVincular.run(asignacion.id, asignacion.curso, asignacion.materia, Number(id));
}

/**
 * Elimina un programa y su historial. Devuelve los registros borrados (programa y versiones)
 * para que se borren sus archivos, o null si no existía.
 */
export const eliminarPrograma = db.transaction((id) => {
    const programa = getPrograma(id);
    if (!programa) return null;
    const versiones = versionesDe(programa.id);
    stmtEliminarVersiones.run(programa.id);
    stmtEliminar.run(programa.id);
    return [programa, ...versiones];
});

/** Cuántos programas o versiones usan un archivo (un programa reutilizado comparte el archivo). */
export function usosDeArchivo(almacenamiento, archivoRef) {
    if (!almacenamiento || !archivoRef) return 0;
    return stmtUsosArchivo.get({ a: almacenamiento, r: archivoRef }).usos;
}

/** Archivos guardados en el servidor, con los datos para ubicarlos en Drive. */
export function archivosLocales() {
    return stmtArchivosLocales.all();
}

/** Apunta todos los programas y versiones de un archivo local al archivo nuevo (en Drive). */
export const moverArchivo = db.transaction((archivoRefLocal, nuevo) => {
    const params = { r: archivoRefLocal, nuevo_a: nuevo.almacenamiento, nuevo_r: nuevo.archivo_ref, url: nuevo.file_url };
    return stmtMoverArchivoProgramas.run(params).changes + stmtMoverArchivoVersiones.run(params).changes;
});
