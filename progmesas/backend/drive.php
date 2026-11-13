<?php
/* =====================================================================
   ARCHIVO: progmesas/backend/drive.php
   PARA QUÉ SIRVE: Google Drive para la versión PHP de ProgMesas (api.php),
   sin librerías externas: la API REST de Drive v3 con OAuth 2.0.

   Usa las mismas credenciales que el servidor Node:
     - backend/oauth.json  → la app de Google (client_id y client_secret)
     - backend/token.json  → el permiso de la cuenta de la escuela (refresh_token)
   En un hosting sin esos archivos (Render), su contenido puede venir en las
   variables de entorno GOOGLE_OAUTH_JSON y GOOGLE_TOKEN_JSON.
   El permiso se obtiene una vez con:  php backend/autorizar_drive.php

   Los PDF se guardan en carpetas Año / Curso / Materia dentro de la carpeta
   raíz y quedan públicos de sólo lectura, como en la versión Node.
   ===================================================================== */

// Este archivo sólo define funciones: no se abre desde el navegador
if (PHP_SAPI !== 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

class DriveError extends RuntimeException {}

/** Valor de configuración: variable de entorno, o constante definida en campus/includes/config.php. */
function driveAjuste(string $nombre, string $porDefecto = ''): string {
    $env = getenv($nombre);
    if ($env !== false && $env !== '') return trim($env);
    $constante = 'PROGMESAS_' . $nombre;
    if (defined($constante)) return trim((string)constant($constante));
    return $porDefecto;
}

function driveConfig(): array {
    static $config = null;
    if ($config !== null) return $config;

    $alcance = strtolower(driveAjuste('DRIVE_ALCANCE', 'completo'));
    $config = [
        'desactivado' => in_array(strtolower(driveAjuste('DRIVE_DESACTIVADO')), ['1', 'true', 'si', 'sí'], true),
        'oauth' => __DIR__ . '/oauth.json',
        'token' => __DIR__ . '/token.json',
        // Carpeta de la escuela (alcance "completo") o una carpeta propia que la app crea (alcance "archivos")
        'raiz' => driveAjuste('DRIVE_CARPETA_RAIZ', $alcance === 'archivos' ? '' : '1EY74DSygnNwaxm6hWhKm5-alO-0X0Lnl'),
        'nombreRaiz' => driveAjuste('DRIVE_CARPETA_NOMBRE', 'ProgMesas - Programas de examen'),
        'permiso' => $alcance === 'archivos'
            ? 'https://www.googleapis.com/auth/drive.file'
            : 'https://www.googleapis.com/auth/drive',
        // Direcciones de Google (las pruebas automáticas las cambian por un Drive simulado)
        'api' => rtrim(driveAjuste('DRIVE_API', 'https://www.googleapis.com'), '/'),
        'tokenUrl' => driveAjuste('DRIVE_TOKEN_URL', 'https://oauth2.googleapis.com/token'),
    ];
    return $config;
}

function driveLeerJson(string $variable, string $ruta): ?array {
    $texto = getenv($variable);
    if ($texto === false || trim($texto) === '') {
        $texto = is_file($ruta) ? (string)@file_get_contents($ruta) : '';
    }
    $datos = $texto !== '' ? json_decode($texto, true) : null;
    return is_array($datos) ? $datos : null;
}

/** Credenciales de la app de Google (oauth.json): ['client_id', 'client_secret', 'redirect_uris']. */
function driveApp(): ?array {
    $c = driveLeerJson('GOOGLE_OAUTH_JSON', driveConfig()['oauth']);
    $app = $c['installed'] ?? $c['web'] ?? null;
    return is_array($app) && !empty($app['client_id']) ? $app : null;
}

/** null si Drive está listo para usarse; si no, el motivo. */
function driveFaltaConfigurar(): ?string {
    $cfg = driveConfig();
    if ($cfg['desactivado']) return 'Drive desactivado (DRIVE_DESACTIVADO)';
    if (!ini_get('allow_url_fopen') || !extension_loaded('openssl')) {
        return 'PHP no puede conectarse a Google (hace falta allow_url_fopen = On y la extensión openssl)';
    }
    if (!driveApp()) return 'Falta backend/oauth.json con las credenciales de la app de Google';
    $token = driveLeerJson('GOOGLE_TOKEN_JSON', $cfg['token']);
    if (empty($token['refresh_token']) && empty($token['access_token'])) {
        return 'Falta el permiso de Google Drive (ejecutá: php backend/autorizar_drive.php)';
    }
    return null;
}

/** Pedido HTTP sin extensiones extra (sólo openssl, que PHP trae). Devuelve [código, cuerpo]. */
function driveHttp(string $metodo, string $url, array $cabeceras = [], ?string $cuerpo = null): array {
    $opciones = [
        'method' => $metodo,
        'header' => implode("\r\n", $cabeceras),
        'ignore_errors' => true,
        'timeout' => 60,
        'protocol_version' => 1.1,
    ];
    if ($cuerpo !== null) $opciones['content'] = $cuerpo;
    $opciones['header'] .= ($opciones['header'] !== '' ? "\r\n" : '') . 'Connection: close';

    $respuesta = @file_get_contents($url, false, stream_context_create(['http' => $opciones]));
    $estado = 0;
    foreach ($http_response_header ?? [] as $linea) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $linea, $m)) $estado = (int)$m[1];
    }
    if ($respuesta === false && $estado === 0) {
        throw new DriveError('No se pudo conectar con Google Drive');
    }
    return [$estado, (string)$respuesta];
}

