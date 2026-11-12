<?php
/* =====================================================================
   ARCHIVO: progmesas/api.php
   PARA QUÉ SIRVE: backend en PHP + SQLite para ProgMesas (Programa de
   Exámenes) cuando corre directamente sobre Apache/XAMPP sin Node.js.
   Usa la misma base backend/database.sqlite y la misma planilla
   backend/data/asignaciones.xlsx que el servidor Node.

   Va dentro del campus integrado (carpeta progmesas, al lado de campus):
   la clave PROGMESAS_SECRETO sale de ../campus/includes/config.php.
   ===================================================================== */

if (is_file(__DIR__ . '/../campus/includes/config.php')) {
    require_once __DIR__ . '/../campus/includes/config.php';
}
const MAX_PDF_MB = 10;

date_default_timezone_set('America/Argentina/Buenos_Aires');

// ================= FUNCIONES DE NORMALIZACIÓN =================

function sinAcentos(string $texto): string {
    $mapa = [
        'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u',
        'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U',
        'à'=>'a','è'=>'e','ì'=>'i','ò'=>'o','ù'=>'u',
        'À'=>'A','È'=>'E','Ì'=>'I','Ò'=>'O','Ù'=>'U',
        'â'=>'a','ê'=>'e','î'=>'i','ô'=>'o','û'=>'u',
        'Â'=>'A','Ê'=>'E','Î'=>'I','Ô'=>'O','Û'=>'U',
        'ã'=>'a','õ'=>'o','Ã'=>'A','Õ'=>'O'
    ];
    return strtr($texto, $mapa);
}

function claveTexto(?string $texto): string {
    $s = mb_strtolower(sinAcentos((string)$texto), 'UTF-8');
    $s = preg_replace('/[^a-z0-9ñ]+/u', ' ', $s);
    return trim((string)$s);
}

function claveCompacta(?string $texto): string {
    return str_replace(' ', '', claveTexto($texto));
}

function cursoClave(?string $curso): string {
    $t = strtoupper(sinAcentos((string)$curso));
    preg_match_all('/\d+/', $t, $numMatch);
    $numeros = array_slice($numMatch[0] ?? [], 0, 2);
    $sinTurno = preg_replace('/\b(TM|TT)\b/', ' ', $t);
    preg_match_all('/[A-Z]{2,4}/', (string)$sinTurno, $letMatch);
    $letras = array_slice($letMatch[0] ?? [], 0, 1);
    return implode('-', array_merge($numeros, $letras));
}

function generarIdAsignacion(string $curso, string $materia): string {
    return cursoClave($curso) . '_' . claveCompacta($materia);
}

// ================= LECTURA DE ASIGNACIONES (.XLSX) =================

function leerEntradasZip(string $ruta): array {
    if (!is_file($ruta)) return [];
    $data = @file_get_contents($ruta);
    if ($data === false) return [];
    $eocdPos = strrpos($data, "\x50\x4b\x05\x06");
    if ($eocdPos === false) return [];
    $eocd = unpack('vccount/vtotal/Vsize/Voffset', substr($data, $eocdPos + 8, 12));
    $pos = $eocd['offset'];
    $entries = [];
    for ($i = 0; $i < $eocd['total']; $i++) {
        if (substr($data, $pos, 4) !== "\x50\x4b\x01\x02") break;
        $hdr = unpack('vver/vverNeeded/vflags/vmethod/vtime/vdate/Vcrc/VcompSize/VuncompSize/vnameLen/vextraLen/vcommentLen/vdisk/vintAttr/VextAttr/VlocalOffset', substr($data, $pos + 4, 42));
        $name = substr($data, $pos + 46, $hdr['nameLen']);
        $localPos = $hdr['localOffset'];
        $lhdr = unpack('vnameLen/vextraLen', substr($data, $localPos + 26, 4));
        $dataStart = $localPos + 30 + $lhdr['nameLen'] + $lhdr['extraLen'];
        $compData = substr($data, $dataStart, $hdr['compSize']);
        if ($hdr['method'] === 0) {
            $entries[$name] = $compData;
        } elseif ($hdr['method'] === 8) {
            $entries[$name] = @gzinflate($compData);
        }
        $pos += 46 + $hdr['nameLen'] + $hdr['extraLen'] + $hdr['commentLen'];
    }
    return $entries;
}

function cargarAsignaciones(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $rutaXlsx = __DIR__ . '/backend/data/asignaciones.xlsx';
    if (!is_file($rutaXlsx)) {
        $cache = ['asignaciones' => [], 'porId' => [], 'error' => 'falta'];
        return $cache;
    }

    $entries = leerEntradasZip($rutaXlsx);
    if (!isset($entries['xl/worksheets/sheet1.xml'])) {
        $cache = ['asignaciones' => [], 'porId' => [], 'error' => 'No se pudo leer asignaciones.xlsx'];
        return $cache;
    }

    $strings = [];
    if (isset($entries['xl/sharedStrings.xml'])) {
        $ss = @simplexml_load_string($entries['xl/sharedStrings.xml']);
        if ($ss) {
            foreach ($ss->si as $si) {
                if (isset($si->t)) {
                    $strings[] = (string)$si->t;
                } else {
                    $txt = '';
                    foreach ($si->r as $run) $txt .= (string)$run->t;
                    $strings[] = $txt;
                }
            }
        }
    }

    $sheet = @simplexml_load_string($entries['xl/worksheets/sheet1.xml']);
    if (!$sheet) {
        $cache = ['asignaciones' => [], 'porId' => [], 'error' => 'Hoja XML inválida'];
        return $cache;
    }

    $filasRaw = [];
    foreach ($sheet->sheetData->row as $row) {
        $r = [];
        foreach ($row->c as $c) {
            $ref = (string)$c['r'];
            $col = preg_replace('/\d+/', '', $ref);
            $type = (string)$c['t'];
            if ($type === 's') {
                $val = $strings[(int)$c->v] ?? '';
            } elseif ($type === 'inlineStr') {
                $val = (string)$c->is->t;
            } else {
                $val = (string)$c->v;
            }
            $r[$col] = $val;
        }
        $filasRaw[] = $r;
    }

    if (empty($filasRaw)) {
        $cache = ['asignaciones' => [], 'porId' => [], 'error' => null];
        return $cache;
    }

    $encabezados = $filasRaw[0];
    $colMap = [];
    foreach ($encabezados as $col => $nombreCol) {
        $colMap[trim($nombreCol)] = $col;
    }

    $vistos = [];
    $resultado = [];
    $porId = [];

    for ($i = 1; $i < count($filasRaw); $i++) {
        $f = $filasRaw[$i];
        $curso = trim($f[$colMap['Curso'] ?? 'B'] ?? '');
        $materia = trim($f[$colMap['Materia'] ?? 'D'] ?? '');
        if ($curso === '' || $materia === '') continue;

        $id = trim($f[$colMap['ID'] ?? 'A'] ?? '');
        if ($id === '') $id = generarIdAsignacion($curso, $materia);
        if (isset($vistos[$id])) continue;
        $vistos[$id] = true;

        $reqTxt = trim($f[$colMap['Requiere programa'] ?? 'F'] ?? 'SI');
        $requiere = !preg_match('/^no$/i', $reqTxt);

        $docentesTxt = (string)($f[$colMap['Docentes'] ?? 'E'] ?? '');
        $partesDoc = array_filter(array_map('trim', explode('/', $docentesTxt)), fn($d) => $d !== '');

        $item = [
            'id' => $id,
            'curso' => $curso,
            'cursoClave' => cursoClave($curso),
            'turno' => trim($f[$colMap['Turno'] ?? 'C'] ?? ''),
            'materia' => $materia,
            'docentes' => array_values($partesDoc),
            'requiere' => $requiere,
            'revisar' => trim($f[$colMap['Revisar'] ?? 'H'] ?? '')
        ];
        $resultado[] = $item;
        $porId[$id] = $item;
    }

    $cache = ['asignaciones' => $resultado, 'porId' => $porId, 'error' => null];
    return $cache;
}

