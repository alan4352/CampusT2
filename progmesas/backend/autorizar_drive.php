<?php
/* =====================================================================
   Autoriza a ProgMesas (versión PHP) a usar el Google Drive de la escuela
   y guarda el permiso en backend/token.json. Se ejecuta una sola vez, en
   la línea de comandos, desde la carpeta progmesas:

       php backend/autorizar_drive.php

   1. Abrí el enlace que aparece, iniciá sesión con la cuenta dueña de la
      carpeta de programas y aceptá.
   2. El navegador va a ir a http://localhost/?code=... (puede decir que la
      página no carga: es normal).
   3. Copiá la dirección completa de la barra (o sólo el valor de "code") y
      pegala acá.

   Hace falta backend/oauth.json (la app de Google). Si la app está en modo
   "Testing" en Google Cloud, el permiso vence a los 7 días: pasala a
   "En producción" en la pantalla de consentimiento de OAuth.
   ===================================================================== */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/drive.php';

$cfg = driveConfig();
$app = driveApp();
if (!$app) {
    fwrite(STDERR, "No se encontró backend/oauth.json con las credenciales de la app de Google.\n");
    exit(1);
}
$redireccion = $app['redirect_uris'][0] ?? 'http://localhost';

$url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
    'client_id' => $app['client_id'],
    'redirect_uri' => $redireccion,
    'response_type' => 'code',
    'access_type' => 'offline',
    'prompt' => 'consent',
    'scope' => $cfg['permiso'],
]);

echo "\nPermiso pedido: {$cfg['permiso']}\n\nAbrí este enlace en el navegador:\n\n$url\n\n";
echo 'Pegá la dirección a la que te redirigió (o el código): ';
$respuesta = trim((string)fgets(STDIN));
$codigo = $respuesta;
if (preg_match('#^https?://#', $respuesta)) {
    parse_str((string)parse_url($respuesta, PHP_URL_QUERY), $params);
    $codigo = $params['code'] ?? '';
}
if ($codigo === '') {
    fwrite(STDERR, "No se encontró el código.\n");
    exit(1);
}

try {
    [$estado, $cuerpo] = driveHttp('POST', $cfg['tokenUrl'], ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
        'code' => $codigo,
        'client_id' => $app['client_id'],
        'client_secret' => $app['client_secret'] ?? '',
        'redirect_uri' => $redireccion,
        'grant_type' => 'authorization_code',
    ]));
} catch (DriveError $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
$token = json_decode($cuerpo, true);
if ($estado !== 200 || empty($token['refresh_token'])) {
    fwrite(STDERR, 'No se pudo obtener el permiso: ' . driveMensajeError($estado, $cuerpo) . "\n");
    exit(1);
}
$token['expiry_date'] = (time() + (int)($token['expires_in'] ?? 3600)) * 1000;
file_put_contents($cfg['token'], json_encode($token, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
@chmod($cfg['token'], 0640);

echo "\nListo: se guardó backend/token.json.\n";
echo "Si el servidor web corre con otro usuario (por ejemplo www-data), dale permiso de lectura y escritura a ese archivo.\n";
if (!empty($token['refresh_token_expires_in'])) {
    $dias = (int)round($token['refresh_token_expires_in'] / 86400);
    echo "Atención: este permiso vence en $dias días porque la app está en modo \"Testing\".\n";
}
