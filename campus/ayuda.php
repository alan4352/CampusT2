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
         TUTORIAL 1: REGISTRO DE ALUMNO
         ========================================================= -->
    <article class="tarjeta-tutorial" id="alumno">
        <div class="tarjeta-tutorial__cuerpo">
            <div class="tarjeta-tutorial__titulo">
                <h2>1. Cómo registrarte como Alumno</h2>
                <span class="badge-rol">Alumno</span>
            </div>

            <!-- REPRODUCTOR DE VIDEO -->
            <div class="video-contenedor">
                <video controls preload="metadata">
                    <source src="videos/registro_alumno.mp4" type="video/mp4">
                    Su navegador no soporta el reproductor de video HTML5.
                </video>
            </div>

            <h3 class="pasos-titulo">📋 Paso a paso para Alumnos:</h3>
            <ol class="pasos-lista">
                <li>Dirigite a la página de <strong>Registro</strong> y seleccioná la pestaña <strong>Alumno</strong>.</li>
                <li>Escribí tu <strong>Nombre y apellido</strong> completo.</li>
                <li>Ingresá tu <strong>Correo electrónico</strong> (puede ser Gmail, Hotmail, Outlook o cualquier otro servicio activo).</li>
                <li>Completá tu <strong>DNI</strong>: el sistema solo admite números (sin puntos, espacios ni letras).</li>
                <li>Hacé clic en el botón rojo <strong>"Registrarme"</strong>.</li>
                <li>
                    <strong>⚠️ Muy importante:</strong> En la siguiente pantalla se mostrará tu <strong>contraseña generada automáticamente</strong>.
                    Anotala o guardala en un lugar seguro, ya que la vas a necesitar para iniciar sesión junto con tu DNI.
                </li>
            </ol>
        </div>
    </article>


    <!-- =========================================================
         TUTORIAL 2: REGISTRO DE PROFESOR
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
                Antes de iniciar el registro, el docente debe acercarse personalmente a <strong>Mesa de Entrada de la escuela</strong> para solicitar la <strong>Clave institucional de registro</strong>. Sin esta clave provista por la institución, no podrá habilitar su cuenta.
            </div>

            <!-- REPRODUCTOR DE VIDEO -->
            <div class="video-contenedor">
                <video controls preload="metadata">
                    <source src="videos/registro_profesor.mp4" type="video/mp4">
                    Su navegador no soporta el reproductor de video HTML5.
                </video>
            </div>

            <h3 class="pasos-titulo">📋 Paso a paso para Docentes:</h3>
            <ol class="pasos-lista">
                <li>Solicitá en <strong>Mesa de Entrada</strong> el código institucional para profesores.</li>
                <li>Accedé a la sección de <strong>Registro</strong> y hacé clic en la pestaña <strong>Profesor</strong>.</li>
                <li>Ingresá tu <strong>Nombre y apellido</strong> y tu <strong>Correo electrónico</strong> institucional o personal.</li>
                <li>En el casillero <strong>"Clave de la escuela"</strong>, escribí exactamente el código provisto por la institución.</li>
                <li>Hacé clic en el botón <strong>"Registrarme"</strong>.</li>
                <li>
                    <strong>⚠️ Guardá tu código:</strong> El sistema te entregará un <strong>Código de Profesor</strong> único (por ejemplo: <code>PROF-A1B2C3</code>). Ese código será tu llave de acceso para iniciar sesión siempre que ingreses al campus.
                </li>
            </ol>
        </div>
    </article>


    <!-- =========================================================
         TUTORIAL 3: RECUPERAR CONTRASEÑA
         ========================================================= -->
    <article class="tarjeta-tutorial" id="recuperar">
        <div class="tarjeta-tutorial__cuerpo">
            <div class="tarjeta-tutorial__titulo">
                <h2>3. Si olvidaste tu contraseña</h2>
                <span class="badge-rol badge-rol--recuperar">Recuperación</span>
            </div>

            <!-- AVISO MESA DE ENTRADA -->
            <div class="aviso-importante">
                <strong>🏛️ Requisito de seguridad:</strong>
                Por motivos de seguridad y protección de los datos de cada estudiante, para restablecer la contraseña se requiere el <strong>Código de la escuela</strong>. Si lo olvidaste o no lo tenés, debés acercarte a <strong>Mesa de Entrada</strong> para solicitarlo antes de continuar.
            </div>

            <!-- REPRODUCTOR DE VIDEO -->
            <div class="video-contenedor">
                <video controls preload="metadata">
                    <source src="videos/recuperar_clave.mp4" type="video/mp4">
                    Su navegador no soporta el reproductor de video HTML5.
                </video>
            </div>

            <h3 class="pasos-titulo">📋 Paso a paso para restablecer la contraseña:</h3>
            <ol class="pasos-lista">
                <li>En la pantalla de ingreso (Login), hacé clic en <strong>"¿Olvidó su contraseña?"</strong>.</li>
                <li>Escribí tu número de <strong>DNI</strong> (sin puntos ni letras).</li>
                <li>En el campo <strong>"Código de la escuela"</strong>, colocá el código institucional provisto por Mesa de Entrada.</li>
                <li>Hacé clic en <strong>"Restablecer contraseña"</strong>.</li>
                <li>El sistema generará una <strong>nueva contraseña</strong> inmediatamente en pantalla. Copiala y usala para acceder con tu DNI.</li>
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
