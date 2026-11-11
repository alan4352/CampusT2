<?php
/* =====================================================================
   ARCHIVO: includes/academico.php
   PARA QUÉ SIRVE: estructura académica del campus (etapa 2).

   - materias                : catálogo de materias (una fila por nombre)
   - curso_materias          : qué materias se dictan en cada curso
   - curso_materia_docentes  : docentes de cada materia de un curso, tal como
                               figuran en la planilla de ProgMesas (apellido,
                               a veces con el nombre): "docente esperado"
   - docentes_vinculos       : a qué cuenta del campus corresponde cada
                               docente de la planilla. Lo confirma un directivo.

   Los datos se importan de la planilla de ProgMesas
   (progmesas/backend/data/asignaciones.xlsx). El directivo ve antes una
   vista previa con lo que cambia; nada se importa sin su confirmación.
   ===================================================================== */
require_once __DIR__ . '/cuentas.php';

const PLANILLA_PROGMESAS = __DIR__ . '/../../progmesas/backend/data/asignaciones.xlsx';


/* ---------------------------------------------------------------------
   CONEXIÓN Y ESTRUCTURA
   --------------------------------------------------------------------- */

/** Conexión con las tablas de cuentas y de estructura académica listas. */
function bdAcademico(): PDO {
    $pdo = bdCuentas();
    if (!migracionAplicada($pdo, 2)) {
        $pdo->query("SELECT GET_LOCK('campus_migracion_cuentas', 30)");
        try {
            if (!migracionAplicada($pdo, 2)) {
                migracionAcademicaV2($pdo);
                $pdo->prepare('INSERT INTO campus_migraciones (version) VALUES (2)')->execute();
            }
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('campus_migracion_cuentas')");
        }
    }
    return $pdo;
}

function migracionAcademicaV2(PDO $pdo): void {
    $opc = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $pdo->exec("CREATE TABLE IF NOT EXISTS materias (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(150) NOT NULL,
        clave VARCHAR(150) NOT NULL,
        UNIQUE KEY uq_materias_clave (clave)
    ) $opc");

    $pdo->exec("CREATE TABLE IF NOT EXISTS curso_materias (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        curso_id INT UNSIGNED NOT NULL,
        materia_id INT UNSIGNED NOT NULL,
        requiere_programa TINYINT(1) NOT NULL DEFAULT 1,
        revisar VARCHAR(255) NOT NULL DEFAULT '',
        UNIQUE KEY uq_curso_materia (curso_id, materia_id),
        CONSTRAINT fk_cm_curso FOREIGN KEY (curso_id) REFERENCES cursos(id),
        CONSTRAINT fk_cm_materia FOREIGN KEY (materia_id) REFERENCES materias(id)
    ) $opc");

    $pdo->exec("CREATE TABLE IF NOT EXISTS curso_materia_docentes (
        curso_materia_id INT UNSIGNED NOT NULL,
        docente_clave VARCHAR(150) NOT NULL,
        docente_nombre VARCHAR(150) NOT NULL,
        PRIMARY KEY (curso_materia_id, docente_clave),
        KEY idx_cmd_docente (docente_clave),
        CONSTRAINT fk_cmd_cm FOREIGN KEY (curso_materia_id) REFERENCES curso_materias(id) ON DELETE CASCADE
    ) $opc");

    $pdo->exec("CREATE TABLE IF NOT EXISTS docentes_vinculos (
        docente_clave VARCHAR(150) NOT NULL PRIMARY KEY,
        usuario_id INT UNSIGNED NOT NULL,
        vinculado_por INT UNSIGNED NULL,
        vinculado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_vinculos_usuario (usuario_id),
        CONSTRAINT fk_vinculos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
    ) $opc");
}


/* ---------------------------------------------------------------------
   NORMALIZACIÓN DE TEXTOS (igual criterio que ProgMesas)
   --------------------------------------------------------------------- */

/** "Formación Ética" → "formacion etica". Sirve para comparar nombres escritos distinto. */
function claveTexto(?string $texto): string {
    // Tabla fija (no Normalizer): la clave tiene que dar igual en todos los servidores
    $s = strtr(mb_strtolower((string) $texto, 'UTF-8'), [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
        'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
        'â' => 'a', 'ê' => 'e', 'î' => 'i', 'ô' => 'o', 'û' => 'u', 'ã' => 'a', 'õ' => 'o',
    ]);
    return trim((string) preg_replace('/[^a-z0-9ñ]+/u', ' ', $s));
}

