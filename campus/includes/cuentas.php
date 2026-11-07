<?php
/* =====================================================================
   ARCHIVO: includes/cuentas.php
   PARA QUÉ SIRVE: cuentas unificadas del campus.

   - usuarios          : una cuenta por persona (DNI, correo, contraseña, estado)
   - usuario_roles     : alumno, profesor, preceptor y/o directivo
   - codigos_acceso    : códigos de un solo uso para el PRIMER INGRESO (alta)
                         y para RECUPERAR la cuenta. Vencen y se guardan cifrados.
   - solicitudes_recuperacion : pedidos de "olvidé mi contraseña"
   - cursos / preceptor_cursos : cursos de la escuela y qué preceptor los atiende

   Las tablas `alumnos` y `profesores` siguen existiendo como "fichas" de
   cada persona (las usan preinscripciones, proyectores y ProgMesas) y se
   enlazan con `usuarios` por la columna usuario_id.

   La primera vez que se usa, este archivo crea las tablas y pasa a
   `usuarios` las cuentas que ya existían (alumnos, profesores y los
   directivos de datos.php). Eso pasa una sola vez (tabla campus_migraciones).
   ===================================================================== */
require_once __DIR__ . '/usuarios.php';   // config, conexión y tablas alumnos/profesores

const ROLES_CAMPUS        = ['alumno', 'profesor', 'preceptor', 'directivo'];
const PRIORIDAD_ROLES     = ['directivo', 'profesor', 'preceptor', 'alumno'];   // rol principal de la sesión
const CODIGO_ALFABETO     = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';                 // sin 0/O, 1/I/L
const CODIGO_LARGO        = 10;
const VIGENCIA_ALTA_HORAS = 168;   // 7 días
const VIGENCIA_RECUP_HORAS = 48;
const CLAVE_LARGO_MINIMO  = 8;

const ETIQUETAS_ROL = [
    'alumno'    => 'Alumno/a',
    'profesor'  => 'Profesor/a',
    'preceptor' => 'Preceptor/a',
    'directivo' => 'Directivo/a',
];

/** Cursos de 2026 tomados de las asignaciones de ProgMesas: [año, división, tecnicatura, turno]. */
const CURSOS_INICIALES = [
    [1, 1, '', 'Mañana'], [1, 2, '', 'Mañana'], [2, 1, '', 'Mañana'], [2, 2, '', 'Mañana'], [2, 4, '', 'Mañana'],
    [3, 1, '', 'Mañana'], [3, 2, '', 'Mañana'], [4, 1, 'TC', 'Mañana'], [4, 2, 'TAE', 'Mañana'], [5, 1, 'TC', 'Mañana'],
    [5, 2, 'TAE', 'Mañana'], [6, 1, 'TC', 'Mañana'], [6, 1, 'TG', 'Mañana'], [6, 2, 'TAE', 'Mañana'], [7, 1, 'TC', 'Mañana'],
    [7, 1, 'TG', 'Mañana'], [7, 2, 'TAE', 'Mañana'],
    [1, 3, '', 'Tarde'], [1, 4, '', 'Tarde'], [1, 5, '', 'Tarde'], [2, 3, '', 'Tarde'], [2, 5, '', 'Tarde'], [3, 3, '', 'Tarde'],
    [4, 1, 'TAE', 'Tarde'], [4, 1, 'TG', 'Tarde'], [5, 1, 'TAE', 'Tarde'], [5, 1, 'TG', 'Tarde'], [6, 1, 'TAE', 'Tarde'],
    [7, 1, 'TAE', 'Tarde'],
];


/* ---------------------------------------------------------------------
   CONEXIÓN Y ESTRUCTURA
   --------------------------------------------------------------------- */

/** Conexión con las tablas de cuentas listas (las crea y migra la primera vez). */
function bdCuentas(): PDO {
    $pdo = conectarBD();
    asegurarEsquemaCuentas($pdo);
    return $pdo;
}

