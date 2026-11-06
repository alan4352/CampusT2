import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const jsonPath = fs.existsSync(path.join(__dirname, 'horarios_profes_2026.json'))
  ? path.join(__dirname, 'horarios_profes_2026.json')
  : path.join(__dirname, '..', 'horarios_profes_2026.json');

const rawData = JSON.parse(fs.readFileSync(jsonPath, 'utf-8'));

function removeAccents(str) {
  return str.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
}

// Mapa con claves sin acentos y en minúsculas
const aliasMap = {
  'mattiasi': 'Mattiassi',
  'mattiassi': 'Mattiassi',
  'acevedo': 'Acevedo',
  'grimberg': 'Grimberg Valeria',
  'fernandez': 'Fernandez Lucrecia',
  'segovia miguel': 'Segovia Miguel',
  'segovia': 'Segovia Miguel',
  'begue jose': 'Begue José Luis',
  'begue jose luis': 'Begue José Luis',
  'begue': 'Begue José Luis',
  'guerrero': 'Guerrero Marcela',
  'guerrero marcela': 'Guerrero Marcela',
  'galvani': 'Galvani Gisela',
  'galvani, gisela': 'Galvani Gisela',
  'galvani gisela': 'Galvani Gisela',
  'martin': 'Martín',
  'alanis': 'Alanís Alejandro',
  'alanis alejandro': 'Alanís Alejandro',
  'leiva': 'Leiva',
  'olivieri': 'Olivieri',
  'bacigaluppe': 'Bacigaluppe Viviana',
  'aguirre roassio': 'Aguirre Roassio Lucrecia',
  'aguirre r. lucrecia': 'Aguirre Roassio Lucrecia',
  'coali': 'Coali Bernardita',
  'ramos barbara': 'Ramos Barbara',
  'anderson': 'Anderson F.',
  'palacio': 'Palacio Viviana',
  'palacio v.': 'Palacio Viviana',
  'palacio a.': 'Palacio A.',
  'maschio': 'Maschio Mauro',
  'maschio mauro': 'Maschio Mauro',
  'mauro maschio': 'Maschio Mauro',
  'gonzalez noble': 'Gonzalez Noble Angelina',
  'toledo': 'Toledo Andrea',
  'toledo, andrea': 'Toledo Andrea',
  'ruiz diaz': 'Ruiz Diaz Flavia',
  'almiron l.': 'Almirón Lourdes',
  'almiron d.': 'Almirón Leonardo',
  'alm lescano': 'Almirón Lescano',
  'almd': 'Almirón Leonardo',
  'gob': 'Gob',
  'encina': 'Encina',
  'farher micaela': 'Farher Micaela',
  'nolli': 'Nolli',
  'siegrist soledad': 'Siegrist Soledad',
  'ortiz aubone ileana': 'Ortiz Aubone Ileana',
  'deruder mariela': 'Deruder Mariela',
  'tamburo lorena': 'Tamburo Lorena',
  'gomez maria jose': 'Gomez Maria José',
  'banegas delia': 'Banegas Delia',
  'otaegui': 'Otaegui',
  'benitez, cristina': 'Benítez Cristina',
  'benitez cristina': 'Benítez Cristina',
  'baldesari': 'Baldesari',
  'gallardo s.': 'Gallardo S.',
  'aguilar': 'Aguilar Lorena',
  'batalla': 'Batalla',
  'compa': 'Compa Cristina',
  'ramirez': 'Ramirez',
  'olmos sabina': 'Olmos Sabina',
  'olmos': 'Olmos Sabina',
  'berterame': 'Berterame',
  'garcia': 'Garcia',
  'benavidez': 'Benavidez Casandra',
  'sosa': 'Sosa Mariela',
  'sosa, mariela': 'Sosa Mariela',
  'molinari bernarda': 'Molinari Bernarda',
  'molinari': 'Molinari Bernarda',
  'bailoni c.': 'Bailoni C.',
  'debona': 'Debona',
  'olivera geier': 'Olivera Geier',
  'siarra, vanina': 'Siarra Vanina',
  'siarra': 'Siarra Vanina',
  'siarra, r.': 'Siarra R.',
  'roldan julieta': 'Roldan Julieta',
  'ponti': 'Ponti Luis',
  'ponti, luis': 'Ponti Luis',
  'bonin': 'Bonin',
  'soto': 'Soto Rocio',
  'sanchez': 'Sanchez German',
  'lasco': 'Lasco',
  'reymond patri': 'Reymond Patricia',
  'russo': 'Russo',
  'cebrian': 'Cebrian',
  'garnier r.': 'Garnier R.',
  'garnier': 'Garnier R.',
  'benitez p.': 'Benítez P.',
  'gonzalez m.': 'Gonzalez M.',
  'gonzalez': 'Gonzalez',
  'lopez, natalia': 'López Natalia',
  'lopez natalia': 'López Natalia',
  'diaz': 'Diaz',
  'escudero': 'Escudero',
  'ferreyra, dina': 'Ferreyra Dina',
  'ferreyra': 'Ferreyra Dina',
  'roberto bourbotte': 'Bourbotte Roberto',
  'bourbotte': 'Bourbotte Roberto',
  'antonelli': 'Antonelli',
  'antonelli j.': 'Antonelli J.',
  'muchiutti': 'Muchiutti',
  'gilardoni, angie': 'Gilardoni Angie',
  'gilardoni': 'Gilardoni Angie',
  'la paz juan eduardo': 'La Paz Juan Eduardo',
  'lima l.': 'Lima L.',
  'd. anderson': 'Anderson D.',
  'acosta, bl.': 'Acosta Blanca',
  'blanc': 'Blanc',
  'larocca': 'Larocca',
  'rojas c.': 'Rojas C.',
  'rojas': 'Rojas',
  'rojas, a.': 'Rojas A.',
  'roude': 'Roude Juan',
  'roude juan': 'Roude Juan',
  'saporitti': 'Saporitti',
  'godoy, lorena': 'Godoy Lorena',
  'godoy lorena': 'Godoy Lorena',
  'godoy': 'Godoy Lorena',
  'baez, olga': 'Báez Olga',
  'baez olga': 'Báez Olga',
  'baez n.': 'Báez N.',
  'sguerzo': 'Sgüerzo',
  'sgüerzo': 'Sgüerzo',
  'villalba': 'Villalba Sara',
  'villalba sara': 'Villalba Sara',
  'trav': 'Trabichet',
  'trabichet': 'Trabichet',
  'benitez, ju': 'Benítez Juan',
  'benitez ju': 'Benítez Juan',
  'cadario': 'Cadario',
  'perez maria soledad': 'Perez Maria Soledad',
  'leonarde': 'Leonardelli',
  'areguatti': 'Areguatti',
  'gallegos': 'Gallegos G.',
  'gallegos, g.': 'Gallegos G.',
  'parras': 'Parras',
  'lechini': 'Lechini',
  'valerio': 'Valerio',
  'delduca': 'Delduca',
  'velazquez, liz': 'Velazquez Liz',
  'benitez e.': 'Benítez Emanuel',
  'almada': 'Almada Evelina',
  'almada evelina': 'Almada Evelina',
  'romero libera': 'Romero Libera',
  'godoy, jorge': 'Godoy Jorge',
  'guzman': 'Guzmán H.',
  'guzman h.': 'Guzmán H.',
  'martinez, hugo': 'Martinez Hugo',
  'quinodoz': 'Quinodoz',
  'duarte': 'Duarte',
  'tito': 'Tito'
};

