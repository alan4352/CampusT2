<?php
/* =====================================================================
   ARCHIVO: logout.php
   PARA QUÉ SIRVE: cierra la sesión del usuario y lo manda al inicio.
   ===================================================================== */
require_once 'includes/sesion.php';

$_SESSION = [];     // 1) Vaciamos todos los datos guardados en la sesión
session_destroy();  // 2) Destruimos la sesión en el servidor

header('Location: login.php'); // 3) Volvemos a la pantalla de inicio de sesión
exit;
