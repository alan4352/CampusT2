<?php
/* =====================================================================
   ARCHIVO: registro.php
   PARA QUÉ SIRVE: permite que alumnos y profesores se registren.
   Los datos se guardan en la base de datos MySQL (ver includes/db.php).

     ALUMNO   → nombre, Gmail y DNI → el sistema GENERA una contraseña
                y la ENVÍA AL CORREO.
     PROFESOR → nombre, correo y clave de la escuela → el sistema GENERA
                un código y lo ENVÍA AL CORREO.
     DIRECTIVO→ NO se registra aquí (los crea la escuela en datos.php).

   En MODO_PRUEBA (includes/config.php) el correo no sale de verdad:
   se guarda en data/correos_simulados.txt y se muestra en pantalla.
   ===================================================================== */
require_once 'includes/sesion.php';
require_once 'includes/usuarios.php';

if (estaLogueado()) {
    header('Location: dashboard.php');
    exit;
}

$error  = '';
$exito  = '';
$claveGenerada = '';
$avisoPrueba = '';
$tipo   = 'alumno';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $tipo   = $_POST['tipo'] ?? '';
    $nombre = trim($_POST['nombre'] ?? '');
    $correo = strtolower(trim($_POST['correo'] ?? ''));

    // ---- Validaciones comunes ----
    if ($tipo !== 'alumno' && $tipo !== 'profesor') {
        $error = 'Tipo de registro no válido.';
        $tipo  = 'alumno';
    } elseif ($nombre === '' || mb_strlen($nombre) > 80) {
        $error = 'Escriba su nombre completo.';
    } elseif (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        $error = 'El correo no es válido.';
    }

    /* -------------------- REGISTRO DE ALUMNO -------------------- */
    elseif ($tipo === 'alumno') {

        $dni = preg_replace('/\D/', '', $_POST['dni'] ?? '');

        if (!preg_match('/^\d{7,8}$/', $dni)) {
            $error = 'El DNI debe tener 7 u 8 números, sin puntos.';
        } else {
            try {
                if (existeAlumno($correo, $dni)) {
                    $error = 'Ya existe un alumno registrado con ese correo o DNI.';
                } else {
                    // Contraseña aleatoria de 8 caracteres
                    $clave = bin2hex(random_bytes(4));

                    // Guardamos en la BD con la contraseña CIFRADA
                    crearAlumno($nombre, $correo, $dni, password_hash($clave, PASSWORD_DEFAULT));

                    // Redirigimos a login.php para iniciar sesión directamente con la clave generada
                    $_SESSION['mensaje_exito'] = [
                        'tipo'    => 'registro',
                        'rol'     => 'alumno',
                        'dni'     => $dni,
                        'clave'   => $clave,
                        'mensaje' => '¡Registro exitoso! Su cuenta ha sido creada.'
                    ];
                    header('Location: login.php');
                    exit;
                }
            } catch (PDOException $ex) {
                error_log('Error de BD en registro (alumno): ' . $ex->getMessage());
                // 23000 = clave duplicada (dos registros simultáneos con el mismo correo/DNI)
                $error = ($ex->getCode() === '23000')
                    ? 'Ya existe un alumno registrado con ese correo o DNI.'
                    : 'No se pudo guardar el registro en la base de datos.';
            }
        }
    }

    /* -------------------- REGISTRO DE PROFESOR -------------------- */
    elseif ($tipo === 'profesor') {

        $claveEscuela = trim($_POST['clave_escuela'] ?? '');

        if (CLAVE_REGISTRO_PROFESOR !== '' && !hash_equals(CLAVE_REGISTRO_PROFESOR, $claveEscuela)) {
            $error = 'La clave de la escuela no es correcta.';
        } else {
            try {
                if (existeProfesor($correo)) {
                    $error = 'Ya existe un profesor registrado con ese correo.';
                } else {
                    // Código tipo PROF-4F9A2C
                    $codigo = 'PROF-' . strtoupper(bin2hex(random_bytes(3)));

                    crearProfesor($nombre, $correo, password_hash($codigo, PASSWORD_DEFAULT));

                    $mensaje = "Hola $nombre,\n\nSu registro como profesor fue exitoso.\n\n"
                             . "Su código de acceso es: $codigo\n\n"
                             . "En el campus, elija «Profesor» e ingrese ese código.";
                    enviarCorreo($correo, 'Su código de acceso al Campus Virtual', $mensaje);

                    $_SESSION['mensaje_exito'] = [
                        'tipo'    => 'registro',
                        'rol'     => 'profesor',
                        'codigo'  => $codigo,
                        'mensaje' => '¡Registro exitoso! Su cuenta de profesor ha sido creada.'
                    ];
                    header('Location: login.php');
                    exit;
                }
            } catch (PDOException $ex) {
                error_log('Error de BD en registro (profesor): ' . $ex->getMessage());
                $error = ($ex->getCode() === '23000')
                    ? 'Ya existe un profesor registrado con ese correo.'
                    : 'No se pudo guardar el registro en la base de datos.';
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
    <title>Registro — Campus Virtual</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="fondo-login">

<main class="login">
    <!-- AQUÍ VA EL LOGO DE LA ESCUELA (mismo archivo: img/logo.png) -->
    <img src="img/logo.png" alt="Logo de la escuela" class="logo logo--login" onerror="this.style.display='none'">
    <h1>Registro</h1>
    <p class="login__sub">Cree su acceso al campus</p>

    <!-- Pestañas: Alumno / Profesor (los directivos NO se registran aquí) -->
    <div class="pestanas">
        <button type="button" class="pestana" data-rol="alumno">Alumno</button>
        <button type="button" class="pestana" data-rol="profesor">Profesor</button>
    </div>

    <!-- MENSAJE DE ERROR -->
    <?php if ($error !== ''): ?>
        <div class="error"><?php echo e($error); ?></div>
    <?php endif; ?>

    <!-- MENSAJE DE ÉXITO -->
    <?php if ($exito !== ''): ?>
        <div class="exito">
            <p><strong><?php echo e($exito); ?></strong></p>
            <?php if ($claveGenerada !== ''): ?>
                <div class="clave-destacada">
                    <span class="clave-destacada__titulo">Su contraseña de acceso:</span>
                    <strong class="clave-destacada__codigo"><?php echo e($claveGenerada); ?></strong>
                </div>
                <p class="nota-clave">⚠️ Guarde o anote esta contraseña. Para iniciar sesión, use su <strong>DNI</strong> y esta <strong>contraseña</strong>.</p>
            <?php endif; ?>
            <?php if ($avisoPrueba !== ''): ?>
                <!-- ESTE RECUADRO SOLO APARECE EN MODO_PRUEBA (config.php) -->
                <p class="exito__prueba"> Modo prueba.<br>
                <strong><?php echo e($avisoPrueba); ?></strong></p>
            <?php endif; ?>
            <p><a href="login.php">Ir a ingresar →</a></p>
        </div>
    <?php endif; ?>


    <!-- ============ FORMULARIO: REGISTRO DE ALUMNO ============ -->
    <form method="post" action="registro.php" class="formulario" id="form-alumno">
        <input type="hidden" name="tipo" value="alumno">

        <label for="a-nombre">Nombre y apellido</label>
        <input type="text" id="a-nombre" name="nombre" value="<?php echo e($nombre ?? ''); ?>" required>

        <label for="a-correo">Correo electrónico</label>
        <input type="email" id="a-correo" name="correo" value="<?php echo e($correo ?? ''); ?>" placeholder="ejemplo@correo.com" required>

        <label for="a-dni">DNI</label>
        <input type="text" id="a-dni" name="dni" value="<?php echo e($dni ?? ''); ?>" inputmode="numeric" pattern="[0-9]*" maxlength="8" oninput="this.value = this.value.replace(/[^0-9]/g, '')" onkeypress="return event.charCode >= 48 && event.charCode <= 57" placeholder="Solo números (sin puntos ni letras)" required>
        <small>Al registrarse se generará su contraseña para iniciar sesión con su DNI.</small>

        <button type="submit" class="boton">Registrarme</button>
    </form>


    <!-- ============ FORMULARIO: REGISTRO DE PROFESOR ============ -->
    <form method="post" action="registro.php" class="formulario" id="form-profesor">
        <input type="hidden" name="tipo" value="profesor">

        <label for="p-nombre">Nombre y apellido</label>
        <input type="text" id="p-nombre" name="nombre" required>

        <label for="p-correo">Correo</label>
        <input type="email" id="p-correo" name="correo" required>

        <label for="p-clave">Clave de la escuela</label>
        <input type="password" id="p-clave" name="clave_escuela">
        <small>Se la entrega la escuela a sus docentes. Le enviaremos su código de acceso por correo.</small>

        <button type="submit" class="boton">Registrarme</button>
    </form>

    <!-- ACCIONES: INGRESO, AYUDA Y VOLVER -->
    <div class="login__acciones">
        <a href="login.php" class="boton boton--secundario">¿Ya tiene cuenta? Ingrese aquí</a>
        <a href="ayuda.php" class="boton boton--gris">❓ ¿Necesitas ayuda para registrarte?</a>
        <a href="index.php" class="boton boton--oscuro">← Volver al inicio</a>
    </div>
</main>


<script>
/* JAVASCRIPT: muestra solo el formulario de la pestaña elegida.
   (La validación real la hace PHP arriba.) */
var tipoInicial = "<?php echo e($tipo); ?>";
var pestanas = document.querySelectorAll('.pestana');
var tipos = ['alumno', 'profesor'];

function mostrarTipo(tipo) {
    tipos.forEach(function (t) {
        document.getElementById('form-' + t).style.display = (t === tipo) ? 'block' : 'none';
    });
    pestanas.forEach(function (p) {
        p.classList.toggle('activa', p.dataset.rol === tipo);
    });
}
pestanas.forEach(function (p) {
    p.addEventListener('click', function () { mostrarTipo(p.dataset.rol); });
});
mostrarTipo(tipoInicial);
</script>

</body>
</html>
