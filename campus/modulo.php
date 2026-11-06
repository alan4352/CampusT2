<?php
/* =====================================================================
   ARCHIVO: modulo.php
   PARA QUÉ SIRVE: página de ejemplo para cada módulo (se abre con
   modulo.php?m=proyector, ?m=mesa o ?m=programa).

   ¿POR QUÉ EXISTE? Ocultar un botón en el panel NO alcanza como
   seguridad: un alumno podría escribir la dirección a mano.
   Por eso VERIFICAMOS EL PERMISO TAMBIÉN AQUÍ, en el servidor.
   ===================================================================== */
require_once 'includes/sesion.php';
requerirLogin(); // Sin sesión → al login

// $_GET['m'] trae el módulo pedido en la dirección (?m=...)
$modulo = $_GET['m'] ?? '';
$rol    = $_SESSION['rol'] ?? '';

// Títulos de cada módulo personalizados según el rol.
$titulos = [
    'proyector'  => 'Reservar proyector',
    'mesa'       => ($rol === 'directivo') ? 'Gestión de mesa de programa de examen' : 'Preinscripción a mesa de exámenes',
    'programa'   => ($rol === 'profesor') ? 'Subir los programas de examen' : (($rol === 'directivo') ? 'Gestión de mesa de programa de examen' : 'Programa de examen'),
];

// Si el módulo solicitado es 'mesa' y tiene permiso, redirigir al sistema de Mesas según el rol
if ($modulo === 'mesa' && tienePermiso('mesa')) {
    if ($rol === 'directivo') {
        header('Location: ../preinscripciones/administrativo.php');
        exit;
    } elseif ($rol === 'alumno') {
        header('Location: ../preinscripciones/alumnos.php');
        exit;
    }
}

// Si el módulo solicitado es 'programa', se abre ProgMesas (programas.php controla el permiso)
if ($modulo === 'programa') {
    header('Location: programas.php');
    exit;
}

// Si el módulo solicitado es 'proyector' y tiene permiso, redirigir al sistema de Reservas de Proyectores
if ($modulo === 'proyector' && tienePermiso('proyector')) {
    header('Location: ../proyectores/reserva_proyectores.php');
    exit;
}

// AQUÍ SE CONTROLA EL ACCESO: si el módulo no existe o el rol no tiene
// permiso (ver tabla $PERMISOS en includes/sesion.php), se bloquea.
if (!isset($titulos[$modulo]) || !tienePermiso($modulo)) {
    http_response_code(403); // 403 = "Prohibido"
    $bloqueado = true;
} else {
    $bloqueado = false;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Módulo — Campus Virtual</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="fondo-panel">
<main class="contenedor panel">

<?php if ($bloqueado): ?>
    <h1>⛔ Acceso denegado</h1>
    <p>Su tipo de usuario no tiene permiso para ver este módulo.</p>
<?php else: ?>
    <h1><?php echo e($titulos[$modulo]); ?></h1>
    <!-- AQUÍ VA EL CONTENIDO REAL DEL MÓDULO (formularios, listados, descargas, etc.) -->
    <p>Este módulo está en construcción.</p>
<?php endif; ?>

    <p><a href="dashboard.php" class="boton boton--chico">← Volver al panel</a></p>
</main>
</body>
</html>