function asegurarEsquemaCuentas(PDO $pdo): void {
    static $listo = false;
    if ($listo) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS campus_migraciones (
        version INT UNSIGNED NOT NULL PRIMARY KEY,
        aplicada_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if (!migracionAplicada($pdo, 1)) {
        // Un solo proceso migra; los demás esperan a que termine
        $pdo->query("SELECT GET_LOCK('campus_migracion_cuentas', 30)");
        try {
            if (!migracionAplicada($pdo, 1)) {
                migracionCuentasV1($pdo);
                $pdo->prepare('INSERT INTO campus_migraciones (version) VALUES (1)')->execute();
            }
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('campus_migracion_cuentas')");
        }
    }
    $listo = true;
}

function migracionAplicada(PDO $pdo, int $version): bool {
    $st = $pdo->prepare('SELECT 1 FROM campus_migraciones WHERE version = ?');
    $st->execute([$version]);
    return $st->fetchColumn() !== false;
}

function columnaExiste(PDO $pdo, string $tabla, string $columna): ?array {
    $st = $pdo->query("SHOW COLUMNS FROM `$tabla`");
    foreach ($st->fetchAll() as $c) {
        if (strcasecmp($c['Field'], $columna) === 0) {
            return $c;
        }
    }
    return null;
}

function migracionCuentasV1(PDO $pdo): void {
    asegurarTablasUsuarios($pdo);   // alumnos y profesores (instalaciones nuevas)
    $opc = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $pdo->exec("CREATE TABLE IF NOT EXISTS usuarios (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(150) NOT NULL,
        dni VARCHAR(20) NULL,
        correo VARCHAR(150) NULL,
        clave_hash VARCHAR(255) NULL,
        estado ENUM('pendiente','activo','bloqueado') NOT NULL DEFAULT 'pendiente',
        creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        ultimo_ingreso DATETIME NULL,
        UNIQUE KEY uq_usuarios_dni (dni),
        UNIQUE KEY uq_usuarios_correo (correo)
    ) $opc");

    $pdo->exec("CREATE TABLE IF NOT EXISTS usuario_roles (
        usuario_id INT UNSIGNED NOT NULL,
        rol ENUM('alumno','profesor','preceptor','directivo') NOT NULL,
        PRIMARY KEY (usuario_id, rol),
        CONSTRAINT fk_roles_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
    ) $opc");

    $pdo->exec("CREATE TABLE IF NOT EXISTS codigos_acceso (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        usuario_id INT UNSIGNED NOT NULL,
        tipo ENUM('alta','recuperacion') NOT NULL,
        codigo_hash CHAR(64) NOT NULL,
        vence DATETIME NOT NULL,
        usado_en DATETIME NULL,
        creado_por INT UNSIGNED NULL,
        creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_codigos_hash (codigo_hash),
        KEY idx_codigos_usuario (usuario_id),
        CONSTRAINT fk_codigos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
    ) $opc");

    $pdo->exec("CREATE TABLE IF NOT EXISTS solicitudes_recuperacion (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        usuario_id INT UNSIGNED NOT NULL,
        pedida_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        estado ENUM('pendiente','resuelta','descartada') NOT NULL DEFAULT 'pendiente',
        resuelta_por INT UNSIGNED NULL,
        resuelta_en DATETIME NULL,
        KEY idx_solicitudes_estado (estado),
        CONSTRAINT fk_solicitudes_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
    ) $opc");

    $pdo->exec("CREATE TABLE IF NOT EXISTS cursos (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        anio TINYINT UNSIGNED NOT NULL,
        division TINYINT UNSIGNED NOT NULL,
        tecnicatura VARCHAR(10) NOT NULL DEFAULT '',
        turno VARCHAR(10) NOT NULL,
        activo TINYINT(1) NOT NULL DEFAULT 1,
        UNIQUE KEY uq_cursos (anio, division, tecnicatura)
    ) $opc");

    $pdo->exec("CREATE TABLE IF NOT EXISTS preceptor_cursos (
        usuario_id INT UNSIGNED NOT NULL,
        curso_id INT UNSIGNED NOT NULL,
        PRIMARY KEY (usuario_id, curso_id),
        CONSTRAINT fk_precursos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
        CONSTRAINT fk_precursos_curso FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE CASCADE
    ) $opc");

    // Fichas existentes: se enlazan con usuarios; correo y contraseña pasan a ser opcionales
    if (!columnaExiste($pdo, 'alumnos', 'usuario_id')) {
        $pdo->exec('ALTER TABLE alumnos ADD COLUMN usuario_id INT UNSIGNED NULL, ADD KEY idx_alumnos_usuario (usuario_id)');
    }
    if (!columnaExiste($pdo, 'alumnos', 'curso_id')) {
        $pdo->exec('ALTER TABLE alumnos ADD COLUMN curso_id INT UNSIGNED NULL, ADD KEY idx_alumnos_curso (curso_id)');
    }
    $col = columnaExiste($pdo, 'alumnos', 'correo');
    if ($col && strtoupper($col['Null']) === 'NO') {
        $pdo->exec('ALTER TABLE alumnos MODIFY correo VARCHAR(150) NULL');
    }
    $col = columnaExiste($pdo, 'alumnos', 'clave_hash');
    if ($col && strtoupper($col['Null']) === 'NO') {
        $pdo->exec('ALTER TABLE alumnos MODIFY clave_hash VARCHAR(255) NULL');
    }
    if (!columnaExiste($pdo, 'profesores', 'usuario_id')) {
        $pdo->exec('ALTER TABLE profesores ADD COLUMN usuario_id INT UNSIGNED NULL, ADD KEY idx_profesores_usuario (usuario_id)');
    }
    $col = columnaExiste($pdo, 'profesores', 'codigo_hash');
    if ($col && strtoupper($col['Null']) === 'NO') {
        $pdo->exec('ALTER TABLE profesores MODIFY codigo_hash VARCHAR(255) NULL');
    }

    if ((int) $pdo->query('SELECT COUNT(*) FROM cursos')->fetchColumn() === 0) {
        $st = $pdo->prepare('INSERT INTO cursos (anio, division, tecnicatura, turno) VALUES (?, ?, ?, ?)');
        foreach (CURSOS_INICIALES as $c) {
            $st->execute($c);
        }
    }

    migrarCuentasExistentes($pdo);
}

/** Pasa a `usuarios` las cuentas que ya existían. Se puede repetir sin duplicar. */
function migrarCuentasExistentes(PDO $pdo): void {
    $alumnos = $pdo->query('SELECT id, nombre, correo, dni, clave_hash FROM alumnos WHERE usuario_id IS NULL')->fetchAll();
    foreach ($alumnos as $a) {
        $uid = vincularOCrearUsuario($pdo, $a['nombre'], $a['dni'], $a['correo'], $a['clave_hash'] ?: null);
        agregarRol($pdo, $uid, 'alumno');
        $pdo->prepare('UPDATE alumnos SET usuario_id = ? WHERE id = ?')->execute([$uid, $a['id']]);
    }

    // Los profesores tenían solo un código PROF-: quedan pendientes y lo usan una vez para elegir contraseña
    $profes = $pdo->query('SELECT id, nombre, correo, dni FROM profesores WHERE usuario_id IS NULL')->fetchAll();
    foreach ($profes as $p) {
        $uid = vincularOCrearUsuario($pdo, $p['nombre'], $p['dni'] ?: null, $p['correo'], null);
        agregarRol($pdo, $uid, 'profesor');
        $pdo->prepare('UPDATE profesores SET usuario_id = ? WHERE id = ?')->execute([$uid, $p['id']]);
    }

    // Directivos de datos.php (solo esta vez: desde ahora se gestionan en el panel)
    $DIRECTIVOS = [];
    if (is_file(__DIR__ . '/datos.php')) {
        include __DIR__ . '/datos.php';
    }
    foreach ($DIRECTIVOS as $d) {
        if (empty($d['correo'])) {
            continue;
        }
        $uid = vincularOCrearUsuario($pdo, $d['nombre'] ?? 'Directivo', null, $d['correo'], $d['clave_hash'] ?? null);
        agregarRol($pdo, $uid, 'directivo');
    }
}

/** Busca la cuenta por DNI o correo; si no existe la crea. Completa los datos que le falten. */
function vincularOCrearUsuario(PDO $pdo, string $nombre, ?string $dni, ?string $correo, ?string $claveHash): int {
    $dni = $dni !== null ? preg_replace('/\D/', '', $dni) : null;
    $dni = $dni !== '' ? $dni : null;
    $correo = $correo !== null ? strtolower(trim($correo)) : null;
    $correo = $correo !== '' ? $correo : null;

    $u = null;
    if ($dni !== null) {
        $u = buscarUsuarioPor($pdo, 'dni', $dni);
    }
    if (!$u && $correo !== null) {
        $u = buscarUsuarioPor($pdo, 'correo', $correo);
    }
    if (!$u) {
        $st = $pdo->prepare('INSERT INTO usuarios (nombre, dni, correo, clave_hash, estado) VALUES (?, ?, ?, ?, ?)');
        $st->execute([trim($nombre), $dni, $correo, $claveHash, $claveHash ? 'activo' : 'pendiente']);
        return (int) $pdo->lastInsertId();
    }

    // Completar sin pisar: si el dato ya lo usa otra cuenta, se deja como está
    $cambios = [];
    if ($u['dni'] === null && $dni !== null && !buscarUsuarioPor($pdo, 'dni', $dni)) {
        $cambios['dni'] = $dni;
    }
    if ($u['correo'] === null && $correo !== null && !buscarUsuarioPor($pdo, 'correo', $correo)) {
        $cambios['correo'] = $correo;
    }
    if ($u['clave_hash'] === null && $claveHash) {
        $cambios['clave_hash'] = $claveHash;
        if ($u['estado'] === 'pendiente') {
            $cambios['estado'] = 'activo';
        }
    }
    if ($cambios) {
        $sets = implode(', ', array_map(fn($c) => "$c = ?", array_keys($cambios)));
        $pdo->prepare("UPDATE usuarios SET $sets WHERE id = ?")->execute([...array_values($cambios), $u['id']]);
    }
    return (int) $u['id'];
}


/* ---------------------------------------------------------------------
   USUARIOS Y ROLES
   --------------------------------------------------------------------- */

/** $campo es siempre 'id', 'dni' o 'correo' (nunca viene del usuario). */
function buscarUsuarioPor(PDO $pdo, string $campo, $valor): ?array {
    if (!in_array($campo, ['id', 'dni', 'correo'], true)) {
        return null;
    }
    $st = $pdo->prepare("SELECT id, nombre, dni, correo, clave_hash, estado FROM usuarios WHERE $campo = ? LIMIT 1");
    $st->execute([$valor]);
    $u = $st->fetch();
    return $u ?: null;
}

/** DNI (con o sin puntos) o correo. */
function buscarUsuarioPorIdentificador(PDO $pdo, string $identificador): ?array {
    $id = trim($identificador);
    if (strpos($id, '@') !== false) {
        return buscarUsuarioPor($pdo, 'correo', strtolower($id));
    }
    $dni = preg_replace('/[\s.\-]/', '', $id);
    if (preg_match('/^\d{7,8}$/', $dni)) {
        return buscarUsuarioPor($pdo, 'dni', $dni);
    }
    return null;
}

function rolesDeUsuario(PDO $pdo, int $usuarioId): array {
    $st = $pdo->prepare('SELECT rol FROM usuario_roles WHERE usuario_id = ?');
    $st->execute([$usuarioId]);
    $roles = $st->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_filter(PRIORIDAD_ROLES, fn($r) => in_array($r, $roles, true)));
}

