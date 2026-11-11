<?php
/* =====================================================================
   ARCHIVO: includes/limite_intentos.php
   PARA QUÉ SIRVE: frena a quien prueba contraseñas o códigos sin parar.
   Cuenta los fallos por "clave" (una cuenta, una IP, un formulario) y
   bloquea un rato cuando se pasa del máximo. Lo usan login.php,
   activar.php y recuperar.php.

   No se cuenta solo por IP: detrás de un proxy (ngrok, Render) muchas
   personas pueden compartir la misma y se bloquearía a toda la escuela.
   ===================================================================== */

const BLOQUEO_SEGUNDOS = 900;   // 15 minutos

/** IP del visitante: el último valor de X-Forwarded-For lo agrega el proxy; lo anterior lo puede inventar el cliente. */
function ipCliente(): string {
    $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($xff !== '') {
        $partes = array_map('trim', explode(',', $xff));
        $ultima = end($partes);
        if (filter_var($ultima, FILTER_VALIDATE_IP)) {
            return $ultima;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function archivoIntentos(string $clave): string {
    $carpeta = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'campus_login';
    if (!is_dir($carpeta)) {
        @mkdir($carpeta, 0700, true);
    }
    return $carpeta . DIRECTORY_SEPARATOR . hash('sha256', $clave) . '.json';
}

function leerIntentos(string $clave): array {
    $datos = ['fallos' => 0, 'bloqueado_hasta' => 0];
    $raw = @file_get_contents(archivoIntentos($clave));
    if ($raw) {
        $dec = json_decode($raw, true);
        if (is_array($dec)) {
            $datos = array_merge($datos, $dec);
        }
    }
    if ($datos['bloqueado_hasta'] > 0 && $datos['bloqueado_hasta'] <= time()) {
        $datos = ['fallos' => 0, 'bloqueado_hasta' => 0];   // el bloqueo venció
    }
    return $datos;
}

function sumarFallo(string $clave, int $maximo): void {
    $d = leerIntentos($clave);
    $d['fallos']++;
    if ($d['fallos'] >= $maximo) {
        $d['bloqueado_hasta'] = time() + BLOQUEO_SEGUNDOS;
    }
    @file_put_contents(archivoIntentos($clave), json_encode($d), LOCK_EX);
}

function borrarIntentos(string $clave): void {
    @unlink(archivoIntentos($clave));
}

/** Momento hasta el que alguna de las claves está bloqueada (0 si ninguna). */
function bloqueadoHasta(string ...$claves): int {
    $max = 0;
    foreach ($claves as $c) {
        $max = max($max, (int) leerIntentos($c)['bloqueado_hasta']);
    }
    return $max > time() ? $max : 0;
}

function minutosRestantes(int $hasta): int {
    return max(1, (int) ceil(($hasta - time()) / 60));
}

/** Normaliza lo que escribió el usuario para que "40.123.456" y "40123456" cuenten igual. */
function claveCuenta(string $identificador): string {
    $id = strtolower(trim($identificador));
    if (preg_match('/^[\d.\s-]+$/', $id)) {
        $id = preg_replace('/\D/', '', $id);
    }
    return 'cuenta:' . $id;
}