function todasLasAsignaciones(): array {
    return cargarAsignaciones()['asignaciones'];
}

function asignacionesRequeridas(): array {
    return array_values(array_filter(todasLasAsignaciones(), fn($a) => $a['requiere']));
}

function asignacionPorId(?string $id): ?array {
    if (!$id) return null;
    return cargarAsignaciones()['porId'][(string)$id] ?? null;
}

function clavesDocente(?string $docente): array {
    $partes = explode(';', (string)$docente);
    return array_values(array_filter(array_map('claveTexto', $partes), fn($k) => $k !== ''));
}

function docenteDictaAsignacion(?string $docente, array $asignacion): bool {
    $claves = clavesDocente($docente);
    if (empty($claves)) return false;
    foreach ($asignacion['docentes'] as $d) {
        if (in_array(claveTexto($d), $claves, true)) return true;
    }
    return false;
}

function asignacionesDeDocente(?string $docente): array {
    $claves = clavesDocente($docente);
    if (empty($claves)) return [];
    return array_values(array_filter(todasLasAsignaciones(), fn($a) => docenteDictaAsignacion($docente, $a)));
}

function buscarAsignacionPara(string $curso, string $materia): ?array {
    $cc = cursoClave($curso);
    $mc = claveCompacta($materia);
    foreach (todasLasAsignaciones() as $a) {
        if ($a['cursoClave'] === $cc && claveCompacta($a['materia']) === $mc) return $a;
    }
    return null;
}

function listaDocentes(): array {
    $nombres = [];
    foreach (todasLasAsignaciones() as $a) {
        foreach ($a['docentes'] as $d) {
            $k = claveTexto($d);
            if ($k !== '' && !isset($nombres[$k])) {
                $nombres[$k] = $d;
            }
        }
    }
    $vals = array_values($nombres);
    usort($vals, fn($a, $b) => strcasecmp(sinAcentos($a), sinAcentos($b)));
    return $vals;
}

function docenteOficial(?string $nombre): ?string {
    $k = claveTexto($nombre);
    if ($k === '') return null;
    foreach (listaDocentes() as $d) {
        if (claveTexto($d) === $k) return $d;
    }
    return null;
}

