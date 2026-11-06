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
   TABLAS Y ESTRUCTURA AUTOMÁTICA
   --------------------------------------------------------------------- */

function asegurarTablasUsuarios(PDO $pdo) {
    static $aseguradas = false;
    if ($aseguradas) {
        return;
    }
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
        dni VARCHAR(20) NULL,
        correo VARCHAR(150) NOT NULL UNIQUE,
        codigo_hash VARCHAR(255) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Verificar si la columna dni ya existe en profesores (en caso de que la tabla ya estuviera creada)
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM profesores LIKE 'dni'")->fetch();
        if (!$cols) {
            $pdo->exec("ALTER TABLE profesores ADD COLUMN dni VARCHAR(20) NULL AFTER nombre");
        }
    } catch (Throwable $e) {}

    $aseguradas = true;
}


/* ---------------------------------------------------------------------
   ALUMNOS
   --------------------------------------------------------------------- */

// Devuelve el alumno con ese correo (array con id, nombre, correo, dni, clave_hash) o false si no existe.
function buscarAlumnoPorCorreo($correo) {
    $correoLimpio = strtolower(trim($correo));
    try {
        $pdo = conectarBD();
        asegurarTablasUsuarios($pdo);
        $st = $pdo->prepare('SELECT id, nombre, correo, dni, clave_hash FROM alumnos WHERE correo = ? LIMIT 1');
        $st->execute([$correoLimpio]);
        $fila = $st->fetch();
        return $fila;   // con base disponible, solo cuenta lo que está en la base
    } catch (PDOException $e) {
        // Sin base de datos: se usan los usuarios de prueba de datos.php
    }
    global $ALUMNOS;
    if (isset($ALUMNOS) && is_array($ALUMNOS)) {
        foreach ($ALUMNOS as $idx => $al) {
            if (strtolower(trim($al['correo'] ?? '')) === $correoLimpio) {
                $al['id'] = $idx + 1;
                return $al;
            }
        }
    }
    return false;
}

// Devuelve el alumno con ese DNI (array con id, nombre, correo, dni, clave_hash) o false si no existe.
function buscarAlumnoPorDni($dni) {
    $dniLimpio = trim($dni);
    try {
        $pdo = conectarBD();
        asegurarTablasUsuarios($pdo);
        $st = $pdo->prepare('SELECT id, nombre, correo, dni, clave_hash FROM alumnos WHERE dni = ? LIMIT 1');
        $st->execute([$dniLimpio]);
        $fila = $st->fetch();
        return $fila;   // con base disponible, solo cuenta lo que está en la base
    } catch (PDOException $e) {
        // Sin base de datos: se usan los usuarios de prueba de datos.php
    }
    global $ALUMNOS;
    if (isset($ALUMNOS) && is_array($ALUMNOS)) {
        foreach ($ALUMNOS as $idx => $al) {
            if (($al['dni'] ?? '') === $dniLimpio) {
                $al['id'] = $idx + 1;
                return $al;
            }
        }
    }
    return false;
}

// Devuelve el alumno por su ID numérico auto-incrementable único en la base de datos
function buscarAlumnoPorId($id) {
    $idNum = (int)$id;
    if ($idNum <= 0) {
        return false;
    }
    try {
        $pdo = conectarBD();
        asegurarTablasUsuarios($pdo);
        $st = $pdo->prepare('SELECT id, nombre, correo, dni, clave_hash FROM alumnos WHERE id = ? LIMIT 1');
        $st->execute([$idNum]);
        $fila = $st->fetch();
        return $fila;   // con base disponible, solo cuenta lo que está en la base
    } catch (PDOException $e) {
        // Sin base de datos: se usan los usuarios de prueba de datos.php
    }
    global $ALUMNOS;
    if (isset($ALUMNOS) && is_array($ALUMNOS) && isset($ALUMNOS[$idNum - 1])) {
        $al = $ALUMNOS[$idNum - 1];
        $al['id'] = $idNum;
        return $al;
    }
    return false;
}

// true si ya hay un alumno con ese correo O ese DNI.
function existeAlumno($correo, $dni) {
    $pdo = conectarBD();
    asegurarTablasUsuarios($pdo);
    $st = $pdo->prepare('SELECT 1 FROM alumnos WHERE correo = ? OR dni = ? LIMIT 1');
    $st->execute([strtolower(trim($correo)), trim($dni)]);
    return $st->fetchColumn() !== false;
}

// Inserta un alumno nuevo. Devuelve el ID auto-generado único de la fila insertada.
function crearAlumno($nombre, $correo, $dni, $claveHash) {
    $pdo = conectarBD();
    asegurarTablasUsuarios($pdo);
    $st = $pdo->prepare('INSERT INTO alumnos (nombre, correo, dni, clave_hash) VALUES (?, ?, ?, ?)');
    $st->execute([trim($nombre), strtolower(trim($correo)), trim($dni), $claveHash]);
    return (int) $pdo->lastInsertId();
}

