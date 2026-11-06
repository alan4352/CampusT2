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

    <div class="formulario" style="display: block; text-align: left; line-height: 1.6; background: rgba(255, 255, 255, 0.04); border: 1px solid rgba(255, 255, 255, 0.12); padding: 1.5rem; border-radius: 10px;">
        <p style="margin-top: 0; font-size: 1.15rem; color: #ffffff;">
            <strong>¿Olvidó su contraseña?</strong>
        </p>
        
        <div style="background: rgba(162, 19, 47, 0.18); border-left: 4px solid var(--primary, #a2132f); padding: 1rem 1.15rem; border-radius: 6px; margin: 1.25rem 0;">
            <p style="margin: 0 0 0.5rem 0; font-size: 1.05rem; font-weight: 700; color: #ffcdd2;">
                Comuníquese con la institución para generar una nueva contraseña.
            </p>
            <p style="margin: 0; font-size: 0.95rem; color: #e5e7eb; line-height: 1.55;">
                Por motivos de seguridad institucional y protección de datos personales, las contraseñas no se restablecen de forma automática por internet.<br><br>
                Para obtener una nueva clave de acceso, acérquese a la <strong>Preceptoría, Secretaría o Dirección</strong> de la escuela presentando su <strong>DNI</strong>. El equipo directivo generará y le entregará su nueva contraseña de inmediato.
            </p>
        </div>

        <p style="margin-bottom: 0; font-size: 0.88rem; color: #9ca3af;">
            Si es docente y extravió su código de acceso, también puede solicitar el reenvío o blanqueo en la Dirección de la escuela.
        </p>
    </div>

    <!-- ACCIONES: VOLVER AL LOGIN Y AL INICIO -->
    <div class="login__acciones">
        <a href="login.php" class="boton boton--secundario">← Volver a iniciar sesión</a>
        <a href="index.php" class="boton boton--oscuro">Volver al inicio</a>
    </div>
</main>

</body>
</html>
