// Vinculación entre la cuenta de un docente (ID único que manda el campus) y su nombre
// en la lista oficial de asignaciones (data/asignaciones.xlsx).
//
// Las cuentas ya vienen verificadas por el campus, así que no hace falta que la asesora apruebe nada.
// Flujo:
//   1. El docente entra por primera vez → queda registrado como "sin_vincular".
//      Si su nombre coincide con UNO SOLO de la lista oficial, se vincula automáticamente.
//   2. Si no, elige su nombre de la lista una vez → "confirmado" y ya puede subir programas.
// La asesora puede asignar, corregir o desvincular el nombre desde el dashboard.
// (El estado "pendiente" quedó de la versión anterior, cuando la asesora tenía que aprobar.)

import db from './db.js';
import { claveTexto } from './lib/normalizar.js';
import { docenteOficial, listaDocentes, asignacionesDeDocente } from './asignaciones.js';
import { registrar } from './registro.js';

db.exec(`
    CREATE TABLE IF NOT EXISTS docentes_usuarios (
        usuario_id TEXT PRIMARY KEY,
        nombre_usuario TEXT NOT NULL DEFAULT '',
        email TEXT NOT NULL DEFAULT '',
        docente TEXT,
        estado TEXT NOT NULL DEFAULT 'sin_vincular'
            CHECK (estado IN ('sin_vincular', 'pendiente', 'confirmado')),
        ultimo_acceso TEXT,
        solicitado_at TEXT,
        confirmado_at TEXT,
        confirmado_por TEXT
    )
`);

const stmtPorId = db.prepare('SELECT * FROM docentes_usuarios WHERE usuario_id = ?');
const stmtTodos = db.prepare('SELECT * FROM docentes_usuarios ORDER BY estado, nombre_usuario');
const stmtRegistrar = db.prepare(`
    INSERT INTO docentes_usuarios (usuario_id, nombre_usuario, email, ultimo_acceso)
    VALUES (@id, @nombre, @email, @ahora)
    ON CONFLICT(usuario_id) DO UPDATE SET
        nombre_usuario = CASE WHEN @nombre <> '' THEN @nombre ELSE nombre_usuario END,
        email = CASE WHEN @email <> '' THEN @email ELSE email END,
        ultimo_acceso = @ahora
`);
const stmtSolicitar = db.prepare(`
    UPDATE docentes_usuarios SET docente = ?, estado = 'pendiente', solicitado_at = ?,
        confirmado_at = NULL, confirmado_por = NULL
    WHERE usuario_id = ?
`);
const stmtConfirmar = db.prepare(`
    UPDATE docentes_usuarios SET docente = ?, estado = 'confirmado', confirmado_at = ?, confirmado_por = ?
    WHERE usuario_id = ?
`);
const stmtDesvincular = db.prepare(`
    UPDATE docentes_usuarios SET docente = NULL, estado = 'sin_vincular', solicitado_at = NULL,
        confirmado_at = NULL, confirmado_por = NULL
    WHERE usuario_id = ?
`);

// Las solicitudes que quedaron esperando la aprobación de la asesora (versión anterior) se confirman
db.transaction(() => {
    const pendientes = db.prepare("SELECT * FROM docentes_usuarios WHERE estado = 'pendiente'").all();
    for (const v of pendientes) {
        const tomado = v.docente && db.prepare(
            "SELECT 1 FROM docentes_usuarios WHERE estado = 'confirmado' AND usuario_id <> ? AND docente = ?"
        ).get(v.usuario_id, v.docente);
        if (v.docente && !tomado) {
            stmtConfirmar.run(v.docente, v.solicitado_at || new Date().toISOString(), 'el docente', v.usuario_id);
        } else {
            stmtDesvincular.run(v.usuario_id);
        }
    }
})();

function error(mensaje, status = 400) {
    return Object.assign(new Error(mensaje), { status });
}

/** Otro usuario que ya tiene confirmado ese nombre (o null). */
function confirmadoPorOtro(docente, usuarioId) {
    const k = claveTexto(docente);
    return stmtTodos.all().find((v) => v.estado === 'confirmado' && v.usuario_id !== usuarioId && claveTexto(v.docente) === k) || null;
}

/** Palabras del nombre, sin acentos y en cualquier orden ("Lucrecia Fernández" = "Fernandez Lucrecia"). */
function clavePalabras(nombre) {
    return claveTexto(nombre).split(' ').filter(Boolean).sort().join(' ');
}

