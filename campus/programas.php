<?php
/* =====================================================================
   ARCHIVO: programas.php
   PARA QUÉ SIRVE: abre ProgMesas (programas de examen) con la sesión
   del campus. Es el destino del módulo "Programas de examen".

   - Sin sesión: manda al login y, al ingresar, vuelve aquí solo.
   - Con sesión: arma el pase y redirige a ProgMesas.
   ===================================================================== */
require_once 'includes/sesion.php';
require_once 'includes/progmesas.php';

if (!estaLogueado()) {
    $_SESSION['volver_a'] = 'programas.php'; // login.php lo usa al ingresar
    header('Location: login.php');
    exit;
}

// Mismo control que el resto de los módulos (tabla $PERMISOS en sesion.php)
if (!tienePermiso('programa')) {
    http_response_code(403);
    $error = 'Su tipo de usuario no tiene permiso para ver este módulo.';
} elseif (!progmesasConfigurado()) {
    $error = 'Falta configurar la clave de ProgMesas (PROGMESAS_SECRETO en includes/config.php).';
} else {
    // El id identifica a la persona en ProgMesas: correo o identificador de sesión
    $correo = $_SESSION['email'] ?? $_SESSION['correo'] ?? '';
    $id = ($correo !== '') ? $correo : ($_SESSION['usuario_id'] ?? $_SESSION['id'] ?? ($_SESSION['rol'] . ':' . $_SESSION['nombre']));
    header('Location: ' . enlaceProgMesas($id, $_SESSION['nombre'], $correo, $_SESSION['rol']));
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Programas de examen — Campus Virtual</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<main class="contenedor panel">
    <h1>Programas de examen</h1>
    <div class="error"><?php echo e($error); ?></div>
    <p><a href="dashboard.php" class="boton boton--chico">← Volver al panel</a></p>
</main>
</body>
</html>