// ================= BASE DE DATOS SQLITE =================

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $rutaDb = __DIR__ . '/backend/database.sqlite';
    $pdo = new PDO('sqlite:' . $rutaDb, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS programas (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            materia TEXT NOT NULL,
            year INTEGER NOT NULL,
            curso TEXT NOT NULL,
            profesor TEXT NOT NULL DEFAULT '',
            file_url TEXT NOT NULL,
            fecha TEXT NOT NULL DEFAULT '',
            uploaded_by TEXT NOT NULL DEFAULT '',
            asignacion_id TEXT,
            almacenamiento TEXT NOT NULL DEFAULT '',
            archivo_ref TEXT NOT NULL DEFAULT '',
            created_at TEXT
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS programas_versiones (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            programa_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            profesor TEXT NOT NULL DEFAULT '',
            file_url TEXT NOT NULL,
            fecha TEXT NOT NULL DEFAULT '',
            uploaded_by TEXT NOT NULL DEFAULT '',
            almacenamiento TEXT NOT NULL DEFAULT '',
            archivo_ref TEXT NOT NULL DEFAULT '',
            created_at TEXT,
            reemplazado_at TEXT NOT NULL,
            reemplazado_por TEXT NOT NULL DEFAULT ''
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS docentes_usuarios (
            usuario_id TEXT PRIMARY KEY,
            nombre_usuario TEXT NOT NULL DEFAULT '',
            email TEXT NOT NULL DEFAULT '',
            docente TEXT,
            estado TEXT NOT NULL DEFAULT 'sin_vincular',
            ultimo_acceso TEXT,
            solicitado_at TEXT,
            confirmado_at TEXT,
            confirmado_por TEXT
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ajustes (
            clave TEXT PRIMARY KEY,
            valor TEXT NOT NULL
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS registro (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            fecha TEXT NOT NULL,
            usuario_id TEXT NOT NULL DEFAULT '',
            usuario_nombre TEXT NOT NULL DEFAULT '',
            rol TEXT NOT NULL DEFAULT '',
            accion TEXT NOT NULL,
            detalle TEXT NOT NULL DEFAULT '{}'
        )
    ");

    // Tipo de programa: 'regular' o 'libre'. Los cargados antes de que existiera el tipo quedan como regulares.
    $columnas = array_column($pdo->query('PRAGMA table_info(programas)')->fetchAll(), 'name');
    if (!in_array('tipo', $columnas, true)) {
        $pdo->exec("ALTER TABLE programas ADD COLUMN tipo TEXT NOT NULL DEFAULT 'regular'");
        $pdo->exec("UPDATE programas SET title = 'Programa regular de ' || substr(title, 13) WHERE title LIKE 'Programa de %'");
    }
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_programas_asignacion_tipo ON programas (asignacion_id, year, tipo)');

    return $pdo;
}

// ================= AUTENTICACIÓN (JWT + SESIÓN DEL CAMPUS) =================

function mapearRol($valor): ?string {
    $mapa = [
        'advisor' => ['asesora', 'asesor', 'advisor', 'directivo', 'directiva'],
        'teacher' => ['docente', 'teacher', 'profesor', 'profesora'],
        'viewer'  => ['preceptor', 'preceptora', 'director', 'directora', 'viewer'],
        'student' => ['alumno', 'alumna', 'student', 'estudiante']
    ];
    $valores = is_array($valor) ? $valor : explode(',', (string)$valor);
    $limpios = array_filter(array_map(fn($v) => strtolower(trim((string)$v)), $valores));
    foreach (['advisor', 'teacher', 'viewer', 'student'] as $interno) {
        foreach ($limpios as $v) {
            if ($v === $interno || in_array($v, $mapa[$interno], true)) return $interno;
        }
    }
    return null;
}

function base64UrlDecode(string $str): string {
    $rem = strlen($str) % 4;
    if ($rem) $str .= str_repeat('=', 4 - $rem);
    return (string)base64_decode(strtr($str, '-_', '+/'));
}

function verificarTokenJwt(string $token): ?array {
    $partes = explode('.', $token);
    if (count($partes) !== 3) return null;
    [$h64, $p64, $s64] = $partes;

    // Sin la clave del campus (32 caracteres o más) no se acepta ningún pase
    if (!defined('PROGMESAS_SECRETO') || strlen(PROGMESAS_SECRETO) < 32 || strpos(PROGMESAS_SECRETO, 'CAMBIAR') === 0) {
        return null;
    }
    $secreto = PROGMESAS_SECRETO;

    $firmaEsperada = hash_hmac('sha256', "$h64.$p64", $secreto, true);
    $firmaRecibida = base64UrlDecode($s64);
    if (!hash_equals($firmaEsperada, $firmaRecibida)) return null;

    $payload = json_decode(base64UrlDecode($p64), true);
    if (!is_array($payload)) return null;
    if (isset($payload['exp']) && time() >= (int)$payload['exp']) return null;

    $id = (string)($payload['sub'] ?? '');
    $rol = mapearRol($payload['role'] ?? '');
    if ($id === '' || !$rol) return null;

    return [
        'id' => $id,
        'nombre' => (string)($payload['name'] ?? ''),
        'email' => (string)($payload['email'] ?? ''),
        'role' => $rol
    ];
}

function obtenerUsuarioActual(): ?array {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($authHeader === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    if (stripos($authHeader, 'Bearer ') === 0) {
        $token = trim(substr($authHeader, 7));
        $u = verificarTokenJwt($token);
        if ($u) return $u;
    }

    // Respaldo: si el usuario ya tiene sesión iniciada en el Campus Virtual en este navegador.
    // La cookie viaja sola, así que un sitio ajeno podría usarla (CSRF): para modificar datos con la
    // sesión se exige la cabecera que solo manda la propia página (frontend/common.js).
    $metodoActual = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($metodoActual, ['GET', 'HEAD'], true) && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'progmesas') {
        return null;
    }
    if (session_status() === PHP_SESSION_NONE) {
        if (is_file(__DIR__ . '/../campus/includes/sesion.php')) {
            require_once __DIR__ . '/../campus/includes/sesion.php';   // misma cookie segura que el campus
        } else {
            @session_start();
        }
    }
    // (mismos datos que guarda login.php del campus: rol, nombre, correo e id)
    if (!empty($_SESSION['rol']) && !empty($_SESSION['nombre'])) {
        $rol = mapearRol($_SESSION['rol']);
        if ($rol) {
            $correo = (string)($_SESSION['correo'] ?? '');
            return [
                'id' => (string)($_SESSION['id'] ?? ($_SESSION['rol'] . ':' . ($correo !== '' ? $correo : $_SESSION['nombre']))),
                'nombre' => (string)$_SESSION['nombre'],
                'email' => $correo,
                'role' => $rol
            ];
        }
    }

    return null;
}

function exigirAuth(?array $usuario): array {
    if (!$usuario) {
        responderJson(['error' => 'Tenés que iniciar sesión'], 401);
    }
    return $usuario;
}

function exigirRol(?array $usuario, array $rolesPermitidos): array {
    $u = exigirAuth($usuario);
    if (!in_array($u['role'], $rolesPermitidos, true)) {
        responderJson(['error' => 'No tenés permiso para esta acción'], 403);
    }
    return $u;
}

// ================= REGISTRO Y AJUSTES =================

function registrarAccion(?array $usuario, string $accion, array $detalle = []): void {
    try {
        $stmt = db()->prepare('INSERT INTO registro (fecha, usuario_id, usuario_nombre, rol, accion, detalle) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            gmdate('Y-m-d\TH:i:s.v\Z'),
            (string)($usuario['id'] ?? 'sistema'),
            (string)($usuario['nombre'] ?? ($usuario ? '' : 'Sistema')),
            (string)($usuario['role'] ?? ''),
            $accion,
            json_encode($detalle, JSON_UNESCAPED_UNICODE)
        ]);
    } catch (Throwable $e) {
        // No interrumpir la operación principal
    }
}

function obtenerFechaLimite(int $anio): ?string {
    $stmt = db()->prepare('SELECT valor FROM ajustes WHERE clave = ?');
    $stmt->execute(["fecha_limite_$anio"]);
    $val = $stmt->fetchColumn();
    return $val !== false && $val !== '' ? (string)$val : null;
}

function guardarFechaLimite(int $anio, $fecha): ?string {
    $clave = "fecha_limite_$anio";
    if ($fecha === null || $fecha === '') {
        $stmt = db()->prepare('DELETE FROM ajustes WHERE clave = ?');
        $stmt->execute([$clave]);
        return null;
    }
    $texto = trim((string)$fecha);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $texto, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        responderJson(['error' => 'La fecha límite no es válida (formato AAAA-MM-DD)'], 400);
    }
    $stmt = db()->prepare('INSERT INTO ajustes (clave, valor) VALUES (?, ?) ON CONFLICT(clave) DO UPDATE SET valor = excluded.valor');
    $stmt->execute([$clave, $texto]);
    return $texto;
}

// ================= VÍNCULOS DE DOCENTES =================

function clavePalabras(?string $nombre): string {
    $partes = array_filter(explode(' ', claveTexto($nombre)));
    sort($partes);
    return implode(' ', $partes);
}

function nombreOficialPara(?string $nombreCuenta): ?string {
    $clave = clavePalabras($nombreCuenta);
    if (strpos($clave, ' ') === false) return null;
    $coincidencias = array_values(array_filter(listaDocentes(), fn($d) => clavePalabras($d) === $clave));
    return count($coincidencias) === 1 ? $coincidencias[0] : null;
}

function vinculoDe(string $usuarioId): ?array {
    $stmt = db()->prepare('SELECT * FROM docentes_usuarios WHERE usuario_id = ?');
    $stmt->execute([$usuarioId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function confirmadoPorOtro(string $docente, string $usuarioId): ?array {
    $k = claveTexto($docente);
    $stmt = db()->query('SELECT * FROM docentes_usuarios');
    foreach ($stmt->fetchAll() as $v) {
        if ($v['estado'] === 'confirmado' && $v['usuario_id'] !== $usuarioId && claveTexto($v['docente']) === $k) {
            return $v;
        }
    }
    return null;
}

function registrarAccesoDocente(array $usuario): array {
    $ahora = gmdate('Y-m-d\TH:i:s.v\Z');
    $stmt = db()->prepare("
        INSERT INTO docentes_usuarios (usuario_id, nombre_usuario, email, ultimo_acceso)
        VALUES (:id, :nombre, :email, :ahora)
        ON CONFLICT(usuario_id) DO UPDATE SET
            nombre_usuario = CASE WHEN :nombre <> '' THEN :nombre ELSE nombre_usuario END,
            email = CASE WHEN :email <> '' THEN :email ELSE email END,
            ultimo_acceso = :ahora
    ");
    $stmt->execute([
        ':id' => $usuario['id'],
        ':nombre' => $usuario['nombre'] ?? '',
        ':email' => $usuario['email'] ?? '',
        ':ahora' => $ahora
    ]);
    $v = vinculoDe($usuario['id']);
    if ($v && $v['estado'] === 'sin_vincular') {
        $docente = nombreOficialPara($usuario['nombre'] ?? '');
        if ($docente && !confirmadoPorOtro($docente, $usuario['id'])) {
            $up = db()->prepare("UPDATE docentes_usuarios SET docente = ?, estado = 'confirmado', confirmado_at = ?, confirmado_por = 'automático' WHERE usuario_id = ?");
            $up->execute([$docente, $ahora, $usuario['id']]);
            registrarAccion($usuario, 'docente.vinculado', ['docente' => $docente, 'por' => 'automático']);
            return vinculoDe($usuario['id']);
        }
    }
    return $v;
}

function docenteConfirmado(string $usuarioId): string {
    $v = vinculoDe($usuarioId);
    return ($v && $v['estado'] === 'confirmado' && !empty($v['docente'])) ? (string)$v['docente'] : '';
}

function vinculoPublico(?array $v): ?array {
    return $v ? ['docente' => $v['docente'], 'estado' => $v['estado']] : null;
}

function resumenVinculos(): array {
    $filas = db()->query('SELECT * FROM docentes_usuarios ORDER BY estado, nombre_usuario')->fetchAll();
    $usuarios = [];
    $confirmados = [];
    foreach ($filas as $v) {
        if ($v['estado'] === 'confirmado' && !empty($v['docente'])) {
            $confirmados[claveTexto($v['docente'])] = true;
        }
        $usuarios[] = [
            'usuarioId' => $v['usuario_id'],
            'nombre' => $v['nombre_usuario'],
            'email' => $v['email'],
            'docente' => $v['docente'],
            'estado' => $v['estado'],
            'ultimoAcceso' => $v['ultimo_acceso'],
            'solicitado' => $v['solicitado_at'],
            'confirmado' => $v['confirmado_at'],
            'confirmadoPor' => $v['confirmado_por'],
            'asignaciones' => !empty($v['docente']) ? count(asignacionesDeDocente($v['docente'])) : 0
        ];
    }
    $sinCuenta = [];
    foreach (listaDocentes() as $d) {
        if (!isset($confirmados[claveTexto($d)])) {
            $sinCuenta[] = [
                'docente' => $d,
                'asignaciones' => count(asignacionesDeDocente($d))
            ];
        }
    }
    return ['usuarios' => $usuarios, 'sinCuenta' => $sinCuenta];
}

// ================= PROGRAMAS Y ARCHIVOS =================

function normalizarFilaPrograma(array $r): array {
    $r['id'] = (int)$r['id'];
    $r['year'] = (int)$r['year'];
    $r['tipo'] = ($r['tipo'] ?? '') === 'libre' ? 'libre' : 'regular';
    return $r;
}

/** Tipos de programa de examen: para alumnos regulares o libres. */
const TIPOS_PROGRAMA = ['regular', 'libre'];

/** Tipo válido ('regular' o 'libre'); sin valor → 'regular'; otro valor → null. */
function tipoValido($valor): ?string {
    if ($valor === null || $valor === '') return 'regular';
    $t = strtolower(trim((string)$valor));
    return in_array($t, TIPOS_PROGRAMA, true) ? $t : null;
}

function errorTipo(): void {
    responderJson(['error' => 'El tipo de programa tiene que ser regular o libre'], 400);
}

function getProgramas(): array {
    $stmt = db()->query("SELECT id, title, materia, year, curso, profesor, file_url, fecha, uploaded_by, asignacion_id, almacenamiento, archivo_ref, created_at, tipo FROM programas ORDER BY year DESC, created_at DESC, id DESC");
    return array_map('normalizarFilaPrograma', $stmt->fetchAll());
}

function getPrograma($id): ?array {
    $stmt = db()->prepare("SELECT id, title, materia, year, curso, profesor, file_url, fecha, uploaded_by, asignacion_id, almacenamiento, archivo_ref, created_at, tipo FROM programas WHERE id = ?");
    $stmt->execute([(int)$id]);
    $r = $stmt->fetch();
    return $r ? normalizarFilaPrograma($r) : null;
}

function programaPublico(array $p, bool $esAsesora): array {
    unset($p['archivo_ref']);
    if (!$esAsesora) unset($p['uploaded_by']);
    return $p;
}

function cantidadDeVersiones(): array {
    $stmt = db()->query('SELECT programa_id, COUNT(*) AS cantidad FROM programas_versiones GROUP BY programa_id');
    $mapa = [];
    foreach ($stmt->fetchAll() as $r) {
        $mapa[(int)$r['programa_id']] = (int)$r['cantidad'];
    }
    return $mapa;
}

function vincularProgramasViejos(): void {
    $stmt = db()->query("SELECT id, curso, materia FROM programas WHERE asignacion_id IS NULL OR asignacion_id = ''");
    $up = db()->prepare('UPDATE programas SET asignacion_id = ?, curso = ?, materia = ? WHERE id = ?');
    foreach ($stmt->fetchAll() as $p) {
        $a = buscarAsignacionPara($p['curso'], $p['materia']);
        if ($a) {
            $up->execute([$a['id'], $a['curso'], $a['materia'], (int)$p['id']]);
        }
    }
}

function crearZipBuffer(array $archivos): string {
    $locales = '';
    $centrales = '';
    $offset = 0;
    $total = count($archivos);

    foreach ($archivos as $arc) {
        $nombre = (string)$arc['nombre'];
        $datos = (string)$arc['datos'];
        $ts = $arc['timestamp'] ?? time();
        $dt = getdate($ts);
        $anio = max((int)$dt['year'], 1980);
        $horaDos = (($dt['hours'] << 11) | ($dt['minutes'] << 5) | intdiv((int)$dt['seconds'], 2)) & 0xFFFF;
        $diaDos = ((($anio - 1980) << 9) | ($dt['mon'] << 5) | $dt['mday']) & 0xFFFF;

        $crc = crc32($datos);
        if ($crc < 0) $crc += 4294967296;
        $tam = strlen($datos);
        $largoNombre = strlen($nombre);

        $local = pack('VvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, $horaDos, $diaDos, $crc, $tam, $tam, $largoNombre, 0);
        $locales .= $local . $nombre . $datos;

        $central = pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, $horaDos, $diaDos, $crc, $tam, $tam, $largoNombre, 0, 0, 0, 0, 0, $offset);
        $centrales .= $central . $nombre;

        $offset += strlen($local) + $largoNombre + $tam;
    }

    $fin = pack('VvvvvVVv', 0x06054b50, 0, 0, $total, $total, strlen($centrales), $offset, 0);
    return $locales . $centrales . $fin;
}

// ================= HELPERS HTTP =================

function responderJson($datos, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

function leerBodyJson(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

function anioLectivo(): int {
    return (int)date('Y');
}

function rangoDeCarga(): array {
    $a = anioLectivo();
    return ['minimo' => $a - 1, 'maximo' => $a + 1];
}

function anioValido($valor): ?int {
    if ($valor === null || $valor === '') return null;
    $n = filter_var($valor, FILTER_VALIDATE_INT);
    return ($n !== false && $n >= 2000 && $n <= 2100) ? (int)$n : null;
}

/** Año de un programa que se sube o se reutiliza: siempre el año lectivo actual (otro año → null). */
function anioDelPrograma($valor): ?int {
    $anio = anioLectivo();
    if ($valor === null || $valor === '') return $anio;
    return anioValido($valor) === $anio ? $anio : null;
}

function errorAnioPrograma(): void {
    responderJson(['error' => 'Sólo se pueden cargar programas del año lectivo actual (' . anioLectivo() . ')'], 400);
}

/** Título de un programa: siempre se genera solo (curso, materia y año). */
function tituloAutomatico(array $asignacion, int $anio, string $tipo = 'regular'): string {
    return "Programa $tipo de {$asignacion['materia']} - {$asignacion['curso']} - $anio";
}

/** Direcciones del campus (carpeta hermana de progmesas), para Ingresar, Cerrar sesión y Volver al campus. */
function urlCampus(string $archivo): string {
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/progmesas/api.php')), '/');
    return rtrim(str_replace('\\', '/', dirname($base)), '/') . "/campus/$archivo";
}

function anioDeCarga($valor): ?int {
    $n = anioValido($valor);
    $r = rangoDeCarga();
    return ($n && $n >= $r['minimo'] && $n <= $r['maximo']) ? $n : null;
}

// ================= DESPACHO DE RUTAS =================

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$rutaRaw = $_GET['r'] ?? $_SERVER['PATH_INFO'] ?? '/';
if (strpos($rutaRaw, '?') !== false) {
    [$rutaRaw, $extraQuery] = explode('?', $rutaRaw, 2);
    parse_str($extraQuery, $extraParams);
    foreach ($extraParams as $k => $v) {
        if (!isset($_GET[$k])) $_GET[$k] = $v;
    }
}
$ruta = '/' . ltrim($rutaRaw, '/');
$usuario = obtenerUsuarioActual();

// GET /config
if ($metodo === 'GET' && $ruta === '/config') {
    $rango = rangoDeCarga();
    responderJson([
        'anioLectivo' => anioLectivo(),
        'anioMinimo' => $rango['minimo'],
        'anioMaximo' => $rango['maximo'],
        'maxPdfMb' => MAX_PDF_MB,
        'authModo' => 'externo',
        'loginUrl' => urlCampus('programas.php'),
        'logoutUrl' => urlCampus('logout.php'),
        'fechaLimite' => obtenerFechaLimite(anioLectivo())
    ]);
}

// GET /yo
if ($metodo === 'GET' && $ruta === '/yo') {
    if (!$usuario) {
        responderJson(['autenticado' => false]);
    }
    $vinculo = $usuario['role'] === 'teacher' ? registrarAccesoDocente($usuario) : null;
    responderJson([
        'autenticado' => true,
        'id' => $usuario['id'],
        'nombre' => $usuario['nombre'],
        'email' => $usuario['email'],
        'role' => $usuario['role'],
        'vinculo' => vinculoPublico($vinculo)
    ]);
}

// GET /docentes/nombres
if ($metodo === 'GET' && $ruta === '/docentes/nombres') {
    $u = exigirRol($usuario, ['teacher', 'advisor']);
    $tomados = [];
    foreach (resumenVinculos()['usuarios'] as $usr) {
        if ($usr['estado'] === 'confirmado' && $usr['usuarioId'] !== $u['id'] && !empty($usr['docente'])) {
            $tomados[$usr['docente']] = true;
        }
    }
    $res = [];
    foreach (listaDocentes() as $d) {
        $res[] = ['nombre' => $d, 'disponible' => !isset($tomados[$d])];
    }
    responderJson($res);
}

// POST /docentes/vinculo
if ($metodo === 'POST' && $ruta === '/docentes/vinculo') {
    $u = exigirRol($usuario, ['teacher']);
    $body = leerBodyJson();
    $v = registrarAccesoDocente($u);
    if ($v['estado'] === 'confirmado') {
        responderJson(['error' => 'Tu cuenta ya está vinculada. Si el nombre es incorrecto, pedile a la asesora que lo corrija.'], 409);
    }
    $docente = docenteOficial($body['docente'] ?? '');
    if (!$docente) {
        responderJson(['error' => 'Ese nombre no figura en la lista de asignaciones'], 400);
    }
    if (confirmadoPorOtro($docente, $u['id'])) {
        responderJson(['error' => 'Ese nombre ya está vinculado a otra cuenta. Hablá con la asesora.'], 409);
    }
    $ahora = gmdate('Y-m-d\TH:i:s.v\Z');
    $stmt = db()->prepare("UPDATE docentes_usuarios SET docente = ?, estado = 'confirmado', solicitado_at = ?, confirmado_at = ?, confirmado_por = 'el docente' WHERE usuario_id = ?");
    $stmt->execute([$docente, $ahora, $ahora, $u['id']]);
    $vNuevo = vinculoDe($u['id']);
    registrarAccion($u, 'docente.vinculado', ['docente' => $docente, 'por' => 'el docente']);
    responderJson(['ok' => true, 'vinculo' => vinculoPublico($vNuevo)]);
}

// GET /docentes/vinculos
if ($metodo === 'GET' && $ruta === '/docentes/vinculos') {
    exigirRol($usuario, ['advisor', 'viewer']);
    responderJson(resumenVinculos());
}

// POST /docentes/vinculos/:usuarioId/confirmar
if ($metodo === 'POST' && preg_match('#^/docentes/vinculos/([^/]+)/confirmar$#', $ruta, $m)) {
    $u = exigirRol($usuario, ['advisor']);
    $usuarioId = rawurldecode($m[1]);
    $antes = vinculoDe($usuarioId);
    if (!$antes) responderJson(['error' => 'No existe ese usuario'], 404);
    $body = leerBodyJson();
    $docente = docenteOficial($body['docente'] ?? $antes['docente']);
    if (!$docente) responderJson(['error' => 'Ese nombre no figura en la lista de asignaciones'], 400);
    $otro = confirmadoPorOtro($docente, $usuarioId);
    if ($otro) {
        $nomOtro = $otro['nombre_usuario'] ?: $otro['usuario_id'];
        responderJson(['error' => "\"$docente\" ya está vinculado a $nomOtro"], 409);
    }
    $ahora = gmdate('Y-m-d\TH:i:s.v\Z');
    $stmt = db()->prepare("UPDATE docentes_usuarios SET docente = ?, estado = 'confirmado', confirmado_at = ?, confirmado_por = ? WHERE usuario_id = ?");
    $stmt->execute([$docente, $ahora, $u['nombre'] ?: $u['id'], $usuarioId]);
    $vNuevo = vinculoDe($usuarioId);
    registrarAccion($u, 'docente.corregido', [
        'cuenta' => $vNuevo['nombre_usuario'] ?: $vNuevo['usuario_id'],
        'docente' => $docente,
        'anterior' => ($antes['estado'] === 'confirmado') ? $antes['docente'] : null
    ]);
    responderJson(['ok' => true, 'vinculo' => vinculoPublico($vNuevo)]);
}

// DELETE /docentes/vinculos/:usuarioId
if ($metodo === 'DELETE' && preg_match('#^/docentes/vinculos/([^/]+)$#', $ruta, $m)) {
    $u = exigirRol($usuario, ['advisor']);
    $usuarioId = rawurldecode($m[1]);
    $antes = vinculoDe($usuarioId);
    if (!$antes) responderJson(['error' => 'No existe ese usuario'], 404);
    $stmt = db()->prepare("UPDATE docentes_usuarios SET docente = NULL, estado = 'sin_vincular', solicitado_at = NULL, confirmado_at = NULL, confirmado_por = NULL WHERE usuario_id = ?");
    $stmt->execute([$usuarioId]);
    registrarAccion($u, 'docente.desvinculado', [
        'cuenta' => $antes['nombre_usuario'] ?: $usuarioId,
        'docente' => $antes['docente'] ?? null
    ]);
    responderJson(['ok' => true]);
}

// GET /programas
if ($metodo === 'GET' && $ruta === '/programas') {
    vincularProgramasViejos();
    $programas = getProgramas();
    $esAsesora = ($usuario['role'] ?? '') === 'advisor';
    $esDocente = ($usuario['role'] ?? '') === 'teacher';
    $versiones = ($esAsesora || $esDocente) ? cantidadDeVersiones() : null;

    if ($esDocente) {
        $misIds = [];
        foreach (asignacionesDeDocente(docenteConfirmado($usuario['id'])) as $a) {
            $misIds[$a['id']] = true;
        }
        $propios = [];
        foreach ($programas as $p) {
            $esMio = (!empty($p['asignacion_id']) && isset($misIds[$p['asignacion_id']]))
                || $p['uploaded_by'] === $usuario['id']
                || (!empty($usuario['email']) && $p['uploaded_by'] === $usuario['email']);
            if ($esMio) {
                $pub = programaPublico($p, false);
                $pub['versiones'] = $versiones[$p['id']] ?? 0;
                $propios[] = $pub;
            }
        }
        responderJson($propios);
    }

    $salida = [];
    foreach ($programas as $p) {
        $pub = programaPublico($p, $esAsesora);
        if ($versiones !== null) {
            $pub['versiones'] = $versiones[$p['id']] ?? 0;
        }
        $salida[] = $pub;
    }
    responderJson($salida);
}

// GET /programas/:id/versiones
if ($metodo === 'GET' && preg_match('#^/programas/(\d+)/versiones$#', $ruta, $m)) {
    $u = exigirRol($usuario, ['teacher', 'advisor']);
    $programa = getPrograma((int)$m[1]);
    if (!$programa) responderJson(['error' => 'Programa no encontrado'], 404);

    $esAsesora = $u['role'] === 'advisor';
    if (!$esAsesora) {
        $doc = docenteConfirmado($u['id']);
        $asig = !empty($programa['asignacion_id']) ? asignacionPorId($programa['asignacion_id']) : null;
        $puede = ($asig && $doc && docenteDictaAsignacion($doc, $asig)) || $programa['uploaded_by'] === $u['id'];
        if (!$puede) responderJson(['error' => 'No tenés permiso para esta acción'], 403);
    }

    $stmt = db()->prepare('SELECT * FROM programas_versiones WHERE programa_id = ? ORDER BY reemplazado_at DESC, id DESC');
    $stmt->execute([$programa['id']]);
    $vers = [];
    foreach ($stmt->fetchAll() as $v) {
        $item = [
            'id' => (int)$v['id'],
            'title' => $v['title'],
            'profesor' => $v['profesor'],
            'fecha' => $v['fecha'],
            'created_at' => $v['created_at'],
            'file_url' => $v['file_url'],
            'almacenamiento' => $v['almacenamiento'],
            'reemplazado_at' => $v['reemplazado_at'],
            'reemplazado_por' => $v['reemplazado_por']
        ];
        if ($esAsesora) $item['uploaded_by'] = $v['uploaded_by'];
        $vers[] = $item;
    }
    responderJson([
        'programa' => programaPublico($programa, $esAsesora),
        'versiones' => $vers
    ]);
}

// GET /asignaciones/mias
if ($metodo === 'GET' && $ruta === '/asignaciones/mias') {
    $u = exigirRol($usuario, ['teacher', 'advisor']);
    $anio = anioValido($_GET['anio'] ?? null) ?: anioLectivo();
    $esAsesora = $u['role'] === 'advisor';
    $docente = $esAsesora ? '' : docenteConfirmado($u['id']);

    $lista = todasLasAsignaciones();
    $propias = [];
    if ($docente !== '') {
        foreach (asignacionesDeDocente($docente) as $a) {
            $propias[$a['id']] = true;
        }
    }

    // Clave asignación + tipo ("id|regular"): cada materia puede tener un programa regular y uno libre
    $cargados = [];
    $anteriores = [];
    foreach (getProgramas() as $p) {
        $aid = $p['asignacion_id'] ?? '';
        if ($aid === '') continue;
        $clave = $aid . '|' . $p['tipo'];
        if ($p['year'] === $anio && !isset($cargados[$clave])) {
            $cargados[$clave] = $p;
        }
        if ($p['year'] < $anio && !isset($anteriores[$clave])) {
            $anteriores[$clave] = $p;
        }
    }
    $porTipo = function (array $mapa, string $id, bool $conAnio): array {
        $salida = [];
        foreach (TIPOS_PROGRAMA as $t) {
            $p = $mapa["$id|$t"] ?? null;
            $salida[$t] = $p ? array_merge(
                ['id' => $p['id'], 'title' => $p['title'], 'fecha' => $p['fecha'], 'tipo' => $p['tipo']],
                $conAnio ? ['year' => $p['year']] : []
            ) : null;
        }
        return $salida;
    };

    $asigSalida = [];
    foreach ($lista as $a) {
        $programasTipo = $porTipo($cargados, $a['id'], false);
        $asigSalida[] = [
            'id' => $a['id'],
            'curso' => $a['curso'],
            'turno' => $a['turno'],
            'materia' => $a['materia'],
            'docentes' => $a['docentes'],
            'requiere' => $a['requiere'],
            'propia' => isset($propias[$a['id']]),
            'programa' => $programasTipo['regular'] ?? $programasTipo['libre'],   // alguno cargado (compatibilidad)
            'programas' => $programasTipo,                                        // ['regular' => …, 'libre' => …]
            'anteriores' => $porTipo($anteriores, $a['id'], true)                 // de años anteriores
        ];
    }

    responderJson([
        'anio' => $anio,
        'docente' => $docente,
        'fechaLimite' => obtenerFechaLimite($anio),
        'vinculo' => $esAsesora ? null : vinculoPublico(vinculoDe($u['id'])),
        'asignaciones' => $asigSalida
    ]);
}

// POST /programas/subir
if ($metodo === 'POST' && $ruta === '/programas/subir') {
    $u = exigirRol($usuario, ['teacher', 'advisor']);
    $esAsesora = $u['role'] === 'advisor';
    $docente = $esAsesora ? '' : (docenteConfirmado($u['id']) ?: ($u['nombre'] ?: $u['id']));

    $asignacion = asignacionPorId($_POST['asignacion_id'] ?? '');
    $anio = anioDelPrograma($_POST['anio'] ?? null);
    $tipo = tipoValido($_POST['tipo'] ?? null);
    if (!$asignacion) responderJson(['error' => 'Elegí la materia y el curso del programa'], 400);
    if (!$anio) errorAnioPrograma();
    if (!$tipo) errorTipo();

    if (empty($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
        responderJson(['error' => 'Falta el archivo PDF'], 400);
    }
    if ($_FILES['pdf']['size'] > MAX_PDF_MB * 1024 * 1024) {
        responderJson(['error' => 'El PDF supera el máximo de ' . MAX_PDF_MB . ' MB'], 413);
    }
    $contenido = file_get_contents($_FILES['pdf']['tmp_name']);
    if ($contenido === false || strlen($contenido) <= 5 || substr($contenido, 0, 5) !== '%PDF-') {
        responderJson(['error' => 'El archivo no es un PDF válido'], 400);
    }

    $titulo = tituloAutomatico($asignacion, $anio, $tipo);
    $profesor = $esAsesora ? (implode(' / ', $asignacion['docentes']) ?: $u['nombre']) : $docente;

    $dirUploads = __DIR__ . '/backend/uploads';
    if (!is_dir($dirUploads)) @mkdir($dirUploads, 0775, true);
    $slug = substr(preg_replace('/\s+/', '_', claveTexto("{$asignacion['curso']} {$asignacion['materia']} $tipo")), 0, 60) ?: 'programa';
    $nombreArchivo = round(microtime(true) * 1000) . '_' . $slug . '.pdf';
    file_put_contents($dirUploads . '/' . $nombreArchivo, $contenido);

    $fileUrl = '/uploads/' . rawurlencode($nombreArchivo);
    $fechaHoy = date('j/n/Y');
    $ahoraIso = gmdate('Y-m-d\TH:i:s.v\Z');

    // Si ya hay uno del mismo tipo para ese curso, materia y año, se reemplaza y el anterior queda en el historial
    $stmtAnt = db()->prepare('SELECT * FROM programas WHERE asignacion_id = ? AND year = ? AND tipo = ? ORDER BY created_at DESC, id DESC LIMIT 1');
    $stmtAnt->execute([$asignacion['id'], $anio, $tipo]);
    $anterior = $stmtAnt->fetch();

    if ($anterior) {
        $id = (int)$anterior['id'];
        $insVer = db()->prepare("
            INSERT INTO programas_versiones (programa_id, title, profesor, file_url, fecha, uploaded_by, almacenamiento, archivo_ref, created_at, reemplazado_at, reemplazado_por)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insVer->execute([
            $id,
            $anterior['title'],
            $anterior['profesor'] ?? '',
            $anterior['file_url'],
            $anterior['fecha'] ?? '',
            $anterior['uploaded_by'] ?? '',
            $anterior['almacenamiento'] ?? '',
            $anterior['archivo_ref'] ?? '',
            $anterior['created_at'],
            $ahoraIso,
            $u['nombre'] ?: $u['id']
        ]);

        $up = db()->prepare("
            UPDATE programas SET title = ?, materia = ?, curso = ?, profesor = ?, file_url = ?, fecha = ?, uploaded_by = ?, almacenamiento = 'local', archivo_ref = ?, created_at = ?
            WHERE id = ?
        ");
        $up->execute([$titulo, $asignacion['materia'], $asignacion['curso'], $profesor, $fileUrl, $fechaHoy, $u['id'], $nombreArchivo, $ahoraIso, $id]);
    } else {
        $ins = db()->prepare("
            INSERT INTO programas (title, materia, year, curso, profesor, file_url, fecha, uploaded_by, asignacion_id, almacenamiento, archivo_ref, created_at, tipo)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'local', ?, ?, ?)
        ");
        $ins->execute([$titulo, $asignacion['materia'], $anio, $asignacion['curso'], $profesor, $fileUrl, $fechaHoy, $u['id'], $asignacion['id'], $nombreArchivo, $ahoraIso, $tipo]);
        $id = (int)db()->lastInsertId();
    }

    registrarAccion($u, $anterior ? 'programa.reemplazado' : 'programa.subido', [
        'id' => $id,
        'curso' => $asignacion['curso'],
        'materia' => $asignacion['materia'],
        'anio' => $anio,
        'tipo' => $tipo,
        'titulo' => $titulo,
        'almacenamiento' => 'local'
    ]);

    responderJson([
        'ok' => true,
        'id' => $id,
        'reemplazado' => (bool)$anterior,
        'almacenamiento' => 'local',
        'aviso' => null
    ]);
}

// POST /programas/reutilizar
if ($metodo === 'POST' && $ruta === '/programas/reutilizar') {
    $u = exigirRol($usuario, ['teacher', 'advisor']);
    $esAsesora = $u['role'] === 'advisor';
    $docente = $esAsesora ? '' : (docenteConfirmado($u['id']) ?: ($u['nombre'] ?: $u['id']));

    $body = leerBodyJson();
    $asignacion = asignacionPorId($body['asignacion_id'] ?? '');
    $anio = anioDelPrograma($body['anio'] ?? null);
    $tipo = tipoValido($body['tipo'] ?? null);
    if (!$asignacion) responderJson(['error' => 'Elegí la materia y el curso del programa'], 400);
    if (!$anio) errorAnioPrograma();
    if (!$tipo) errorTipo();

    $stmtOrig = db()->prepare('SELECT * FROM programas WHERE asignacion_id = ? AND year < ? AND tipo = ? ORDER BY year DESC, created_at DESC, id DESC LIMIT 1');
    $stmtOrig->execute([$asignacion['id'], $anio, $tipo]);
    $origen = $stmtOrig->fetch();
    if (!$origen) responderJson(['error' => "No hay un programa $tipo de años anteriores para ese curso y materia"], 404);

    $tituloNuevo = tituloAutomatico($asignacion, $anio, $tipo);
    $profesor = $esAsesora ? ($origen['profesor'] ?: implode(' / ', $asignacion['docentes'])) : $docente;
    $fechaHoy = date('j/n/Y');
    $ahoraIso = gmdate('Y-m-d\TH:i:s.v\Z');

    $stmtAct = db()->prepare('SELECT * FROM programas WHERE asignacion_id = ? AND year = ? AND tipo = ? ORDER BY created_at DESC, id DESC LIMIT 1');
    $stmtAct->execute([$asignacion['id'], $anio, $tipo]);
    $actual = $stmtAct->fetch();

    if ($actual) {
        $id = (int)$actual['id'];
        $insVer = db()->prepare("
            INSERT INTO programas_versiones (programa_id, title, profesor, file_url, fecha, uploaded_by, almacenamiento, archivo_ref, created_at, reemplazado_at, reemplazado_por)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insVer->execute([
            $id, $actual['title'], $actual['profesor'] ?? '', $actual['file_url'], $actual['fecha'] ?? '',
            $actual['uploaded_by'] ?? '', $actual['almacenamiento'] ?? '', $actual['archivo_ref'] ?? '',
            $actual['created_at'], $ahoraIso, $u['nombre'] ?: $u['id']
        ]);

        $up = db()->prepare("
            UPDATE programas SET title = ?, materia = ?, curso = ?, profesor = ?, file_url = ?, fecha = ?, uploaded_by = ?, almacenamiento = ?, archivo_ref = ?, created_at = ?
            WHERE id = ?
        ");
        $up->execute([$tituloNuevo, $asignacion['materia'], $asignacion['curso'], $profesor, $origen['file_url'], $fechaHoy, $u['id'], $origen['almacenamiento'], $origen['archivo_ref'], $ahoraIso, $id]);
    } else {
        $ins = db()->prepare("
            INSERT INTO programas (title, materia, year, curso, profesor, file_url, fecha, uploaded_by, asignacion_id, almacenamiento, archivo_ref, created_at, tipo)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([$tituloNuevo, $asignacion['materia'], $anio, $asignacion['curso'], $profesor, $origen['file_url'], $fechaHoy, $u['id'], $asignacion['id'], $origen['almacenamiento'], $origen['archivo_ref'], $ahoraIso, $tipo]);
        $id = (int)db()->lastInsertId();
    }

    registrarAccion($u, 'programa.reutilizado', [
        'id' => $id,
        'curso' => $asignacion['curso'],
        'materia' => $asignacion['materia'],
        'anio' => $anio,
        'tipo' => $tipo,
        'desde' => (int)$origen['year'],
        'titulo' => $tituloNuevo
    ]);

    responderJson(['ok' => true, 'id' => $id, 'reemplazado' => (bool)$actual, 'desde' => (int)$origen['year']]);
}

// DELETE /programas/:id
if ($metodo === 'DELETE' && preg_match('#^/programas/(\d+)$#', $ruta, $m)) {
    $u = exigirRol($usuario, ['advisor']);
    $id = (int)$m[1];
    $programa = getPrograma($id);
    if (!$programa) responderJson(['error' => 'Programa no encontrado'], 404);

    $stmtVers = db()->prepare('SELECT * FROM programas_versiones WHERE programa_id = ?');
    $stmtVers->execute([$id]);
    $versiones = $stmtVers->fetchAll();

    db()->prepare('DELETE FROM programas_versiones WHERE programa_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM programas WHERE id = ?')->execute([$id]);

    $todosBorrados = array_merge([$programa], $versiones);
    $stmtUsos = db()->prepare("
        SELECT (SELECT COUNT(*) FROM programas WHERE almacenamiento = :a AND archivo_ref = :r)
             + (SELECT COUNT(*) FROM programas_versiones WHERE almacenamiento = :a AND archivo_ref = :r)
    ");
    foreach ($todosBorrados as $reg) {
        if (($reg['almacenamiento'] ?? '') === 'local' && !empty($reg['archivo_ref'])) {
            $stmtUsos->execute([':a' => 'local', ':r' => $reg['archivo_ref']]);
            if ((int)$stmtUsos->fetchColumn() === 0) {
                $rutaPdf = __DIR__ . '/backend/uploads/' . basename($reg['archivo_ref']);
                if (is_file($rutaPdf)) @unlink($rutaPdf);
            }
        }
    }

    registrarAccion($u, 'programa.eliminado', [
        'id' => $id,
        'curso' => $programa['curso'],
        'materia' => $programa['materia'],
        'anio' => $programa['year'],
        'tipo' => $programa['tipo'],
        'titulo' => $programa['title'],
        'versiones' => count($versiones)
    ]);

    responderJson(['ok' => true]);
}

// PATCH /programas/:id/asignacion
if ($metodo === 'PATCH' && preg_match('#^/programas/(\d+)/asignacion$#', $ruta, $m)) {
    $u = exigirRol($usuario, ['advisor']);
    $programa = getPrograma((int)$m[1]);
    $body = leerBodyJson();
    $asignacion = asignacionPorId($body['asignacion_id'] ?? '');
    if (!$programa) responderJson(['error' => 'Programa no encontrado'], 404);
    if (!$asignacion) responderJson(['error' => 'Asignación inexistente'], 400);

    $up = db()->prepare('UPDATE programas SET asignacion_id = ?, curso = ?, materia = ? WHERE id = ?');
    $up->execute([$asignacion['id'], $asignacion['curso'], $asignacion['materia'], $programa['id']]);
    registrarAccion($u, 'programa.vinculado', [
        'id' => $programa['id'],
        'titulo' => $programa['title'],
        'curso' => $asignacion['curso'],
        'materia' => $asignacion['materia'],
        'anio' => $programa['year']
    ]);
    responderJson(['ok' => true]);
}

// GET /cobertura
if ($metodo === 'GET' && $ruta === '/cobertura') {
    exigirRol($usuario, ['advisor', 'viewer']);
    $anio = anioValido($_GET['anio'] ?? null) ?: anioLectivo();
    vincularProgramasViejos();

    // Una materia está completa con cualquiera de los dos programas (regular o libre)
    $delAnio = array_values(array_filter(getProgramas(), fn($p) => $p['year'] === $anio));
    $porAsignacion = [];   // id => ['regular' => …, 'libre' => …]
    foreach ($delAnio as $p) {
        if (!empty($p['asignacion_id']) && !isset($porAsignacion[$p['asignacion_id']][$p['tipo']])) {
            $porAsignacion[$p['asignacion_id']][$p['tipo']] = $p;
        }
    }
    $resumen = fn($p) => $p ? [
        'id' => $p['id'],
        'title' => $p['title'],
        'tipo' => $p['tipo'],
        'fecha' => $p['fecha'],
        'created_at' => $p['created_at'],
        'file_url' => $p['file_url'],
        'almacenamiento' => $p['almacenamiento']
    ] : null;

    $asignaciones = [];
    foreach (asignacionesRequeridas() as $a) {
        $tipos = $porAsignacion[$a['id']] ?? [];
        // El programa que se muestra: el más reciente de los cargados
        $p = null;
        foreach ($tipos as $cand) {
            if (!$p || strcmp((string)$cand['created_at'], (string)$p['created_at']) > 0) $p = $cand;
        }
        $programasTipo = [];
        foreach (TIPOS_PROGRAMA as $t) $programasTipo[$t] = $resumen($tipos[$t] ?? null);
        $asignaciones[] = [
            'id' => $a['id'],
            'curso' => $a['curso'],
            'turno' => $a['turno'],
            'materia' => $a['materia'],
            'docentes' => $a['docentes'],
            'revisar' => $a['revisar'],
            'programa' => $resumen($p),
            'programas' => $programasTipo
        ];
    }

    $idsValidos = [];
    foreach (todasLasAsignaciones() as $a) $idsValidos[$a['id']] = true;

    $sinAsignacion = [];
    foreach ($delAnio as $p) {
        if (empty($p['asignacion_id']) || !isset($idsValidos[$p['asignacion_id']])) {
            $sinAsignacion[] = programaPublico($p, true);
        }
    }

    $localesCount = (int)db()->query("SELECT COUNT(DISTINCT archivo_ref) FROM programas WHERE almacenamiento = 'local' AND archivo_ref <> ''")->fetchColumn();
    $infoAsig = cargarAsignaciones();

    responderJson([
        'anio' => $anio,
        'asignaciones' => $asignaciones,
        'sinAsignacion' => $sinAsignacion,
        'fechaLimite' => obtenerFechaLimite($anio),
        'estado' => [
            'drive' => ['disponible' => false, 'error' => null, 'modo' => 'local'],
            'asignaciones' => ['cantidad' => count($infoAsig['asignaciones']), 'error' => $infoAsig['error']],
            'locales' => $localesCount
        ]
    ]);
}

// GET /fecha-limite
if ($metodo === 'GET' && $ruta === '/fecha-limite') {
    $anio = anioValido($_GET['anio'] ?? null) ?: anioLectivo();
    responderJson(['anio' => $anio, 'fecha' => obtenerFechaLimite($anio)]);
}

// PUT /fecha-limite
if ($metodo === 'PUT' && $ruta === '/fecha-limite') {
    $u = exigirRol($usuario, ['advisor']);
    $body = leerBodyJson();
    $anio = anioDeCarga($body['anio'] ?? null);
    if (!$anio) {
        $r = rangoDeCarga();
        responderJson(['error' => "El año lectivo tiene que estar entre {$r['minimo']} y {$r['maximo']}"], 400);
    }
    $anterior = obtenerFechaLimite($anio);
    $fecha = guardarFechaLimite($anio, $body['fecha'] ?? null);
    registrarAccion($u, 'ajustes.fecha_limite', ['anio' => $anio, 'fecha' => $fecha, 'anterior' => $anterior]);
    responderJson(['ok' => true, 'anio' => $anio, 'fecha' => $fecha]);
}

// GET /registro
if ($metodo === 'GET' && $ruta === '/registro') {
    exigirRol($usuario, ['advisor', 'viewer']);
    $limite = min(max((int)($_GET['limite'] ?? 200), 1), 2000);
    $stmt = db()->prepare('SELECT * FROM registro ORDER BY id DESC LIMIT ?');
    $stmt->execute([$limite]);
    $salida = [];
    foreach ($stmt->fetchAll() as $r) {
        $det = json_decode($r['detalle'] ?? '{}', true);
        $salida[] = [
            'id' => (int)$r['id'],
            'fecha' => $r['fecha'],
            'usuario' => [
                'id' => $r['usuario_id'],
                'nombre' => $r['usuario_nombre'],
                'rol' => $r['rol']
            ],
            'accion' => $r['accion'],
            'detalle' => is_array($det) ? $det : []
        ];
    }
    responderJson($salida);
}

// POST /drive/sincronizar
if ($metodo === 'POST' && $ruta === '/drive/sincronizar') {
    exigirRol($usuario, ['advisor']);
    responderJson(['ok' => true, 'pasados' => 0, 'pendientes' => 0, 'errores' => []]);
}

// GET /cursos/zip
if ($metodo === 'GET' && $ruta === '/cursos/zip') {
    $curso = trim((string)($_GET['curso'] ?? ''));
    if ($curso === '') responderJson(['error' => 'Indicá el curso'], 400);
    $anio = isset($_GET['anio']) && $_GET['anio'] !== '' ? anioValido($_GET['anio']) : null;
    if (isset($_GET['anio']) && $_GET['anio'] !== '' && !$anio) {
        responderJson(['error' => 'El año lectivo no es válido'], 400);
    }

    $tipo = isset($_GET['tipo']) && $_GET['tipo'] !== '' ? tipoValido($_GET['tipo']) : null;
    if (isset($_GET['tipo']) && $_GET['tipo'] !== '' && !$tipo) errorTipo();

    $clave = cursoClave($curso);
    $programas = array_values(array_filter(
        getProgramas(),
        fn($p) => cursoClave($p['curso']) === $clave && (!$anio || $p['year'] === $anio) && (!$tipo || $p['tipo'] === $tipo)
    ));
    if (empty($programas)) {
        responderJson(['error' => 'No hay programas cargados para ese curso'], 404);
    }

    $archivos = [];
    $usados = [];
    $faltantes = [];
    foreach ($programas as $p) {
        $nombreTipo = $p['tipo'] === 'libre' ? 'Libre' : 'Regular';
        $matLimpia = trim((string)preg_replace('/[\\\\\/:*?"<>|\x00-\x1f]+/', ' ', "{$p['materia']} - $nombreTipo"));
        $matLimpia = substr(preg_replace('/\s+/', ' ', $matLimpia), 0, 90) ?: 'programa';
        $nombre = "{$p['year']}/$matLimpia";
        for ($i = 2; isset($usados[claveTexto($nombre)]); $i++) {
            $nombre = "{$p['year']}/$matLimpia ($i)";
        }
        $usados[claveTexto($nombre)] = true;

        $rutaLocal = '';
        if (!empty($p['archivo_ref']) && ($p['almacenamiento'] === 'local' || strpos($p['file_url'], '/uploads/') === 0)) {
            $rutaLocal = __DIR__ . '/backend/uploads/' . basename($p['archivo_ref']);
        } elseif (strpos($p['file_url'], '/uploads/') === 0) {
            $rutaLocal = __DIR__ . '/backend/uploads/' . basename(rawurldecode(substr($p['file_url'], strlen('/uploads/'))));
        }

        if ($rutaLocal !== '' && is_file($rutaLocal)) {
            $archivos[] = [
                'nombre' => "$nombre.pdf",
                'datos' => (string)file_get_contents($rutaLocal),
                'timestamp' => !empty($p['created_at']) ? strtotime($p['created_at']) : time()
            ];
        } else {
            $faltantes[] = "{$p['year']} - {$p['materia']} ({$p['tipo']}): El archivo está alojado externamente o no se encontró en el servidor local.";
        }
    }

    if (!empty($faltantes)) {
        $archivos[] = [
            'nombre' => 'NO INCLUIDOS.txt',
            'datos' => "Estos programas no se pudieron incluir en el archivo local:\r\n\r\n" . implode("\r\n", $faltantes) . "\r\n",
            'timestamp' => time()
        ];
    }

    $nombreZip = 'Programas ' . ($tipo ? ($tipo === 'libre' ? 'libres ' : 'regulares ') : '') . $curso . ($anio ? " $anio" : '') . '.zip';
    $nombreAscii = preg_replace('/[^\x20-\x7e]/', '', sinAcentos($nombreZip));
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . addslashes($nombreAscii) . '"; filename*=UTF-8\'\'' . rawurlencode($nombreZip));
    echo crearZipBuffer($archivos);
    exit;
}

// GET /programas/faltantes
if ($metodo === 'GET' && $ruta === '/programas/faltantes') {
    exigirRol($usuario, ['advisor', 'viewer']);
    $anio = anioValido($_GET['anio'] ?? null) ?: anioLectivo();
    $delAnio = array_values(array_filter(getProgramas(), fn($p) => $p['year'] === $anio));
    $conPrograma = [];
    foreach ($delAnio as $p) {
        if (!empty($p['asignacion_id'])) $conPrograma[$p['asignacion_id']] = true;
    }
    $faltantes = [];
    foreach (asignacionesRequeridas() as $a) {
        if (!isset($conPrograma[$a['id']])) {
            $faltantes[] = [
                'id' => $a['id'],
                'curso' => $a['curso'],
                'materia' => $a['materia'],
                'profesor' => implode(' / ', $a['docentes']),
                'turno' => $a['turno']
            ];
        }
    }
    responderJson($faltantes);
}

responderJson(['error' => 'Ruta inexistente'], 404);
