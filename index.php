<?php
// Archivo de entrada principal - Escuela Técnica N° 2 "Independencia"
// Detecta la sesión activa del Campus Virtual y redirige de forma automática:
// - Directivos: Panel administrativo de Mesas de Examen
// - Alumnos: Formulario de Preinscripción a Mesas
// - Profesores / Otros: Panel del Campus Virtual
// - Sin sesión: Portal institucional del Campus Virtual

if (is_file(__DIR__ . '/campus/includes/sesion.php')) {
    require_once __DIR__ . '/campus/includes/sesion.php';
}

if (function_exists('estaLogueado') && estaLogueado()) {
    $rol = (string) ($_SESSION['rol'] ?? '');
    if ($rol === 'directivo') {
        header('Location: preinscripciones/administrativo.php');
        exit;
    }
    if ($rol === 'alumno') {
        header('Location: preinscripciones/alumnos.php');
        exit;
    }
    header('Location: campus/dashboard.php');
    exit;
}

header('Location: campus/index.php');
exit;
