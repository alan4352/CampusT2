<?php
/* =====================================================================
   ARCHIVO: login.php
   PARA QUÉ SIRVE: formulario único de acceso al Campus Virtual.
   Todos (alumnos, profesores, preceptores y directivos) entran con su
   DNI o correo y su contraseña. La contraseña la elige cada persona la
   primera vez, con el código de alta que le da la escuela (activar.php).

   IMPORTANTE: la sesión se llena en includes/cuentas.php
   (iniciarSesionCuenta) con el formato que usan los demás módulos:
   $_SESSION['id'] = 'alumno:<DNI>' | 'profesor:<correo>' | ...
   ===================================================================== */

require_once 'includes/sesion.php';
require_once 'includes/cuentas.php';
require_once 'includes/limite_intentos.php';

const LOGIN_MAX_FALLOS_CUENTA = 5;      // fallos seguidos con el mismo identificador
const LOGIN_MAX_FALLOS_IP     = 30;     // fallos desde la misma IP

// Destino tras el inicio de sesión exitoso
function destinoTrasLogin() {
    $destino = $_SESSION['volver_a'] ?? 'dashboard.php';
    unset($_SESSION['volver_a']);
    return preg_match('/^[a-z_]+\.php$/', $destino) ? $destino : 'dashboard.php';
}

// Si ya inició sesión, redirigimos a su destino correspondiente
if (estaLogueado()) {
    header('Location: ' . destinoTrasLogin());
    exit;
}

