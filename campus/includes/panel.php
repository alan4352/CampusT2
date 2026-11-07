<?php
/* =====================================================================
   ARCHIVO: includes/panel.php
   PARA QUÉ SIRVE: piezas comunes de los paneles de gestión
   (cuentas.php y preceptor.php): encabezado, mensajes después de una
   acción y la tabla de códigos para imprimir y repartir.
   ===================================================================== */

/** Mensaje para mostrar después de redirigir (patrón POST → redirección → GET). */
function avisar(string $tipo, string $mensaje): void {
    $_SESSION['avisos'][] = [$tipo === 'error' ? 'error' : 'exito', $mensaje];
}

function redirigir(string $url): void {
    header('Location: ' . $url);
    exit;
}

/** Guarda códigos recién generados para mostrarlos UNA vez (no quedan en ningún otro lado). */
function guardarCodigosParaMostrar(array $codigos): void {
    $_SESSION['codigos_nuevos'] = $codigos;
}

function encabezadoPanel(string $titulo): void {
    $nombre = $_SESSION['nombre'] ?? '';
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($titulo); ?> — Campus Virtual</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="gestion.css">
</head>
<body class="fondo-panel">
<header class="barra no-imprimir">
    <div class="contenedor barra__interior">
        <a href="dashboard.php" class="barra__logo">
            <img src="img/logo.png" alt="Logo de la escuela" class="logo" onerror="this.style.display='none'">
            Campus Virtual
        </a>
        <nav class="barra__menu">
            <span class="barra__usuario"><?php echo e($nombre); ?> · <?php echo e(etiquetaRoles(rolesSesion())); ?></span>
            <a href="dashboard.php" class="boton boton--chico">Mi panel</a>
            <a href="logout.php" class="boton boton--chico boton--borde">Cerrar sesión</a>
        </nav>
    </div>
</header>
<main class="contenedor panel gestion">
    <h1 class="no-imprimir"><?php echo e($titulo); ?></h1>
    <?php
    foreach ($_SESSION['avisos'] ?? [] as [$tipo, $mensaje]) {
        echo '<div class="' . $tipo . ' no-imprimir">' . e($mensaje) . '</div>';
    }
    unset($_SESSION['avisos']);

    $codigos = $_SESSION['codigos_nuevos'] ?? [];
    unset($_SESSION['codigos_nuevos']);
    if ($codigos) {
        tablaCodigos($codigos);
    }
}

function piePanel(): void {
    echo "</main>\n</body>\n</html>\n";
}

/** Códigos recién generados, listos para imprimir y recortar. */
function tablaCodigos(array $codigos): void {
    ?>
    <section class="caja caja--codigos">
        <div class="caja__titulo no-imprimir">
            <h2>Códigos generados (<?php echo count($codigos); ?>)</h2>
            <button type="button" class="boton boton--chico" onclick="window.print()">Imprimir</button>
        </div>
        <p class="no-imprimir aviso-codigos">Anótelos o imprímalos ahora: <strong>no se vuelven a mostrar</strong>.
            Cada uno sirve una sola vez. Entréguelos en persona.</p>
        <div class="codigos">
            <?php foreach ($codigos as $c): ?>
                <div class="codigo-tarjeta">
                    <div class="codigo-tarjeta__escuela">E.E.T. N.º 2 · Campus Virtual</div>
                    <div class="codigo-tarjeta__nombre"><?php echo e($c['nombre']); ?></div>
                    <?php if (!empty($c['dni'])): ?><div>DNI <?php echo e($c['dni']); ?></div><?php endif; ?>
                    <div class="codigo-tarjeta__codigo"><?php echo e($c['codigo']); ?></div>
                    <div class="codigo-tarjeta__pie">
                        <?php echo $c['tipo'] === 'alta' ? 'Primer ingreso' : 'Recuperar cuenta'; ?>
                        · vence <?php echo e(date('d/m/Y H:i', strtotime($c['vence']))); ?><br>
                        Ingrese en el campus → «Activar cuenta» y elija su contraseña.
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php
}

/** Texto y clase del estado de una cuenta (para las tablas). */
function estadoCuenta(array $u): array {
    if ($u['estado'] === 'bloqueado') {
        return ['Suspendida', 'estado--bloqueado'];
    }
    if ($u['estado'] === 'activo') {
        return ['Activa', 'estado--activo'];
    }
    return [!empty($u['codigo_vence']) ? 'Código entregado' : 'Sin activar', 'estado--pendiente'];
}
