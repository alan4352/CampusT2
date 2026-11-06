<?php
// Crea las tablas del campus que el código usa pero no crea solo. Se corre al arrancar el contenedor.
// Las de preinscripciones, proyectores y ProgMesas se crean solas al usarse.
require __DIR__ . '/../campus/includes/db.php';

$pdo = conectarBD();
$pdo->exec("CREATE TABLE IF NOT EXISTS alumnos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    correo VARCHAR(150) NOT NULL,
    dni VARCHAR(20) NOT NULL UNIQUE,
    clave_hash VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS profesores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    correo VARCHAR(150) NOT NULL UNIQUE,
    codigo_hash VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
echo "[Campus] Tablas listas\n";
