<?php
/* =====================================================================
   ARCHIVO: dashboard.php  (PANEL DE CONTROL)
   PARA QUÉ SIRVE: página PRIVADA. Muestra los módulos que corresponden
   a los ROLES de la persona (puede tener más de uno, por ejemplo
   profesor y preceptor).

   REGLAS DE ACCESO:
     - Directivo : proyectores, gestión de mesas, control de programas
                   y gestión de cuentas
     - Profesor  : proyectores y subir programas de examen
     - Preceptor : alumnos y cuentas de sus cursos, consulta de programas
     - Alumno    : preinscripción a mesa y programas de examen
   ===================================================================== */
require_once 'includes/sesion.php';
require_once 'includes/cuentas.php';   // etiquetas de los roles

requerirLogin();

$rol    = $_SESSION['rol'];     // rol principal (el que usan los módulos)
$nombre = $_SESSION['nombre'];
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
        <a href="dashboard.php" class="barra__logo">
            <img src="img/logo.png" alt="Logo de la escuela" class="logo" onerror="this.style.display='none'">
            Campus Virtual
        </a>
        <nav class="barra__menu">
            <span class="barra__usuario"><?php echo e($nombre); ?> · <?php echo e(etiquetaRoles(rolesSesion())); ?></span>
            <a href="logout.php" class="boton boton--chico boton--borde">Cerrar sesión</a>
        </nav>
    </div>
</header>


<main class="contenedor panel">
    <h1>¡Hola, <?php echo e($nombre); ?>!</h1>
    <p class="seccion__intro">Estos son los módulos disponibles para usted:</p>

    <div class="modulos">

        <?php if (tieneRol('directivo') || tieneRol('preceptor')): ?>
            <a class="modulo" href="hoy.php">
                <h2><?php echo tieneRol('directivo') ? 'Hoy y pendientes' : 'Hoy en mis cursos'; ?></h2>
                <p><?php echo tieneRol('directivo')
                    ? 'Lo que falta resolver para que el campus funcione y cómo va la asistencia de hoy en cada curso.'
                    : 'Asistencia de hoy, alertas y pedidos de cada uno de sus cursos, con un botón para cada cosa.'; ?></p>
            </a>
        <?php endif; ?>

        <?php
        /* Asistencias: el texto depende de lo que puede hacer cada rol */
        if (tienePermiso('asistencia')):
            if (tieneRol('profesor') || tieneRol('preceptor')) {
                [$tA, $dA, $uA] = ['Asistencias', 'Tome la asistencia del día, justifique faltas y revise los reportes.', 'asistencia.php?ver=tomar'];
            } elseif (tieneRol('directivo')) {
                [$tA, $dA, $uA] = ['Asistencias', 'Reportes de asistencia de todos los cursos y justificación de faltas.', 'asistencia.php?ver=reportes'];
            } else {
                [$tA, $dA, $uA] = ['Mis asistencias', 'Sus asistencias por día y por materia.', 'asistencia.php?ver=reportes'];
            }
            if (!tieneRol('preceptor') && !tieneRol('directivo') && tieneRol('profesor')) {
                $dA = 'Tome la asistencia del día en sus materias y revise los reportes.';
            }
        ?>
            <a class="modulo" href="<?php echo $uA; ?>">
                <h2><?php echo e($tA); ?></h2>
                <p><?php echo e($dA); ?></p>
            </a>
        <?php endif; ?>

        <?php if (tienePermiso('cuentas')): ?>
            <a class="modulo" href="cuentas.php">
                <h2>Gestión de cuentas</h2>
                <p>Cree las cuentas del personal, asigne cursos a los preceptores, genere códigos de acceso y atienda los pedidos de recuperación.</p>
            </a>
        <?php endif; ?>

        <?php if (tienePermiso('estructura')): ?>
            <a class="modulo" href="estructura.php">
                <h2>Estructura académica</h2>
                <p>Materias de cada curso y sus docentes. Impórtelas de ProgMesas y vincule a cada docente con su cuenta.</p>
            </a>
        <?php endif; ?>

        <?php if (tienePermiso('materias')): ?>
            <a class="modulo" href="materias.php">
                <h2><?php echo tieneRol('profesor') ? 'Mis materias' : 'Materias de mis cursos'; ?></h2>
                <p><?php echo tieneRol('profesor') ? 'Los cursos y materias que dicta.' : 'Qué materias tiene cada uno de sus cursos y quién las dicta.'; ?></p>
            </a>
        <?php endif; ?>

        <?php if (tienePermiso('padron')): ?>
            <a class="modulo" href="preceptor.php">
                <h2>Alumnos y cuentas</h2>
                <p>Cargue a los alumnos de sus cursos, entrégueles el código de primer ingreso y ayúdelos a recuperar su cuenta.</p>
            </a>
        <?php endif; ?>

        <?php if (tienePermiso('proyector')): ?>
            <a class="modulo" href="../proyectores/reserva_proyectores.php">
                <h2>Reservar proyector</h2>
                <p>Consulte disponibilidad y reserve proyectores para sus clases.</p>
            </a>
        <?php endif; ?>

        <?php if (tieneRol('directivo')): ?>
            <a class="modulo" href="../preinscripciones/administrativo.php">
                <h2>Gestión de mesa de programa de examen</h2>
                <p>Administración y gestión de mesas de exámenes y programas.</p>
            </a>
        <?php elseif (tieneRol('alumno')): ?>
            <a class="modulo" href="../preinscripciones/alumnos.php">
                <h2>Preinscripción a mesa</h2>
                <p>Anótese en las mesas de exámenes.</p>
            </a>
        <?php endif; ?>

        <?php
        /* ProgMesas recibe el rol principal: se muestra una sola tarjeta, la de ese rol */
        $programas = [
            'directivo' => ['Control de programas de examen', 'Vea qué programas faltan por curso y por docente, fije la fecha límite y revise el registro.'],
            'profesor'  => ['Subir los programas de examen', 'Cargue y actualice los programas de examen de sus asignaturas.'],
            'preceptor' => ['Programas de examen', 'Consulte qué programas están cargados en cada curso.'],
            'alumno'    => ['Programa de examen', 'Descargue el programa de examen de cada materia.'],
        ];
        if (tienePermiso('programa') && isset($programas[$rol])):
        ?>
            <a class="modulo" href="programas.php">
                <h2><?php echo e($programas[$rol][0]); ?></h2>
                <p><?php echo e($programas[$rol][1]); ?></p>
            </a>
        <?php endif; ?>

    </div>
</main>

</body>
</html>
