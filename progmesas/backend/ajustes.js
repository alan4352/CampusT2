// Ajustes que la asesora cambia desde el dashboard (por ahora, la fecha límite de entrega de cada año).

import db from './db.js';

db.exec(`
    CREATE TABLE IF NOT EXISTS ajustes (
        clave TEXT PRIMARY KEY,
        valor TEXT NOT NULL
    )
`);

const stmtLeer = db.prepare('SELECT valor FROM ajustes WHERE clave = ?');
const stmtGuardar = db.prepare(`
    INSERT INTO ajustes (clave, valor) VALUES (?, ?)
    ON CONFLICT(clave) DO UPDATE SET valor = excluded.valor
`);
const stmtBorrar = db.prepare('DELETE FROM ajustes WHERE clave = ?');

/** Fecha límite de entrega de un año ("AAAA-MM-DD") o null si no hay. */
export function fechaLimite(anio) {
    return stmtLeer.get(`fecha_limite_${anio}`)?.valor || null;
}

/** Guarda (o borra, con null o '') la fecha límite. Valida el formato y que sea una fecha real. */
export function guardarFechaLimite(anio, fecha) {
    const clave = `fecha_limite_${anio}`;
    if (fecha === null || fecha === undefined || fecha === '') {
        stmtBorrar.run(clave);
        return null;
    }
    const texto = String(fecha).trim();
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(texto);
    const d = m && new Date(Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3])));
    if (!d || d.toISOString().slice(0, 10) !== texto) {
        throw Object.assign(new Error('La fecha límite no es válida (formato AAAA-MM-DD)'), { status: 400 });
    }
    stmtGuardar.run(clave, texto);
    return texto;
}