function agregarRol(PDO $pdo, int $usuarioId, string $rol): void {
    if (!in_array($rol, ROLES_CAMPUS, true)) {
        throw new InvalidArgumentException('Rol inválido');
    }
    $pdo->prepare('INSERT IGNORE INTO usuario_roles (usuario_id, rol) VALUES (?, ?)')->execute([$usuarioId, $rol]);
}

function etiquetaRoles(array $roles): string {
    return implode(' · ', array_map(fn($r) => ETIQUETAS_ROL[$r] ?? $r, $roles));
}

/**
 * Crea una cuenta PENDIENTE (sin contraseña: la elige la persona con su código de alta).
 * $datos: nombre, dni, correo (opcional para alumnos), curso_id (alumnos).
 * Lanza RuntimeException con un mensaje para mostrar si algo no es válido.
 */
function crearCuenta(PDO $pdo, array $datos, string $rol): int {
    if (!in_array($rol, ROLES_CAMPUS, true)) {
        throw new RuntimeException('Rol inválido.');
    }
    $nombre = trim((string) ($datos['nombre'] ?? ''));
    $dni = preg_replace('/\D/', '', (string) ($datos['dni'] ?? ''));
    $correo = strtolower(trim((string) ($datos['correo'] ?? '')));
    $cursoId = (int) ($datos['curso_id'] ?? 0);

    if ($nombre === '' || mb_strlen($nombre) > 150) {
        throw new RuntimeException('Escriba el nombre y apellido.');
    }
    if (!preg_match('/^\d{7,8}$/', $dni) && ($rol !== 'directivo' || $dni !== '')) {
        throw new RuntimeException('El DNI debe tener 7 u 8 números, sin puntos.');
    }
    if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('El correo no es válido.');
    }
    if ($rol !== 'alumno' && $correo === '') {
        throw new RuntimeException('Para profesores, preceptores y directivos el correo es obligatorio.');
    }
    if ($rol === 'alumno' && !cursoExiste($pdo, $cursoId)) {
        throw new RuntimeException('Elija el curso del alumno.');
    }
    if ($dni !== '' && buscarUsuarioPor($pdo, 'dni', $dni)) {
        throw new RuntimeException('Ya existe una cuenta con ese DNI. Si es la misma persona, agréguele el rol desde su cuenta.');
    }
    if ($correo !== '' && buscarUsuarioPor($pdo, 'correo', $correo)) {
        throw new RuntimeException('Ya existe una cuenta con ese correo.');
    }

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("INSERT INTO usuarios (nombre, dni, correo, estado) VALUES (?, ?, ?, 'pendiente')");
        $st->execute([$nombre, $dni !== '' ? $dni : null, $correo !== '' ? $correo : null]);
        $uid = (int) $pdo->lastInsertId();
        agregarRol($pdo, $uid, $rol);
        crearFicha($pdo, $uid, $rol, $nombre, $dni, $correo, $cursoId);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $uid;
}

