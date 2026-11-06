<?php
/* =====================================================================
   ARCHIVO: login.php
   PARA QUÉ SIRVE: muestra el formulario de acceso y VALIDA los datos
   cuando el usuario lo envía. Alumnos y profesores se consultan en la
   base de datos MySQL; los directivos siguen definidos en includes/datos.php.
   ===================================================================== */

require_once 'includes/sesion.php';
require_once 'includes/datos.php';      // Aquí están los DIRECTIVOS
require_once 'includes/usuarios.php';   // Funciones que consultan la base de datos

// A dónde ir después de ingresar: al panel, o al módulo que se estaba abriendo
// (por ejemplo programas.php guarda 'programas.php' en $_SESSION['volver_a']).
function destinoTrasLogin() {
    $destino = $_SESSION['volver_a'] ?? 'dashboard.php';
    unset($_SESSION['volver_a']);
    return preg_match('/^[a-z_]+\.php$/', $destino) ? $destino : 'dashboard.php';
}

// Si ya inició sesión, no tiene sentido mostrar el login
if (estaLogueado()) {
    header('Location: ' . destinoTrasLogin());
    exit;
}

$error = '';
$rolElegido = 'alumno';
$dniPrellenado = '';
$mensajeExito = null;

// Si venimos de un registro o recuperación de contraseña, recuperamos el mensaje
if (isset($_SESSION['mensaje_exito'])) {
    $mensajeExito = $_SESSION['mensaje_exito'];
    unset($_SESSION['mensaje_exito']);

    if (!empty($mensajeExito['rol'])) {
        $rolElegido = $mensajeExito['rol'];
    }
    if (!empty($mensajeExito['dni'])) {
        $dniPrellenado = $mensajeExito['dni'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $rolElegido = $_POST['rol'] ?? '';

    try {

        /* ---------- CASO A: ALUMNO (DNI + contraseña) ---------- */
        if ($rolElegido === 'alumno') {

            $dni   = preg_replace('/\D/', '', $_POST['dni'] ?? '');
            $clave = $_POST['clave'] ?? '';

            // Buscamos al alumno por DNI en la base de datos
            $alumno = buscarAlumnoPorDni($dni);

            // Verificamos contraseña (password_verify compara con el hash)
            if ($alumno && password_verify($clave, $alumno['clave_hash'])) {

                session_regenerate_id(true);
                $_SESSION['rol']    = 'alumno';
                $_SESSION['nombre'] = $alumno['nombre'];
                $_SESSION['correo'] = $alumno['correo'] ?? '';
                $_SESSION['id']     = 'alumno:' . $alumno['dni'];   // lo usa ProgMesas
                header('Location: ' . destinoTrasLogin());
                exit;
            }
            $error = 'DNI o contraseña incorrectos.';
        }

        /* ---------- CASO B: PROFESOR (solo código) ---------- */
        elseif ($rolElegido === 'profesor') {

            $codigo = strtoupper(trim($_POST['codigo'] ?? ''));

            if ($codigo !== '') {
                // El código está cifrado, así que se compara contra cada profesor
                foreach (listarProfesores() as $profesor) {
                    if (password_verify($codigo, $profesor['codigo_hash'])) {
                        session_regenerate_id(true);
                        $_SESSION['rol']    = 'profesor';
                        $_SESSION['nombre'] = $profesor['nombre'];
                        $_SESSION['correo'] = $profesor['correo'] ?? '';
                        $_SESSION['id']     = 'profesor:' . strtolower($profesor['correo'] ?? $profesor['nombre']);
                        header('Location: ' . destinoTrasLogin());
                        exit;
                    }
                }
            }
            $error = 'Código de profesor no válido.';
        }

        /* ---------- CASO C: DIRECTIVO (definidos en datos.php) ---------- */
        elseif ($rolElegido === 'directivo') {

            $correo = trim($_POST['correo'] ?? '');
            $clave  = $_POST['clave'] ?? '';

            foreach ($DIRECTIVOS as $directivo) {
                if (strtolower($correo) === strtolower($directivo['correo'])
                    && password_verify($clave, $directivo['clave_hash'])) {

                    session_regenerate_id(true);
                    $_SESSION['rol']    = 'directivo';
                    $_SESSION['nombre'] = $directivo['nombre'];
                    $_SESSION['correo'] = $directivo['correo'];
                    $_SESSION['id']     = 'directivo:' . strtolower($directivo['correo']);
                    header('Location: ' . destinoTrasLogin());
                    exit;
                }
            }
            $error = 'Correo o contraseña incorrectos.';
        }

        else {
            $error = 'Seleccione un tipo de usuario válido.';
            $rolElegido = 'alumno';
        }

    } catch (PDOException $ex) {
        // No mostramos detalles técnicos al visitante; quedan en el log del servidor
        error_log('Error de BD en login: ' . $ex->getMessage());
        $error = 'No se pudo conectar con la base de datos. Intente más tarde.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ingresar al campus</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="fondo-login">

<main class="login">
    <!-- AQUÍ VA EL LOGO DE LA ESCUELA (mismo archivo: img/logo.png) -->
    <img src="img/logo.png" alt="Logo de la escuela" class="logo logo--login" onerror="this.style.display='none'">
    <h1>Campus Virtual</h1>
    <p class="login__sub">Seleccione su tipo de usuario</p>

    <!-- PESTAÑAS para elegir el rol (el JavaScript de abajo las hace funcionar) -->
    <div class="pestanas">
        <button type="button" class="pestana" data-rol="alumno">Alumno</button>
        <button type="button" class="pestana" data-rol="profesor">Profesor</button>
        <button type="button" class="pestana" data-rol="directivo">Directivo</button>
    </div>

    <!-- Mensaje de éxito (registro o recuperación de contraseña) -->
    <?php if ($mensajeExito): ?>
        <div class="exito">
            <p><strong><?php echo e($mensajeExito['mensaje']); ?></strong></p>
            <?php if (!empty($mensajeExito['clave'])): ?>
                <div class="clave-destacada">
                    <span class="clave-destacada__titulo">
                        <?php echo ($mensajeExito['tipo'] === 'recuperacion') ? 'Su nueva contraseña:' : 'Su contraseña generada:'; ?>
                    </span>
                    <strong class="clave-destacada__codigo"><?php echo e($mensajeExito['clave']); ?></strong>
                </div>
                <p class="nota-clave">⚠️ Guarde o copie esta contraseña. Ingrese a continuación con su <strong>DNI</strong> y esta <strong>contraseña</strong>.</p>
            <?php elseif (!empty($mensajeExito['codigo'])): ?>
                <div class="clave-destacada">
                    <span class="clave-destacada__titulo">Su código de profesor:</span>
                    <strong class="clave-destacada__codigo"><?php echo e($mensajeExito['codigo']); ?></strong>
                </div>
                <p class="nota-clave">⚠️ Guarde su código para ingresar a continuación.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Mensaje de error (solo aparece si $error tiene texto) -->
    <?php if ($error !== ''): ?>
        <div class="error"><?php echo e($error); // e() escapa el texto por seguridad ?></div>
    <?php endif; ?>


    <!-- ============ FORMULARIO ALUMNO ============ -->
    <form method="post" action="login.php" class="formulario" id="form-alumno">
        <input type="hidden" name="rol" value="alumno"> <!-- Campo oculto: indica el rol -->

        <label for="a-dni">DNI</label>
        <input type="text" id="a-dni" name="dni" value="<?php echo e($dniPrellenado); ?>" inputmode="numeric" pattern="[0-9]*" maxlength="8" oninput="this.value = this.value.replace(/[^0-9]/g, '')" onkeypress="return event.charCode >= 48 && event.charCode <= 57" placeholder="Solo números (sin puntos ni letras)" required>

        <label for="a-clave">Contraseña</label>
        <input type="password" id="a-clave" name="clave" required>
        <div class="login__ayuda-clave">
            <small>Ingrese su DNI y la contraseña generada al registrarse.</small>
            <a href="recuperar.php" class="login__olvido">¿Olvidó su contraseña?</a>
        </div>

        <button type="submit" class="boton">Ingresar</button>
    </form>


    <!-- ============ FORMULARIO PROFESOR ============ -->
    <form method="post" action="login.php" class="formulario" id="form-profesor">
        <input type="hidden" name="rol" value="profesor">

        <label for="p-codigo">Código de profesor</label>
        <input type="text" id="p-codigo" name="codigo" placeholder="Ej: PROF-A1B2C3" required>
        <small>Código entregado por la escuela.</small>

        <button type="submit" class="boton">Ingresar</button>
    </form>


    <!-- ============ FORMULARIO DIRECTIVO ============ -->
    <form method="post" action="login.php" class="formulario" id="form-directivo">
        <input type="hidden" name="rol" value="directivo">

        <label for="d-correo">Correo</label>
        <input type="email" id="d-correo" name="correo" required>

        <label for="d-clave">Contraseña</label>
        <input type="password" id="d-clave" name="clave" required>

        <button type="submit" class="boton">Ingresar</button>
    </form>

    <!-- ACCIONES: REGISTRO, RECUPERACIÓN, AYUDA Y VOLVER -->
    <div class="login__acciones">
        <a href="registro.php" class="boton boton--secundario">¿Primera vez? Regístrese aquí</a>
        <a href="recuperar.php" class="boton boton--gris">¿Olvidó su contraseña?</a>
        <a href="ayuda.php" class="boton boton--gris">❓ ¿Necesitas ayuda para registrarte?</a>
        <a href="index.php" class="boton boton--oscuro">← Volver al inicio</a>
    </div>
</main>


<script>
/* =====================================================================
   JAVASCRIPT: muestra solo el formulario del rol elegido.
   No tiene que ver con la seguridad: la validación real la hace PHP.
   ===================================================================== */

// PHP nos pasa qué pestaña mostrar al cargar (por si hubo un error)
var rolInicial = "<?php echo e($rolElegido); ?>";

// Guardamos todas las pestañas y todos los formularios
var pestanas = document.querySelectorAll('.pestana');
var roles = ['alumno', 'profesor', 'directivo'];

// Función que muestra un rol y oculta los demás
function mostrarRol(rol) {
    roles.forEach(function (r) {
        // Muestra el formulario del rol elegido, oculta los otros
        document.getElementById('form-' + r).style.display = (r === rol) ? 'block' : 'none';
    });
    pestanas.forEach(function (p) {
        // Marca como "activa" la pestaña elegida
        p.classList.toggle('activa', p.dataset.rol === rol);
    });
}

// Al hacer clic en una pestaña, mostramos su formulario
pestanas.forEach(function (p) {
    p.addEventListener('click', function () { mostrarRol(p.dataset.rol); });
});

// Mostramos la pestaña inicial al cargar la página
mostrarRol(rolInicial);
</script>

</body>
</html>
