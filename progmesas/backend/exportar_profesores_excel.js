import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import xlsx from 'xlsx';

xlsx.set_fs(fs); // SheetJS como módulo ES necesita el fs de Node

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const jsonPath = path.join(__dirname, 'profesores_extraidos.json');
const data = JSON.parse(fs.readFileSync(jsonPath, 'utf-8'));

// 1. Hoja de Profesores Únicos
const rowsProfesores = data.profesores.map((prof, idx) => {
  const materias = [...new Set(data.detallePorProfesor[prof].map(d => d.materia))].join(', ');
  const cursos = [...new Set(data.detallePorProfesor[prof].map(d => d.curso))].join(', ');
  const turnos = [...new Set(data.detallePorProfesor[prof].map(d => d.turno))].join(', ');
  return {
    'N°': idx + 1,
    'Docente / Profesor': prof,
    'Turno': turnos,
    'Cursos': cursos,
    'Materias Asignadas': materias,
    'Total Asignaciones': data.detallePorProfesor[prof].length
  };
});

// 2. Hoja de Asignaciones Detalladas (Materia + Curso + Docente)
const rowsAsignaciones = data.asignaciones.map((a, idx) => ({
  'N°': idx + 1,
  'Curso': a.curso,
  'Turno': a.turno,
  'Materia': a.materia,
  'Profesor / Docente': a.profesor
}));

const wb = xlsx.utils.book_new();
const ws1 = xlsx.utils.json_to_sheet(rowsProfesores);
const ws2 = xlsx.utils.json_to_sheet(rowsAsignaciones);

xlsx.utils.book_append_sheet(wb, ws1, 'Docentes Únicos');
xlsx.utils.book_append_sheet(wb, ws2, 'Detalle Asignaciones');

const outPath = path.join(__dirname, 'profesores_extraidos.xlsx');
xlsx.writeFile(wb, outPath);
console.log('Excel generado exitosamente en:', outPath);