function normalizeName(raw) {
  let clean = raw.trim().replace(/\s+/g, ' ');
  clean = clean.replace(/[,;]+$/, '').trim();
  const key = removeAccents(clean);
  if (aliasMap[key]) {
    return aliasMap[key];
  }
  return clean;
}

const profDocMap = new Map();
const listadoAsignaciones = [];

for (const curso of rawData) {
  const cursoStr = `${curso.año} ${curso.division}` + (curso.especialidad && curso.especialidad !== 'Ciclo Básico' ? ` ${curso.especialidad}` : '');
  
  for (const asig of curso.asignaturas) {
    if (!asig.docente || asig.docente.trim() === '' || asig.docente === 'Equipo Docente') continue;

    const parts = asig.docente.split('/').map(d => d.trim()).filter(Boolean);
    for (const p of parts) {
      const subParts = p.includes(' - ') ? p.split(' - ').map(x => x.trim()) : [p];
      for (const s of subParts) {
        if (!s || s.length < 2) continue;
        const normName = normalizeName(s);

        if (!profDocMap.has(normName)) {
          profDocMap.set(normName, []);
        }

        profDocMap.get(normName).push({
          materia: asig.materia,
          curso: cursoStr,
          turno: curso.turno
        });

        listadoAsignaciones.push({
          profesor: normName,
          materia: asig.materia,
          curso: cursoStr,
          turno: curso.turno
        });
      }
    }
  }
}

const profesoresOrdenados = Array.from(profDocMap.keys()).sort((a,b) => a.localeCompare(b, 'es', { sensitivity: 'base' }));

console.log('=== TOTAL DE DOCENTES ÚNICOS EXTRAÍDOS:', profesoresOrdenados.length, '===');
console.log(JSON.stringify(profesoresOrdenados, null, 2));

// Guardar salida en un archivo JSON procesado
fs.writeFileSync(path.join(__dirname, 'profesores_extraidos.json'), JSON.stringify({
  total: profesoresOrdenados.length,
  profesores: profesoresOrdenados,
  detallePorProfesor: Object.fromEntries(
    profesoresOrdenados.map(p => [p, profDocMap.get(p)])
  ),
  asignaciones: listadoAsignaciones
}, null, 2), 'utf-8');
console.log('Archivo profesores_extraidos.json guardado con éxito.');
