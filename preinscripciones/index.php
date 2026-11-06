<?php
// Índice del módulo de Preinscripciones
require_once __DIR__ . '/conexion.php';

if (estaLogueado()) {
    $rol = (string) ($_SESSION['rol'] ?? '');
    if ($rol === 'directivo') {
        header('Location: administrativo.php');
        exit;
    }
    if ($rol === 'alumno') {
        header('Location: alumnos.php');
        exit;
    }
}

header('Location: ' . URL_CAMPUS);
exit;