/**
 * Nombre de la lista oficial que corresponde al nombre de la cuenta, sólo si coincide con uno solo
 * y completo (mismas palabras). Un apellido suelto o un nombre repetido no se vinculan solos.
 */
export function nombreOficialPara(nombreCuenta) {
    const clave = clavePalabras(nombreCuenta);
    if (!clave.includes(' ')) return null;
    const coincidencias = listaDocentes().filter((d) => clavePalabras(d) === clave);
    return coincidencias.length === 1 ? coincidencias[0] : null;
}

/** Registra (o actualiza) a un docente cada vez que entra, y lo vincula solo si su nombre coincide. */
export function registrarAcceso(usuario) {
    stmtRegistrar.run({ id: usuario.id, nombre: usuario.nombre || '', email: usuario.email || '', ahora: new Date().toISOString() });
    const v = stmtPorId.get(usuario.id);
    if (v.estado === 'sin_vincular') {
        const docente = nombreOficialPara(usuario.nombre);
        if (docente && !confirmadoPorOtro(docente, usuario.id)) {
            stmtConfirmar.run(docente, new Date().toISOString(), 'automático', usuario.id);
            registrar(usuario, 'docente.vinculado', { docente, por: 'automático' });
            return stmtPorId.get(usuario.id);
        }
    }
    return v;
}

export function vinculoDe(usuarioId) {
    return stmtPorId.get(String(usuarioId)) || null;
}

/** Nombre oficial del docente si el vínculo está confirmado; si no, cadena vacía. */
export function docenteConfirmado(usuarioId) {
    const v = vinculoDe(usuarioId);
    return v && v.estado === 'confirmado' ? v.docente : '';
}

/** El docente elige su nombre de la lista: queda vinculado en el momento (la cuenta ya viene verificada). */
export function solicitarVinculo(usuario, nombre) {
    const v = registrarAcceso(usuario);
    if (v.estado === 'confirmado') {
        throw error('Tu cuenta ya está vinculada. Si el nombre es incorrecto, pedile a la asesora que lo corrija.', 409);
    }
    const docente = docenteOficial(nombre);
    if (!docente) throw error('Ese nombre no figura en la lista de asignaciones');
    if (confirmadoPorOtro(docente, usuario.id)) {
        throw error('Ese nombre ya está vinculado a otra cuenta. Hablá con la asesora.', 409);
    }
    const ahora = new Date().toISOString();
    stmtSolicitar.run(docente, ahora, usuario.id);
    stmtConfirmar.run(docente, ahora, 'el docente', usuario.id);
    return vinculoDe(usuario.id);
}

/** La asesora confirma el nombre pedido, o asigna/corrige uno directamente. */
export function confirmarVinculo(usuarioId, nombre, asesora) {
    const v = vinculoDe(usuarioId);
    if (!v) throw error('No existe ese usuario', 404);
    const docente = docenteOficial(nombre ?? v.docente);
    if (!docente) throw error('Ese nombre no figura en la lista de asignaciones');
    const otro = confirmadoPorOtro(docente, usuarioId);
    if (otro) throw error(`"${docente}" ya está vinculado a ${otro.nombre_usuario || otro.usuario_id}`, 409);
    stmtConfirmar.run(docente, new Date().toISOString(), asesora.nombre || asesora.id, usuarioId);
    return vinculoDe(usuarioId);
}

/** Rechazar una solicitud o desvincular una cuenta confirmada. */
export function desvincular(usuarioId) {
    if (stmtDesvincular.run(String(usuarioId)).changes === 0) throw error('No existe ese usuario', 404);
}

/** Resumen para el dashboard. */
export function resumenVinculos() {
    const usuarios = stmtTodos.all().map((v) => ({
        usuarioId: v.usuario_id,
        nombre: v.nombre_usuario,
        email: v.email,
        docente: v.docente,
        estado: v.estado,
        ultimoAcceso: v.ultimo_acceso,
        solicitado: v.solicitado_at,
        confirmado: v.confirmado_at,
        confirmadoPor: v.confirmado_por,
        asignaciones: v.docente ? asignacionesDeDocente(v.docente).length : 0
    }));
    const confirmados = new Set(usuarios.filter((u) => u.estado === 'confirmado').map((u) => claveTexto(u.docente)));
    const sinCuenta = listaDocentes()
        .filter((d) => !confirmados.has(claveTexto(d)))
        .map((d) => ({ docente: d, asignaciones: asignacionesDeDocente(d).length }));
    return { usuarios, sinCuenta };
}
