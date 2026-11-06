// Guarda los PDF en Google Drive y, si Drive no está disponible, en la carpeta uploads del servidor.
// Cuando Drive vuelve, pasarADrive() sube los que quedaron en el servidor.

import fs from 'fs';
import path from 'path';
import { RUTAS } from './config.js';
import { subirArchivo, eliminarArchivo, descargarArchivo, verificarDrive } from './drive.js';
import { claveTexto } from './lib/normalizar.js';
import { usosDeArchivo, archivosLocales, moverArchivo } from './store.js';

fs.mkdirSync(RUTAS.uploads, { recursive: true });

function nombreSeguro(texto) {
    return claveTexto(texto).replace(/\s+/g, '_').slice(0, 60) || 'programa';
}

function rutaLocal(archivoRef) {
    // basename evita leer o borrar algo fuera de la carpeta uploads
    return path.join(RUTAS.uploads, path.basename(String(archivoRef || '')));
}

/**
 * @returns {Promise<{almacenamiento:'drive'|'local', archivo_ref:string, file_url:string, aviso:string|null}>}
 */
export async function guardarPdf(buffer, { anio, curso, materia, profesor }) {
    try {
        const id = await subirArchivo(buffer, anio, materia, curso, profesor);
        return {
            almacenamiento: 'drive',
            archivo_ref: id,
            file_url: `https://drive.google.com/file/d/${id}/preview`,
            aviso: null
        };
    } catch (err) {
        console.warn('[Almacenamiento] Drive no disponible, se guarda en el servidor:', err.message);
        const nombre = `${Date.now()}_${nombreSeguro(`${curso} ${materia}`)}.pdf`;
        fs.writeFileSync(rutaLocal(nombre), buffer);
        return {
            almacenamiento: 'local',
            archivo_ref: nombre,
            file_url: `/uploads/${encodeURIComponent(nombre)}`,
            aviso: `El programa se guardó en el servidor porque Google Drive no está disponible (${err.message}).`
        };
    }
}

/** Borra el archivo de un programa o versión, sólo si ningún otro programa o versión lo usa. */
export async function eliminarPdfSiNoSeUsa(registro) {
    if (!registro?.archivo_ref) return false;
    if (usosDeArchivo(registro.almacenamiento, registro.archivo_ref) > 0) return false;
    await eliminarPdf(registro);
    return true;
}

export async function eliminarPdf(programa) {
    if (!programa) return;
    if (programa.almacenamiento === 'drive') {
        await eliminarArchivo(programa.archivo_ref);
    } else if (programa.almacenamiento === 'local' && programa.archivo_ref) {
        fs.rm(rutaLocal(programa.archivo_ref), { force: true }, () => {});
    }
}

/** Contenido del PDF de un programa (local o de Drive). */
export async function leerPdf(programa) {
    if (programa.almacenamiento === 'drive') return descargarArchivo(programa.archivo_ref);
    if (programa.almacenamiento === 'local') return fs.promises.readFile(rutaLocal(programa.archivo_ref));
    throw new Error('El programa no tiene un archivo guardado');
}

let sincronizando = null;

/**
 * Sube a Drive los PDF que quedaron guardados en el servidor y actualiza los programas que los usan.
 * Devuelve { pasados, pendientes, errores }. Si Drive no está disponible no hace nada.
 */
export function pasarADrive() {
    // Una sola sincronización a la vez
    sincronizando ||= (async () => {
        const locales = archivosLocales();
        const resultado = { pasados: 0, pendientes: locales.length, errores: [] };
        if (locales.length === 0) return resultado;

        const drive = await verificarDrive();
        if (!drive.disponible) {
            resultado.errores.push(drive.error || 'Google Drive no está disponible');
            return resultado;
        }

        for (const archivo of locales) {
            try {
                const buffer = await fs.promises.readFile(rutaLocal(archivo.archivo_ref));
                const id = await subirArchivo(buffer, archivo.year, archivo.materia, archivo.curso, archivo.profesor);
                moverArchivo(archivo.archivo_ref, {
                    almacenamiento: 'drive',
                    archivo_ref: id,
                    file_url: `https://drive.google.com/file/d/${id}/preview`
                });
                fs.rm(rutaLocal(archivo.archivo_ref), { force: true }, () => {});
                resultado.pasados++;
                resultado.pendientes--;
            } catch (err) {
                resultado.errores.push(`${archivo.archivo_ref}: ${err.message}`);
            }
        }
        if (resultado.pasados) console.log(`[Drive] ${resultado.pasados} programa(s) guardados en el servidor pasaron a Drive`);
        return resultado;
    })().finally(() => {
        sincronizando = null;
    });
    return sincronizando;
}

/** Cantidad de archivos que están guardados en el servidor (y no en Drive). */
export function cantidadLocales() {
    return archivosLocales().length;
}
