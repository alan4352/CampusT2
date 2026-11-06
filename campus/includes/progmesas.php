<?php
/* =====================================================================
   ARCHIVO: includes/progmesas.php
   PARA QUÉ SIRVE: arma el "pase" (token JWT firmado con HS256) que
   permite entrar a ProgMesas con la sesión del campus, sin volver a
   escribir la contraseña.

   El pase lleva: quién es (id), su nombre, su correo y su rol del campus
   (alumno, profesor o directivo). ProgMesas lo traduce así:
     profesor  → docente   (carga los programas de cualquier curso)
     directivo → asesora   (dashboard y control de entregas)
     alumno    → alumno    (consulta)
   Está firmado con PROGMESAS_SECRETO: si alguien lo modifica, ProgMesas
   lo rechaza.
   ===================================================================== */
require_once __DIR__ . '/config.php';

// Codificación "base64url" que usa JWT (base64 sin '=' y con - y _).
function base64url($datos) {
    return rtrim(strtr(base64_encode($datos), '+/', '-_'), '=');
}

// true si la clave compartida ya se configuró en config.php.
function progmesasConfigurado() {
    return strlen(PROGMESAS_SECRETO) >= 32 && strpos(PROGMESAS_SECRETO, 'CAMBIAR') !== 0;
}

// Crea el pase para el usuario con sesión iniciada.
function crearPaseProgMesas($id, $nombre, $correo, $rol) {
    $cabecera = ['alg' => 'HS256', 'typ' => 'JWT'];
    $ahora = time();
    $datos = [
        'sub'   => (string) $id,
        'name'  => (string) $nombre,
        'email' => (string) $correo,
        'role'  => (string) $rol,
        'iat'   => $ahora,
        'exp'   => $ahora + PROGMESAS_HORAS * 3600,
    ];
    $json = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    $partes = base64url(json_encode($cabecera, $json)) . '.' . base64url(json_encode($datos, $json));
    $firma = hash_hmac('sha256', $partes, PROGMESAS_SECRETO, true);
    return $partes . '.' . base64url($firma);
}

// Dirección de ProgMesas con el pase incluido.
// Si el servidor Node está activo en el puerto 3000 lo usa; si no, abre la versión integrada en Apache/XAMPP (../progmesas).
function enlaceProgMesas($id, $nombre, $correo, $rol) {
    $base = '../progmesas';
    $sock = @fsockopen('127.0.0.1', 3000, $errno, $errstr, 0.12);
    if (is_resource($sock)) {
        fclose($sock);
        $base = rtrim(PROGMESAS_URL, '/');
    }
    return $base . '/?token=' . rawurlencode(crearPaseProgMesas($id, $nombre, $correo, $rol));
}
