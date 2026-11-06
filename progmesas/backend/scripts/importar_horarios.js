// Importa las planillas de horarios (una hoja por curso) y genera data/asignaciones.xlsx.
//
// Uso (desde la carpeta backend):
//   node scripts/importar_horarios.js "Horarios_2026_T.Mañana.xlsx" "Horarios_2026_T.Tarde.xlsx"
//
// Si data/asignaciones.xlsx ya existe NO se pisa: se genera data/asignaciones_importadas_<fecha>.xlsx
// para que puedas comparar y copiar lo que haga falta. Para reemplazarlo usá --reemplazar.

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import xlsx from 'xlsx';

xlsx.set_fs(fs); // SheetJS como módulo ES necesita el fs de Node
import { interpretarHoja, unificarDocentes } from '../lib/parser_horarios.js';
import { escribirAsignaciones } from '../lib/asignaciones_excel.js';
import { claveTexto } from '../lib/normalizar.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const DESTINO = path.join(__dirname, '..', 'data', 'asignaciones.xlsx');

const args = process.argv.slice(2);
const reemplazar = args.includes('--reemplazar');
const archivos = args.filter((a) => !a.startsWith('--'));

if (archivos.length === 0) {
    console.log('Uso: node scripts/importar_horarios.js <horarios_mañana.xlsx> <horarios_tarde.xlsx> [--reemplazar]');
    process.exit(1);
}

function turnoDesdeNombre(archivo) {
    const n = claveTexto(path.basename(archivo));
    if (/tarde|\btt\b/.test(n)) return 'Tarde';
    if (/manana|\btm\b/.test(n)) return 'Mañana';
    return '';
}

const cursos = [];
for (const archivo of archivos) {
    if (!fs.existsSync(archivo)) {
        console.error(`No existe el archivo: ${archivo}`);
        process.exit(1);
    }
    const libro = xlsx.readFile(archivo);
    const turno = turnoDesdeNombre(archivo);
    for (const nombre of libro.SheetNames) {
        const filas = xlsx.utils.sheet_to_json(libro.Sheets[nombre], { header: 1, raw: false, defval: '' });
        const curso = interpretarHoja({ hoja: nombre, turno, filas });
        if (curso) cursos.push(curso);
        else console.log(`  (se ignoró la hoja "${nombre}" de ${path.basename(archivo)})`);
    }
}

const variantes = unificarDocentes(cursos);

// Orden: turno, año, división, especialidad
cursos.sort((a, b) =>
    a.turno.localeCompare(b.turno) || a.anio - b.anio || a.division - b.division || a.especialidad.localeCompare(b.especialidad)
);

const filas = [];
for (const c of cursos) {
    for (const a of c.asignaturas) {
        filas.push({ curso: c.curso, turno: c.turno, materia: a.materia, docentes: a.docentes, origen: a.origen, revisar: a.revisar });
    }
}

// Resumen por docente para detectar el mismo nombre escrito de dos formas
const porDocente = new Map();
for (const f of filas) {
    for (const d of f.docentes) {
        if (!porDocente.has(d)) porDocente.set(d, { docente: d, asignaciones: 0, cursos: new Set() });
        const item = porDocente.get(d);
        item.asignaciones++;
        item.cursos.add(f.curso);
    }
}
const docentes = [...porDocente.values()]
    .sort((a, b) => a.docente.localeCompare(b.docente, 'es'))
    .map((d) => ({
        docente: d.docente,
        asignaciones: d.asignaciones,
        cursos: [...d.cursos].join(', '),
        variantes: [...(variantes.get(d.docente) || [])].join(' | ')
    }));

fs.mkdirSync(path.dirname(DESTINO), { recursive: true });
let salida = DESTINO;
if (fs.existsSync(DESTINO) && !reemplazar) {
    const fecha = new Date().toISOString().slice(0, 16).replace(/[:T]/g, '-');
    salida = path.join(path.dirname(DESTINO), `asignaciones_importadas_${fecha}.xlsx`);
}
escribirAsignaciones(salida, filas, docentes);

const aRevisar = filas.filter((f) => f.revisar).length;
console.log(`Cursos: ${cursos.length} | Asignaciones: ${filas.length} | Docentes: ${docentes.length} | Para revisar: ${aRevisar}`);
console.log(`Archivo generado: ${salida}`);
if (salida !== DESTINO) {
    console.log('Ya existía data/asignaciones.xlsx, así que no se modificó. Usá --reemplazar para pisarlo.');
}
