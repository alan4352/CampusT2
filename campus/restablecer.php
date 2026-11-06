<?php
/* =====================================================================
   ARCHIVO: restablecer.php
   PARA QUÉ SIRVE: permite que los directivos restablezcan la contraseña
   de un alumno de forma segura, verificando token CSRF y registrando
   la acción en el log del servidor.
   ===================================================================== */
require_once 'includes/sesion.php';
require_once 'includes/usuarios.php';

// Si no inició sesión, mandar a login.php
if (!estaLogueado()) {
    header('Location: login.php');
    exit;
}

// Si no es directivo, redirigir al panel principal
if (($_SESSION['rol'] ?? '') !== 'directivo') {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$exito = '';
$dni   = '';
$alumnoEncontrado = null;
$nuevaClave = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tokenCsrf = $_POST['csrf_token'] ?? '';
    if (!validarTokenCsrf($tokenCsrf)) {
        $error = 'Error de validación de seguridad (CSRF inválido o sesión expirada). Recargue la página.';
    } else {
        $dni = preg_replace('/\D/', '', $_POST['dni'] ?? '');

        if ($dni === '' || !preg_match('/^\d{7,8}$/', $dni)) {
            $error = 'El DNI debe tener 7 u 8 números válidos.';
        } else {
            try {
                $alumno = buscarAlumnoPorDni($dni);
                if (!$alumno) {
                    $error = 'No se encontró ningún alumno registrado con el DNI ingresado (' . e($dni) . ').';
                } else {
                    $alumnoEncontrado = $alumno;
                    // Generar nueva contraseña aleatoria de 8 caracteres
                    $nuevaClave = bin2hex(random_bytes(4));

                    actualizarClaveAlumno($dni, password_hash($nuevaClave, PASSWORD_DEFAULT));

                    // Registro de auditoría en log
                    $directivoNom = $_SESSION['nombre'] ?? 'Directivo';
                    $directivoCor = $_SESSION['correo'] ?? '';
                    error_log(sprintf(
                        '[CAMPUS AUDITORIA] Directivo "%s" (%s) restableció la clave del alumno "%s" (DNI: %s).',
                        $directivoNom,
                        $directivoCor,
                        $alumno['nombre'],
                        $dni
                    ));

                    $exito = 'Contraseña restablecida exitosamente para el alumno ' . e($alumno['nombre']) . ' (DNI: ' . e($dni) . ').';
                }
            } catch (Throwable $e) {
                error_log('Error en restablecer contraseña directivo: ' . $e->getMessage());
                $error = 'Ocurrió un error en la base de datos al restablecer la contraseña.';
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
    <title>Restablecer contraseña de alumno — Campus Virtual</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="fondo-panel">

<header class="barra">
    <div class="contenedor barra__interior">
        <a href="dashboard.php" class="barra__logo">
            <img src="img/logo.png" alt="Logo de la escuela" class="logo" onerror="this.style.display='none'">
            Campus Virtual
        </a>
        <nav class="barra__menu">
            <span class="barra__usuario"><?php echo e($_SESSION['nombre']); ?> · Directivo/a</span>
            <a href="dashboard.php" class="boton boton--chico boton--borde">← Volver al panel</a>
        </nav>
    </div>
</header>

<main class="contenedor panel" style="max-width: 600px; margin: 2rem auto;">
    <h1>Restablecer contraseña de Alumno</h1>
    <p class="seccion__intro">
        Ingrese el DNI del alumno para generarle una nueva clave de acceso al campus.
    </p>

    <?php if ($error !== ''): ?>
        <div class="error" style="margin-bottom: 1.5rem;"><?php echo e($error); ?></div>
    <?php endif; ?>

    <?php if ($exito !== ''): ?>
        <div style="background: rgba(46, 125, 50, 0.12); border: 1px solid #2e7d32; border-radius: 8px; padding: 1.25rem; margin-bottom: 1.5rem; text-align: center;">
            <p style="margin: 0 0 0.75rem 0; font-weight: 600; color: #1b5e20;">
                ✓ <?php echo $exito; ?>
            </p>
            <p style="margin: 0; font-size: 0.95rem; color: #333;">
                Nueva clave generada para entregar al alumno:
            </p>
            <div style="font-family: monospace; font-size: 1.4rem; font-weight: 700; color: var(--color-guinda, #6b0f1a); background: #fff; padding: 0.6rem 1rem; border-radius: 6px; display: inline-block; margin: 0.6rem 0; border: 1px dashed var(--color-guinda, #6b0f1a); letter-spacing: 2px;">
                <?php echo e($nuevaClave); ?>
            </div>
            <p style="margin: 0.5rem 0 0 0; font-size: 0.85rem; color: #666;">
                Indique al alumno que inicie sesión con su DNI y esta contraseña.
            </p>
        </div>
    <?php endif; ?>

    <form method="post" action="restablecer.php" class="formulario" style="display: block; text-align: left; background: #fff; padding: 1.5rem; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.06);">
        <input type="hidden" name="csrf_token" value="<?php echo e(obtenerTokenCsrf()); ?>">

        <label for="dni" style="font-weight: 600; display: block; margin-bottom: 0.4rem;">Número de DNI del alumno</label>
        <input type="text" id="dni" name="dni" value="<?php echo e($dni); ?>" inputmode="numeric" pattern="[0-9]*" maxlength="8" oninput="this.value = this.value.replace(/[^0-9]/g, '')" placeholder="Ej. 40123456 (sin puntos)" required style="width: 100%; margin-bottom: 1rem;">

        <div style="display: flex; gap: 0.75rem; align-items: center; justify-content: flex-end;">
            <a href="dashboard.php" class="boton boton--secundario" style="margin: 0;">Cancelar</a>
            <button type="submit" class="boton" style="margin: 0;">Generar nueva contraseña</button>
        </div>
    </form>
</main>

</body>
</html>
