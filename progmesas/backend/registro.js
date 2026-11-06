// Registro de acciones: quién hizo qué y cuándo (subidas, reemplazos, borrados, vinculaciones, ajustes).
// Sirve para saber, por ejemplo, quién eliminó un programa o con qué nombre se vinculó un docente.

import db from './db.js';

db.exec(`
    CREATE TABLE IF NOT EXISTS registro (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        fecha TEXT NOT NULL,
        usuario_id TEXT NOT NULL DEFAULT '',
        usuario_nombre TEXT NOT NULL DEFAULT '',
        rol TEXT NOT NULL DEFAULT '',
        accion TEXT NOT NULL,
        detalle TEXT NOT NULL DEFAULT '{}'
    )
`);
db.exec('CREATE INDEX IF NOT EXISTS idx_registro_fecha ON registro (fecha)');

const stmtInsertar = db.prepare(`
    INSERT INTO registro (fecha, usuario_id, usuario_nombre, rol, accion, detalle)
    VALUES (?, ?, ?, ?, ?, ?)
`);
const stmtUltimos = db.prepare('SELECT * FROM registro ORDER BY id DESC LIMIT ?');

/**
 * Anota una acción. `usuario` es req.user (o null para acciones del sistema).
 * No lanza errores: un fallo del registro nunca debe frenar la acción principal.
 */
export function registrar(usuario, accion, detalle = {}) {
    try {
        stmtInsertar.run(
            new Date().toISOString(),
            String(usuario?.id || 'sistema'),
            String(usuario?.nombre || (usuario ? '' : 'Sistema')),
            String(usuario?.role || ''),
            accion,
            JSON.stringify(detalle)
        );
    } catch (err) {
        console.error('[Registro] No se pudo anotar la acción', accion, '-', err.message);
    }
}

/** Últimas acciones, de la más nueva a la más vieja. */
export function ultimosRegistros(limite = 200) {
    const n = Math.min(Math.max(parseInt(limite, 10) || 200, 1), 2000);
    return stmtUltimos.all(n).map((r) => {
        let detalle = {};
        try {
            detalle = JSON.parse(r.detalle);
        } catch {
            /* detalle dañado: se muestra vacío */
        }
        return {
            id: r.id,
            fecha: r.fecha,
            usuario: { id: r.usuario_id, nombre: r.usuario_nombre, rol: r.rol },
            accion: r.accion,
            detalle
        };
    });
}
