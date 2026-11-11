<?php
/* =====================================================================
   ARCHIVO: registro.php
   PARA QUÉ SIRVE: explica cómo se obtiene una cuenta. Ya no hay registro
   abierto: las cuentas las crea la escuela (el preceptor, para sus
   alumnos; los directivos, para el personal) y cada persona la activa
   con un código de un solo uso en activar.php.
   ===================================================================== */
require_once 'includes/sesion.php';

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
    <title>¿Cómo obtengo mi cuenta? — Campus Virtual</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="fondo-login">

<main class="login">
    <img src="img/logo.png" alt="Logo de la escuela" class="logo logo--login" onerror="this.style.display='none'">
    <h1>¿Cómo obtengo mi cuenta?</h1>

    <div class="exito" style="text-align:left;">
        <p>Las cuentas del campus las crea la escuela:</p>
        <p><strong>Alumnos:</strong> su preceptor carga sus datos y le entrega un <strong>código de acceso</strong>.</p>
        <p><strong>Profesores y preceptores:</strong> un directivo crea la cuenta y le entrega el código.</p>
        <p>Con el código, entre a <a href="activar.php">Activar cuenta</a> y elija su contraseña. El código sirve una
           sola vez y vence a los pocos días: si se le venció, pida otro.</p>
    </div>

    <div class="login__acciones">
        <a href="activar.php" class="boton">Tengo un código</a>
        <a href="login.php" class="boton boton--secundario">← Volver a ingresar</a>
    </div>
</main>

</body>
</html>
