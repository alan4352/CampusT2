<?php
/* =====================================================================
   ARCHIVO: activar.php
   PARA QUÉ SIRVE: primer ingreso y recuperación de la cuenta.
     Paso 1: la persona escribe el código que le dio la escuela
             (preceptor para alumnos, directivo para el personal).
     Paso 2: elige su contraseña (y su correo, si la cuenta no tiene).
   El código sirve una sola vez y vence. Los profesores registrados
   antes llegan acá desde login.php con su código PROF-.
   ===================================================================== */
require_once 'includes/sesion.php';
require_once 'includes/cuentas.php';
require_once 'includes/limite_intentos.php';

const ACTIVAR_MAX_FALLOS_IP = 10;
const ACTIVAR_MINUTOS_PASO2 = 20;   // tiempo para completar el paso 2

if (estaLogueado()) {
    header('Location: dashboard.php');
    exit;
}

if (isset($_GET['cancelar'])) {
    unset($_SESSION['activacion']);
    header('Location: activar.php');
    exit;
}

$error = '';
$ci = 'activar-ip:' . ipCliente();

// Datos del paso 2 (si ya se validó el código y no venció el tiempo)
$act = $_SESSION['activacion'] ?? null;
if (is_array($act) && (time() - (int) ($act['desde'] ?? 0)) > ACTIVAR_MINUTOS_PASO2 * 60) {
    unset($_SESSION['activacion']);
    $act = null;
    $error = 'Pasó demasiado tiempo. Vuelva a escribir su código.';
}

$cuenta = null;
try {
    $pdo = bdCuentas();
    if ($act) {
        $cuenta = buscarUsuarioPor($pdo, 'id', (int) $act['usuario_id']);
        if (!$cuenta || $cuenta['estado'] === 'bloqueado') {
            unset($_SESSION['activacion']);
            $act = null;
            $cuenta = null;
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_valido()) {
            $error = 'La página venció. Recárguela e intente de nuevo.';
        } elseif (($_POST['paso'] ?? '') === 'codigo') {
            /* ---------- PASO 1: validar el código ---------- */
            $bloqueo = bloqueadoHasta($ci);
            if ($bloqueo) {
                $error = 'Demasiados códigos incorrectos. Intente nuevamente en ' . minutosRestantes($bloqueo) . ' minuto(s).';
            } else {
                $cod = buscarCodigoVigente($pdo, (string) ($_POST['codigo'] ?? ''));
                if ($cod) {
                    session_regenerate_id(true);
                    $_SESSION['activacion'] = ['usuario_id' => (int) $cod['id'], 'codigo_id' => (int) $cod['codigo_id'], 'desde' => time()];
                    header('Location: activar.php');
                    exit;
                }
                sumarFallo($ci, ACTIVAR_MAX_FALLOS_IP);
                $error = 'El código no es válido, ya se usó o venció. Pida uno nuevo en la escuela.';
            }
        } elseif (($_POST['paso'] ?? '') === 'clave' && $act && $cuenta) {
            /* ---------- PASO 2: elegir la contraseña ---------- */
            $clave  = (string) ($_POST['clave'] ?? '');
            $clave2 = (string) ($_POST['clave2'] ?? '');
            if ($clave !== $clave2) {
                $error = 'Las contraseñas no coinciden.';
            } else {
                try {
                    $correo = $cuenta['correo'] === null ? (string) ($_POST['correo'] ?? '') : '';
                    establecerClave($pdo, (int) $cuenta['id'], $act['codigo_id'] !== null ? (int) $act['codigo_id'] : null, $clave, $correo);
                    unset($_SESSION['activacion']);
                    borrarIntentos($ci);
                    auditarCuentas('cuenta ' . ($cuenta['dni'] ?: $cuenta['correo']) . ' eligió su contraseña');
                    $_SESSION['mensaje_exito'] = [
                        'mensaje'       => '¡Listo! Ya puede ingresar con su DNI o correo y la contraseña que eligió.',
                        'identificador' => (string) ($cuenta['dni'] ?: $cuenta['correo']),
                    ];
                    header('Location: login.php');
                    exit;
                } catch (RuntimeException $ex) {
                    $error = $ex->getMessage();
                }
            }
        }
    }
} catch (PDOException $ex) {
    error_log('Error de BD en activar: ' . $ex->getMessage());
    $error = 'No se pudo conectar con la base de datos. Intente más tarde.';
}

