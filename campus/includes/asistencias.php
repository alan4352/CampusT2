<?php
/* =====================================================================
   ARCHIVO: includes/asistencias.php
   PARA QUÉ SIRVE: módulo de asistencias del campus (etapa 3), tomado del
   sistema de asistencias (PROJECT_ASIST) y pasado a MySQL.

   - asistencias          : una fila por alumno, día y materia.
                            curso_materia_id NULL = asistencia GENERAL del
                            día, la que toma el preceptor.
                            estado: presente, tarde (con hora de llegada) o
                            ausente; justificada la marca el preceptor o un
                            directivo.
   - alertas_descartadas  : alertas de inasistencias que el preceptor ya vio.

   REGLAS (las controla este archivo en el servidor, no el navegador):
   - Solo se toma asistencia del DÍA DE HOY, con la fecha del servidor.
     Las de días anteriores no se pueden cambiar; solo justificar.
   - El profesor toma asistencia solo en las materias vinculadas a su cuenta
     (estructura académica); el preceptor, la general de sus cursos.
   - Justifican el preceptor del curso y los directivos.
   ===================================================================== */
require_once __DIR__ . '/academico.php';

date_default_timezone_set('America/Argentina/Buenos_Aires');

const ESTADOS_ASISTENCIA = ['presente' => 'Presente', 'tarde' => 'Tarde', 'ausente' => 'Ausente'];
const ALERTA_DIAS        = 7;   // ventana para las alertas
const ALERTA_FALTAS      = 3;   // días con falta sin justificar dentro de la ventana
const LIMITE_FILAS       = 3000;


/* ---------------------------------------------------------------------
   CONEXIÓN Y ESTRUCTURA
   --------------------------------------------------------------------- */

function bdAsistencias(): PDO {
    $pdo = bdAcademico();
    if (!migracionAplicada($pdo, 3)) {
        $pdo->query("SELECT GET_LOCK('campus_migracion_cuentas', 30)");
        try {
            if (!migracionAplicada($pdo, 3)) {
                migracionAsistenciasV3($pdo);
                $pdo->prepare('INSERT INTO campus_migraciones (version) VALUES (3)')->execute();
            }
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('campus_migracion_cuentas')");
        }
    }
    // MySQL trabaja con la misma fecha y hora que PHP (Argentina)
    $pdo->exec("SET time_zone = '" . date('P') . "'");
    return $pdo;
}

function migracionAsistenciasV3(PDO $pdo): void {
    $opc = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    // materia_clave = 0 para la asistencia general: permite la clave única (MySQL no compara NULL)
    $pdo->exec("CREATE TABLE IF NOT EXISTS asistencias (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        alumno_id INT UNSIGNED NOT NULL,
        curso_id INT UNSIGNED NOT NULL,
        curso_materia_id INT UNSIGNED NULL,
        materia_clave INT UNSIGNED AS (IFNULL(curso_materia_id, 0)) STORED,
        fecha DATE NOT NULL,
        estado ENUM('presente','tarde','ausente') NOT NULL,
        hora_llegada TIME NULL,
        justificada TINYINT(1) NOT NULL DEFAULT 0,
        justificada_por INT UNSIGNED NULL,
        justificada_en DATETIME NULL,
        tomada_por INT UNSIGNED NULL,
        creada_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        actualizada_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_asistencia (alumno_id, curso_id, materia_clave, fecha),
        KEY idx_asist_curso_fecha (curso_id, fecha),
        KEY idx_asist_alumno_fecha (alumno_id, fecha),
        CONSTRAINT fk_asist_alumno FOREIGN KEY (alumno_id) REFERENCES usuarios(id) ON DELETE CASCADE,
        CONSTRAINT fk_asist_curso FOREIGN KEY (curso_id) REFERENCES cursos(id),
        CONSTRAINT fk_asist_cm FOREIGN KEY (curso_materia_id) REFERENCES curso_materias(id),
        CONSTRAINT fk_asist_tomada FOREIGN KEY (tomada_por) REFERENCES usuarios(id) ON DELETE SET NULL
    ) $opc");

    $pdo->exec("CREATE TABLE IF NOT EXISTS alertas_descartadas (
        preceptor_id INT UNSIGNED NOT NULL,
        alumno_id INT UNSIGNED NOT NULL,
        descartada_en DATE NOT NULL,
        PRIMARY KEY (preceptor_id, alumno_id),
        CONSTRAINT fk_alerta_prec FOREIGN KEY (preceptor_id) REFERENCES usuarios(id) ON DELETE CASCADE,
        CONSTRAINT fk_alerta_alumno FOREIGN KEY (alumno_id) REFERENCES usuarios(id) ON DELETE CASCADE
    ) $opc");
}

