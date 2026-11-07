<?php
/* =====================================================================
   ARCHIVO: restablecer.php
   Las contraseñas ya no se restablecen acá: el directivo genera un código
   de recuperación desde la gestión de cuentas (cuentas.php) y la persona
   elige su contraseña nueva en activar.php. Se mantiene para no romper
   enlaces viejos.
   ===================================================================== */
header('Location: cuentas.php');
exit;
