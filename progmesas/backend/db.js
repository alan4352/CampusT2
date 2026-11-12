import Database from 'better-sqlite3';
import fs from 'fs';
import path from 'path';
import { RUTAS, BACKEND } from './config.js';

const db = new Database(RUTAS.db);
db.pragma('journal_mode = WAL');

// ================= PROGRAMAS =================

db.exec(`
    CREATE TABLE IF NOT EXISTS programas (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        materia TEXT NOT NULL,
        year INTEGER NOT NULL,
        curso TEXT NOT NULL,
        profesor TEXT NOT NULL,
        file_url TEXT NOT NULL,
        fecha TEXT NOT NULL,
        uploaded_by TEXT DEFAULT ''
    )
`);

function asegurarColumna(tabla, columna, definicion) {
    const cols = db.pragma(`table_info(${tabla})`);
    if (!cols.some((c) => c.name === columna)) {
        db.exec(`ALTER TABLE ${tabla} ADD COLUMN ${columna} ${definicion}`);
        return true;
    }
    return false;
}

asegurarColumna('programas', 'uploaded_by', "TEXT DEFAULT ''");
asegurarColumna('programas', 'asignacion_id', 'TEXT');
asegurarColumna('programas', 'almacenamiento', "TEXT DEFAULT ''"); // 'drive' | 'local'
asegurarColumna('programas', 'archivo_ref', "TEXT DEFAULT ''");    // id de Drive o nombre del archivo local
const nuevaCreatedAt = asegurarColumna('programas', 'created_at', 'TEXT');
// Tipo de programa: 'regular' o 'libre'. Los cargados antes de que existiera el tipo quedan como regulares.
// El título sólo aclara cuando el programa es libre ("Programa libre de …"); el regular queda "Programa de …".
asegurarColumna('programas', 'tipo', "TEXT NOT NULL DEFAULT 'regular'");
for (const tabla of ['programas', 'programas_versiones']) {
    if (db.pragma(`table_info(${tabla})`).length) {
        db.exec(`UPDATE ${tabla} SET title = 'Programa de ' || substr(title, 21) WHERE title LIKE 'Programa regular de %'`);
    }
}

db.exec('DROP INDEX IF EXISTS idx_programas_asignacion');
db.exec('CREATE INDEX IF NOT EXISTS idx_programas_asignacion_tipo ON programas (asignacion_id, year, tipo)');

// Completar datos de las filas creadas con la versión anterior
if (nuevaCreatedAt) {
    const filas = db.prepare('SELECT id, fecha, file_url FROM programas').all();
    const actualizar = db.prepare(
        'UPDATE programas SET created_at = ?, file_url = ?, almacenamiento = ?, archivo_ref = ? WHERE id = ?'
    );
    db.transaction(() => {
        for (const f of filas) {
            // fecha "27/8/2026" → ISO
            const m = String(f.fecha || '').match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
            const iso = m
                ? new Date(Number(m[3]), Number(m[2]) - 1, Number(m[1]), 12).toISOString()
                : new Date().toISOString();

            let url = f.file_url || '';
            let almacenamiento = '';
            let ref = '';
            const local = url.match(/\/uploads\/([^/?#]+)$/);
            const drive = url.match(/drive\.google\.com\/file\/d\/([^/]+)/);
            if (local) {
                // "http://localhost:3000/uploads/x.pdf" → "/uploads/x.pdf" (funciona desde cualquier equipo)
                ref = decodeURIComponent(local[1]);
                url = `/uploads/${encodeURIComponent(ref)}`;
                almacenamiento = 'local';
            } else if (drive) {
                ref = drive[1];
                almacenamiento = 'drive';
            }
            actualizar.run(iso, url, almacenamiento, ref, f.id);
        }
    })();
    console.log(`[DB] Se actualizaron ${filas.length} programas al nuevo formato`);
}

// Migración única desde el antiguo programas.json
const { cantidad } = db.prepare('SELECT COUNT(*) AS cantidad FROM programas').get();
const jsonPath = path.join(BACKEND, 'programas.json');
if (cantidad === 0 && fs.existsSync(jsonPath)) {
    try {
        const lista = JSON.parse(fs.readFileSync(jsonPath, 'utf-8'));
        if (Array.isArray(lista) && lista.length > 0) {
            const insertar = db.prepare(`
                INSERT INTO programas (title, materia, year, curso, profesor, file_url, fecha, uploaded_by, created_at, almacenamiento)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            `);
            db.transaction(() => {
                for (const p of lista) {
                    insertar.run(
                        String(p.title || 'Sin título'),
                        String(p.materia || 'Sin materia'),
                        Number(p.year) || new Date().getFullYear(),
                        String(p.curso || ''),
                        String(p.profesor || 'Sin profesor'),
                        String(p.file_url || ''),
                        String(p.fecha || new Date().toLocaleDateString('es-AR')),
                        String(p.uploaded_by || ''),
                        new Date().toISOString(),
                        String(p.file_url || '').includes('drive.google.com') ? 'drive' : ''
                    );
                }
            })();
            console.log(`[DB] Migrados ${lista.length} programas desde programas.json`);
        }
    } catch (err) {
        console.error('[DB] Error al migrar programas.json:', err.message);
    }
}

export default db;
