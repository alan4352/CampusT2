// Generador de archivos ZIP mínimo (sin compresión), sin dependencias.
// Los PDF ya vienen comprimidos, así que guardarlos tal cual es más rápido y casi no cambia el tamaño.

import zlib from 'zlib';

// CRC-32 (zlib.crc32 existe desde Node 20.15 / 22.2; si no, se calcula con una tabla)
const TABLA = (() => {
    const t = new Uint32Array(256);
    for (let n = 0; n < 256; n++) {
        let c = n;
        for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
        t[n] = c >>> 0;
    }
    return t;
})();

export function crc32(buffer) {
    if (typeof zlib.crc32 === 'function') return zlib.crc32(buffer) >>> 0;
    let c = 0xffffffff;
    for (let i = 0; i < buffer.length; i++) c = TABLA[(c ^ buffer[i]) & 0xff] ^ (c >>> 8);
    return (c ^ 0xffffffff) >>> 0;
}

function fechaDos(fecha) {
    const anio = Math.max(fecha.getFullYear(), 1980);
    return {
        hora: (fecha.getHours() << 11) | (fecha.getMinutes() << 5) | Math.floor(fecha.getSeconds() / 2),
        dia: ((anio - 1980) << 9) | ((fecha.getMonth() + 1) << 5) | fecha.getDate()
    };
}

/**
 * Arma un ZIP con los archivos dados.
 * @param {{ nombre: string, datos: Buffer, fecha?: Date }[]} archivos  nombre con "/" para carpetas
 * @returns {Buffer}
 */
export function crearZip(archivos) {
    const locales = [];
    const centrales = [];
    let desplazamiento = 0;

    for (const { nombre, datos, fecha = new Date() } of archivos) {
        const nombreBytes = Buffer.from(nombre, 'utf8');
        const crc = crc32(datos);
        const { hora, dia } = fechaDos(fecha);

        const local = Buffer.alloc(30);
        local.writeUInt32LE(0x04034b50, 0);   // firma
        local.writeUInt16LE(20, 4);           // versión necesaria
        local.writeUInt16LE(0x0800, 6);       // nombres en UTF-8
        local.writeUInt16LE(0, 8);            // sin compresión
        local.writeUInt16LE(hora, 10);
        local.writeUInt16LE(dia, 12);
        local.writeUInt32LE(crc, 14);
        local.writeUInt32LE(datos.length, 18);
        local.writeUInt32LE(datos.length, 22);
        local.writeUInt16LE(nombreBytes.length, 26);
        local.writeUInt16LE(0, 28);
        locales.push(local, nombreBytes, datos);

        const central = Buffer.alloc(46);
        central.writeUInt32LE(0x02014b50, 0);
        central.writeUInt16LE(20, 4);         // hecho por
        central.writeUInt16LE(20, 6);         // versión necesaria
        central.writeUInt16LE(0x0800, 8);
        central.writeUInt16LE(0, 10);
        central.writeUInt16LE(hora, 12);
        central.writeUInt16LE(dia, 14);
        central.writeUInt32LE(crc, 16);
        central.writeUInt32LE(datos.length, 20);
        central.writeUInt32LE(datos.length, 24);
        central.writeUInt16LE(nombreBytes.length, 28);
        central.writeUInt32LE(desplazamiento, 42);
        centrales.push(central, nombreBytes);

        desplazamiento += local.length + nombreBytes.length + datos.length;
    }

    const directorio = Buffer.concat(centrales);
    const fin = Buffer.alloc(22);
    fin.writeUInt32LE(0x06054b50, 0);
    fin.writeUInt16LE(archivos.length, 8);
    fin.writeUInt16LE(archivos.length, 10);
    fin.writeUInt32LE(directorio.length, 12);
    fin.writeUInt32LE(desplazamiento, 16);

    return Buffer.concat([...locales, directorio, fin]);
}

/** Lee un ZIP sin compresión (lo usan las pruebas para comprobar lo que se generó). */
export function leerZip(zip) {
    const fin = zip.lastIndexOf(Buffer.from([0x50, 0x4b, 0x05, 0x06]));
    if (fin < 0) throw new Error('No es un ZIP');
    const cantidad = zip.readUInt16LE(fin + 10);
    let p = zip.readUInt32LE(fin + 16);
    const archivos = [];
    for (let i = 0; i < cantidad; i++) {
        if (zip.readUInt32LE(p) !== 0x02014b50) throw new Error('Directorio del ZIP dañado');
        const crc = zip.readUInt32LE(p + 16);
        const tam = zip.readUInt32LE(p + 20);
        const largoNombre = zip.readUInt16LE(p + 28);
        const extra = zip.readUInt16LE(p + 30);
        const comentario = zip.readUInt16LE(p + 32);
        const local = zip.readUInt32LE(p + 42);
        const nombre = zip.subarray(p + 46, p + 46 + largoNombre).toString('utf8');
        const inicio = local + 30 + zip.readUInt16LE(local + 26) + zip.readUInt16LE(local + 28);
        const datos = zip.subarray(inicio, inicio + tam);
        if (crc32(datos) !== crc) throw new Error(`CRC incorrecto en ${nombre}`);
        archivos.push({ nombre, datos });
        p += 46 + largoNombre + extra + comentario;
    }
    return archivos;
}