/** Agrega un rol a una cuenta existente (por ejemplo, un profesor que también es preceptor). */
function agregarRolACuenta(PDO $pdo, int $usuarioId, string $rol, int $cursoId = 0): void {
    $u = buscarUsuarioPor($pdo, 'id', $usuarioId);
    if (!$u) {
        throw new RuntimeException('La cuenta no existe.');
    }
    if ($rol !== 'alumno' && $rol !== 'directivo' && $u['dni'] === null) {
        throw new RuntimeException('La cuenta no tiene DNI cargado.');
    }
    if ($rol !== 'alumno' && $u['correo'] === null) {
        throw new RuntimeException('La cuenta no tiene correo: es obligatorio para ese rol.');
    }
    if ($rol === 'alumno' && !cursoExiste($pdo, $cursoId)) {
        throw new RuntimeException('Elija el curso del alumno.');
    }
    agregarRol($pdo, $usuarioId, $rol);
    crearFicha($pdo, $usuarioId, $rol, $u['nombre'], (string) $u['dni'], (string) $u['correo'], $cursoId);
}

/** Ficha en `alumnos` o `profesores` (la usan los demás módulos). */
function crearFicha(PDO $pdo, int $uid, string $rol, string $nombre, string $dni, string $correo, int $cursoId): void {
    if ($rol === 'alumno') {
        $st = $pdo->prepare('SELECT id FROM alumnos WHERE dni = ? LIMIT 1');
        $st->execute([$dni]);
        $fichaId = $st->fetchColumn();
        if ($fichaId) {
            $pdo->prepare('UPDATE alumnos SET usuario_id = ?, curso_id = ?, nombre = ? WHERE id = ?')
                ->execute([$uid, $cursoId, $nombre, $fichaId]);
        } else {
            $pdo->prepare('INSERT INTO alumnos (nombre, correo, dni, clave_hash, usuario_id, curso_id) VALUES (?, ?, ?, NULL, ?, ?)')
                ->execute([$nombre, $correo !== '' ? $correo : null, $dni, $uid, $cursoId]);
        }
    } elseif ($rol === 'profesor') {
        $st = $pdo->prepare('SELECT id FROM profesores WHERE correo = ? LIMIT 1');
        $st->execute([$correo]);
        $fichaId = $st->fetchColumn();
        if ($fichaId) {
            $pdo->prepare('UPDATE profesores SET usuario_id = ?, dni = ?, nombre = ? WHERE id = ?')
                ->execute([$uid, $dni !== '' ? $dni : null, $nombre, $fichaId]);
        } else {
            $pdo->prepare('INSERT INTO profesores (nombre, dni, correo, codigo_hash, usuario_id) VALUES (?, ?, ?, NULL, ?)')
                ->execute([$nombre, $dni !== '' ? $dni : null, $correo, $uid]);
        }
    }
}