function hoy(): string {
    return date('Y-m-d');
}

function fechaValida(string $f): ?string {
    $d = DateTime::createFromFormat('!Y-m-d', $f);
    return $d && $d->format('Y-m-d') === $f ? $f : null;
}


/* ---------------------------------------------------------------------
   QUIÉN TOMA ASISTENCIA DE QUÉ
   --------------------------------------------------------------------- */

/**
 * Lo que esta cuenta puede tomar, según sus roles:
 *   [clave => ['curso_id', 'cm_id' (null = general), 'curso', 'materia', 'etiqueta']]
 * Claves: "g-<curso>" (general, preceptor) y "m-<curso_materia>" (materia, profesor).
 */
function opcionesToma(PDO $pdo, int $uid): array {
    $op = [];
    $roles = rolesDeUsuario($pdo, $uid);
    if (in_array('preceptor', $roles, true)) {
        foreach (cursosDePreceptor($pdo, $uid) as $c) {
            $op['g-' . $c['id']] = ['curso_id' => (int) $c['id'], 'cm_id' => null, 'curso' => nombreCurso($c),
                                    'materia' => 'Asistencia general', 'etiqueta' => nombreCurso($c) . ' — Asistencia general'];
        }
    }
    if (in_array('profesor', $roles, true)) {
        foreach (materiasDeProfesor($pdo, $uid) as $m) {
            $op['m-' . $m['id']] = ['curso_id' => (int) $m['curso_id'], 'cm_id' => (int) $m['id'], 'curso' => nombreCurso($m),
                                    'materia' => $m['materia'], 'etiqueta' => nombreCurso($m) . ' — ' . $m['materia']];
        }
    }
    return $op;
}

/** Alumnos del curso para la planilla (cuentas con rol alumno y ese curso). */
function alumnosParaToma(PDO $pdo, int $cursoId): array {
    return array_values(array_filter(alumnosDeCurso($pdo, $cursoId), fn($a) => $a['estado'] !== 'bloqueado'));
}

