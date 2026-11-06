<?php
/* =====================================================================
   ARCHIVO: includes/datos.php
   PARA QUÉ SIRVE: contiene los usuarios de EJEMPLO y los DIRECTIVOS.

   - ALUMNOS y PROFESORES: estos de ejemplo se copian a data/usuarios.json
     SOLO la primera vez. Después, los nuevos se agregan por registro.php.
     (Si quiere volver a empezar de cero, borre data/usuarios.json.)
   - DIRECTIVOS: se leen siempre de AQUÍ. No pueden registrarse solos;
     la escuela los crea editando este archivo.

   ⚠ Las contraseñas de ejemplo son SOLO PARA PRUEBAS. Cámbielas
   antes de publicar el sitio.
   ===================================================================== */


/* ---------------------------------------------------------------------
   1) ALUMNOS (ejemplo)
   Lógica real: al registrarse, el sistema GENERA una contraseña
   aleatoria y se la ENVÍA AL CORREO del alumno (ver registro.php).
   En la base se guarda solo el "hash" (versión cifrada) de esa clave.
   --------------------------------------------------------------------- */
$ALUMNOS = [
    [
        'nombre'     => 'Lucía Fernández',
        'correo'     => 'alumno@gmail.com',
        'dni'        => '40123456',
        'clave_hash' => password_hash('Alumno2026', PASSWORD_DEFAULT), // Contraseña de prueba: Alumno2026
    ],
];


/* ---------------------------------------------------------------------
   2) PROFESORES (ejemplo)
   Cada profesor tiene un CÓDIGO que le llega por correo al registrarse.
   Se guarda el hash del código, igual que una contraseña.
   --------------------------------------------------------------------- */
$PROFESORES = [
    [
        'nombre'      => 'Martín Gómez',
        'correo'      => 'profesor@escuela.edu.ar',
        'codigo_hash' => password_hash('PROF-A1B2C3', PASSWORD_DEFAULT), // Código de prueba: PROF-A1B2C3
    ],
];


/* ---------------------------------------------------------------------
   3) DIRECTIVOS (se definen SOLO aquí)
   Entran con correo + contraseña.
   AQUÍ SE AGREGAN O CAMBIAN DIRECTIVOS: copie un bloque entre llaves
   y cambie el correo, el nombre y la contraseña (dentro de password_hash).
   --------------------------------------------------------------------- */
$DIRECTIVOS = [
    [
        'nombre'     => 'Marta Rodríguez',
        'correo'     => 'directora@escuela.edu.ar',
        'clave_hash' => password_hash('Directivo2026', PASSWORD_DEFAULT), // Contraseña: Directivo2026
    ],
];