/** Mensaje de error de una respuesta de Google. */
function driveMensajeError(int $estado, string $cuerpo): string {
    $datos = json_decode($cuerpo, true);
    $error = $datos['error'] ?? null;
    if ($error === 'invalid_grant') {
        return 'El permiso de Google Drive venció. Volvé a autorizar con: php backend/autorizar_drive.php';
    }
    $mensaje = is_array($error) ? ($error['message'] ?? '') : (string)($datos['error_description'] ?? $error ?? '');
    return 'Google Drive respondió ' . $estado . ($mensaje !== '' ? ": $mensaje" : '');
}

/** Token de acceso vigente; lo renueva con el refresh_token cuando vence. */
function driveTokenAcceso(): string {
    static $cache = null;
    if ($cache && $cache['vence'] > time() + 60) return $cache['token'];

    $cfg = driveConfig();
    $falta = driveFaltaConfigurar();
    if ($falta) throw new DriveError($falta);
    $token = driveLeerJson('GOOGLE_TOKEN_JSON', $cfg['token']) ?? [];

    // expiry_date viene en milisegundos (formato de la librería de Google que usa la versión Node)
    $vence = isset($token['expiry_date']) ? intdiv((int)$token['expiry_date'], 1000) : 0;
    if (!empty($token['access_token']) && $vence > time() + 60) {
        $cache = ['token' => $token['access_token'], 'vence' => $vence];
        return $cache['token'];
    }
    if (empty($token['refresh_token'])) {
        throw new DriveError('El permiso de Google Drive venció. Volvé a autorizar con: php backend/autorizar_drive.php');
    }

    $app = driveApp();
    [$estado, $cuerpo] = driveHttp('POST', $cfg['tokenUrl'], ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
        'client_id' => $app['client_id'],
        'client_secret' => $app['client_secret'] ?? '',
        'refresh_token' => $token['refresh_token'],
        'grant_type' => 'refresh_token',
    ]));
    $nuevo = json_decode($cuerpo, true);
    if ($estado !== 200 || empty($nuevo['access_token'])) {
        throw new DriveError(driveMensajeError($estado, $cuerpo));
    }

    $vence = time() + (int)($nuevo['expires_in'] ?? 3600);
    $cache = ['token' => $nuevo['access_token'], 'vence' => $vence];

    // Se guarda el token renovado (si viene de un archivo que se puede escribir)
    if (trim((string)getenv('GOOGLE_TOKEN_JSON')) === '' && is_writable($cfg['token'])) {
        $token['access_token'] = $nuevo['access_token'];
        $token['expiry_date'] = $vence * 1000;
        if (!empty($nuevo['refresh_token'])) $token['refresh_token'] = $nuevo['refresh_token'];
        @file_put_contents($cfg['token'], json_encode($token, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    return $cache['token'];
}

/** Llamada a la API de Drive v3. $ruta empieza con /drive/v3 o /upload/drive/v3. Devuelve [código, cuerpo]. */
function driveApi(string $metodo, string $ruta, array $query = [], ?string $cuerpo = null, string $tipo = 'application/json'): array {
    $url = driveConfig()['api'] . $ruta . ($query ? '?' . http_build_query($query) : '');
    $cabeceras = ['Authorization: Bearer ' . driveTokenAcceso()];
    if ($cuerpo !== null) $cabeceras[] = "Content-Type: $tipo";
    return driveHttp($metodo, $url, $cabeceras, $cuerpo);
}

function driveJson(string $metodo, string $ruta, array $query = [], ?array $datos = null): array {
    [$estado, $cuerpo] = driveApi($metodo, $ruta, $query, $datos === null ? null : json_encode($datos, JSON_UNESCAPED_UNICODE));
    if ($estado < 200 || $estado >= 300) throw new DriveError(driveMensajeError($estado, $cuerpo));
    return json_decode($cuerpo, true) ?: [];
}

/** Escapa un texto para una consulta de Drive (q=...). */
function driveEscapar(string $texto): string {
    return str_replace(["\\", "'"], ["\\\\", "\\'"], $texto);
}

/** Busca una carpeta por nombre dentro de otra; si no existe, la crea. Devuelve su id. */
function driveCarpeta(string $nombre, string $padre): string {
    static $cache = [];
    $clave = "$padre/$nombre";
    if (isset($cache[$clave])) return $cache[$clave];

    $q = "'" . driveEscapar($padre) . "' in parents and name='" . driveEscapar($nombre) . "'"
        . " and mimeType='application/vnd.google-apps.folder' and trashed=false";
    $lista = driveJson('GET', '/drive/v3/files', ['q' => $q, 'fields' => 'files(id,name)']);
    if (!empty($lista['files'][0]['id'])) return $cache[$clave] = $lista['files'][0]['id'];

    $nueva = driveJson('POST', '/drive/v3/files', ['fields' => 'id'], [
        'name' => $nombre,
        'mimeType' => 'application/vnd.google-apps.folder',
        'parents' => [$padre],
    ]);
    return $cache[$clave] = $nueva['id'];
}

function driveRaiz(): string {
    $cfg = driveConfig();
    return $cfg['raiz'] !== '' ? $cfg['raiz'] : driveCarpeta($cfg['nombreRaiz'], 'root');
}

/** Sube un PDF a Año / Curso / Materia, lo deja público (sólo lectura) y devuelve el id del archivo. */
function driveSubir(string $contenido, int $anio, string $curso, string $materia, string $profesor, string $tipo = ''): string {
    $carpetaAnio = driveCarpeta((string)$anio, driveRaiz());
    $carpetaCurso = driveCarpeta($curso, $carpetaAnio);
    $carpetaMateria = driveCarpeta($materia, $carpetaCurso);

    $nombre = "$anio-{$curso}_{$profesor}_{$materia}" . ($tipo === 'libre' ? '_libre' : '') . '.pdf';
    $limite = 'progmesas' . bin2hex(random_bytes(8));
    $cuerpo = "--$limite\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n"
        . json_encode(['name' => $nombre, 'parents' => [$carpetaMateria]], JSON_UNESCAPED_UNICODE) . "\r\n"
        . "--$limite\r\nContent-Type: application/pdf\r\n\r\n" . $contenido . "\r\n--$limite--";

    [$estado, $respuesta] = driveApi('POST', '/upload/drive/v3/files', ['uploadType' => 'multipart', 'fields' => 'id'],
        $cuerpo, "multipart/related; boundary=$limite");
    $datos = json_decode($respuesta, true);
    if ($estado < 200 || $estado >= 300 || empty($datos['id'])) {
        throw new DriveError(driveMensajeError($estado, $respuesta));
    }

    driveJson('POST', '/drive/v3/files/' . rawurlencode($datos['id']) . '/permissions', [], ['role' => 'reader', 'type' => 'anyone']);
    return $datos['id'];
}

/** Contenido de un archivo de Drive (para el ZIP de un curso). */
function driveDescargar(string $id): string {
    [$estado, $cuerpo] = driveApi('GET', '/drive/v3/files/' . rawurlencode($id), ['alt' => 'media']);
    if ($estado !== 200) throw new DriveError(driveMensajeError($estado, $cuerpo));
    return $cuerpo;
}

/** Borra un archivo de Drive. Si ya no existe no es un error. */
function driveEliminar(string $id): void {
    if ($id === '') return;
    [$estado, $cuerpo] = driveApi('DELETE', '/drive/v3/files/' . rawurlencode($id));
    if ($estado !== 204 && $estado !== 200 && $estado !== 404) {
        throw new DriveError(driveMensajeError($estado, $cuerpo));
    }
}

/** Dirección para ver el PDF de Drive dentro de la página. */
function driveUrlVista(string $id): string {
    return 'https://drive.google.com/file/d/' . rawurlencode($id) . '/preview';
}