/** Lo ya cargado ese día para ese curso y materia: [alumno_id => fila]. */
function asistenciasDelDia(PDO $pdo, int $cursoId, ?int $cmId, string $fecha): array {
    $st = $pdo->prepare('SELECT alumno_id, estado, hora_llegada, justificada FROM asistencias
                         WHERE curso_id = ? AND materia_clave = ? AND fecha = ?');
    $st->execute([$cursoId, $cmId ?? 0, $fecha]);
    $res = [];
    foreach ($st as $r) {
        $res[(int) $r['alumno_id']] = $r;
    }
    return $res;
}

/**
 * Guarda la planilla de HOY. $datos = [alumno_id => ['estado' => …, 'hora' => 'HH:MM']].
 * Un alumno del curso que no viene en $datos queda ausente (como en el sistema original).
 * Devuelve cuántos alumnos se guardaron.
 */
function guardarToma(PDO $pdo, int $uid, string $clave, array $datos): int {
    $op = opcionesToma($pdo, $uid)[$clave] ?? null;
    if (!$op) {
        throw new RuntimeException('No puede tomar asistencia en ese curso o materia.');
    }
    $alumnos = alumnosParaToma($pdo, $op['curso_id']);
    if (!$alumnos) {
        throw new RuntimeException('El curso no tiene alumnos cargados.');
    }
    $fecha = hoy();
    $ahora = date('H:i');
    $st = $pdo->prepare('INSERT INTO asistencias (alumno_id, curso_id, curso_materia_id, fecha, estado, hora_llegada, tomada_por)
                         VALUES (?, ?, ?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE estado = VALUES(estado), hora_llegada = VALUES(hora_llegada), tomada_por = VALUES(tomada_por)');
    $pdo->beginTransaction();
    try {
        foreach ($alumnos as $a) {
            $d = $datos[$a['id']] ?? [];
            $estado = array_key_exists($d['estado'] ?? '', ESTADOS_ASISTENCIA) ? $d['estado'] : 'ausente';
            $hora = null;
            if ($estado === 'tarde') {
                $hora = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) ($d['hora'] ?? '')) ? $d['hora'] : $ahora;
            }
            $st->execute([(int) $a['id'], $op['curso_id'], $op['cm_id'], $fecha, $estado, $hora, $uid]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return count($alumnos);
}


/* ---------------------------------------------------------------------
   JUSTIFICAR
   --------------------------------------------------------------------- */

/** ¿Esta cuenta puede justificar en ese curso? Directivos: todos; preceptores: sus cursos. */
function puedeJustificar(PDO $pdo, int $uid, int $cursoId): bool {
    $roles = rolesDeUsuario($pdo, $uid);
    if (in_array('directivo', $roles, true)) {
        return true;
    }
    if (in_array('preceptor', $roles, true)) {
        return in_array($cursoId, array_map(fn($c) => (int) $c['id'], cursosDePreceptor($pdo, $uid)), true);
    }
    return false;
}

/** Marca o desmarca la justificación. Es lo único que se puede cambiar de un día anterior. */
function justificarAsistencia(PDO $pdo, int $uid, int $asistenciaId, bool $justificada): array {
    $st = $pdo->prepare("SELECT a.id, a.curso_id, a.estado, a.fecha, u.nombre FROM asistencias a JOIN usuarios u ON u.id = a.alumno_id WHERE a.id = ?");
    $st->execute([$asistenciaId]);
    $a = $st->fetch();
    if (!$a || !puedeJustificar($pdo, $uid, (int) $a['curso_id'])) {
        throw new RuntimeException('No puede justificar esa asistencia.');
    }
    if ($a['estado'] === 'presente') {
        throw new RuntimeException('Solo se justifican ausencias y llegadas tarde.');
    }
    $pdo->prepare('UPDATE asistencias SET justificada = ?, justificada_por = ?, justificada_en = ? WHERE id = ?')
        ->execute([$justificada ? 1 : 0, $justificada ? $uid : null, $justificada ? date('Y-m-d H:i:s') : null, $asistenciaId]);
    return $a;
}


/* ---------------------------------------------------------------------
   CONSULTAS Y REPORTES
   --------------------------------------------------------------------- */

/**
 * Alcance de lo que una cuenta puede consultar:
 *   ['todo' => true] directivo · ['cursos' => [...]] preceptor ·
 *   ['materias' => [...]] profesor · ['alumno' => id] alumno.
 * Una persona con varios roles suma sus alcances (OR).
 */
function alcanceConsulta(PDO $pdo, int $uid): array {
    $roles = rolesDeUsuario($pdo, $uid);
    if (in_array('directivo', $roles, true)) {
        return ['todo' => true];
    }
    $al = [];
    if (in_array('preceptor', $roles, true)) {
        $al['cursos'] = array_map(fn($c) => (int) $c['id'], cursosDePreceptor($pdo, $uid));
    }
    if (in_array('profesor', $roles, true)) {
        $al['materias'] = array_map(fn($m) => (int) $m['id'], materiasDeProfesor($pdo, $uid));
    }
    if (in_array('alumno', $roles, true)) {
        $al['alumno'] = $uid;
    }
    return $al;
}

/** Condición SQL del alcance (con sus parámetros). Sin alcance → no ve nada. */
function sqlAlcance(array $al, array &$p): string {
    if (!empty($al['todo'])) {
        return '1=1';
    }
    $o = [];
    if (!empty($al['cursos'])) {
        $o[] = 'a.curso_id IN (' . implode(',', array_fill(0, count($al['cursos']), '?')) . ')';
        array_push($p, ...$al['cursos']);
    }
    if (!empty($al['materias'])) {
        $o[] = 'a.curso_materia_id IN (' . implode(',', array_fill(0, count($al['materias']), '?')) . ')';
        array_push($p, ...$al['materias']);
    }
    if (!empty($al['alumno'])) {
        $o[] = 'a.alumno_id = ?';
        $p[] = $al['alumno'];
    }
    return $o ? '(' . implode(' OR ', $o) . ')' : '1=0';
}

/**
 * Filtros que vienen del formulario, ya validados:
 * curso, materia ('' todas, 'g' general, o id de curso_materia), alumno, desde, hasta, estado, justificada ('', 'si', 'no').
 */
function filtrosAsistencia(array $q): array {
    $mat = (string) ($q['materia'] ?? '');
    return [
        'curso'   => max(0, (int) ($q['curso'] ?? 0)),
        'materia' => $mat === 'g' ? 'g' : (ctype_digit($mat) ? (int) $mat : ''),
        'alumno'  => max(0, (int) ($q['alumno'] ?? 0)),
        'desde'   => fechaValida((string) ($q['desde'] ?? '')) ?? date('Y-m-01'),
        'hasta'   => fechaValida((string) ($q['hasta'] ?? '')) ?? hoy(),
        'estado'  => array_key_exists($q['estado'] ?? '', ESTADOS_ASISTENCIA) ? $q['estado'] : '',
        'just'    => in_array($q['just'] ?? '', ['si', 'no'], true) ? $q['just'] : '',
    ];
}

/** Registros que coinciden con los filtros dentro del alcance, más el resumen. */
function buscarAsistencias(PDO $pdo, array $al, array $f): array {
    $p = [];
    $w = [sqlAlcance($al, $p), 'a.fecha BETWEEN ? AND ?'];
    array_push($p, $f['desde'], $f['hasta']);
    if ($f['curso'])            { $w[] = 'a.curso_id = ?';         $p[] = $f['curso']; }
    if ($f['materia'] === 'g')  { $w[] = 'a.curso_materia_id IS NULL'; }
    elseif ($f['materia'])      { $w[] = 'a.curso_materia_id = ?'; $p[] = $f['materia']; }
    if ($f['alumno'])           { $w[] = 'a.alumno_id = ?';        $p[] = $f['alumno']; }
    if ($f['estado'])           { $w[] = 'a.estado = ?';           $p[] = $f['estado']; }
    if ($f['just'] !== '')      { $w[] = 'a.justificada = ' . ($f['just'] === 'si' ? 1 : 0); }

    $st = $pdo->prepare('SELECT a.id, a.fecha, a.estado, a.hora_llegada, a.justificada, a.curso_id, a.curso_materia_id, a.alumno_id,
                                u.nombre AS alumno, u.dni, m.nombre AS materia, t.nombre AS tomada_por,
                                c.anio, c.division, c.tecnicatura, c.turno
                         FROM asistencias a
                         JOIN usuarios u ON u.id = a.alumno_id
                         JOIN cursos c ON c.id = a.curso_id
                         LEFT JOIN curso_materias cm ON cm.id = a.curso_materia_id
                         LEFT JOIN materias m ON m.id = cm.materia_id
                         LEFT JOIN usuarios t ON t.id = a.tomada_por
                         WHERE ' . implode(' AND ', $w) . '
                         ORDER BY a.fecha DESC, c.anio, c.division, u.nombre, m.nombre
                         LIMIT ' . (LIMITE_FILAS + 1));
    $st->execute($p);
    $filas = $st->fetchAll();
    $cortado = count($filas) > LIMITE_FILAS;
    $filas = array_slice($filas, 0, LIMITE_FILAS);
    return ['filas' => $filas, 'cortado' => $cortado, 'resumen' => resumenAsistencias($filas), 'por_alumno' => resumenPorAlumno($filas)];
}

/** Totales como en el sistema original: % de asistencia = (presentes + tardes) / registros. */
function resumenAsistencias(array $filas): array {
    $r = ['total' => count($filas), 'presente' => 0, 'tarde' => 0, 'ausente' => 0, 'justificadas' => 0];
    foreach ($filas as $f) {
        $r[$f['estado']]++;
        $r['justificadas'] += (int) $f['justificada'];
    }
    $r['porcentaje'] = $r['total'] ? round(($r['presente'] + $r['tarde']) * 100 / $r['total'], 1) : 0;
    return $r;
}

/** Totales por alumno, de más faltas a menos. */
function resumenPorAlumno(array $filas): array {
    $por = [];
    foreach ($filas as $f) {
        $por[$f['alumno_id']]['alumno'] = $f['alumno'];
        $por[$f['alumno_id']]['curso'] = nombreCurso($f);
        $por[$f['alumno_id']]['filas'][] = $f;
    }
    $res = [];
    foreach ($por as $id => $x) {
        $res[$id] = ['alumno' => $x['alumno'], 'curso' => $x['curso']] + resumenAsistencias($x['filas']);
    }
    uasort($res, fn($a, $b) => [$b['ausente'] - $b['justificadas'], $a['alumno']] <=> [$a['ausente'] - $a['justificadas'], $b['alumno']]);
    return $res;
}

/** Alumnos de los cursos (o del alcance) para el filtro. */
function alumnosDeCursos(PDO $pdo, array $cursoIds): array {
    $res = [];
    foreach ($cursoIds as $c) {
        foreach (alumnosDeCurso($pdo, (int) $c) as $a) {
            $res[(int) $a['id']] = $a['nombre'];
        }
    }
    asort($res);
    return $res;
}


/* ---------------------------------------------------------------------
   ALERTAS DEL PRECEPTOR
   --------------------------------------------------------------------- */

/**
 * Alumnos de los cursos del preceptor con ALERTA_FALTAS o más días con alguna
 * falta sin justificar en los últimos ALERTA_DIAS días. Una alerta descartada
 * vuelve a aparecer si el alumno falta de nuevo después de descartarla.
 */
function alertasPreceptor(PDO $pdo, int $preceptorId): array {
    $st = $pdo->prepare("SELECT a.alumno_id, u.nombre, a.curso_id, c.anio, c.division, c.tecnicatura, c.turno,
                                COUNT(DISTINCT a.fecha) AS dias, MAX(a.fecha) AS ultima
                         FROM asistencias a
                         JOIN preceptor_cursos pc ON pc.curso_id = a.curso_id AND pc.usuario_id = ?
                         JOIN usuarios u ON u.id = a.alumno_id
                         JOIN cursos c ON c.id = a.curso_id
                         LEFT JOIN alertas_descartadas d ON d.preceptor_id = ? AND d.alumno_id = a.alumno_id
                         WHERE a.estado = 'ausente' AND a.justificada = 0 AND a.fecha >= ?
                         GROUP BY a.alumno_id, u.nombre, a.curso_id, c.anio, c.division, c.tecnicatura, c.turno, d.descartada_en
                         HAVING dias >= ? AND (MAX(d.descartada_en) IS NULL OR MAX(a.fecha) > MAX(d.descartada_en))
                         ORDER BY dias DESC, u.nombre");
    $st->execute([$preceptorId, $preceptorId, date('Y-m-d', strtotime('-' . (ALERTA_DIAS - 1) . ' days')), ALERTA_FALTAS]);
    return $st->fetchAll();
}

function descartarAlerta(PDO $pdo, int $preceptorId, int $alumnoId): void {
    if (!preceptorGestionaAlumno($pdo, $preceptorId, $alumnoId)) {
        throw new RuntimeException('Ese alumno no es de uno de sus cursos.');
    }
    $pdo->prepare('INSERT INTO alertas_descartadas (preceptor_id, alumno_id, descartada_en) VALUES (?, ?, ?)
                   ON DUPLICATE KEY UPDATE descartada_en = VALUES(descartada_en)')->execute([$preceptorId, $alumnoId, hoy()]);
}

function auditarAsistencias(string $accion): void {
    $quien = ($_SESSION['correo'] ?? '') ?: ($_SESSION['nombre'] ?? 'sistema');
    error_log('[CAMPUS ASISTENCIAS] ' . preg_replace('/[\x00-\x1F\x7F]+/u', ' ', "$quien: $accion"));
}

/** Etiqueta de estado con el color del sistema de asistencias. */
function etiquetaEstado(string $estado, bool $justificada = false): string {
    $cls = ['presente' => 'bg-success', 'tarde' => 'bg-warning', 'ausente' => 'bg-danger'][$estado] ?? 'bg-secondary';
    return '<span class="badge ' . $cls . '">' . e(ESTADOS_ASISTENCIA[$estado] ?? $estado) . '</span>'
         . ($justificada ? ' <span class="badge bg-info">Justificada</span>' : '');
}
