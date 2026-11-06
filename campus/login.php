<?php
/* =====================================================================
   ARCHIVO: login.php
   PARA QUÉ SIRVE: Formulario unificado de acceso al Campus Virtual.
   Permite ingresar a Alumnos, Profesores y Directivos desde un único
   formulario inteligente, detectando automáticamente el tipo de usuario
   según el identificador (DNI, correo electrónico o código PROF-).
   ===================================================================== */

require_once 'includes/sesion.php';
require_once 'includes/datos.php';      // Directivos institucionales
require_once 'includes/usuarios.php';   // Alumnos y profesores en MySQL

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
$mensajeExito = null;

// Control de intentos fallidos (Rate Limiting contra ataques de fuerza bruta)
$ipRemota = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$rateLimitKey = 'campus_login_' . md5($ipRemota);
$rateLimitFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $rateLimitKey . '.json';

$rateData = ['intentos' => 0, 'bloqueado_hasta' => 0];
if (file_exists($rateLimitFile)) {
    $raw = @file_get_contents($rateLimitFile);
    if ($raw) {
        $decoded = @json_decode($raw, true);
        if (is_array($decoded)) {
            $rateData = $decoded;
        }
    }
}

$ahora = time();
$bloqueadoHasta = max($rateData['bloqueado_hasta'] ?? 0, $_SESSION['login_bloqueado_hasta'] ?? 0);
$estaBloqueado = ($bloqueadoHasta > $ahora);

// Si venimos de un registro o recuperación, precargamos el identificador
if (isset($_SESSION['mensaje_exito'])) {
    $mensajeExito = $_SESSION['mensaje_exito'];
    unset($_SESSION['mensaje_exito']);

    if (!empty($mensajeExito['dni'])) {
        $identificadorPrellenado = $mensajeExito['dni'];
    } elseif (!empty($mensajeExito['codigo'])) {
        $identificadorPrellenado = $mensajeExito['codigo'];
    }
}

// Funciones auxiliares para iniciar sesión de forma consistente según el rol
function iniciarSesionAlumno(array $alumno, string $rateLimitFile): void {
    unset($_SESSION['login_intentos'], $_SESSION['login_bloqueado_hasta']);
    if (file_exists($rateLimitFile)) { @unlink($rateLimitFile); }

    session_regenerate_id(true);
    $_SESSION['rol']        = 'alumno';
    $_SESSION['usuario_id'] = (int) ($alumno['id'] ?? 0);
    $_SESSION['user_id']    = (int) ($alumno['id'] ?? 0);
    $_SESSION['nombre']     = $alumno['nombre'];
    $_SESSION['dni']        = $alumno['dni'];
    $_SESSION['correo']     = $alumno['correo'] ?? '';
    $_SESSION['email']      = $alumno['correo'] ?? '';
    $_SESSION['id']         = (int) ($alumno['id'] ?? 0);
    header('Location: ' . destinoTrasLogin());
    exit;
}

function iniciarSesionProfesor(array $profesor, string $rateLimitFile): void {
    unset($_SESSION['login_intentos'], $_SESSION['login_bloqueado_hasta']);
    if (file_exists($rateLimitFile)) { @unlink($rateLimitFile); }

    session_regenerate_id(true);
    $_SESSION['rol']        = 'profesor';
    $_SESSION['usuario_id'] = (int) ($profesor['id'] ?? 0);
    $_SESSION['user_id']    = (int) ($profesor['id'] ?? 0);
    $_SESSION['nombre']     = $profesor['nombre'];
    $_SESSION['dni']        = $profesor['dni'] ?? '';
    $_SESSION['correo']     = $profesor['correo'] ?? '';
    $_SESSION['email']      = $profesor['correo'] ?? '';
    $_SESSION['id']         = (int) ($profesor['id'] ?? 0);
    header('Location: ' . destinoTrasLogin());
    exit;
}

