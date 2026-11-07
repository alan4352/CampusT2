<?php
// Archivo de conexión principal del módulo de Mesas de Examen.
// Se encarga de traer la sesión activa del Campus Virtual, conectar con la base de datos
// y comprobar qué rol tiene el usuario que entró (alumno o directivo).

// Buscamos la carpeta includes del campus según dónde esté guardado el proyecto
if (is_file(__DIR__ . '/../campus/includes/sesion.php')) {
    require_once __DIR__ . '/../campus/includes/sesion.php';
    require_once __DIR__ . '/../campus/includes/db.php';
    if (is_file(__DIR__ . '/../campus/includes/datos.php')) {
        require_once __DIR__ . '/../campus/includes/datos.php';
    }
    define('URL_CAMPUS', '../campus/login.php');
    define('URL_PANEL_CAMPUS', '../campus/dashboard.php');
    define('URL_LOGOUT_CAMPUS', '../campus/logout.php');
} elseif (is_file(__DIR__ . '/../includes/sesion.php')) {
    require_once __DIR__ . '/../includes/sesion.php';
    require_once __DIR__ . '/../includes/db.php';
    if (is_file(__DIR__ . '/../includes/datos.php')) {
        require_once __DIR__ . '/../includes/datos.php';
    }
    define('URL_CAMPUS', '../login.php');
    define('URL_PANEL_CAMPUS', '../dashboard.php');
    define('URL_LOGOUT_CAMPUS', '../logout.php');
} elseif (is_file(__DIR__ . '/includes/sesion.php')) {
    require_once __DIR__ . '/includes/sesion.php';
    require_once __DIR__ . '/includes/db.php';
    if (is_file(__DIR__ . '/includes/datos.php')) {
        require_once __DIR__ . '/includes/datos.php';
    }
    define('URL_CAMPUS', 'login.php');
    define('URL_PANEL_CAMPUS', 'dashboard.php');
    define('URL_LOGOUT_CAMPUS', 'logout.php');
} else {
    die('Error crítico: No se encontró la carpeta includes/ del Campus Virtual.');
}

// Abre la conexión con la base de datos y se asegura de que las tablas estén creadas
function conectar(): PDO
{
    static $preparada = false;
    $pdo = conectarBD();

    if (!$preparada) {
        try {
            asegurar_estructura($pdo);
        } catch (Throwable $e) {
            error_log('conexion.php (estructura mesas): ' . $e->getMessage());
        }
        $preparada = true;
    }
    return $pdo;
}

// Revisa si el usuario tiene permiso para entrar a Mesas y devuelve sus datos según su rol
function usuario_mesas(?string $rolRequerido = null): ?array
{
    if (!tienePermiso('mesa')) {
        return null;
    }

    $rol    = (string) ($_SESSION['rol'] ?? '');
    $nombre = trim((string) ($_SESSION['nombre'] ?? ''));

    if ($rolRequerido !== null && $rol !== $rolRequerido) {
        return null;
    }

    if ($rol === 'directivo') {
        return [
            'rol'    => 'directivo',
            'nombre' => $nombre,
        ];
    }

    if ($rol === 'alumno') {
        // El DNI sale de la sesión (login.php guarda id = "alumno:<DNI>"), nunca del nombre ni del correo:
        // dos alumnos pueden llamarse igual o compartir correo (la columna correo no es única).
        $dniSesion = preg_match('/^alumno:(\d{7,8})$/', (string) ($_SESSION['id'] ?? ''), $m) ? $m[1] : '';
        if ($dniSesion === '' && preg_match('/^\d{7,8}$/', (string) ($_SESSION['dni'] ?? ''))) {
            $dniSesion = (string) $_SESSION['dni'];
        }
        if ($dniSesion === '') {
            return ['id' => null, 'rol' => 'alumno', 'nombre' => $nombre, 'dni' => '', 'correo' => ''];
        }

        try {
            $st = conectar()->prepare('SELECT id, nombre, dni, correo FROM alumnos WHERE dni = ? LIMIT 1');
            $st->execute([$dniSesion]);
            $fila = $st->fetch();
            if ($fila !== false) {
                return [
                    'id'     => (int) $fila['id'],
                    'rol'    => 'alumno',
                    'nombre' => (string) $fila['nombre'],
                    'dni'    => (string) ($fila['dni'] ?? ''),
                    'correo' => (string) ($fila['correo'] ?? ''),
                ];
            }
        } catch (Throwable $e) {
            error_log('conexion.php (consulta alumno): ' . $e->getMessage());
        }

        // Si es uno de los alumnos de prueba cargados en datos.php, tomamos sus datos de ahí
        global $ALUMNOS;
        if (isset($ALUMNOS) && is_array($ALUMNOS)) {
            foreach ($ALUMNOS as $idx => $al) {
                if ((string) ($al['dni'] ?? '') === $dniSesion) {
                    return [
                        'id'     => $idx + 1,
                        'rol'    => 'alumno',
                        'nombre' => (string) $al['nombre'],
                        'dni'    => (string) ($al['dni'] ?? ''),
                        'correo' => (string) ($al['correo'] ?? ''),
                    ];
                }
            }
        }

        return [
            'id'     => null,
            'rol'    => 'alumno',
            'nombre' => $nombre,
            'dni'    => '',
            'correo' => '',
        ];
    }

    return null;
}

