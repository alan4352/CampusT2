<?php
/* =====================================================================
   ARCHIVO: recuperar.php
   PARA QUÉ SIRVE: permite que los alumnos restablezcan su contraseña
   si la olvidaron, ingresando su DNI y el código de la escuela.
   ===================================================================== */
require_once 'includes/sesion.php';
require_once 'includes/config.php';
require_once 'includes/usuarios.php';

// Si ya inició sesión, redirigimos al panel
if (estaLogueado()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$dni   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dni           = preg_replace('/\D/', '', $_POST['dni'] ?? '');
    $codigoEscuela = trim($_POST['codigo_escuela'] ?? '');

    if ($dni === '') {
        $error = 'Por favor, ingrese su número de DNI.';
    } elseif ($codigoEscuela === '') {
        $error = 'Por favor, ingrese el código de la escuela.';
    } elseif (!hash_equals(CLAVE_REGISTRO_PROFESOR, $codigoEscuela)) {
        $error = 'El código de la escuela no es correcto.';
    } else {
        try {
            // Buscamos si existe un alumno registrado con ese DNI
            $alumno = buscarAlumnoPorDni($dni);

            if (!$alumno) {
                $error = 'No se encontró ningún alumno registrado con el DNI ingresado.';
            } else {
                // Generamos una nueva contraseña aleatoria de 8 caracteres
                $nuevaClave = bin2hex(random_bytes(4));

                // Actualizamos la contraseña cifrada en la base de datos
                actualizarClaveAlumno($dni, password_hash($nuevaClave, PASSWORD_DEFAULT));

                // Guardamos los datos para mostrarlos en el login y redirigimos
                $_SESSION['mensaje_exito'] = [
                    'tipo'    => 'recuperacion',
                    'rol'     => 'alumno',
                    'dni'     => $dni,
                    'clave'   => $nuevaClave,
                    'mensaje' => '¡Contraseña restablecida con éxito!'
                ];

                header('Location: login.php');
                exit;
            }
        } catch (PDOException $ex) {
            error_log('Error de BD en recuperar contraseña: ' . $ex->getMessage());
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
    <title>Recuperar contraseña — Campus Virtual</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="fondo-login">

<main class="login">
    <!-- LOGO DE LA ESCUELA -->
    <img src="img/logo.png" alt="Logo de la escuela" class="logo logo--login" onerror="this.style.display='none'">
    <h1>Campus Virtual</h1>
    <p class="login__sub">Recuperar contraseña de Alumno</p>

    <!-- Mensaje de error -->
    <?php if ($error !== ''): ?>
        <div class="error"><?php echo e($error); ?></div>
    <?php endif; ?>

    <!-- Formulario de recuperación -->
    <form method="post" action="recuperar.php" class="formulario" style="display: block;">
        <label for="r-dni">DNI</label>
        <input type="text" id="r-dni" name="dni" value="<?php echo e($dni); ?>" inputmode="numeric" pattern="[0-9]*" maxlength="8" oninput="this.value = this.value.replace(/[^0-9]/g, '')" onkeypress="return event.charCode >= 48 && event.charCode <= 57" placeholder="Solo números (sin puntos ni letras)" required>

        <label for="r-codigo">Código de la escuela</label>
        <input type="password" id="r-codigo" name="codigo_escuela" placeholder="Código provisto por la institución" required>
        <small>Ingrese el mismo código que se solicita a los profesores para registrarse.</small>

        <button type="submit" class="boton">Restablecer contraseña</button>
    </form>

    <!-- ACCIONES: VOLVER AL LOGIN Y AL INICIO -->
    <div class="login__acciones">
        <a href="login.php" class="boton boton--secundario">← Volver a iniciar sesión</a>
        <a href="index.php" class="boton boton--oscuro">Volver al inicio</a>
    </div>
</main>

</body>
</html>