// DNI parcialmente oculto para confirmar que es la cuenta correcta
$dniVisible = '';
if ($cuenta && $cuenta['dni']) {
    $dniVisible = str_repeat('•', max(0, strlen($cuenta['dni']) - 3)) . substr($cuenta['dni'], -3);
}
$esRecuperacion = $cuenta && $cuenta['clave_hash'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activar cuenta — Campus Virtual</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .formulario { display: block; }
        .codigo-input { text-transform: uppercase; letter-spacing: 2px; font-size: 1.15rem; text-align: center; }
        .cuenta-confirmar { background: rgba(255,255,255,0.08); border-left: 4px solid var(--primary); border-radius: 8px; padding: 12px 14px; margin-bottom: 14px; color: #e5e7eb; text-align: left; }
    </style>
</head>
<body class="fondo-login">

<main class="login">
    <img src="img/logo.png" alt="Logo de la escuela" class="logo logo--login" onerror="this.style.display='none'">
    <h1><?php echo $cuenta ? ($esRecuperacion ? 'Nueva contraseña' : 'Activar cuenta') : 'Primer ingreso'; ?></h1>

    <?php if ($error !== ''): ?>
        <div class="error"><?php echo e($error); ?></div>
    <?php endif; ?>

    <?php if (!$cuenta): ?>
        <p class="login__sub">Escriba el código que le dio la escuela: el preceptor (alumnos) o un directivo (personal).
            También sirve el código para recuperar una cuenta.</p>
        <form method="post" action="activar.php" class="formulario">
            <?php echo csrf_campo(); ?>
            <input type="hidden" name="paso" value="codigo">
            <label for="codigo">Código de acceso</label>
            <input type="text" id="codigo" name="codigo" class="codigo-input" maxlength="16" placeholder="ABCDE-FGHJK"
                   autocomplete="one-time-code" autocapitalize="characters" required autofocus>
            <small>Vence a los pocos días y sirve una sola vez.</small>
            <button type="submit" class="boton">Continuar</button>
        </form>
    <?php else: ?>
        <div class="cuenta-confirmar">
            Cuenta de <strong><?php echo e($cuenta['nombre']); ?></strong>
            <?php if ($dniVisible !== ''): ?> · DNI <?php echo e($dniVisible); ?><?php endif; ?>
            <br><small>Si no es usted, <a href="activar.php?cancelar=1" style="color:#fca5a5;">cancele</a> y avise en la escuela.</small>
        </div>
        <form method="post" action="activar.php" class="formulario">
            <?php echo csrf_campo(); ?>
            <input type="hidden" name="paso" value="clave">
            <?php if ($cuenta['correo'] === null): ?>
                <label for="correo">Correo (opcional)</label>
                <input type="email" id="correo" name="correo" placeholder="nombre@correo.com" autocomplete="email">
                <small>Con el correo también puede ingresar. Si no tiene, ingrese con su DNI.</small>
            <?php endif; ?>
            <label for="clave">Contraseña nueva</label>
            <input type="password" id="clave" name="clave" minlength="<?php echo CLAVE_LARGO_MINIMO; ?>" autocomplete="new-password" required autofocus>
            <small>Al menos <?php echo CLAVE_LARGO_MINIMO; ?> caracteres. No la comparta con nadie.</small>
            <label for="clave2">Repita la contraseña</label>
            <input type="password" id="clave2" name="clave2" minlength="<?php echo CLAVE_LARGO_MINIMO; ?>" autocomplete="new-password" required>
            <button type="submit" class="boton">Guardar contraseña</button>
        </form>
    <?php endif; ?>

    <div class="login__acciones">
        <a href="login.php" class="boton boton--secundario">← Volver a ingresar</a>
    </div>
</main>

</body>
</html>
