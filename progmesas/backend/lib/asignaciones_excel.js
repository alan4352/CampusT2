// Formato del archivo data/asignaciones.xlsx: la lista oficial de qué programa
// tiene que entregar cada curso. La asesora puede editarlo con Excel.

import fs from 'fs';
import xlsx from 'xlsx';

// SheetJS como módulo ES necesita el fs de Node para leer y escribir archivos
xlsx.set_fs(fs);
import { claveCompacta, cursoClave } from './normalizar.js';

export const HOJA_ASIGNACIONES = 'Asignaciones';
export const HOJA_DOCENTES = 'Docentes';
export const HOJA_INSTRUCCIONES = 'Instrucciones';

export const COLUMNAS = ['ID', 'Curso', 'Turno', 'Materia', 'Docentes', 'Requiere programa', 'Origen', 'Revisar'];

export const SEPARADOR_DOCENTES = ' / ';

export function generarId(curso, materia) {
    return `${cursoClave(curso)}_${claveCompacta(materia)}`;
}

const INSTRUCCIONES = [
    ['Cómo editar este archivo'],
    [''],
    ['• Cada fila de "Asignaciones" es un programa de examen que el sistema espera.'],
    ['• Docentes: separá varios nombres con " / ". El nombre tiene que coincidir con el "docente" de la cuenta del profesor.'],
    ['• Requiere programa: poné NO para que una materia no cuente como pendiente (por ejemplo, un taller sin mesa de examen).'],
    ['• ID: no lo cambies. Si renombrás una materia, el ID mantiene la relación con los programas ya cargados.'],
    ['  Para agregar una materia nueva dejá el ID vacío: el sistema lo genera solo.'],
    ['• Revisar: el importador marca las filas que conviene controlar (contraturno o sin docente). Borrá la marca cuando esté verificada.'],
    ['• La hoja "Docentes" es sólo de consulta: sirve para encontrar el mismo docente escrito de dos formas.'],
    [''],
    ['El servidor relee este archivo automáticamente cuando lo guardás.']
];

/**
 * Escribe el libro de asignaciones.
 * @param {string} ruta
 * @param {Array<{id?:string,curso:string,turno:string,materia:string,docentes:string[],requiere?:boolean,origen?:string,revisar?:string}>} filas
 * @param {Array<{docente:string,asignaciones:number,cursos:string,variantes:string}>} docentes
 */
export function escribirAsignaciones(ruta, filas, docentes = []) {
    const datos = filas.map((f) => ({
        ID: f.id || generarId(f.curso, f.materia),
        Curso: f.curso,
        Turno: f.turno || '',
        Materia: f.materia,
        Docentes: (f.docentes || []).join(SEPARADOR_DOCENTES),
        'Requiere programa': f.requiere === false ? 'NO' : 'SI',
        Origen: f.origen || '',
        Revisar: f.revisar || ''
    }));

    const libro = xlsx.utils.book_new();
    const hoja = xlsx.utils.json_to_sheet(datos, { header: COLUMNAS });
    hoja['!cols'] = [{ wch: 22 }, { wch: 12 }, { wch: 9 }, { wch: 44 }, { wch: 48 }, { wch: 10 }, { wch: 12 }, { wch: 32 }];
    hoja['!autofilter'] = { ref: hoja['!ref'] };
    xlsx.utils.book_append_sheet(libro, hoja, HOJA_ASIGNACIONES);

    const hojaDoc = xlsx.utils.json_to_sheet(
        docentes.map((d) => ({
            Docente: d.docente,
            Asignaciones: d.asignaciones,
            Cursos: d.cursos,
            'Variantes encontradas en las planillas': d.variantes
        }))
    );
    hojaDoc['!cols'] = [{ wch: 30 }, { wch: 12 }, { wch: 60 }, { wch: 40 }];
    xlsx.utils.book_append_sheet(libro, hojaDoc, HOJA_DOCENTES);

    xlsx.utils.book_append_sheet(libro, xlsx.utils.aoa_to_sheet(INSTRUCCIONES), HOJA_INSTRUCCIONES);
    xlsx.writeFile(libro, ruta);
}

/** Lee el libro y devuelve las asignaciones validadas (sin filas vacías, ID único). */
export function leerAsignaciones(ruta) {
    const libro = xlsx.readFile(ruta);
    const hoja = libro.Sheets[HOJA_ASIGNACIONES] || libro.Sheets[libro.SheetNames[0]];
    const filas = xlsx.utils.sheet_to_json(hoja, { defval: '' });
    const vistos = new Set();
    const resultado = [];
    const advertencias = [];

    filas.forEach((f, i) => {
        const curso = String(f.Curso || '').trim();
        const materia = String(f.Materia || '').trim();
        if (!curso || !materia) return;
        let id = String(f.ID || '').trim() || generarId(curso, materia);
        if (vistos.has(id)) {
            advertencias.push(`Fila ${i + 2}: ID repetido "${id}" (se ignoró la fila)`);
            return;
        }
        vistos.add(id);
        const requiere = !/^\s*no\s*$/i.test(String(f['Requiere programa'] ?? ''));
        resultado.push({
            id,
            curso,
            cursoClave: cursoClave(curso),
            turno: String(f.Turno || '').trim(),
            materia,
            docentes: String(f.Docentes || '')
                .split('/')
                .map((d) => d.trim())
                .filter(Boolean),
            requiere,
            revisar: String(f.Revisar || '').trim()
        });
    });

    return { asignaciones: resultado, advertencias };
}
