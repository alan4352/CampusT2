<?php
/* =====================================================================
   ARCHIVO: includes/usuarios.php
   PARA QUÉ SIRVE: funciones para leer y guardar alumnos y profesores
   en la base de datos MySQL, y para enviar los correos con
   contraseñas/códigos.

   Todas las consultas usan "consultas preparadas" (?): los datos del
   usuario nunca se pegan dentro del SQL, lo que evita la inyección SQL.

   NOTA: los DIRECTIVOS no están en la base: se definen solo en
   includes/datos.php, porque NO pueden registrarse por su cuenta.
   ===================================================================== */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';


/* ---------------------------------------------------------------------
   ALUMNOS
   --------------------------------------------------------------------- */

// Devuelve el alumno con ese correo (array) o false si no existe.
function buscarAlumnoPorCorreo($correo) {
    $st = conectarBD()->prepare('SELECT nombre, correo, dni, clave_hash FROM alumnos WHERE correo = ? LIMIT 1');
    $st->execute([strtolower(trim($correo))]);
    return $st->fetch();
}

// Devuelve el alumno con ese DNI (array) o false si no existe.
function buscarAlumnoPorDni($dni) {
    $dniLimpio = trim($dni);
    try {
        $pdo = conectarBD();
        $pdo->exec("CREATE TABLE IF NOT EXISTS alumnos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nombre VARCHAR(100) NOT NULL,
            correo VARCHAR(150) NOT NULL,
            dni VARCHAR(20) NOT NULL UNIQUE,
            clave_hash VARCHAR(255) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $st = $pdo->prepare('SELECT nombre, correo, dni, clave_hash FROM alumnos WHERE dni = ? LIMIT 1');
        $st->execute([$dniLimpio]);
        $fila = $st->fetch();
        if ($fila !== false) {
            return $fila;
        }
    } catch (PDOException $e) {
        // Fallback sin MySQL: buscar en $ALUMNOS de includes/datos.php
    }
    global $ALUMNOS;
    if (isset($ALUMNOS) && is_array($ALUMNOS)) {
        foreach ($ALUMNOS as $al) {
            if (($al['dni'] ?? '') === $dniLimpio) {
                return $al;
            }
        }
    }
    return false;
}

// true si ya hay un alumno con ese correo O ese DNI.
function existeAlumno($correo, $dni) {
    $st = conectarBD()->prepare('SELECT 1 FROM alumnos WHERE correo = ? OR dni = ? LIMIT 1');
    $st->execute([$correo, $dni]);
    return $st->fetchColumn() !== false;
}

// Inserta un alumno nuevo. La clave ya debe venir cifrada (password_hash).
function crearAlumno($nombre, $correo, $dni, $claveHash) {
    $st = conectarBD()->prepare('INSERT INTO alumnos (nombre, correo, dni, clave_hash) VALUES (?, ?, ?, ?)');
    $st->execute([$nombre, $correo, $dni, $claveHash]);
}

// Actualiza la contraseña cifrada de un alumno identificado por su DNI.
function actualizarClaveAlumno($dni, $claveHash) {
    $st = conectarBD()->prepare('UPDATE alumnos SET clave_hash = ? WHERE dni = ?');
    return $st->execute([$claveHash, trim($dni)]);
}


/* ---------------------------------------------------------------------
   PROFESORES
   --------------------------------------------------------------------- */

// Devuelve todos los profesores (el login compara el código contra cada hash).
function listarProfesores() {
    return conectarBD()->query('SELECT nombre, correo, codigo_hash FROM profesores')->fetchAll();
}

// true si ya hay un profesor con ese correo.
function existeProfesor($correo) {
    $st = conectarBD()->prepare('SELECT 1 FROM profesores WHERE correo = ? LIMIT 1');
    $st->execute([$correo]);
    return $st->fetchColumn() !== false;
}

// Inserta un profesor nuevo. El código ya debe venir cifrado (password_hash).
function crearProfesor($nombre, $correo, $codigoHash) {
    $st = conectarBD()->prepare('INSERT INTO profesores (nombre, correo, codigo_hash) VALUES (?, ?, ?)');
    $st->execute([$nombre, $correo, $codigoHash]);
}


/* ---------------------------------------------------------------------
   CORREO
   --------------------------------------------------------------------- */

// Carpeta data/ (solo se usa para el archivo de correos simulados).
function asegurarCarpetaData() {
    $carpeta = dirname(ARCHIVO_CORREOS);
    if (!is_dir($carpeta)) {
        mkdir($carpeta, 0755, true);
    }
}

// Envía un correo (o lo simula, según MODO_PRUEBA en config.php).
function enviarCorreo($para, $asunto, $mensaje) {

    // ---- MODO PRUEBA: no se envía nada, se anota en un archivo de texto ----
    if (MODO_PRUEBA) {
        asegurarCarpetaData();
        $registro = '[' . date('Y-m-d H:i:s') . '] PARA: ' . $para
                  . ' | ASUNTO: ' . $asunto . "\n" . $mensaje . "\n----------\n";
        return file_put_contents(ARCHIVO_CORREOS, $registro, FILE_APPEND | LOCK_EX) !== false;
    }

    // ---- MODO REAL: envío con mail() ----
    // AQUÍ SE PUEDE CAMBIAR mail() POR PHPMailer (con SMTP) para mayor confiabilidad
    $cabeceras  = 'From: ' . CORREO_REMITENTE . "\r\n";
    $cabeceras .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $asuntoCodificado = '=?UTF-8?B?' . base64_encode($asunto) . '?=';
    return mail($para, $asuntoCodificado, $mensaje, $cabeceras);
}
