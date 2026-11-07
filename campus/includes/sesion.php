<?php
/* =====================================================================
   ARCHIVO: includes/sesion.php
   PARA QUÉ SIRVE: reúne todo lo relacionado con la SESIÓN y los
   PERMISOS. Los demás archivos lo incluyen con require_once.

   ¿QUÉ ES UNA SESIÓN?
   Es la "memoria" del servidor sobre cada visitante. Cuando alguien
   inicia sesión guardamos sus datos en $_SESSION y PHP los recuerda
   mientras navega de una página a otra.
   ===================================================================== */

// PASO 1: iniciar (o retomar) la sesión.
// DEBE ejecutarse ANTES de escribir cualquier HTML en la página.
// La cookie de sesión: JavaScript no puede leerla (httponly), no viaja en pedidos que arma otro
// sitio (samesite) y por HTTPS (ngrok) solo viaja cifrada (secure). El servidor no acepta IDs de
// sesión inventados (use_strict_mode).
if (session_status() === PHP_SESSION_NONE) {
    $esHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $esHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}


/* ---------------------------------------------------------------------
   PROTECCIÓN CSRF PARA FORMULARIOS
   csrf_campo() va dentro de cada <form method="post">; csrf_valido()
   se revisa antes de procesar el POST.
   --------------------------------------------------------------------- */
function csrf_token() {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_campo() {
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}

function csrf_valido() {
    return isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string) $_POST['csrf']);
}


/* ---------------------------------------------------------------------
   TABLA DE PERMISOS
   Define qué roles pueden entrar a cada módulo.
   AQUÍ SE CAMBIAN LOS PERMISOS en el futuro: agregue o quite roles
   de la lista de cada módulo.
   --------------------------------------------------------------------- */
$PERMISOS = [
    'proyector'  => ['directivo', 'profesor'],                // Reservar proyector (alumnos NO)
    'mesa'       => ['directivo', 'alumno'],                  // Mesa de examen (profesores NO tienen preinscripción)
    'programa'   => ['directivo', 'profesor', 'preceptor', 'alumno'], // Programas de examen (preceptor: consulta)
    'cuentas'    => ['directivo'],                            // Gestión de cuentas de todo el personal y alumnos
    'padron'     => ['preceptor'],                            // Alumnos y códigos de los cursos del preceptor
];


/* ---------------------------------------------------------------------
   FUNCIÓN: estaLogueado()
   Devuelve true si el usuario ya inició sesión correctamente.
   --------------------------------------------------------------------- */
function estaLogueado() {
    // isset() pregunta: "¿existe esta variable de sesión?"
    return isset($_SESSION['rol']) && isset($_SESSION['nombre']);
}


/* ---------------------------------------------------------------------
   FUNCIÓN: requerirLogin()
   Se pone al inicio de las páginas privadas. Si NO hay sesión,
   manda al visitante al login y detiene el resto del código.
   --------------------------------------------------------------------- */
function requerirLogin() {
    if (!estaLogueado()) {
        header('Location: login.php'); // Redirige al formulario de acceso
        exit;                          // exit es OBLIGATORIO: corta la ejecución
    }
}


/* ---------------------------------------------------------------------
   FUNCIÓN: tienePermiso($modulo)
   Devuelve true si el rol guardado en la sesión puede usar el módulo.
   --------------------------------------------------------------------- */
function tienePermiso($modulo) {
    global $PERMISOS; // Permite usar el array de arriba dentro de la función
    if (!estaLogueado() || !isset($PERMISOS[$modulo])) {
        return false;
    }
    // Una persona puede tener varios roles (por ejemplo, profesor y preceptor): alcanza con uno
    return (bool) array_intersect(rolesSesion(), $PERMISOS[$modulo]);
}


/* ---------------------------------------------------------------------
   ROLES DE LA SESIÓN
   $_SESSION['rol'] es el rol principal (lo usan los módulos viejos);
   $_SESSION['roles'] son todos los de la persona.
   --------------------------------------------------------------------- */
function rolesSesion(): array {
    if (!estaLogueado()) {
        return [];
    }
    $roles = $_SESSION['roles'] ?? [$_SESSION['rol']];
    return is_array($roles) ? $roles : [$_SESSION['rol']];
}

function tieneRol(string $rol): bool {
    return in_array($rol, rolesSesion(), true);
}

/** Corta la página si la persona no tiene el permiso (va al panel, o al login si no inició sesión). */
function requerirPermiso(string $modulo): void {
    requerirLogin();
    if (!tienePermiso($modulo)) {
        header('Location: dashboard.php');
        exit;
    }
}


/* ---------------------------------------------------------------------
   FUNCIÓN: e($texto)
   Atajo para "escapar" texto antes de mostrarlo en HTML.
   Evita que alguien inyecte código malicioso (ataque XSS).
   REGLA DE ORO: todo dato de usuario que imprima, páselo por e().
   --------------------------------------------------------------------- */
function e($texto) {
    return htmlspecialchars((string)$texto, ENT_QUOTES, 'UTF-8');
}
