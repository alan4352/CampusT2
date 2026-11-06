<?php
/* =====================================================================
   ARCHIVO: restablecer.php
   PARA QUÉ SIRVE: un DIRECTIVO restablece la contraseña de un alumno
   que la olvidó. El sistema genera una contraseña nueva y la muestra
   solo al directivo, que se la entrega al alumno en persona.
   Cada cambio queda anotado en el log del servidor.
   ===================================================================== */
require_once 'includes/sesion.php';
require_once 'includes/usuarios.php';

requerirLogin();
if (($_SESSION['rol'] ?? '') !== 'directivo') {
    header('Location: dashboard.php');
    exit;
}

$error      = '';
$nuevaClave = '';
$alumnoOk   = null;
$dni        = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dni = preg_replace('/\D/', '', $_POST['dni'] ?? '');

    if (!csrf_valido()) {
        $error = 'La página venció. Recárguela e intente de nuevo.';
    } elseif (!preg_match('/^\d{7,8}$/', $dni)) {
        $error = 'El DNI debe tener 7 u 8 números, sin puntos.';
    } else {
        try {
            $st = conectarBD()->prepare('SELECT nombre, dni FROM alumnos WHERE dni = ? LIMIT 1');
            $st->execute([$dni]);
            $alumno = $st->fetch();

            if (!$alumno) {
                $error = 'No hay ningún alumno registrado con ese DNI.';
            } else {
                $nuevaClave = bin2hex(random_bytes(5));   // 10 caracteres
                actualizarClaveAlumno($dni, password_hash($nuevaClave, PASSWORD_DEFAULT));
                $alumnoOk = $alumno;
                error_log(sprintf('[campus] %s restableció la contraseña del alumno DNI %s',
                    $_SESSION['correo'] ?? $_SESSION['nombre'], $dni));
            }
        } catch (PDOException $ex) {
            error_log('Error de BD en restablecer contraseña: ' . $ex->getMessage());
            $error = 'No se pudo conectar con la base de datos. Intente nuevamente más tarde.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restablecer contraseña de alumno — Campus Virtual</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="fondo-login">

<main class="login">
    <img src="img/logo.png" alt="Logo de la escuela" class="logo logo--login" onerror="this.style.display='none'">
    <h1>Restablecer contraseña</h1>
    <p class="login__sub">Para alumnos que olvidaron su contraseña</p>

    <?php if ($error !== ''): ?>
        <div class="error"><?php echo e($error); ?></div>
    <?php endif; ?>

    <?php if ($alumnoOk): ?>
        <div class="exito">
            <p><strong>Contraseña restablecida para <?php echo e($alumnoOk['nombre']); ?> (DNI <?php echo e($alumnoOk['dni']); ?>).</strong></p>
            <div class="clave-destacada">
                <span class="clave-destacada__titulo">Nueva contraseña:</span>
                <strong class="clave-destacada__codigo"><?php echo e($nuevaClave); ?></strong>
            </div>
            <p class="nota-clave">⚠️ Entréguesela al alumno en persona. No se vuelve a mostrar.</p>
        </div>
    <?php endif; ?>

    <form method="post" action="restablecer.php" class="formulario" style="display: block;">
        <?php echo csrf_campo(); ?>
        <label for="r-dni">DNI del alumno</label>
        <input type="text" id="r-dni" name="dni" value="<?php echo $alumnoOk ? '' : e($dni); ?>" inputmode="numeric" pattern="[0-9]*" maxlength="8" oninput="this.value = this.value.replace(/[^0-9]/g, '')" placeholder="Solo números (sin puntos ni letras)" required>
        <small>Verifique la identidad del alumno antes de restablecer su contraseña.</small>

        <button type="submit" class="boton">Generar contraseña nueva</button>
    </form>

    <div class="login__acciones">
        <a href="dashboard.php" class="boton boton--oscuro">← Volver al panel</a>
    </div>
</main>

</body>
</html>
