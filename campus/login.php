<?php
/* =====================================================================
   ARCHIVO: login.php
   PARA QUÉ SIRVE: Formulario unificado de acceso al Campus Virtual.
   Alumnos, profesores y directivos entran desde un único formulario;
   el tipo de usuario se detecta según el identificador (DNI, correo
   electrónico o código PROF-).

   IMPORTANTE: $_SESSION['id'] se guarda con el prefijo del rol
   ('alumno:<DNI>', 'profesor:<correo>', 'directivo:<correo>').
   ProgMesas (api.php) y proyectores (conexion.php) identifican a la
   persona con ese valor: no cambiar el formato.
   ===================================================================== */

require_once 'includes/sesion.php';
require_once 'includes/datos.php';      // Directivos institucionales
require_once 'includes/usuarios.php';   // Alumnos y profesores en MySQL

const LOGIN_MAX_FALLOS_CUENTA = 5;      // fallos seguidos con el mismo identificador
const LOGIN_MAX_FALLOS_IP     = 30;     // fallos desde la misma IP (frena probar muchos códigos)
const LOGIN_BLOQUEO_SEGUNDOS  = 900;    // 15 minutos

// Destino tras el inicio de sesión exitoso
function destinoTrasLogin() {
    $destino = $_SESSION['volver_a'] ?? 'dashboard.php';
    unset($_SESSION['volver_a']);
    return preg_match('/^[a-z_]+\.php$/', $destino) ? $destino : 'dashboard.php';
}

/* ---------------------------------------------------------------------
   LÍMITE DE INTENTOS FALLIDOS
   Se cuenta por cuenta (identificador) y, con un tope más alto, por IP.
   No se cuenta solo por IP: detrás del proxy de Render muchos usuarios
   pueden compartir la misma y se bloquearía a toda la escuela.
   --------------------------------------------------------------------- */
