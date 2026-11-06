// Hace una copia de seguridad ahora (base de datos, PDF del servidor y asignaciones.xlsx).
//
//   node scripts/respaldo.js                 → usa RESPALDOS_DIR del .env
//   node scripts/respaldo.js D:\Respaldos    → guarda en esa carpeta
//
// Conserva las últimas RESPALDOS_CONSERVAR copias (14 por defecto) y borra las más viejas.

import path from 'path';
import { RESPALDOS } from '../config.js';
import { hacerRespaldo } from '../respaldos.js';

const dir = process.argv[2] ? path.resolve(process.argv[2]) : RESPALDOS.dir;
if (!dir) {
    console.error('Indicá la carpeta: node scripts/respaldo.js <carpeta>, o configurá RESPALDOS_DIR en el .env');
    process.exit(1);
}

try {
    const r = await hacerRespaldo({ dir });
    console.log(`Copia guardada en ${r.carpeta} (${r.archivos} archivo(s)).`);
    if (r.borrados.length) console.log(`Se borraron ${r.borrados.length} copia(s) vieja(s).`);
} catch (err) {
    console.error('No se pudo hacer la copia:', err.message);
    process.exitCode = 1;
}
