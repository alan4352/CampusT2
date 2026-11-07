<?php
/* =====================================================================
   ARCHIVO: recuperar.php
   PARA QUÉ SIRVE: pedir que se recupere la cuenta. El pedido le aparece
   al preceptor del curso (alumnos) o a los directivos (todos), que
   entregan un código de recuperación. Con ese código, la persona elige
   una contraseña nueva en activar.php.
   La respuesta es siempre la misma, exista o no la cuenta: así esta
   página no sirve para averiguar qué DNI o correos están registrados.
   ===================================================================== */
require_once 'includes/sesion.php';
require_once 'includes/cuentas.php';
require_once 'includes/limite_intentos.php';

const RECUPERAR_MAX_PEDIDOS_IP = 10;

if (estaLogueado()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$enviado = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ci = 'recuperar-ip:' . ipCliente();
    $bloqueo = bloqueadoHasta($ci);
    if (!csrf_valido()) {
        $error = 'La página venció. Recárguela e intente de nuevo.';
    } elseif ($bloqueo) {
        $error = 'Demasiados pedidos seguidos. Intente nuevamente en ' . minutosRestantes($bloqueo) . ' minuto(s).';
    } elseif (trim((string) ($_POST['identificador'] ?? '')) === '') {
        $error = 'Escriba su DNI o su correo.';
    } else {
        try {
            $pdo = bdCuentas();
            $u = buscarUsuarioPorIdentificador($pdo, (string) $_POST['identificador']);
            if ($u && $u['estado'] !== 'bloqueado') {
                registrarSolicitudRecuperacion($pdo, (int) $u['id']);
            }
            sumarFallo($ci, RECUPERAR_MAX_PEDIDOS_IP);   // cuenta todos los pedidos, no solo los fallidos
            $enviado = true;
        } catch (PDOException $ex) {
            error_log('Error de BD en recuperar: ' . $ex->getMessage());
            $error = 'No se pudo conectar con la base de datos. Intente más tarde.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperar cuenta — Campus Virtual</title>
    <link rel="stylesheet" href="style.css">
    <style>.formulario { display: block; }</style>
</head>
<body class="fondo-login">

<main class="login">
    <img src="img/logo.png" alt="Logo de la escuela" class="logo logo--login" onerror="this.style.display='none'">
    <h1>Recuperar cuenta</h1>

    <?php if ($error !== ''): ?>
        <div class="error"><?php echo e($error); ?></div>
    <?php endif; ?>

    <?php if ($enviado): ?>
        <div class="exito">
            <p><strong>Pedido registrado.</strong></p>
            <p>Si los datos corresponden a una cuenta, su <strong>preceptor</strong> (alumnos) o un <strong>directivo</strong>
               le va a entregar un código de recuperación. Con ese código elija una contraseña nueva en
               <a href="activar.php">Activar cuenta</a>.</p>
        </div>
    <?php else: ?>
        <p class="login__sub">Escriba su DNI o su correo. La escuela le va a dar un código para elegir una contraseña nueva.</p>
        <form method="post" action="recuperar.php" class="formulario">
            <?php echo csrf_campo(); ?>
            <label for="identificador">DNI o correo</label>
            <input type="text" id="identificador" name="identificador" placeholder="Ej: 40123456" autocomplete="username" required autofocus>
            <button type="submit" class="boton">Pedir recuperación</button>
        </form>
        <p class="login__sub" style="margin-top:12px;">¿Ya tiene el código? <a href="activar.php">Úselo acá</a>.</p>
    <?php endif; ?>

    <div class="login__acciones">
        <a href="login.php" class="boton boton--secundario">← Volver a ingresar</a>
        <a href="index.php" class="boton boton--oscuro">Volver al inicio</a>
    </div>
</main>

</body>
</html>