/** Clave de una materia: sin espacios, como el ID de ProgMesas ("educacionmusical"). */
function claveMateria(string $nombre): string {
    return str_replace(' ', '', claveTexto($nombre));
}

/** "4° 1° TAE" → [4, 1, 'TAE']. Devuelve null si el texto no es un curso. */
function interpretarCurso(string $texto): ?array {
    if (!preg_match('/^\s*(\d{1,2})\s*°\s*(\d{1,2})\s*°?\s*([A-Za-z]{0,6})\s*$/u', $texto, $m)) {
        return null;
    }
    return [(int) $m[1], (int) $m[2], strtoupper($m[3])];
}


/* ---------------------------------------------------------------------
   LECTURA DE LA PLANILLA DE PROGMESAS (.xlsx)
   --------------------------------------------------------------------- */

/** Archivos internos de un .xlsx (es un zip). No necesita la extensión zip de PHP. */
function leerZip(string $ruta): array {
    $data = @file_get_contents($ruta);
    if ($data === false) {
        return [];
    }
    $fin = strrpos($data, "PK\x05\x06");
    if ($fin === false) {
        return [];
    }
    $eocd = unpack('vtotal/Vtam/Vinicio', substr($data, $fin + 10, 10));
    $pos = $eocd['inicio'];
    $archivos = [];
    for ($i = 0; $i < $eocd['total']; $i++) {
        if (substr($data, $pos, 4) !== "PK\x01\x02") {
            break;
        }
        $h = unpack('vmetodo/x8/Vcomp/x4/vnom/vextra/vcoment/x8/Vlocal', substr($data, $pos + 10, 36));
        $nombre = substr($data, $pos + 46, $h['nom']);
        $l = unpack('vnom/vextra', substr($data, $h['local'] + 26, 4));
        $crudo = substr($data, $h['local'] + 30 + $l['nom'] + $l['extra'], $h['comp']);
        if ($h['metodo'] === 0) {
            $archivos[$nombre] = $crudo;
        } elseif ($h['metodo'] === 8) {
            $archivos[$nombre] = @gzinflate($crudo);
        }
        $pos += 46 + $h['nom'] + $h['extra'] + $h['coment'];
    }
    return $archivos;
}

/**
 * Filas de la planilla de asignaciones. Cada fila:
 * curso (texto), turno, materia, docentes (lista), requiere (bool), revisar.
 * Lanza RuntimeException si no se puede leer.
 */
function leerPlanillaAsignaciones(string $ruta = PLANILLA_PROGMESAS): array {
    if (!is_file($ruta)) {
        throw new RuntimeException('No se encontró la planilla de ProgMesas (progmesas/backend/data/asignaciones.xlsx).');
    }
    $zip = leerZip($ruta);
    $hoja = isset($zip['xl/worksheets/sheet1.xml']) ? @simplexml_load_string($zip['xl/worksheets/sheet1.xml']) : false;
    if (!$hoja) {
        throw new RuntimeException('No se pudo leer la planilla de ProgMesas.');
    }
    $textos = [];
    if (isset($zip['xl/sharedStrings.xml']) && ($ss = @simplexml_load_string($zip['xl/sharedStrings.xml']))) {
        foreach ($ss->si as $si) {
            $t = isset($si->t) ? (string) $si->t : '';
            foreach ($si->r as $r) {
                $t .= (string) $r->t;
            }
            $textos[] = $t;
        }
    }

    $filas = [];
    foreach ($hoja->sheetData->row as $row) {
        $f = [];
        foreach ($row->c as $c) {
            $col = preg_replace('/\d+/', '', (string) $c['r']);
            $tipo = (string) $c['t'];
            $f[$col] = trim($tipo === 's' ? ($textos[(int) $c->v] ?? '') : ($tipo === 'inlineStr' ? (string) $c->is->t : (string) $c->v));
        }
        $filas[] = $f;
    }
    if (!$filas) {
        return [];
    }

    $columna = [];
    foreach (array_shift($filas) as $col => $titulo) {
        $columna[$titulo] = $col;
    }
    $celda = fn(array $f, string $titulo, string $porDefecto) => $f[$columna[$titulo] ?? $porDefecto] ?? '';

    $resultado = [];
    foreach ($filas as $f) {
        $curso = $celda($f, 'Curso', 'B');
        $materia = $celda($f, 'Materia', 'D');
        if ($curso === '' || $materia === '') {
            continue;
        }
        $resultado[] = [
            'curso'    => $curso,
            'turno'    => $celda($f, 'Turno', 'C'),
            'materia'  => $materia,
            'docentes' => array_values(array_filter(array_map('trim', explode('/', $celda($f, 'Docentes', 'E'))), 'strlen')),
            'requiere' => !preg_match('/^no$/i', $celda($f, 'Requiere programa', 'F') ?: 'SI'),
            'revisar'  => mb_substr($celda($f, 'Revisar', 'H'), 0, 255),
        ];
    }
    return $resultado;
}