$error = '';
$identificadorPrellenado = '';
$mensajeExito = $_SESSION['mensaje_exito'] ?? null;   // viene de activar.php
unset($_SESSION['mensaje_exito']);
if (is_array($mensajeExito) && !empty($mensajeExito['identificador'])) {
    $identificadorPrellenado = $mensajeExito['identificador'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identificador = trim((string) ($_POST['identificador'] ?? ''));
    $clave         = (string) ($_POST['clave'] ?? '');
    $identificadorPrellenado = $identificador;

    $cc = claveCuenta($identificador);
    $ci = 'ip:' . ipCliente();
    $bloqueo = bloqueadoHasta($cc, $ci);

    if ($bloqueo) {
        $error = 'Demasiados intentos fallidos. Por seguridad, el acceso quedó bloqueado. Intente nuevamente en '
               . minutosRestantes($bloqueo) . ' minuto(s).';
    } elseif ($identificador === '') {
        $error = 'Por favor, ingrese su DNI o su correo.';
    } else {
        $fallo = false;
        try {
            $pdo = bdCuentas();

            /* Profesores registrados antes: su código PROF- sirve UNA vez para elegir contraseña */
            $codigoLegado = stripos($identificador, 'PROF-') === 0 ? $identificador
                          : (stripos(trim($clave), 'PROF-') === 0 ? trim($clave) : '');
            $profe = $codigoLegado !== '' ? buscarProfesorPorCodigoLegado($pdo, $codigoLegado) : null;
            if ($profe) {
                borrarIntentos($cc);
                session_regenerate_id(true);
                $_SESSION['activacion'] = ['usuario_id' => (int) $profe['id'], 'codigo_id' => null, 'desde' => time()];
                auditarCuentas("profesor {$profe['correo']} usó su código PROF- para activar la cuenta");
                header('Location: activar.php');
                exit;
            }

            $u = buscarUsuarioPorIdentificador($pdo, $identificador);
            $claveOk = $u && $u['clave_hash'] && password_verify($clave, $u['clave_hash']);

            if ($claveOk && $u['estado'] === 'bloqueado') {
                $error = 'Su cuenta está suspendida. Consulte en la escuela.';
            } elseif ($claveOk && $u['estado'] === 'activo') {
                if (password_needs_rehash($u['clave_hash'], PASSWORD_DEFAULT)) {
                    $pdo->prepare('UPDATE usuarios SET clave_hash = ? WHERE id = ?')
                        ->execute([password_hash($clave, PASSWORD_DEFAULT), $u['id']]);
                }
                if (iniciarSesionCuenta($pdo, $u)) {
                    borrarIntentos($cc);
                    header('Location: ' . destinoTrasLogin());
                    exit;
                }
                $error = 'Su cuenta todavía no tiene un rol asignado. Consulte en la escuela.';
            } else {
                $fallo = true;
                $error = 'Datos de acceso incorrectos. Si es su primer ingreso, active su cuenta con el código que le dio la escuela.';
            }
        } catch (PDOException $ex) {
            error_log('Error de BD en login: ' . $ex->getMessage());
            $error = 'No se pudo conectar con la base de datos. Intente más tarde.';
        }

        // Solo los datos incorrectos cuentan como intento fallido (no las caídas de la base)
        if ($fallo) {
            sumarFallo($cc, LOGIN_MAX_FALLOS_CUENTA);
            sumarFallo($ci, LOGIN_MAX_FALLOS_IP);
            if (bloqueadoHasta($cc, $ci)) {
                $error = 'Demasiados intentos fallidos. Por seguridad, el acceso quedó bloqueado por 15 minutos.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ingresar al Campus Virtual | EET N°2</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .form-unificado {
            display: block !important;
            text-align: left;
        }
        .form-unificado label {
            display: block;
            margin: 14px 0 6px;
            font-weight: 700;
            font-size: 0.95rem;
            color: #e5e7eb;
        }
        .form-unificado input {
            width: 100%;
            padding: 12px 14px;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.16);
            border-radius: 8px;
            font-size: 1rem;
            color: #ffffff;
            box-sizing: border-box;
            transition: all 0.2s ease;
        }
        .form-unificado input:focus {
            background: rgba(255, 255, 255, 0.09);
            outline: 3px solid rgba(162, 19, 47, 0.35);
            border-color: var(--primary, #a2132f);
        }
        .identificador-hint {
            font-size: 0.85rem;
            color: #9ca3af;
            margin-top: 6px;
        }
        .login__sub--unificado {
            color: #d1d5db;
            font-size: 0.92rem;
            margin-bottom: 1.5rem;
        }
    </style>
</head>
<body class="fondo-login">

<main class="login">
    <!-- LOGO DE LA ESCUELA -->
    <img src="img/logo.png" alt="Logo de la escuela" class="logo logo--login" onerror="this.style.display='none'">
    <h1>Campus Virtual</h1>
    <p class="login__sub login__sub--unificado">Alumnos, profesores, preceptores y directivos</p>

    <!-- Mensaje de éxito (cuenta activada o contraseña nueva) -->
    <?php if (is_array($mensajeExito) && !empty($mensajeExito['mensaje'])): ?>
        <div class="exito" style="margin-bottom: 1.25rem;">
            <p><strong><?php echo e($mensajeExito['mensaje']); ?></strong></p>
        </div>
    <?php endif; ?>

    <!-- Mensaje de error -->
    <?php if ($error !== ''): ?>
        <div class="error" style="margin-bottom: 1.25rem;"><?php echo e($error); ?></div>
    <?php endif; ?>

    <!-- ============ FORMULARIO DE ACCESO ============ -->
    <form method="post" action="login.php" class="formulario form-unificado" id="form-login-unificado" novalidate>
        <label for="identificador">DNI o correo</label>
        <input
            type="text"
            id="identificador"
            name="identificador"
            value="<?php echo e($identificadorPrellenado); ?>"
            placeholder="Ej: 40123456 o nombre@correo.com"
            required
            autocomplete="username"
            autofocus
        >
        <div class="identificador-hint">El DNI va sin puntos.</div>

        <label for="clave">Contraseña</label>
        <input
            type="password"
            id="clave"
            name="clave"
            placeholder="Su contraseña"
            autocomplete="current-password"
        >
        <div style="margin-top: 8px;">
            <a href="recuperar.php" class="login__olvido" style="color: #f87171; font-size: 0.88rem; font-weight: 600; text-decoration: underline;">
                ¿Olvidó su contraseña?
            </a>
        </div>

        <button type="submit" class="boton" style="margin-top: 1.25rem; width: 100%;">Ingresar</button>
    </form>

    <!-- ACCIONES: PRIMER INGRESO Y VOLVER -->
    <div class="login__acciones" style="margin-top: 1.5rem;">
        <a href="activar.php" class="boton boton--secundario">¿Primer ingreso? Active su cuenta con su código</a>
        <a href="index.php" class="boton boton--oscuro">← Volver al inicio</a>
    </div>
</main>

</body>
</html>
