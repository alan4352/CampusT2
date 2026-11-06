import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import xlsx from 'xlsx';

xlsx.set_fs(fs); // SheetJS como módulo ES necesita el fs de Node

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

// Posibles ubicaciones de horarios_profes_2026.json y archivos procesados
const RUTAS_HORARIOS_JSON = [
    path.join(__dirname, 'horarios_profes_2026.json'),
    path.join(__dirname, '..', 'horarios_profes_2026.json'),
    path.join(__dirname, 'profesores_extraidos.json'),
    path.join(__dirname, 'horarios_extraidos.json')
];
const ARCHIVO_EXCEL = path.join(__dirname, 'materias_profesores_editable.xlsx');

/**
 * Obtiene la lista oficial de asignaciones (curso, materia, profesor, turno)
 * leyendo directamente del archivo horarios_profes_2026.json.
 */
export function obtenerProfesoresEsperados() {
    // 1. Si existe profesores_extraidos.json procesado
    const rutaProcesada = path.join(__dirname, 'profesores_extraidos.json');
    if (fs.existsSync(rutaProcesada)) {
        try {
            const data = JSON.parse(fs.readFileSync(rutaProcesada, 'utf-8'));
            if (Array.isArray(data.asignaciones) && data.asignaciones.length > 0) {
                return data.asignaciones;
            }
        } catch (e) {
            console.error('[Error al leer profesores_extraidos.json]:', e.message);
        }
    }

    // 2. Buscar directamente horarios_profes_2026.json
    for (const ruta of RUTAS_HORARIOS_JSON) {
        if (fs.existsSync(ruta)) {
            try {
                const data = JSON.parse(fs.readFileSync(ruta, 'utf-8'));
                if (Array.isArray(data)) {
                    const resultado = [];
                    const vistos = new Set();

                    for (const c of data) {
                        const cursoStr = `${c.año} ${c.division}` + (c.especialidad && c.especialidad !== 'Ciclo Básico' ? ` ${c.especialidad}` : '');
                        for (const asig of c.asignaturas || []) {
                            if (!asig.docente || asig.docente.trim() === '' || asig.docente === 'Equipo Docente') continue;

                            const partes = asig.docente.split('/').map(d => d.trim()).filter(Boolean);
                            for (const p of partes) {
                                const subPartes = p.includes(' - ') ? p.split(' - ').map(x => x.trim()) : [p];
                                for (const prof of subPartes) {
                                    if (!prof || prof.length < 2) continue;
                                    const key = `${cursoStr.toLowerCase()}|${asig.materia.toLowerCase()}|${prof.toLowerCase()}`;
                                    if (!vistos.has(key)) {
                                        vistos.add(key);
                                        resultado.push({
                                            curso: cursoStr,
                                            materia: asig.materia,
                                            profesor: prof,
                                            turno: c.turno
                                        });
                                    }
                                }
                            }
                        }
                    }
                    if (resultado.length > 0) return resultado;
                }
            } catch (err) {
                console.error(`[Error al procesar ${ruta}]:`, err.message);
            }
        }
    }

    // 3. Fallback: Excel
    return leerExcelFallback();
}

/**
 * Fallback a archivo Excel en caso de no existir los archivos JSON
 */
function leerExcelFallback() {
    if (!xlsx || typeof xlsx.readFile !== 'function' || !fs.existsSync(ARCHIVO_EXCEL)) {
        return [];
    }
    const workbook = xlsx.readFile(ARCHIVO_EXCEL);
    const nombreHoja = workbook.SheetNames[0];
    const hoja = workbook.Sheets[nombreHoja];
    const datos = xlsx.utils.sheet_to_json(hoja);
    const resultado = [];
    const vistos = new Set();

    datos.forEach(fila => {
        if (!fila.Curso || !fila.Materia) return;
        const curso = String(fila.Curso).trim();
        const materia = String(fila.Materia).trim();
        const profesores = String(fila.Profesores || '')
            .split(',')
            .map(p => p.trim())
            .filter(Boolean);

        profesores.forEach(profesor => {
            const claveUnica = `${curso.toLowerCase()}|${materia.toLowerCase()}|${profesor.toLowerCase()}`;
            if (!vistos.has(claveUnica)) {
                vistos.add(claveUnica);
                resultado.push({
                    curso,
                    materia,
                    profesor
                });
            }
        });
    });

    return resultado;
}