// Actualiza la contraseña cifrada de un alumno identificado por su DNI.
function actualizarClaveAlumno($dni, $claveHash) {
    $pdo = conectarBD();
    asegurarTablasUsuarios($pdo);
    $st = $pdo->prepare('UPDATE alumnos SET clave_hash = ? WHERE dni = ?');
    return $st->execute([$claveHash, trim($dni)]);
}


/* ---------------------------------------------------------------------
   PROFESORES
   --------------------------------------------------------------------- */

// Devuelve todos los profesores con id, nombre, dni, correo, codigo_hash
function listarProfesores() {
    try {
        $pdo = conectarBD();
        asegurarTablasUsuarios($pdo);
        return $pdo->query('SELECT id, nombre, dni, correo, codigo_hash FROM profesores ORDER BY id ASC')->fetchAll();
    } catch (PDOException $e) {
    }
    global $PROFESORES;
    if (isset($PROFESORES) && is_array($PROFESORES)) {
        $lista = [];
        foreach ($PROFESORES as $idx => $p) {
            $p['id'] = $idx + 1;
            $lista[] = $p;
        }
        return $lista;
    }
    return [];
}

// Devuelve el profesor por su ID único auto-incrementable
function buscarProfesorPorId($id) {
    $idNum = (int)$id;
    if ($idNum <= 0) {
        return false;
    }
    try {
        $pdo = conectarBD();
        asegurarTablasUsuarios($pdo);
        $st = $pdo->prepare('SELECT id, nombre, dni, correo, codigo_hash FROM profesores WHERE id = ? LIMIT 1');
        $st->execute([$idNum]);
        $fila = $st->fetch();
        return $fila;   // con base disponible, solo cuenta lo que está en la base
    } catch (PDOException $e) {
        // Sin base de datos: se usan los usuarios de prueba de datos.php
    }
    return false;
}

// Devuelve el profesor por correo
function buscarProfesorPorCorreo($correo) {
    $correoLimpio = strtolower(trim($correo));
    try {
        $pdo = conectarBD();
        asegurarTablasUsuarios($pdo);
        $st = $pdo->prepare('SELECT id, nombre, dni, correo, codigo_hash FROM profesores WHERE correo = ? LIMIT 1');
        $st->execute([$correoLimpio]);
        $fila = $st->fetch();
        return $fila;   // con base disponible, solo cuenta lo que está en la base
    } catch (PDOException $e) {
        // Sin base de datos: se usan los usuarios de prueba de datos.php
    }
    return false;
}

// Devuelve el profesor por DNI
function buscarProfesorPorDni($dni) {
    $dniLimpio = preg_replace('/\D/', '', $dni);
    if ($dniLimpio === '') return false;
    try {
        $pdo = conectarBD();
        asegurarTablasUsuarios($pdo);
        $st = $pdo->prepare('SELECT id, nombre, dni, correo, codigo_hash FROM profesores WHERE dni = ? LIMIT 1');
        $st->execute([$dniLimpio]);
        $fila = $st->fetch();
        return $fila;   // con base disponible, solo cuenta lo que está en la base
    } catch (PDOException $e) {
        // Sin base de datos: se usan los usuarios de prueba de datos.php
    }
    return false;
}

// true si ya hay un profesor con ese correo.
function existeProfesor($correo) {
    $pdo = conectarBD();
    asegurarTablasUsuarios($pdo);
    $st = $pdo->prepare('SELECT 1 FROM profesores WHERE correo = ? LIMIT 1');
    $st->execute([strtolower(trim($correo))]);
    return $st->fetchColumn() !== false;
}

// true si ya hay un profesor con ese DNI.
function existeProfesorPorDni($dni) {
    $dniLimpio = preg_replace('/\D/', '', $dni);
    if ($dniLimpio === '') return false;
    $pdo = conectarBD();
    asegurarTablasUsuarios($pdo);
    $st = $pdo->prepare('SELECT 1 FROM profesores WHERE dni = ? LIMIT 1');
    $st->execute([$dniLimpio]);
    return $st->fetchColumn() !== false;
}

// Inserta un profesor nuevo y devuelve su ID numérico auto-generado único.
function crearProfesor($nombre, $correo, $codigoHash, $dni = null) {
    $pdo = conectarBD();
    asegurarTablasUsuarios($pdo);
    $dniLimpio = $dni ? preg_replace('/\D/', '', $dni) : null;
    $st = $pdo->prepare('INSERT INTO profesores (nombre, dni, correo, codigo_hash) VALUES (?, ?, ?, ?)');
    $st->execute([trim($nombre), $dniLimpio, strtolower(trim($correo)), $codigoHash]);
    return (int) $pdo->lastInsertId();
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
