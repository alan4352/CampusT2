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

// PASO 1: iniciar (o retomar) la sesión con cookies seguras (HttpOnly, SameSite=Lax, Secure en HTTPS y strict mode).
// DEBE ejecutarse ANTES de escribir cualquier HTML en la página.
if (session_status() === PHP_SESSION_NONE) {
    $esHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $esHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    @ini_set('session.use_strict_mode', '1');
    session_start();
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
    'programa'   => ['directivo', 'profesor', 'alumno'],      // Programas de examen
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
    // in_array() busca el rol del usuario dentro de la lista de roles permitidos
    return in_array($_SESSION['rol'], $PERMISOS[$modulo], true);
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


/* ---------------------------------------------------------------------
   PROTECCIÓN CSRF
   Genera y valida un token de sesión para proteger formularios POST.
   --------------------------------------------------------------------- */
function obtenerTokenCsrf(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_token'];
}

function validarTokenCsrf(?string $token): bool {
    return !empty($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)$token);
}
