<?php
/* =====================================================================
   ARCHIVO: dashboard.php  (PANEL DE CONTROL)
   PARA QUÉ SIRVE: página PRIVADA. Muestra "cuadros" (módulos) distintos
   según el ROL guardado en $_SESSION.

   REGLAS DE ACCESO:
     - Directivo : Reservar proyector + Gestión de mesa de programa de examen
                   + Control de programas de examen (ProgMesas)
     - Profesor  : Reservar proyector + Subir los programas de examen
     - Alumno    : Preinscripción a mesa + Programa de examen (sin proyector)
   ===================================================================== */
require_once 'includes/sesion.php';

// PASO 1: si no hay sesión, requerirLogin() redirige a login.php
requerirLogin();

// PASO 2: leemos los datos guardados al iniciar sesión
$rol    = $_SESSION['rol'];     // 'alumno', 'profesor' o 'directivo'
$nombre = $_SESSION['nombre'];  // Nombre para saludar

// Texto amigable para mostrar el rol (el operador ?? usa un valor por defecto)
$etiquetas = ['alumno' => 'Alumno/a', 'profesor' => 'Profesor/a', 'directivo' => 'Directivo/a'];
$etiquetaRol = $etiquetas[$rol] ?? 'Usuario';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi panel — Campus Virtual</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="fondo-panel">

<!-- =================== BARRA SUPERIOR =================== -->
<header class="barra">
    <div class="contenedor barra__interior">
        <!-- AQUÍ VA EL LOGO DE LA ESCUELA (mismo archivo: img/logo.png) -->
        <a href="dashboard.php" class="barra__logo">
            <img src="img/logo.png" alt="Logo de la escuela" class="logo" onerror="this.style.display='none'">
            Campus Virtual
        </a>
        <nav class="barra__menu">
            <!-- Mostramos nombre y rol (con e() por seguridad) -->
            <span class="barra__usuario"><?php echo e($nombre); ?> · <?php echo e($etiquetaRol); ?></span>
            <a href="logout.php" class="boton boton--chico boton--borde">Cerrar sesión</a>
        </nav>
    </div>
</header>


<main class="contenedor panel">
    <h1>¡Hola, <?php echo e($nombre); ?>!</h1>
    <p class="seccion__intro">Estos son los módulos disponibles para usted:</p>

    <div class="modulos">

        <?php
        /* =============================================================
           MÓDULO: RESERVAR PROYECTOR
           Solo DIRECTIVOS y PROFESORES. El alumno NO lo ve.
           ============================================================= */
        if ($rol === 'directivo' || $rol === 'profesor') {
        ?>
            <a class="modulo" href="../proyectores/reserva_proyectores.php">
                <h2>Reservar proyector</h2>
                <p>Consulte disponibilidad y reserve proyectores para sus clases.</p>
            </a>
        <?php
        }
        ?>

        <?php
        /* =============================================================
           MÓDULOS DE MESAS Y PROGRAMAS DE EXAMEN
           - Directivo: Gestión de mesa de programa de examen
           - Alumno: Preinscripción a mesa
           (El profesor NO tiene preinscripción)
           ============================================================= */
        if ($rol === 'directivo') {
        ?>
            <a class="modulo" href="../preinscripciones/administrativo.php">
                <h2>Gestión de mesa de programa de examen</h2>
                <p>Administración y gestión de mesas de exámenes y programas.</p>
            </a>
        <?php
        } elseif ($rol === 'alumno') {
        ?>
            <a class="modulo" href="../preinscripciones/alumnos.php">
                <h2>Preinscripción a mesa</h2>
                <p>Anótese en las mesas de exámenes.</p>
            </a>
        <?php } ?>

        <?php
        /* =============================================================
           MÓDULO: PROGRAMAS DE EXAMEN (ProgMesas, ver programas.php)
           - Directivo: Control de programas de examen
           - Profesor: Subir los programas de examen
           - Alumno: Programa de examen
           ============================================================= */
        if ($rol === 'directivo') {
        ?>
            <a class="modulo" href="programas.php">
                <h2>Control de programas de examen</h2>
                <p>Vea qué programas faltan por curso y por docente, fije la fecha límite y revise el registro.</p>
            </a>
        <?php
        } elseif ($rol === 'profesor') {
        ?>
            <a class="modulo" href="programas.php">
                <h2>Subir los programas de examen</h2>
                <p>Cargue y actualice los programas de examen de sus asignaturas.</p>
            </a>
        <?php
        } elseif ($rol === 'alumno') {
        ?>
            <a class="modulo" href="programas.php">
                <h2>Programa de examen</h2>
                <p>Descargue el programa de examen de cada materia.</p>
            </a>
        <?php } ?>

    </div>
</main>

</body>
</html>
