<?php
/* =====================================================================
   ARCHIVO: recuperar.php
   PARA QUÉ SIRVE: información para alumnos sobre cómo restablecer su
   contraseña de forma segura. Por motivos de seguridad y privacidad,
   las contraseñas las restablece el personal directivo de la institución
   en campus/restablecer.php.
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

    <div class="formulario" style="display: block; text-align: left; line-height: 1.6;">
        <p style="margin-top: 0; font-size: 1.05rem;">
            <strong>¿Olvidaste tu contraseña de alumno?</strong>
        </p>
        <p style="color: var(--color-texto-suave, #555); font-size: 0.95rem;">
            Por motivos de seguridad y para proteger la privacidad de las cuentas y solicitudes de exámenes, las contraseñas de los alumnos ya no se restablecen públicamente.
        </p>
        <div style="background: rgba(160, 20, 20, 0.08); border-left: 4px solid var(--color-guinda, #6b0f1a); padding: 0.85rem 1rem; border-radius: 4px; margin: 1rem 0;">
            <p style="margin: 0; font-size: 0.92rem; color: #222;">
                <strong>¿Cómo solicitar una nueva clave?</strong><br>
                Acercate a la <strong>Preceptoría, Secretaría o Dirección</strong> de la escuela con tu DNI. El personal directivo restablecerá tu acceso de forma inmediata.
            </div>
        </div>
    </div>

    <!-- ACCIONES: VOLVER AL LOGIN Y AL INICIO -->
    <div class="login__acciones">
        <a href="login.php" class="boton boton--secundario">← Volver a iniciar sesión</a>
        <a href="index.php" class="boton boton--oscuro">Volver al inicio</a>
    </div>
</main>

</body>
</html>
