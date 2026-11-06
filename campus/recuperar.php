<?php
/* =====================================================================
   ARCHIVO: recuperar.php
   PARA QUÉ SIRVE: le explica al alumno cómo recuperar su contraseña.
   La contraseña NO se restablece desde acá: con el DNI y una clave
   compartida cualquiera podía quedarse con la cuenta de otro alumno.
   La restablece un directivo desde restablecer.php.
   ===================================================================== */
require_once 'includes/sesion.php';

// Si ya inició sesión, redirigimos al panel
if (estaLogueado()) {
    header('Location: dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperar contraseña — Campus Virtual</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="fondo-login">

<main class="login">
    <!-- LOGO DE LA ESCUELA -->
    <img src="img/logo.png" alt="Logo de la escuela" class="logo logo--login" onerror="this.style.display='none'">
    <h1>Campus Virtual</h1>
    <p class="login__sub">Recuperar contraseña de Alumno</p>

    <div class="exito">
        <p><strong>¿Olvidó su contraseña?</strong></p>
        <p>Pídale a un directivo de la escuela que la restablezca. Le van a pedir su <strong>DNI</strong>
           y le van a dar una contraseña nueva para ingresar.</p>
    </div>

    <!-- ACCIONES: VOLVER AL LOGIN Y AL INICIO -->
    <div class="login__acciones">
        <a href="login.php" class="boton boton--secundario">← Volver a iniciar sesión</a>
        <a href="index.php" class="boton boton--oscuro">Volver al inicio</a>
    </div>
</main>

</body>
</html>