function cambiarEstadoCuenta(PDO $pdo, int $usuarioId, string $estado): void {
    $u = buscarUsuarioPor($pdo, 'id', $usuarioId);
    if (!$u) {
        throw new RuntimeException('La cuenta no existe.');
    }
    if ($estado === 'bloqueado') {
        $nuevo = 'bloqueado';
    } elseif ($estado === 'desbloqueado') {
        $nuevo = $u['clave_hash'] ? 'activo' : 'pendiente';
    } else {
        throw new RuntimeException('Estado inválido.');
    }
    $pdo->prepare('UPDATE usuarios SET estado = ? WHERE id = ?')->execute([$nuevo, $usuarioId]);
}


/* ---------------------------------------------------------------------
   INICIO DE SESIÓN
   --------------------------------------------------------------------- */

/**
 * Llena la sesión con el MISMO formato que usan los módulos:
 *   rol (principal), roles, nombre, correo, dni, usuario_id e
 *   id = 'alumno:<DNI>' | 'profesor:<correo>' | 'preceptor:<correo>' | 'directivo:<correo>'
 */
function iniciarSesionCuenta(PDO $pdo, array $u): bool {
    $roles = rolesDeUsuario($pdo, (int) $u['id']);
    if (!$roles) {
        return false;
    }
    $principal = $roles[0];
    $correo = (string) ($u['correo'] ?? '');
    $dni = (string) ($u['dni'] ?? '');

    session_regenerate_id(true);
    foreach (['rol', 'roles', 'nombre', 'correo', 'dni', 'usuario_id', 'id', 'reservas_profesor'] as $k) {
        unset($_SESSION[$k]);
    }
    $_SESSION['rol']        = $principal;
    $_SESSION['roles']      = $roles;
    $_SESSION['nombre']     = $u['nombre'];
    $_SESSION['correo']     = $correo;
    $_SESSION['dni']        = $dni;
    $_SESSION['usuario_id'] = (int) $u['id'];
    $_SESSION['id']         = $principal . ':' . ($principal === 'alumno' ? $dni : strtolower($correo !== '' ? $correo : $dni));

    $pdo->prepare('UPDATE usuarios SET ultimo_ingreso = NOW() WHERE id = ?')->execute([$u['id']]);
    return true;
}

/** Login con el código PROF- de antes: solo sirve una vez, para que el profesor elija su contraseña. */
function buscarProfesorPorCodigoLegado(PDO $pdo, string $codigo): ?array {
    $codigo = strtoupper(trim($codigo));
    if (stripos($codigo, 'PROF-') !== 0) {
        return null;
    }
    $filas = $pdo->query("SELECT p.codigo_hash, u.id, u.nombre, u.dni, u.correo, u.clave_hash, u.estado
                          FROM profesores p JOIN usuarios u ON u.id = p.usuario_id
                          WHERE p.codigo_hash IS NOT NULL AND p.codigo_hash <> '' AND u.clave_hash IS NULL
                            AND u.estado = 'pendiente'")->fetchAll();
    foreach ($filas as $f) {
        if (password_verify($codigo, $f['codigo_hash'])) {
            unset($f['codigo_hash']);
            return $f;
        }
    }
    return null;
}


/* ---------------------------------------------------------------------
   CÓDIGOS DE ACCESO (alta y recuperación)
   --------------------------------------------------------------------- */

function normalizarCodigo(string $codigo): string {
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $codigo));
}

