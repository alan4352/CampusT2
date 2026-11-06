<?php
/* =====================================================================
   ARCHIVO: progmesas/index.php
   PARA QUÉ SIRVE: entrada a ProgMesas cuando corre dentro del campus,
   sobre Apache/XAMPP (con api.php, sin Node).
   - Si la persona ya inició sesión en el campus y llega sin pase, la
     manda a ../campus/programas.php, que arma el pase y vuelve acá.
   - Si no, muestra ProgMesas en modo consulta pública.
   ===================================================================== */

if (empty($_GET['token']) && is_file(__DIR__ . '/../campus/includes/sesion.php')) {
    require_once __DIR__ . '/../campus/includes/sesion.php';
    if (function_exists('estaLogueado') && estaLogueado()) {
        header('Location: ../campus/programas.php');
        exit;
    }
}

header('Content-Type: text/html; charset=utf-8');
readfile(__DIR__ . '/index.html');