/** CSRF: lo que modifica datos solo se acepta desde las páginas del módulo, que mandan la cabecera
 *  X-Requested-With (otro sitio no puede agregarla sin CORS, que este servidor no habilita). */
function exigir_peticion_propia(): void
{
    $m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array($m, ['GET', 'HEAD', 'OPTIONS'], true)) {
        return;
    }
    $cabecera = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
    if (!in_array($cabecera, ['preinscripciones', 'XMLHttpRequest'], true)) {
        responder_json(['success' => false, 'error' => 'Petición no permitida'], 403);
    }
}

// Función que usamos en api.php para bloquear peticiones si no hay sesión iniciada
function exigir_usuario_api(bool $soloDirectivo = false): array
{
    exigir_peticion_propia();
    $u = null;
    try {
        $u = usuario_mesas();
    } catch (Throwable $e) {
        error_log('conexion.php (exigir_usuario_api): ' . $e->getMessage());
        if (tienePermiso('mesa')) {
            $u = [
                'rol'    => (string) $_SESSION['rol'],
                'nombre' => trim((string) $_SESSION['nombre']),
            ];
        }
    }
    session_write_close();

    if ($u === null) {
        responder_json(['success' => false, 'error' => 'Sesión no iniciada o sin permiso', 'login' => true], 401);
    }
    if ($soloDirectivo && $u['rol'] !== 'directivo') {
        responder_json(['success' => false, 'error' => 'Solo el personal directivo/administrativo puede realizar esta operación'], 403);
    }
    return $u;
}

// Estructura de las tablas que necesita el sistema de mesas en MySQL
function sql_tablas_mesas(): array
{
    return [
        <<<'SQL'
CREATE TABLE IF NOT EXISTS `configuracion` (
  `clave` varchar(50) NOT NULL,
  `valor` text DEFAULT NULL,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS `alumnos_inscripciones` (
  `id` varchar(50) NOT NULL,
  `alumno_id` int DEFAULT NULL,
  `student_name` varchar(150) NOT NULL,
  `student_dni` varchar(20) NOT NULL,
  `student_course` varchar(50) NOT NULL,
  `student_phone` varchar(50) DEFAULT NULL,
  `student_email` varchar(150) DEFAULT NULL,
  `paid` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ai_alumno_id` (`alumno_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS `inscripcion_materias` (
  `id` int NOT NULL AUTO_INCREMENT,
  `inscripcion_id` varchar(50) NOT NULL,
  `subject_name` varchar(150) NOT NULL,
  `subject_year` varchar(50) NOT NULL,
  `subject_family` varchar(100) DEFAULT NULL,
  `exam_turn` varchar(50) DEFAULT 'Febrero / Marzo',
  `paid` tinyint(1) DEFAULT 0,
  `grade` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `inscripcion_id` (`inscripcion_id`),
  CONSTRAINT `fk_inscripcion_materias` FOREIGN KEY (`inscripcion_id`) REFERENCES `alumnos_inscripciones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS `mesas_examen` (
  `id` varchar(50) NOT NULL,
  `subject_name` varchar(255) NOT NULL,
  `course` varchar(100) NOT NULL,
  `specialty` varchar(50) DEFAULT '',
  `exam_turn` varchar(50) NOT NULL,
  `exam_date` date NOT NULL,
  `exam_time` varchar(20) NOT NULL,
  `classroom` varchar(100) DEFAULT 'Por determinar',
  `pres_docente` varchar(150) DEFAULT NULL,
  `v1_docente` varchar(150) DEFAULT NULL,
  `v2_docente` varchar(150) DEFAULT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    ];
}

/** Crea las tablas del módulo si faltan e inicializa los valores por defecto de configuración. */
function asegurar_estructura(PDO $pdo): void
{
    foreach (sql_tablas_mesas() as $sql) {
        $pdo->exec($sql);
    }

    try {
        $cols = $pdo->query("DESCRIBE alumnos_inscripciones")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('alumno_id', $cols, true)) {
            $pdo->exec("ALTER TABLE alumnos_inscripciones ADD COLUMN alumno_id INT NULL DEFAULT NULL AFTER id, ADD INDEX idx_ai_alumno_id (alumno_id)");
        }
    } catch (Throwable $e) {
    }

    // Valores por defecto de configuración si la tabla está vacía
    $anioActual = date('Y');
    $defaults = [
        'preinscripcion_habilitada'   => '1',
        'preinscripcion_fecha_inicio' => $anioActual . '-01-01',
        'preinscripcion_fecha_fin'    => $anioActual . '-12-31',
        'preinscripcion_importe'      => '3000',
    ];
    $st = $pdo->prepare('INSERT IGNORE INTO `configuracion` (`clave`, `valor`) VALUES (?, ?)');
    foreach ($defaults as $k => $v) {
        $st->execute([$k, $v]);
    }
}

/** Responde en JSON y termina. */
function responder_json($datos, int $codigo = 200): void
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
