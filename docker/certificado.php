<?php
// Guarda el certificado del proveedor de MySQL (variable DB_SSL_CA) como archivo PEM válido.
// Render a veces junta todo el texto en una sola línea: aquí se rearma con los saltos de línea.
// Imprime la ruta del archivo si el certificado es válido; si no, no imprime nada (la conexión sigue cifrada sin verificar).
$texto = (string) getenv('DB_SSL_CA');
$pem = '';
if (preg_match_all('/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s', $texto, $m)) {
    foreach ($m[1] as $cuerpo) {
        $base64 = preg_replace('/[^A-Za-z0-9+\/=]/', '', $cuerpo);
        $pem .= "-----BEGIN CERTIFICATE-----\n" . chunk_split($base64, 64, "\n") . "-----END CERTIFICATE-----\n";
    }
}
if ($pem === '' || !@openssl_x509_read($pem)) {
    fwrite(STDERR, "[Campus] DB_SSL_CA no tiene un certificado válido: la conexión a MySQL va cifrada pero sin verificar el servidor\n");
    exit(0);
}
$ruta = '/etc/ssl/certs/mysql-proveedor.pem';
file_put_contents($ruta, $pem);
echo $ruta;
