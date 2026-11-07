<?php
/* =====================================================================
   ARCHIVO: ayuda.php
   PARA QUÉ SIRVE: página pública con tutoriales en video y explicaciones
   paso a paso sobre cómo registrarse (Alumno y Profesor) y cómo recuperar
   la contraseña si fue olvidada.
   ===================================================================== */
require_once 'includes/sesion.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Centro de Ayuda y Tutoriales — Campus Virtual</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="fondo-panel">

<!-- =================== BARRA SUPERIOR =================== -->
<header class="barra">
    <div class="contenedor barra__interior">
        <a href="index.php" class="barra__logo">
            <img src="img/logo.png" alt="Logo de la escuela" class="logo" onerror="this.style.display='none'">
            Campus Virtual · Ayuda
        </a>
        <nav class="barra__menu">
            <a href="login.php" class="boton boton--chico boton--secundario">Ingresar</a>
            <a href="registro.php" class="boton boton--chico">Registrarse</a>
            <a href="index.php" class="boton boton--chico boton--borde">Inicio</a>
        </nav>
    </div>
</header>

<main class="ayuda-pagina">

    <!-- ENCABEZADO -->
    <div class="ayuda-header">
        <h1>Centro de Ayuda y Tutoriales</h1>
        <p>Encontrá aquí los videos explicativos y el paso a paso detallado para registrarte o recuperar el acceso a tu cuenta.</p>
    </div>

    <!-- =========================================================
         GUÍA 1: REGISTRO DE ALUMNO
         ========================================================= -->
    <article class="tarjeta-tutorial" id="alumno">
        <div class="tarjeta-tutorial__cuerpo">
            <div class="tarjeta-tutorial__titulo">
                <h2>1. Cómo registrarte como Alumno</h2>
                <span class="badge-rol">Alumno</span>
            </div>

            <h3 class="pasos-titulo">📋 Paso a paso para Alumnos:</h3>
            <ol class="pasos-lista">
                <li>Dirigite a la página de <strong>Registro</strong> y seleccioná la pestaña <strong>Alumno</strong>.</li>
                <li>Escribí tu <strong>Nombre y apellido</strong> completo.</li>
                <li>Ingresá tu <strong>Correo electrónico</strong> activo.</li>
                <li>Completá tu <strong>DNI</strong>: solo números (sin puntos, espacios ni letras).</li>
                <li>Hacé clic en el botón rojo <strong>"Registrarme"</strong>.</li>
                <li>
                    <strong>⚠️ Muy importante:</strong> En la siguiente pantalla se mostrará tu <strong>contraseña generada automáticamente</strong>.
                    Anotala o guardala en un lugar seguro, ya que la vas a necesitar para iniciar sesión junto con tu DNI.
                </li>
            </ol>
        </div>
    </article>


    <!-- =========================================================
         GUÍA 2: REGISTRO DE PROFESOR
         ========================================================= -->
    <article class="tarjeta-tutorial" id="profesor">
        <div class="tarjeta-tutorial__cuerpo">
            <div class="tarjeta-tutorial__titulo">
                <h2>2. Cómo registrarte como Profesor</h2>
                <span class="badge-rol badge-rol--profesor">Profesor</span>
            </div>

            <!-- AVISO MESA DE ENTRADA -->
            <div class="aviso-importante">
                <strong>🏛️ Requisito previo obligatorio:</strong>
                Antes de iniciar el registro, el docente debe solicitar a la <strong>Dirección o Secretaría de la escuela</strong> la <strong>Clave institucional de registro</strong>. Sin esta clave provista por la institución, no podrá habilitar su cuenta.
            </div>

            <h3 class="pasos-titulo">📋 Paso a paso para Docentes:</h3>
            <ol class="pasos-lista">
                <li>Solicitá en la institución la clave institucional para registro de profesores.</li>
                <li>Accedé a la sección de <strong>Registro</strong> y hacé clic en la pestaña <strong>Profesor</strong>.</li>
                <li>Ingresá tu <strong>Nombre y apellido</strong>, tu número de <strong>DNI</strong> y tu <strong>Correo electrónico</strong>.</li>
                <li>En el casillero <strong>"Clave de la escuela"</strong>, escribí exactamente el código provisto por la institución.</li>
                <li>Hacé clic en el botón <strong>"Registrarme"</strong>.</li>
                <li>
                    <strong>⚠️ Guardá tu código:</strong> El sistema te entregará un <strong>Código de Profesor</strong> único (por ejemplo: <code>PROF-A1B2C3</code>). Ese código será tu llave de acceso para iniciar sesión siempre que ingreses al campus.
                </li>
            </ol>
        </div>
    </article>


    <!-- =========================================================
         GUÍA 3: RECUPERAR CONTRASEÑA
         ========================================================= -->
    <article class="tarjeta-tutorial" id="recuperar">
        <div class="tarjeta-tutorial__cuerpo">
            <div class="tarjeta-tutorial__titulo">
                <h2>3. Si olvidaste tu contraseña</h2>
                <span class="badge-rol badge-rol--recuperar">Recuperación</span>
            </div>

            <!-- AVISO INSTITUCIONAL -->
            <div class="aviso-importante" style="background: rgba(162, 19, 47, 0.15); border-left: 4px solid var(--primary, #a2132f); color: #fff;">
                <strong>🏛️ Contacte a la institución para generar una nueva contraseña:</strong><br>
                Por razones de seguridad y protección de la privacidad, el blanqueo de contraseñas ya no se realiza de forma automática por internet.
            </div>

            <h3 class="pasos-titulo">📋 Cómo solicitar una nueva clave:</h3>
            <ol class="pasos-lista">
                <li>Acercate personalmente a la <strong>Preceptoría, Secretaría o Dirección</strong> de la escuela.</li>
                <li>Presentá tu número de <strong>DNI</strong> para acreditar tu identidad.</li>
                <li>El personal directivo generará y te entregará tu nueva clave de acceso en el momento.</li>
            </ol>
        </div>
    </article>

    <!-- ACCIONES FINALES -->
    <div class="ayuda-acciones">
        <a href="registro.php" class="boton">Ir al formulario de registro →</a>
        <a href="login.php" class="boton boton--secundario">Ingresar al campus →</a>
        <a href="index.php" class="boton boton--gris">← Volver al inicio</a>
    </div>

</main>

</body>
</html>
