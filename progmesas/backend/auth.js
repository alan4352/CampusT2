// Identidad del usuario. Es la ÚNICA parte de ProgMesas que sabe de dónde viene el usuario.
// ProgMesas no tiene login propio: la persona inicia sesión en el campus de la escuela y llega
// con un pase firmado (token JWT) que se verifica con la clave compartida (EXTERNO_JWT_SECRET).
//
// El resto del sistema sólo usa req.user = { id, nombre, email, role }, donde role es uno de:
//   teacher (docente) | advisor (asesora; en el campus, directivo) | viewer (consulta: preceptor) | student (alumno)
// Para conectar otro sistema de ingreso sólo hace falta tocar este archivo y el .env.

import jwt from 'jsonwebtoken';
import { EXTERNO, ROLES } from './config.js';

// ================= ROLES =================

const PRIORIDAD = ['advisor', 'teacher', 'viewer', 'student'];

/** Traduce el rol del campus (texto o lista) al rol interno. Devuelve null si no se reconoce. */
export function mapearRol(valor) {
    const valores = (Array.isArray(valor) ? valor : String(valor ?? '').split(','))
        .map((v) => String(v).trim().toLowerCase())
        .filter(Boolean);
    for (const interno of PRIORIDAD) {
        if (valores.some((v) => v === interno || ROLES[interno].includes(v))) return interno;
    }
    return null;
}

// ================= LECTURA DEL TOKEN =================

function leerCookie(req, nombre) {
    const cookies = req.headers.cookie || '';
    for (const parte of cookies.split(';')) {
        const [k, ...v] = parte.trim().split('=');
        if (k === nombre) return decodeURIComponent(v.join('='));
    }
    return null;
}

function leerToken(req) {
    const header = req.headers.authorization || '';
    if (header.startsWith('Bearer ')) return header.slice(7);
    if (EXTERNO.cookie) return leerCookie(req, EXTERNO.cookie);
    return null;
}

function valorClaim(payload, ruta) {
    // admite claims anidados, por ejemplo "usuario.id"
    return ruta.split('.').reduce((obj, k) => (obj == null ? undefined : obj[k]), payload);
}

/**
 * Devuelve la identidad normalizada o lanza un error con status 401.
 * @returns {{ id: string, nombre: string, email: string, role: string }}
 */
function verificarToken(token) {
    const clave = EXTERNO.clavePublica || EXTERNO.secreto;
    const algoritmos = EXTERNO.clavePublica ? ['RS256', 'RS384', 'RS512', 'ES256', 'ES384'] : ['HS256', 'HS384', 'HS512'];
    const p = jwt.verify(token, clave, { algorithms: algoritmos });

    const id = valorClaim(p, EXTERNO.claimId);
    const role = mapearRol(valorClaim(p, EXTERNO.claimRol));
    if (id === undefined || id === null || id === '') throw new Error(`El token no trae el dato "${EXTERNO.claimId}"`);
    if (!role) throw new Error('Rol no reconocido');
    return {
        id: String(id),
        nombre: String(valorClaim(p, EXTERNO.claimNombre) || ''),
        email: String(valorClaim(p, EXTERNO.claimEmail) || ''),
        role
    };
}

// ================= MIDDLEWARES =================

/** Exige un usuario identificado. */
export function auth(req, res, next) {
    const token = leerToken(req);
    if (!token) return res.status(401).json({ error: 'Tenés que iniciar sesión' });
    try {
        req.user = verificarToken(token);
        next();
    } catch (err) {
        const mensaje = err.message === 'Rol no reconocido'
            ? 'Tu rol no tiene acceso a ProgMesas'
            : 'La sesión venció o no es válida. Volvé a ingresar.';
        res.status(401).json({ error: mensaje });
    }
}

/** Si hay un usuario identificado lo usa; si no, sigue como visitante. */
export function authOpcional(req, res, next) {
    const token = leerToken(req);
    req.user = null;
    if (token) {
        try {
            req.user = verificarToken(token);
        } catch {
            req.user = null;
        }
    }
    next();
}

export function rol(...roles) {
    return (req, res, next) => {
        if (!req.user || !roles.includes(req.user.role)) {
            return res.status(403).json({ error: 'No tenés permiso para esta acción' });
        }
        next();
    };
}
