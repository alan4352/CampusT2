<?php
/* =====================================================================
   ARCHIVO: ayuda.php
   PARA QUÉ SIRVE: página pública que explica cómo se obtiene la cuenta
   (código de primer ingreso), cómo se ingresa y cómo recuperarla.
   ===================================================================== */
require_once 'includes/sesion.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Centro de Ayuda — Campus Virtual</title>
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
            <a href="activar.php" class="boton boton--chico">Activar cuenta</a>
            <a href="index.php" class="boton boton--chico boton--borde">Inicio</a>
        </nav>
    </div>
</header>

<main class="ayuda-pagina">

    <div class="ayuda-header">
        <h1>Centro de Ayuda</h1>
        <p>Cómo obtener tu cuenta, ingresar al campus y recuperarla si olvidaste la contraseña.</p>
    </div>

    <!-- GUÍA 1: PRIMER INGRESO -->
    <article class="tarjeta-tutorial" id="primer-ingreso">
        <div class="tarjeta-tutorial__cuerpo">
            <div class="tarjeta-tutorial__titulo">
                <h2>1. Tu primer ingreso</h2>
                <span class="badge-rol">Todos</span>
            </div>

            <div class="aviso-importante">
                <strong>🏛️ Las cuentas las crea la escuela.</strong>
                No hay registro libre: a los <strong>alumnos</strong> los carga su <strong>preceptor</strong>, y al
                <strong>personal</strong> (profesores, preceptores y directivos) un <strong>directivo</strong>.
            </div>

            <h3 class="pasos-titulo">📋 Paso a paso:</h3>
            <ol class="pasos-lista">
                <li>Pedí tu <strong>código de acceso</strong> (por ejemplo <code>ABCDE-FGHJK</code>): a tu preceptor si sos alumno, o en Dirección si sos docente.</li>
                <li>Entrá a <a href="activar.php"><strong>Activar cuenta</strong></a> y escribí el código.</li>
                <li>Revisá que la cuenta sea la tuya (nombre y últimos números del DNI).</li>
                <li>Elegí tu <strong>contraseña</strong> (al menos 8 caracteres) y, si te lo pide, tu correo.</li>
                <li>Listo: desde ahí ingresás siempre con tu <strong>DNI o correo</strong> y tu contraseña.</li>
            </ol>
            <p class="pasos-titulo">⚠️ El código sirve <strong>una sola vez</strong> y vence a los 7 días. Si se te venció, pedí otro.</p>
        </div>
    </article>

    <!-- GUÍA 2: PROFESORES CON CÓDIGO PROF- -->
    <article class="tarjeta-tutorial" id="profesor">
        <div class="tarjeta-tutorial__cuerpo">
            <div class="tarjeta-tutorial__titulo">
                <h2>2. Profesores que ya tenían un código PROF-</h2>
                <span class="badge-rol badge-rol--profesor">Profesor</span>
            </div>
            <ol class="pasos-lista">
                <li>Entrá a <a href="login.php"><strong>Ingresar</strong></a> y escribí tu código <code>PROF-…</code> en el campo del DNI o correo.</li>
                <li>El sistema te lleva a elegir tu <strong>contraseña</strong>.</li>
                <li>Desde ese momento ingresás con tu DNI o correo y esa contraseña: el código <code>PROF-</code> deja de servir.</li>
            </ol>
        </div>
    </article>

    <!-- GUÍA 3: RECUPERAR -->
    <article class="tarjeta-tutorial" id="recuperar">
        <div class="tarjeta-tutorial__cuerpo">
            <div class="tarjeta-tutorial__titulo">
                <h2>3. Si olvidaste tu contraseña</h2>
                <span class="badge-rol badge-rol--recuperar">Recuperación</span>
            </div>
            <ol class="pasos-lista">
                <li>Entrá a <a href="recuperar.php"><strong>Recuperar cuenta</strong></a> y escribí tu DNI o correo. También podés pedirlo en persona.</li>
                <li>Tu <strong>preceptor</strong> (alumnos) o un <strong>directivo</strong> verifica que seas vos y te da un <strong>código de recuperación</strong>.</li>
                <li>Con ese código, en <a href="activar.php"><strong>Activar cuenta</strong></a>, elegí una contraseña nueva.</li>
            </ol>
        </div>
    </article>

    <div class="ayuda-acciones">
        <a href="activar.php" class="boton">Tengo un código →</a>
        <a href="login.php" class="boton boton--secundario">Ingresar al campus →</a>
        <a href="index.php" class="boton boton--gris">← Volver al inicio</a>
    </div>

</main>

</body>
</html>