function formatearCodigo(string $codigo): string {
    return substr($codigo, 0, 5) . '-' . substr($codigo, 5);
}

function hashCodigo(string $codigo): string {
    return hash('sha256', normalizarCodigo($codigo));
}

/**
 * Genera un código nuevo para la cuenta y anula los anteriores del mismo tipo que no se usaron.
 * Devuelve ['codigo' => 'ABCDE-FGHJK', 'vence' => 'Y-m-d H:i:s']. El código en claro no se guarda.
 */
function generarCodigoAcceso(PDO $pdo, int $usuarioId, string $tipo, ?int $creadoPor): array {
    if (!in_array($tipo, ['alta', 'recuperacion'], true)) {
        throw new InvalidArgumentException('Tipo de código inválido');
    }
    $u = buscarUsuarioPor($pdo, 'id', $usuarioId);
    if (!$u) {
        throw new RuntimeException('La cuenta no existe.');
    }
    if ($u['estado'] === 'bloqueado') {
        throw new RuntimeException('La cuenta está bloqueada: desbloquéela primero.');
    }
    if ($tipo === 'alta' && $u['clave_hash']) {
        $tipo = 'recuperacion';   // ya activada: lo que necesita es recuperar el acceso
    }
    $horas = $tipo === 'alta' ? VIGENCIA_ALTA_HORAS : VIGENCIA_RECUP_HORAS;

    $pdo->prepare('UPDATE codigos_acceso SET vence = NOW() WHERE usuario_id = ? AND usado_en IS NULL AND vence > NOW()')
        ->execute([$usuarioId]);

    $largoAlfabeto = strlen(CODIGO_ALFABETO);
    for ($intento = 0; $intento < 5; $intento++) {
        $plano = '';
        for ($i = 0; $i < CODIGO_LARGO; $i++) {
            $plano .= CODIGO_ALFABETO[random_int(0, $largoAlfabeto - 1)];
        }
        try {
            $pdo->prepare('INSERT INTO codigos_acceso (usuario_id, tipo, codigo_hash, vence, creado_por)
                           VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? HOUR), ?)')
                ->execute([$usuarioId, $tipo, hashCodigo($plano), $horas, $creadoPor]);
            $id = (int) $pdo->lastInsertId();
            $vence = $pdo->query("SELECT vence FROM codigos_acceso WHERE id = $id")->fetchColumn();
            return ['codigo' => formatearCodigo($plano), 'vence' => $vence, 'tipo' => $tipo];
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {   // 1062 = código repetido: se genera otro
                throw $e;
            }
        }
    }
    throw new RuntimeException('No se pudo generar el código. Intente de nuevo.');
}

