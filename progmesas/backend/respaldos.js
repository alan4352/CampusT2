// Copias de seguridad: base de datos, PDF guardados en el servidor y lista oficial de asignaciones.
//
// Con RESPALDOS_DIR configurado, el servidor hace una copia por día a la hora RESPALDO_HORA (3 por defecto)
// y conserva las últimas RESPALDOS_CONSERVAR (14 por defecto). También se puede correr a mano:
//   node scripts/respaldo.js

import fs from 'fs';
import path from 'path';
import db from './db.js';
import { RUTAS, RESPALDOS } from './config.js';

const dosDigitos = (n) => String(n).padStart(2, '0');

function nombreCarpeta(fecha = new Date()) {
    return `${fecha.getFullYear()}-${dosDigitos(fecha.getMonth() + 1)}-${dosDigitos(fecha.getDate())}_` +
        `${dosDigitos(fecha.getHours())}${dosDigitos(fecha.getMinutes())}${dosDigitos(fecha.getSeconds())}`;
}

/** Carpetas de respaldo existentes, de la más vieja a la más nueva. */
export function listarRespaldos(dir = RESPALDOS.dir) {
    if (!dir || !fs.existsSync(dir)) return [];
    return fs.readdirSync(dir, { withFileTypes: true })
        .filter((e) => e.isDirectory() && /^\d{4}-\d{2}-\d{2}_\d{6}(_\d+)?$/.test(e.name))
        .map((e) => e.name)
        .sort();
}

/**
 * Hace una copia completa en una carpeta nueva dentro de `dir` y borra las más viejas.
 * La base se copia con la función de respaldo de SQLite, que es segura con el servidor andando.
 * @returns {Promise<{carpeta:string, archivos:number, borrados:string[]}>}
 */
export async function hacerRespaldo({ dir = RESPALDOS.dir, conservar = RESPALDOS.conservar } = {}) {
    if (!dir) throw new Error('Falta RESPALDOS_DIR (carpeta donde guardar las copias)');
    let carpeta = path.join(dir, nombreCarpeta());
    for (let i = 1; fs.existsSync(carpeta); i++) carpeta = path.join(dir, `${nombreCarpeta()}_${i}`);
    fs.mkdirSync(carpeta, { recursive: true });

    await db.backup(path.join(carpeta, 'database.sqlite'));
    let archivos = 1;

    if (fs.existsSync(RUTAS.uploads)) {
        fs.cpSync(RUTAS.uploads, path.join(carpeta, 'uploads'), { recursive: true });
        archivos += fs.readdirSync(path.join(carpeta, 'uploads')).length;
    }
    if (fs.existsSync(RUTAS.asignaciones)) {
        fs.copyFileSync(RUTAS.asignaciones, path.join(carpeta, 'asignaciones.xlsx'));
        archivos++;
    }

    const borrados = [];
    const todas = listarRespaldos(dir);
    for (const vieja of todas.slice(0, Math.max(0, todas.length - Math.max(1, conservar)))) {
        fs.rmSync(path.join(dir, vieja), { recursive: true, force: true });
        borrados.push(vieja);
    }
    return { carpeta, archivos, borrados };
}

/** Programa el respaldo diario dentro del servidor (sólo si RESPALDOS_DIR está configurado). */
export function programarRespaldos(alTerminar = () => {}) {
    if (!RESPALDOS.dir) return false;
    const siguiente = () => {
        const ahora = new Date();
        const prox = new Date(ahora);
        prox.setHours(RESPALDOS.hora, 0, 0, 0);
        if (prox <= ahora) prox.setDate(prox.getDate() + 1);
        return prox - ahora;
    };
    const ciclo = () => {
        hacerRespaldo()
            .then((r) => {
                console.log(`[Respaldo] Copia guardada en ${r.carpeta}`);
                alTerminar(null, r);
            })
            .catch((err) => {
                console.error('[Respaldo] No se pudo hacer la copia:', err.message);
                alTerminar(err);
            })
            .finally(() => setTimeout(ciclo, siguiente()).unref());
    };
    setTimeout(ciclo, siguiente()).unref();
    console.log(`[Respaldo] Copias diarias a las ${RESPALDOS.hora} h en ${RESPALDOS.dir}`);
    return true;
}