function ipCliente(): string {
    // Render agrega la IP real al final de X-Forwarded-For; lo anterior lo puede inventar el cliente
    $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($xff !== '') {
        $partes = array_map('trim', explode(',', $xff));
        $ultima = end($partes);
        if (filter_var($ultima, FILTER_VALIDATE_IP)) {
            return $ultima;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function archivoIntentos(string $clave): string {
    $carpeta = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'campus_login';
    if (!is_dir($carpeta)) {
        @mkdir($carpeta, 0700, true);
    }
    return $carpeta . DIRECTORY_SEPARATOR . hash('sha256', $clave) . '.json';
}

function leerIntentos(string $clave): array {
    $datos = ['fallos' => 0, 'bloqueado_hasta' => 0];
    $raw = @file_get_contents(archivoIntentos($clave));
    if ($raw) {
        $dec = json_decode($raw, true);
        if (is_array($dec)) {
            $datos = array_merge($datos, $dec);
        }
    }
    // Si el bloqueo ya venció, se empieza de cero
    if ($datos['bloqueado_hasta'] > 0 && $datos['bloqueado_hasta'] <= time()) {
        $datos = ['fallos' => 0, 'bloqueado_hasta' => 0];
    }
    return $datos;
}

function sumarFallo(string $clave, int $maximo): void {
    $d = leerIntentos($clave);
    $d['fallos']++;
    if ($d['fallos'] >= $maximo) {
        $d['bloqueado_hasta'] = time() + LOGIN_BLOQUEO_SEGUNDOS;
    }
    @file_put_contents(archivoIntentos($clave), json_encode($d), LOCK_EX);
}

function borrarIntentos(string $clave): void {
    @unlink(archivoIntentos($clave));
}

// Normaliza lo que escribió el usuario para que "40.123.456" y "40123456" cuenten igual
function claveCuenta(string $identificador): string {
    $id = strtolower(trim($identificador));
    if (preg_match('/^[\d.\s-]+$/', $id)) {
        $id = preg_replace('/\D/', '', $id);
    }
    return 'cuenta:' . $id;
}

/* ---------------------------------------------------------------------
   INICIO DE SESIÓN (mismo formato de sesión que antes del login unificado)
   --------------------------------------------------------------------- */
function iniciarSesion(string $rol, string $nombre, string $correo, string $id, string $claveCuentaUsada): void {
    borrarIntentos($claveCuentaUsada);
    session_regenerate_id(true);
    $_SESSION['rol']    = $rol;
    $_SESSION['nombre'] = $nombre;
    $_SESSION['correo'] = $correo;
    $_SESSION['id']     = $id;   // lo usan ProgMesas y proyectores
    header('Location: ' . destinoTrasLogin());
    exit;
}

function iniciarSesionAlumno(array $alumno, string $cc): void {
    iniciarSesion('alumno', $alumno['nombre'], $alumno['correo'] ?? '', 'alumno:' . $alumno['dni'], $cc);
}

function iniciarSesionProfesor(array $profesor, string $cc): void {
    $correo = $profesor['correo'] ?? '';
    iniciarSesion('profesor', $profesor['nombre'], $correo,
        'profesor:' . strtolower($correo !== '' ? $correo : $profesor['nombre']), $cc);
}

function iniciarSesionDirectivo(array $directivo, string $cc): void {
    iniciarSesion('directivo', $directivo['nombre'], $directivo['correo'],
        'directivo:' . strtolower($directivo['correo']), $cc);
}

// Si ya inició sesión, redirigimos a su destino correspondiente
if (estaLogueado()) {
    header('Location: ' . destinoTrasLogin());
    exit;
}

$error = '';
$identificadorPrellenado = '';
$mensajeExito = null;

// Si venimos de un registro, precargamos el identificador
if (isset($_SESSION['mensaje_exito'])) {
    $mensajeExito = $_SESSION['mensaje_exito'];
    unset($_SESSION['mensaje_exito']);

    if (!empty($mensajeExito['dni'])) {
        $identificadorPrellenado = $mensajeExito['dni'];
    } elseif (!empty($mensajeExito['codigo'])) {
        $identificadorPrellenado = $mensajeExito['codigo'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identificador = trim((string) ($_POST['identificador'] ?? ''));
    $clave         = (string) ($_POST['clave'] ?? '');
    $identificadorPrellenado = $identificador;

    $cc = claveCuenta($identificador);
    $ci = 'ip:' . ipCliente();
    $bloqueo = max(leerIntentos($cc)['bloqueado_hasta'], leerIntentos($ci)['bloqueado_hasta']);

    if ($bloqueo > time()) {
        $minutos = max(1, (int) ceil(($bloqueo - time()) / 60));
        $error = "Demasiados intentos fallidos. Por seguridad, el acceso quedó bloqueado. Intente nuevamente en {$minutos} minuto(s).";
    } elseif ($identificador === '') {
        $error = 'Por favor, ingrese su DNI, correo electrónico o código de profesor.';
    } else {
        try {
            /* 1. CÓDIGO DE PROFESOR (PROF-...) en el identificador o en el campo contraseña */
            $codigo = '';
            if (stripos($identificador, 'PROF-') === 0) {
                $codigo = strtoupper($identificador);
            } elseif (stripos($clave, 'PROF-') === 0) {
                $codigo = strtoupper(trim($clave));
            }
            if ($codigo !== '') {
                foreach (listarProfesores() as $profesor) {
                    if (password_verify($codigo, $profesor['codigo_hash'])) {
                        iniciarSesionProfesor($profesor, $cc);
                    }
                }
            }

            /* 2. CORREO ELECTRÓNICO: directivo, profesor (con su código) o alumno */
            if (strpos($identificador, '@') !== false && $clave !== '') {
                $correo = strtolower($identificador);

                foreach ($DIRECTIVOS as $directivo) {
                    if (strtolower($directivo['correo']) === $correo && password_verify($clave, $directivo['clave_hash'])) {
                        iniciarSesionDirectivo($directivo, $cc);
                    }
                }
                $prof = buscarProfesorPorCorreo($correo);
                if ($prof && password_verify(strtoupper(trim($clave)), $prof['codigo_hash'])) {
                    iniciarSesionProfesor($prof, $cc);
                }
                $alumno = buscarAlumnoPorCorreo($correo);
                if ($alumno && password_verify($clave, $alumno['clave_hash'])) {
                    iniciarSesionAlumno($alumno, $cc);
                }
            }

            /* 3. DNI (7 u 8 números): alumno con su contraseña o profesor con su código */
            $dni = preg_replace('/\D/', '', $identificador);
            if (strpos($identificador, '@') === false && preg_match('/^\d{7,8}$/', $dni) && $clave !== '') {
                $alumno = buscarAlumnoPorDni($dni);
                if ($alumno && password_verify($clave, $alumno['clave_hash'])) {
                    iniciarSesionAlumno($alumno, $cc);
                }
                $prof = buscarProfesorPorDni($dni);
                if ($prof && password_verify(strtoupper(trim($clave)), $prof['codigo_hash'])) {
                    iniciarSesionProfesor($prof, $cc);
                }
            }

            $error = 'Datos de acceso incorrectos. Verifique su DNI, correo o código y su contraseña.';

        } catch (PDOException $ex) {
            error_log('Error de BD en login: ' . $ex->getMessage());
            $error = 'No se pudo conectar con la base de datos. Intente más tarde.';
        }

        // Solo los datos incorrectos cuentan como intento fallido (no las caídas de la base)
        if (strpos($error, 'Datos de acceso incorrectos') === 0) {
            sumarFallo($cc, LOGIN_MAX_FALLOS_CUENTA);
            sumarFallo($ci, LOGIN_MAX_FALLOS_IP);
            if (max(leerIntentos($cc)['bloqueado_hasta'], leerIntentos($ci)['bloqueado_hasta']) > time()) {
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
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.85rem;
            color: #9ca3af;
            margin-top: 6px;
            transition: color 0.2s ease;
        }
        .identificador-hint.detected {
            color: #4ade80;
            font-weight: 600;
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
    <p class="login__sub login__sub--unificado">Portal institucional de acceso unificado</p>

    <!-- Mensaje de éxito tras registro o recuperación -->
    <?php if ($mensajeExito): ?>
        <div class="exito" style="margin-bottom: 1.25rem;">
            <p><strong><?php echo e($mensajeExito['mensaje']); ?></strong></p>
            <?php if (!empty($mensajeExito['clave'])): ?>
                <div class="clave-destacada">
                    <span class="clave-destacada__titulo">
                        <?php echo ($mensajeExito['tipo'] === 'recuperacion') ? 'Su nueva contraseña:' : 'Su contraseña generada:'; ?>
                    </span>
                    <strong class="clave-destacada__codigo"><?php echo e($mensajeExito['clave']); ?></strong>
                </div>
                <p class="nota-clave">⚠️ Guarde o copie esta contraseña para ingresar a continuación.</p>
            <?php elseif (!empty($mensajeExito['codigo'])): ?>
                <div class="clave-destacada">
                    <span class="clave-destacada__titulo">Su código de profesor:</span>
                    <strong class="clave-destacada__codigo"><?php echo e($mensajeExito['codigo']); ?></strong>
                </div>
                <p class="nota-clave">⚠️ Guarde su código para ingresar a continuación.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Mensaje de error -->
    <?php if ($error !== ''): ?>
        <div class="error" style="margin-bottom: 1.25rem;"><?php echo e($error); ?></div>
    <?php endif; ?>

    <!-- ============ FORMULARIO DE ACCESO UNIFICADO ============ -->
    <form method="post" action="login.php" class="formulario form-unificado" id="form-login-unificado" novalidate>
        <label for="identificador">Identificación</label>
        <input 
            type="text" 
            id="identificador" 
            name="identificador" 
            value="<?php echo e($identificadorPrellenado); ?>" 
            placeholder="DNI, correo o código PROF-" 
            required 
            autocomplete="username" 
            autofocus
        >
        <div class="identificador-hint" id="identificadorHint">
            <span>Ingrese su DNI (alumno), código PROF- (profesor) o correo (directivo)</span>
        </div>

        <div id="campoClaveWrapper" style="margin-top: 0.5rem;">
            <label for="clave" id="labelClave">Contraseña</label>
            <input 
                type="password" 
                id="clave" 
                name="clave" 
                placeholder="Su contraseña de acceso" 
                autocomplete="current-password"
            >
            <div class="login__ayuda-clave" style="margin-top: 8px;">
                <small id="ayudaClave">Alumnos y Directivos ingresan su contraseña personal.</small>
            </div>
            <div style="margin-top: 8px;">
                <a href="recuperar.php" class="login__olvido" style="color: #f87171; font-size: 0.88rem; font-weight: 600; text-decoration: underline;">
                    ¿Olvidó su contraseña? Contacte a la institución para generar una nueva
                </a>
            </div>
        </div>

        <button type="submit" class="boton" style="margin-top: 1.25rem; width: 100%;">Ingresar</button>
    </form>

    <!-- ACCIONES: REGISTRO Y VOLVER -->
    <div class="login__acciones" style="margin-top: 1.5rem;">
        <a href="registro.php" class="boton boton--secundario">¿Primera vez? Regístrese aquí</a>
        <a href="index.php" class="boton boton--oscuro">← Volver al inicio</a>
    </div>
</main>

<script>
// Detección visual en tiempo real para guiar al usuario según lo que teclea
(function() {
    const inputId = document.getElementById('identificador');
    const hintEl = document.getElementById('identificadorHint');
    const campoClave = document.getElementById('campoClaveWrapper');
    const ayudaClave = document.getElementById('ayudaClave');

    function actualizarPistas() {
        if (!inputId || !hintEl) return;
        const val = inputId.value.trim();

        if (val.length === 0) {
            hintEl.className = 'identificador-hint';
            hintEl.innerHTML = '<span>Ingrese su DNI (alumno), código PROF- (profesor) o correo (directivo)</span>';
            if (campoClave) campoClave.style.display = 'block';
            if (ayudaClave) ayudaClave.textContent = 'Alumnos y Directivos ingresan su contraseña personal.';
            return;
        }

        // Si empieza con PROF- (o contiene código de profesor)
        if (/^prof-/i.test(val) || /^prof/i.test(val)) {
            hintEl.className = 'identificador-hint detected';
            hintEl.innerHTML = '<span>✓ Código de profesor detectado (no requiere contraseña adicional)</span>';
            if (ayudaClave) ayudaClave.textContent = 'Los profesores ingresan directamente con su código.';
            return;
        }

        // Si contiene @ (correo electrónico de directivo o profesor)
        if (val.includes('@')) {
            hintEl.className = 'identificador-hint detected';
            hintEl.innerHTML = '<span>✓ Correo institucional detectado · Ingrese su contraseña</span>';
            if (campoClave) campoClave.style.display = 'block';
            if (ayudaClave) ayudaClave.textContent = 'Directivos y alumnos: su contraseña. Profesores: su código PROF-.';
            return;
        }

        // Si son solo números (DNI de alumno o profesor)
        if (/^\d+$/.test(val)) {
            hintEl.className = 'identificador-hint detected';
            hintEl.innerHTML = '<span>✓ DNI detectado · Ingrese su contraseña</span>';
            if (campoClave) campoClave.style.display = 'block';
            if (ayudaClave) ayudaClave.textContent = 'Alumnos: la contraseña generada al registrarse. Profesores: su código PROF-.';
            return;
        }

        hintEl.className = 'identificador-hint';
        hintEl.innerHTML = '<span>DNI, correo institucional o código de profesor</span>';
        if (campoClave) campoClave.style.display = 'block';
    }

    if (inputId) {
        inputId.addEventListener('input', actualizarPistas);
        actualizarPistas();
    }
})();
</script>

</body>
</html>