/** Código vigente y sin usar (con los datos de la cuenta), o null. */
function buscarCodigoVigente(PDO $pdo, string $codigo): ?array {
    if (strlen(normalizarCodigo($codigo)) !== CODIGO_LARGO) {
        return null;
    }
    $st = $pdo->prepare("SELECT c.id AS codigo_id, c.tipo, u.id, u.nombre, u.dni, u.correo, u.clave_hash, u.estado
                         FROM codigos_acceso c JOIN usuarios u ON u.id = c.usuario_id
                         WHERE c.codigo_hash = ? AND c.usado_en IS NULL AND c.vence > NOW()
                           AND u.estado <> 'bloqueado' LIMIT 1");
    $st->execute([hashCodigo($codigo)]);
    $f = $st->fetch();
    return $f ?: null;
}

/**
 * Activa la cuenta (alta) o cambia la contraseña (recuperación).
 * $codigoId = null cuando viene del código PROF- de antes.
 */
function establecerClave(PDO $pdo, int $usuarioId, ?int $codigoId, string $clave, string $correoNuevo = ''): void {
    if (mb_strlen($clave) < CLAVE_LARGO_MINIMO) {
        throw new RuntimeException('La contraseña debe tener al menos ' . CLAVE_LARGO_MINIMO . ' caracteres.');
    }
    $u = buscarUsuarioPor($pdo, 'id', $usuarioId);
    if (!$u || $u['estado'] === 'bloqueado') {
        throw new RuntimeException('La cuenta no está disponible. Consulte en la escuela.');
    }
    $correoNuevo = strtolower(trim($correoNuevo));
    if ($correoNuevo !== '') {
        if (!filter_var($correoNuevo, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('El correo no es válido.');
        }
        $otro = buscarUsuarioPor($pdo, 'correo', $correoNuevo);
        if ($otro && (int) $otro['id'] !== $usuarioId) {
            throw new RuntimeException('Ese correo ya lo usa otra cuenta.');
        }
    }

    $pdo->beginTransaction();
    try {
        if ($codigoId !== null) {
            $st = $pdo->prepare('UPDATE codigos_acceso SET usado_en = NOW()
                                 WHERE id = ? AND usuario_id = ? AND usado_en IS NULL AND vence > NOW()');
            $st->execute([$codigoId, $usuarioId]);
            if ($st->rowCount() !== 1) {
                throw new RuntimeException('El código ya se usó o venció. Pida uno nuevo en la escuela.');
            }
        }
        $pdo->prepare("UPDATE usuarios SET clave_hash = ?, estado = 'activo', correo = COALESCE(correo, ?) WHERE id = ?")
            ->execute([password_hash($clave, PASSWORD_DEFAULT), $correoNuevo !== '' ? $correoNuevo : null, $usuarioId]);
        // Códigos y pedidos pendientes de esta cuenta ya no sirven
        $pdo->prepare('UPDATE codigos_acceso SET vence = NOW() WHERE usuario_id = ? AND usado_en IS NULL AND vence > NOW()')
            ->execute([$usuarioId]);
        $pdo->prepare("UPDATE solicitudes_recuperacion SET estado = 'resuelta', resuelta_en = NOW()
                       WHERE usuario_id = ? AND estado = 'pendiente'")->execute([$usuarioId]);
        // Fichas: el código PROF- deja de servir y el correo nuevo pasa a la ficha del alumno
        $pdo->prepare("UPDATE profesores SET codigo_hash = NULL WHERE usuario_id = ?")->execute([$usuarioId]);
        if ($correoNuevo !== '') {
            $pdo->prepare('UPDATE alumnos SET correo = ? WHERE usuario_id = ? AND correo IS NULL')->execute([$correoNuevo, $usuarioId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}


/* ---------------------------------------------------------------------
   PEDIDOS DE RECUPERACIÓN
   --------------------------------------------------------------------- */

function registrarSolicitudRecuperacion(PDO $pdo, int $usuarioId): void {
    $st = $pdo->prepare("SELECT 1 FROM solicitudes_recuperacion WHERE usuario_id = ? AND estado = 'pendiente'");
    $st->execute([$usuarioId]);
    if (!$st->fetchColumn()) {
        $pdo->prepare('INSERT INTO solicitudes_recuperacion (usuario_id) VALUES (?)')->execute([$usuarioId]);
    }
}

/** Pedidos pendientes. Con $cursoIds se limita a alumnos de esos cursos (panel del preceptor). */
function solicitudesPendientes(PDO $pdo, ?array $cursoIds = null): array {
    $sql = "SELECT s.id, s.usuario_id, s.pedida_en, u.nombre, u.dni, u.correo, a.curso_id,
                   (SELECT GROUP_CONCAT(r.rol) FROM usuario_roles r WHERE r.usuario_id = u.id) AS roles
            FROM solicitudes_recuperacion s
            JOIN usuarios u ON u.id = s.usuario_id
            LEFT JOIN alumnos a ON a.usuario_id = u.id
            WHERE s.estado = 'pendiente'";
    $params = [];
    if ($cursoIds !== null) {
        if (!$cursoIds) {
            return [];
        }
        $sql .= ' AND a.curso_id IN (' . implode(',', array_fill(0, count($cursoIds), '?')) . ')';
        $params = array_values($cursoIds);
    }
    $st = $pdo->prepare($sql . ' ORDER BY s.pedida_en');
    $st->execute($params);
    return $st->fetchAll();
}

function descartarSolicitud(PDO $pdo, int $solicitudId, int $resueltaPor): void {
    $pdo->prepare("UPDATE solicitudes_recuperacion SET estado = 'descartada', resuelta_por = ?, resuelta_en = NOW()
                   WHERE id = ? AND estado = 'pendiente'")->execute([$resueltaPor, $solicitudId]);
}

function marcarSolicitudesAtendidas(PDO $pdo, int $usuarioId, int $resueltaPor): void {
    $pdo->prepare("UPDATE solicitudes_recuperacion SET estado = 'resuelta', resuelta_por = ?, resuelta_en = NOW()
                   WHERE usuario_id = ? AND estado = 'pendiente'")->execute([$resueltaPor, $usuarioId]);
}


/* ---------------------------------------------------------------------
   CURSOS Y PADRÓN
   --------------------------------------------------------------------- */

function nombreCurso(array $c): string {
    return $c['anio'] . '° ' . $c['division'] . '°' . ($c['tecnicatura'] !== '' ? ' ' . $c['tecnicatura'] : '')
         . ' · ' . $c['turno'];
}

function listarCursos(PDO $pdo, bool $soloActivos = true): array {
    $sql = 'SELECT id, anio, division, tecnicatura, turno, activo FROM cursos'
         . ($soloActivos ? ' WHERE activo = 1' : '') . ' ORDER BY turno, anio, division, tecnicatura';
    return $pdo->query($sql)->fetchAll();
}

function cursoExiste(PDO $pdo, int $cursoId): bool {
    if ($cursoId <= 0) {
        return false;
    }
    $st = $pdo->prepare('SELECT 1 FROM cursos WHERE id = ? AND activo = 1');
    $st->execute([$cursoId]);
    return $st->fetchColumn() !== false;
}

function cursosDePreceptor(PDO $pdo, int $usuarioId): array {
    $st = $pdo->prepare('SELECT c.id, c.anio, c.division, c.tecnicatura, c.turno, c.activo
                         FROM preceptor_cursos pc JOIN cursos c ON c.id = pc.curso_id
                         WHERE pc.usuario_id = ? ORDER BY c.turno, c.anio, c.division, c.tecnicatura');
    $st->execute([$usuarioId]);
    return $st->fetchAll();
}

function asignarCursoPreceptor(PDO $pdo, int $usuarioId, int $cursoId, bool $asignar): void {
    if (!in_array('preceptor', rolesDeUsuario($pdo, $usuarioId), true)) {
        throw new RuntimeException('La cuenta no es de un preceptor.');
    }
    if ($asignar) {
        if (!cursoExiste($pdo, $cursoId)) {
            throw new RuntimeException('El curso no existe.');
        }
        $pdo->prepare('INSERT IGNORE INTO preceptor_cursos (usuario_id, curso_id) VALUES (?, ?)')->execute([$usuarioId, $cursoId]);
    } else {
        $pdo->prepare('DELETE FROM preceptor_cursos WHERE usuario_id = ? AND curso_id = ?')->execute([$usuarioId, $cursoId]);
    }
}

/** Alumnos de un curso, con el estado de su cuenta. */
function alumnosDeCurso(PDO $pdo, int $cursoId): array {
    $st = $pdo->prepare("SELECT u.id, u.nombre, u.dni, u.correo, u.estado, u.ultimo_ingreso,
                                (SELECT MAX(c.vence) FROM codigos_acceso c
                                  WHERE c.usuario_id = u.id AND c.usado_en IS NULL AND c.vence > NOW()) AS codigo_vence
                         FROM alumnos a JOIN usuarios u ON u.id = a.usuario_id
                         WHERE a.curso_id = ? ORDER BY u.nombre");
    $st->execute([$cursoId]);
    return $st->fetchAll();
}

/** Curso del alumno (o 0 si no tiene ficha de alumno). */
function cursoDeAlumno(PDO $pdo, int $usuarioId): int {
    $st = $pdo->prepare('SELECT curso_id FROM alumnos WHERE usuario_id = ? LIMIT 1');
    $st->execute([$usuarioId]);
    return (int) $st->fetchColumn();
}

/** ¿El preceptor atiende el curso de ese alumno? */
function preceptorGestionaAlumno(PDO $pdo, int $preceptorId, int $alumnoUsuarioId): bool {
    $st = $pdo->prepare('SELECT 1 FROM alumnos a JOIN preceptor_cursos pc ON pc.curso_id = a.curso_id
                         JOIN usuario_roles r ON r.usuario_id = a.usuario_id AND r.rol = \'alumno\'
                         WHERE a.usuario_id = ? AND pc.usuario_id = ? LIMIT 1');
    $st->execute([$alumnoUsuarioId, $preceptorId]);
    return $st->fetchColumn() !== false;
}

/** Cuentas (para el panel del directivo), con filtro opcional por rol y texto. */
function listarCuentas(PDO $pdo, string $rol = '', string $buscar = ''): array {
    $sql = "SELECT u.id, u.nombre, u.dni, u.correo, u.estado, u.ultimo_ingreso,
                   GROUP_CONCAT(r.rol ORDER BY FIELD(r.rol,'directivo','profesor','preceptor','alumno')) AS roles,
                   (SELECT MAX(c.vence) FROM codigos_acceso c
                     WHERE c.usuario_id = u.id AND c.usado_en IS NULL AND c.vence > NOW()) AS codigo_vence,
                   (SELECT a.curso_id FROM alumnos a WHERE a.usuario_id = u.id LIMIT 1) AS curso_id
            FROM usuarios u LEFT JOIN usuario_roles r ON r.usuario_id = u.id";
    $where = [];
    $params = [];
    if (in_array($rol, ROLES_CAMPUS, true)) {
        $where[] = 'u.id IN (SELECT usuario_id FROM usuario_roles WHERE rol = ?)';
        $params[] = $rol;
    }
    if ($buscar !== '') {
        $where[] = '(u.nombre LIKE ? OR u.dni LIKE ? OR u.correo LIKE ?)';
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $buscar) . '%';
        array_push($params, $like, $like, $like);
    }
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $st = $pdo->prepare($sql . ' GROUP BY u.id ORDER BY u.nombre LIMIT 500');
    $st->execute($params);
    return $st->fetchAll();
}


/* ---------------------------------------------------------------------
   AUDITORÍA
   --------------------------------------------------------------------- */

function auditarCuentas(string $accion): void {
    $quien = ($_SESSION['correo'] ?? '') ?: ($_SESSION['nombre'] ?? 'sistema');
    error_log('[CAMPUS CUENTAS] ' . preg_replace('/[\x00-\x1F\x7F]+/u', ' ', "$quien: $accion"));
}