/* ---------------------------------------------------------------------
   IMPORTACIÓN: VISTA PREVIA Y APLICACIÓN
   --------------------------------------------------------------------- */

/**
 * Compara la planilla con lo que hay en el campus y arma el plan de importación.
 * No modifica nada. Devuelve:
 *   asignaciones  : lo que va a quedar, por curso_id y clave de materia
 *   materias_nuevas, nuevas, cambios, solo_campus : diferencias para mostrar
 *   cursos_desconocidos, turnos_distintos, sin_docente : avisos
 *   huella        : resumen del plan; se exige al aplicar para que se importe
 *                   exactamente lo que el directivo vio
 */
function planImportacion(PDO $pdo, array $filas): array {
    $cursos = [];
    foreach (listarCursos($pdo, false) as $c) {
        $cursos[$c['anio'] . '-' . $c['division'] . '-' . $c['tecnicatura']] = $c;
    }

    $plan = ['asignaciones' => [], 'materias_nuevas' => [], 'nuevas' => [], 'cambios' => [], 'solo_campus' => [],
             'cursos_desconocidos' => [], 'turnos_distintos' => [], 'sin_docente' => 0, 'filas' => count($filas)];

    foreach ($filas as $f) {
        $p = interpretarCurso($f['curso']);
        $curso = $p ? ($cursos[implode('-', $p)] ?? null) : null;
        if (!$curso) {
            $plan['cursos_desconocidos'][$f['curso']] = ($plan['cursos_desconocidos'][$f['curso']] ?? 0) + 1;
            continue;
        }
        if ($f['turno'] !== '' && claveTexto($f['turno']) !== claveTexto($curso['turno'])) {
            $plan['turnos_distintos'][nombreCurso($curso)] = $f['turno'];
        }
        $clave = claveMateria($f['materia']);
        $k = $curso['id'] . '|' . $clave;
        $a = $plan['asignaciones'][$k] ?? ['curso_id' => (int) $curso['id'], 'curso' => nombreCurso($curso), 'materia' => $f['materia'],
                                           'clave' => $clave, 'requiere' => $f['requiere'], 'revisar' => $f['revisar'], 'docentes' => []];
        foreach ($f['docentes'] as $d) {
            if (($dc = claveTexto($d)) !== '') {
                $a['docentes'][$dc] = $d;
            }
        }
        $plan['asignaciones'][$k] = $a;
    }

    // Lo que ya está en el campus
    $materias = $pdo->query('SELECT clave FROM materias')->fetchAll(PDO::FETCH_COLUMN);
    $actuales = [];
    foreach ($pdo->query("SELECT cm.id, cm.curso_id, cm.requiere_programa, cm.revisar, m.nombre, m.clave,
                                 GROUP_CONCAT(d.docente_clave ORDER BY d.docente_clave SEPARATOR '|') AS docentes
                          FROM curso_materias cm JOIN materias m ON m.id = cm.materia_id
                          LEFT JOIN curso_materia_docentes d ON d.curso_materia_id = cm.id
                          GROUP BY cm.id") as $r) {
        $actuales[$r['curso_id'] . '|' . $r['clave']] = $r;
    }

    $materiasVistas = array_flip($materias);
    foreach ($plan['asignaciones'] as $k => $a) {
        if (!$a['docentes']) {
            $plan['sin_docente']++;
        }
        if (!isset($materiasVistas[$a['clave']])) {
            $materiasVistas[$a['clave']] = true;
            $plan['materias_nuevas'][] = $a['materia'];
        }
        $act = $actuales[$k] ?? null;
        if (!$act) {
            $plan['nuevas'][] = $a;
            continue;
        }
        $antes = $act['docentes'] !== null ? explode('|', $act['docentes']) : [];
        $despues = array_keys($a['docentes']);
        $detalle = [];
        if ($quitar = array_diff($antes, $despues)) {
            $detalle[] = 'deja de figurar: ' . implode(', ', $quitar);
        }
        if ($sumar = array_diff($despues, $antes)) {
            $detalle[] = 'se agrega: ' . implode(', ', array_map(fn($c) => $a['docentes'][$c], $sumar));
        }
        if ((bool) $act['requiere_programa'] !== $a['requiere']) {
            $detalle[] = $a['requiere'] ? 'pasa a requerir programa' : 'deja de requerir programa';
        }
        if ($act['revisar'] !== $a['revisar']) {
            $detalle[] = 'nota: «' . ($a['revisar'] ?: 'sin nota') . '»';
        }
        if ($detalle) {
            $plan['cambios'][] = $a + ['detalle' => $detalle];
        }
    }
    foreach ($actuales as $k => $act) {
        if (!isset($plan['asignaciones'][$k])) {
            $plan['solo_campus'][] = ['id' => (int) $act['id'], 'curso_id' => (int) $act['curso_id'], 'materia' => $act['nombre']];
        }
    }

    $plan['huella'] = hash('sha256', json_encode([array_values($plan['asignaciones']), $plan['solo_campus']]));
    return $plan;
}

/**
 * Aplica el plan en una sola transacción. Agrega materias y asignaciones,
 * actualiza docentes y notas. Lo que está solo en el campus se quita
 * únicamente si $quitarAusentes es true.
 */
function aplicarImportacion(PDO $pdo, array $plan, bool $quitarAusentes): array {
    $pdo->beginTransaction();
    try {
        $insMateria = $pdo->prepare('INSERT IGNORE INTO materias (nombre, clave) VALUES (?, ?)');
        $idMateria  = $pdo->prepare('SELECT id FROM materias WHERE clave = ?');
        $upsert     = $pdo->prepare('INSERT INTO curso_materias (curso_id, materia_id, requiere_programa, revisar) VALUES (?, ?, ?, ?)
                                     ON DUPLICATE KEY UPDATE requiere_programa = VALUES(requiere_programa), revisar = VALUES(revisar)');
        $idCm       = $pdo->prepare('SELECT id FROM curso_materias WHERE curso_id = ? AND materia_id = ?');
        $docentes   = $pdo->prepare('SELECT docente_clave FROM curso_materia_docentes WHERE curso_materia_id = ?');
        $insDocente = $pdo->prepare('INSERT INTO curso_materia_docentes (curso_materia_id, docente_clave, docente_nombre) VALUES (?, ?, ?)
                                     ON DUPLICATE KEY UPDATE docente_nombre = VALUES(docente_nombre)');
        $delDocente = $pdo->prepare('DELETE FROM curso_materia_docentes WHERE curso_materia_id = ? AND docente_clave = ?');

        foreach ($plan['asignaciones'] as $a) {
            $insMateria->execute([$a['materia'], $a['clave']]);
            $idMateria->execute([$a['clave']]);
            $mid = (int) $idMateria->fetchColumn();
            $upsert->execute([$a['curso_id'], $mid, $a['requiere'] ? 1 : 0, $a['revisar']]);
            $idCm->execute([$a['curso_id'], $mid]);
            $cmid = (int) $idCm->fetchColumn();

            $docentes->execute([$cmid]);
            foreach (array_diff($docentes->fetchAll(PDO::FETCH_COLUMN), array_keys($a['docentes'])) as $sobra) {
                $delDocente->execute([$cmid, $sobra]);
            }
            foreach ($a['docentes'] as $clave => $nombre) {
                $insDocente->execute([$cmid, $clave, mb_substr($nombre, 0, 150)]);
            }
        }

        $quitadas = $conservadas = 0;
        if ($quitarAusentes && $plan['solo_campus']) {
            $del = $pdo->prepare('DELETE FROM curso_materias WHERE id = ?');
            foreach ($plan['solo_campus'] as $s) {
                try {
                    $del->execute([$s['id']]);
                    $quitadas += $del->rowCount();
                } catch (PDOException $e) {
                    // 1451: ya tiene asistencias tomadas; se conserva para no perder el historial
                    if ((int) ($e->errorInfo[1] ?? 0) !== 1451) {
                        throw $e;
                    }
                    $conservadas++;
                }
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['materias' => count($plan['materias_nuevas']), 'nuevas' => count($plan['nuevas']),
            'cambios' => count($plan['cambios']), 'quitadas' => $quitadas, 'conservadas' => $conservadas];
}


/* ---------------------------------------------------------------------
   CONSULTAS
   --------------------------------------------------------------------- */

/** Cantidad de materias por curso: [curso_id => n]. */
function materiasPorCurso(PDO $pdo): array {
    return $pdo->query('SELECT curso_id, COUNT(*) FROM curso_materias GROUP BY curso_id')->fetchAll(PDO::FETCH_KEY_PAIR);
}

/**
 * Materias de un curso con sus docentes. Cada docente trae, si está vinculado,
 * el nombre de la cuenta del campus.
 */
function materiasDeCurso(PDO $pdo, int $cursoId): array {
    $st = $pdo->prepare("SELECT cm.id, m.nombre AS materia, cm.requiere_programa, cm.revisar,
                                d.docente_nombre, d.docente_clave, u.nombre AS cuenta
                         FROM curso_materias cm JOIN materias m ON m.id = cm.materia_id
                         LEFT JOIN curso_materia_docentes d ON d.curso_materia_id = cm.id
                         LEFT JOIN docentes_vinculos v ON v.docente_clave = d.docente_clave
                         LEFT JOIN usuarios u ON u.id = v.usuario_id
                         WHERE cm.curso_id = ?
                         ORDER BY m.nombre, d.docente_nombre");
    $st->execute([$cursoId]);
    $materias = [];
    foreach ($st as $r) {
        $materias[$r['id']] ??= ['id' => (int) $r['id'], 'materia' => $r['materia'], 'requiere' => (bool) $r['requiere_programa'],
                                 'revisar' => $r['revisar'], 'docentes' => []];
        if ($r['docente_clave'] !== null) {
            $materias[$r['id']]['docentes'][] = ['nombre' => $r['docente_nombre'], 'clave' => $r['docente_clave'], 'cuenta' => $r['cuenta']];
        }
    }
    return array_values($materias);
}

/** Materias y cursos de un profesor, según los docentes de la planilla vinculados a su cuenta. */
function materiasDeProfesor(PDO $pdo, int $usuarioId): array {
    $st = $pdo->prepare("SELECT DISTINCT cm.id, cm.curso_id, m.nombre AS materia, c.anio, c.division, c.tecnicatura, c.turno
                         FROM docentes_vinculos v
                         JOIN curso_materia_docentes d ON d.docente_clave = v.docente_clave
                         JOIN curso_materias cm ON cm.id = d.curso_materia_id
                         JOIN materias m ON m.id = cm.materia_id
                         JOIN cursos c ON c.id = cm.curso_id
                         WHERE v.usuario_id = ?
                         ORDER BY c.turno, c.anio, c.division, c.tecnicatura, m.nombre");
    $st->execute([$usuarioId]);
    return $st->fetchAll();
}

/** ¿Las palabras del docente de la planilla ("Anderson F.") están en el nombre de la cuenta? */
function docenteCoincideCon(string $docenteClave, string $nombreCuenta): bool {
    $cuenta = explode(' ', claveTexto($nombreCuenta));
    foreach (explode(' ', $docenteClave) as $palabra) {
        $ok = strlen($palabra) === 1
            ? (bool) array_filter($cuenta, fn($w) => $w !== '' && $w[0] === $palabra)   // inicial
            : in_array($palabra, $cuenta, true);
        if (!$ok) {
            return false;
        }
    }
    return $docenteClave !== '';
}

/**
 * Docentes de la planilla con su vínculo y las cuentas de profesor que podrían
 * ser esa persona (sugerencias). Un directivo confirma cada vínculo.
 */
function docentesEsperados(PDO $pdo): array {
    $profesores = $pdo->query("SELECT u.id, u.nombre FROM usuarios u JOIN usuario_roles r ON r.usuario_id = u.id AND r.rol = 'profesor'
                               ORDER BY u.nombre")->fetchAll();
    $filas = $pdo->query("SELECT d.docente_clave, MIN(d.docente_nombre) AS nombre, COUNT(DISTINCT d.curso_materia_id) AS materias,
                                 v.usuario_id, u.nombre AS cuenta
                          FROM curso_materia_docentes d
                          LEFT JOIN docentes_vinculos v ON v.docente_clave = d.docente_clave
                          LEFT JOIN usuarios u ON u.id = v.usuario_id
                          GROUP BY d.docente_clave, v.usuario_id, u.nombre
                          ORDER BY nombre")->fetchAll();
    foreach ($filas as &$f) {
        $f['sugeridos'] = $f['usuario_id'] ? [] : array_values(array_filter($profesores, fn($p) => docenteCoincideCon($f['docente_clave'], $p['nombre'])));
    }
    unset($f);
    return $filas;
}

/** "1° 1° · Mañana — Geografía" de cada docente de la planilla: [docente_clave => [textos]]. */
function asignacionesPorDocente(PDO $pdo): array {
    $res = [];
    foreach ($pdo->query('SELECT d.docente_clave, m.nombre AS materia, c.anio, c.division, c.tecnicatura, c.turno
                          FROM curso_materia_docentes d JOIN curso_materias cm ON cm.id = d.curso_materia_id
                          JOIN materias m ON m.id = cm.materia_id JOIN cursos c ON c.id = cm.curso_id
                          ORDER BY c.turno, c.anio, c.division, c.tecnicatura, m.nombre') as $r) {
        $res[$r['docente_clave']][] = nombreCurso($r) . ' — ' . $r['materia'];
    }
    return $res;
}

/** Docentes de la planilla sin vincular que podrían ser esta cuenta (al crear un profesor). */
function sugerenciasParaProfesor(PDO $pdo, int $usuarioId): array {
    $u = buscarUsuarioPor($pdo, 'id', $usuarioId);
    if (!$u) {
        return [];
    }
    $sueltos = $pdo->query('SELECT DISTINCT d.docente_clave, d.docente_nombre FROM curso_materia_docentes d
                            LEFT JOIN docentes_vinculos v ON v.docente_clave = d.docente_clave
                            WHERE v.usuario_id IS NULL')->fetchAll();
    return array_values(array_filter($sueltos, fn($d) => docenteCoincideCon($d['docente_clave'], $u['nombre'])));
}

function vincularDocente(PDO $pdo, string $docenteClave, int $usuarioId, int $quien): void {
    $st = $pdo->prepare('SELECT 1 FROM curso_materia_docentes WHERE docente_clave = ? LIMIT 1');
    $st->execute([$docenteClave]);
    if ($st->fetchColumn() === false) {
        throw new RuntimeException('Ese docente no figura en las materias importadas.');
    }
    if (!in_array('profesor', rolesDeUsuario($pdo, $usuarioId), true)) {
        throw new RuntimeException('Solo se puede vincular con una cuenta que tenga el rol de profesor.');
    }
    $pdo->prepare('INSERT INTO docentes_vinculos (docente_clave, usuario_id, vinculado_por) VALUES (?, ?, ?)
                   ON DUPLICATE KEY UPDATE usuario_id = VALUES(usuario_id), vinculado_por = VALUES(vinculado_por), vinculado_en = CURRENT_TIMESTAMP')
        ->execute([$docenteClave, $usuarioId, $quien ?: null]);
}

function desvincularDocente(PDO $pdo, string $docenteClave): void {
    $pdo->prepare('DELETE FROM docentes_vinculos WHERE docente_clave = ?')->execute([$docenteClave]);
}

function auditarEstructura(string $accion): void {
    $quien = ($_SESSION['correo'] ?? '') ?: ($_SESSION['nombre'] ?? 'sistema');
    error_log('[CAMPUS ESTRUCTURA] ' . preg_replace('/[\x00-\x1F\x7F]+/u', ' ', "$quien: $accion"));
}