function iniciarSesionDirectivo(array $directivo, string $rateLimitFile): void {
    unset($_SESSION['login_intentos'], $_SESSION['login_bloqueado_hasta']);
    if (file_exists($rateLimitFile)) { @unlink($rateLimitFile); }

    session_regenerate_id(true);
    $_SESSION['rol']        = 'directivo';
    $_SESSION['usuario_id'] = 'directivo:' . strtolower($directivo['correo']);
    $_SESSION['user_id']    = 'directivo:' . strtolower($directivo['correo']);
    $_SESSION['nombre']     = $directivo['nombre'];
    $_SESSION['correo']     = $directivo['correo'];
    $_SESSION['email']      = $directivo['correo'];
    $_SESSION['id']         = 'directivo:' . strtolower($directivo['correo']);
    header('Location: ' . destinoTrasLogin());
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identificador = trim($_POST['identificador'] ?? $_POST['dni'] ?? $_POST['correo'] ?? $_POST['codigo'] ?? '');
    $clave         = $_POST['clave'] ?? '';

    $identificadorPrellenado = $identificador;

    if ($estaBloqueado) {
        $minutosRestantes = max(1, (int) ceil(($bloqueadoHasta - $ahora) / 60));
        $error = "Demasiados intentos fallidos. Por seguridad, el acceso ha sido bloqueado temporalmente. Intente nuevamente en {$minutosRestantes} minuto(s).";
    } elseif ($identificador === '') {
        $error = 'Por favor, ingrese su DNI, correo electrónico o código de profesor.';
    } else {
        try {
            $autenticado = false;

            /* =================================================================
               1. DETECCIÓN POR CÓDIGO DE PROFESOR (PROF-...)
               ================================================================= */
            $codigoCandidato = '';
            if (stripos($identificador, 'PROF-') === 0) {
                $codigoCandidato = strtoupper($identificador);
            } elseif (stripos($clave, 'PROF-') === 0) {
                $codigoCandidato = strtoupper($clave);
            }

            if ($codigoCandidato !== '') {
                foreach (listarProfesores() as $profesor) {
                    if (password_verify($codigoCandidato, $profesor['codigo_hash'])) {
                        iniciarSesionProfesor($profesor, $rateLimitFile);
                    }
                }
            }

            /* =================================================================
               2. DETECCIÓN POR CORREO ELECTRÓNICO (contiene @)
               ================================================================= */
            if (!$autenticado && strpos($identificador, '@') !== false) {
                $correoLimpio = strtolower($identificador);

                // A) Verificar si es Directivo
                foreach ($DIRECTIVOS as $directivo) {
                    if (strtolower($directivo['correo']) === $correoLimpio && password_verify($clave, $directivo['clave_hash'])) {
                        iniciarSesionDirectivo($directivo, $rateLimitFile);
                    }
                }

                // B) Verificar si es Profesor por correo
                $prof = buscarProfesorPorCorreo($correoLimpio);
                if ($prof && password_verify($clave, $prof['codigo_hash'])) {
                    iniciarSesionProfesor($prof, $rateLimitFile);
                }

                // C) Verificar si es Alumno por correo
                $alumnoCorreo = buscarAlumnoPorCorreo($correoLimpio);
                if ($alumnoCorreo && password_verify($clave, $alumnoCorreo['clave_hash'])) {
                    iniciarSesionAlumno($alumnoCorreo, $rateLimitFile);
                }
            }

            /* =================================================================
               3. DETECCIÓN POR DNI (secuencia numérica de 7 u 8 dígitos)
               ================================================================= */
            $dniLimpio = preg_replace('/\D/', '', $identificador);
            if (!$autenticado && $dniLimpio !== '' && strlen($dniLimpio) >= 7 && strlen($dniLimpio) <= 9) {
                // A) Buscar en Alumnos por DNI
                $alumno = buscarAlumnoPorDni($dniLimpio);
                if ($alumno && password_verify($clave, $alumno['clave_hash'])) {
                    iniciarSesionAlumno($alumno, $rateLimitFile);
                }

                // B) Buscar en Profesores por DNI (con código o contraseña)
                $profDni = buscarProfesorPorDni($dniLimpio);
                if ($profDni && password_verify($clave, $profDni['codigo_hash'])) {
                    iniciarSesionProfesor($profDni, $rateLimitFile);
                }
            }

            /* =================================================================
               4. CASO DE RESPALDO: Código de profesor sin prefijo o directo
               ================================================================= */
            if (!$autenticado && $clave === '' && strlen($identificador) >= 4) {
                $posibleCodigo = strtoupper($identificador);
                foreach (listarProfesores() as $profesor) {
                    if (password_verify($posibleCodigo, $profesor['codigo_hash'])) {
                        iniciarSesionProfesor($profesor, $rateLimitFile);
                    }
                }
            }

            if (!$autenticado) {
                $error = 'Datos de acceso incorrectos. Verifique su DNI, correo o código y su contraseña.';
            }

        } catch (PDOException $ex) {
            error_log('Error de BD en login unificado: ' . $ex->getMessage());
            $error = 'No se pudo conectar con la base de datos. Intente más tarde.';
        }

        // Si falló la autenticación, incrementamos el contador de intentos
        if ($error !== '') {
            $intentos = ($rateData['intentos'] ?? 0) + 1;
            $bloqueadoHasta = 0;
            if ($intentos >= 5) {
                $bloqueadoHasta = time() + 900; // 15 minutos de bloqueo
                $error = 'Demasiados intentos fallidos. Por seguridad, su acceso ha sido bloqueado temporalmente por 15 minutos.';
            }
            $rateData = ['intentos' => $intentos, 'bloqueado_hasta' => $bloqueadoHasta];
            $_SESSION['login_intentos'] = $intentos;
            $_SESSION['login_bloqueado_hasta'] = $bloqueadoHasta;
            @file_put_contents($rateLimitFile, json_encode($rateData));
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

    <!-- ACCIONES: REGISTRO, RECUPERACIÓN Y VOLVER -->
    <div class="login__acciones" style="margin-top: 1.5rem;">
        <a href="registro.php" class="boton boton--secundario">¿Primera vez? Regístrese aquí</a>
        <a href="recuperar.php" class="boton boton--gris">¿Olvidó su contraseña? Solicitar nueva en la institución</a>
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
            if (ayudaClave) ayudaClave.textContent = 'Ingrese la contraseña asociada a su correo.';
            return;
        }

        // Si son solo números (DNI de alumno o profesor)
        if (/^\d+$/.test(val)) {
            hintEl.className = 'identificador-hint detected';
            hintEl.innerHTML = '<span>✓ DNI detectado · Ingrese su contraseña de alumno</span>';
            if (campoClave) campoClave.style.display = 'block';
            if (ayudaClave) ayudaClave.textContent = 'Ingrese la contraseña generada al registrarse.';
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
