<?php
/* =====================================================================
   ARCHIVO: includes/panel.php
   PARA QUÉ SIRVE: maqueta común de los paneles de gestión
   (cuentas.php, preceptor.php, estructura.php y materias.php), con el
   mismo estilo y estructura del sistema de asistencias: barra lateral con
   el escudo y el menú según los roles, encabezado bordó con la fecha y
   el contenido en tarjetas (Bootstrap 5.3 + panel-asistencia.css).
   También: mensajes después de una acción y códigos para imprimir.
   ===================================================================== */

/** Mensaje para mostrar después de redirigir (patrón POST → redirección → GET). */
function avisar(string $tipo, string $mensaje): void {
    $_SESSION['avisos'][] = [$tipo === 'error' ? 'error' : 'exito', $mensaje];
}

function redirigir(string $url): void {
    header('Location: ' . $url);
    exit;
}

/**
 * Curso con el que trabaja el preceptor. Se recuerda en la sesión: lo que elige en una
 * pantalla (planilla, justificar, reportes, alumnos) queda elegido en las demás.
 * $ids: cursos que puede usar; $pedido: el que viene en el enlace o el selector.
 */
function cursoActivo(array $ids, $pedido = null): int {
    $p = (int) $pedido;
    if ($p && in_array($p, $ids, true)) {
        $_SESSION['curso_activo'] = $p;
        return $p;
    }
    $s = (int) ($_SESSION['curso_activo'] ?? 0);
    return in_array($s, $ids, true) ? $s : (int) ($ids[0] ?? 0);
}

/** Guarda códigos recién generados para mostrarlos UNA vez (no quedan en ningún otro lado). */
function guardarCodigosParaMostrar(array $codigos): void {
    $_SESSION['codigos_nuevos'] = $codigos;
}

/**
 * Menú de la barra lateral según los roles de la sesión.
 * Cada entrada: [clave para marcarla activa, texto, ícono de Bootstrap Icons, enlace].
 */
function menuPanel(): array {
    $menu = [['inicio', 'Mi panel', 'bi-house', 'dashboard.php']];
    if (tieneRol('directivo') || tieneRol('preceptor')) {
        $menu[] = ['hoy', tieneRol('directivo') ? 'Hoy y pendientes' : 'Hoy', 'bi-sun', 'hoy.php'];
    }
    if (tienePermiso('asistencia')) {
        $menu[] = 'Asistencias';
        if (tieneRol('profesor') || tieneRol('preceptor')) {
            $menu[] = ['asistencia:tomar', 'Tomar asistencia', 'bi-clipboard-check', 'asistencia.php?ver=tomar'];
        }
        if (tieneRol('directivo') || tieneRol('preceptor')) {
            $menu[] = ['asistencia:justificar', 'Justificar faltas', 'bi-patch-check', 'asistencia.php?ver=justificar'];
        }
        $soloAlumno = rolesSesion() === ['alumno'];
        $menu[] = ['asistencia:reportes', $soloAlumno ? 'Mis asistencias' : 'Reportes', $soloAlumno ? 'bi-calendar-check' : 'bi-graph-up', 'asistencia.php?ver=reportes'];
    }
    if (tienePermiso('cuentas')) {
        $menu[] = 'Cuentas';
        $menu[] = ['cuentas:personal', 'Personal', 'bi-person-badge', 'cuentas.php?ver=personal'];
        $menu[] = ['cuentas:alumnos', 'Alumnos', 'bi-people', 'cuentas.php?ver=alumnos'];
        $menu[] = ['cuentas:preceptores', 'Preceptores por curso', 'bi-person-gear', 'cuentas.php?ver=preceptores'];
        $menu[] = ['cuentas:pedidos', 'Pedidos de recuperación', 'bi-key', 'cuentas.php?ver=pedidos'];
    }
    if (tienePermiso('estructura')) {
        $menu[] = 'Estructura académica';
        $menu[] = ['estructura:cursos', 'Materias por curso', 'bi-journal', 'estructura.php?ver=cursos'];
        $menu[] = ['estructura:docentes', 'Docentes', 'bi-person-check', 'estructura.php?ver=docentes'];
        $menu[] = ['estructura:importar', 'Importar de ProgMesas', 'bi-cloud-download', 'estructura.php?ver=importar'];
    }
    if (tienePermiso('padron')) {
        $menu[] = ['padron', 'Alumnos y cuentas', 'bi-key', 'preceptor.php'];
    }
    if (tienePermiso('materias')) {
        $menu[] = ['materias', tieneRol('profesor') ? 'Mis materias' : 'Materias de mis cursos', 'bi-journal-bookmark', 'materias.php'];
    }
    return $menu;
}

/**
 * Abre la página: barra lateral, encabezado y avisos.
 *   $activo    : clave del menú a resaltar (ver menuPanel)
 *   $contadores: números para mostrar junto a una entrada del menú, por clave
 *   $selectorCurso: muestra en el encabezado el curso activo del preceptor, para cambiarlo
 */
function encabezadoPanel(string $titulo, string $icono = 'bi-grid', string $subtitulo = '', string $activo = '', array $contadores = [], bool $selectorCurso = false): void {
    $cursosSel = [];
    if ($selectorCurso && tieneRol('preceptor') && function_exists('cursosDePreceptor')) {
        $cursosSel = cursosDePreceptor(bdCuentas(), (int) ($_SESSION['usuario_id'] ?? 0));
    }
    $nombre = $_SESSION['nombre'] ?? '';
    $rolesTxt = etiquetaRoles(rolesSesion());
    $etiquetaPanel = tieneRol('directivo') ? 'Panel Directivo' : (tieneRol('preceptor') ? 'Panel Preceptor' : (tieneRol('profesor') ? 'Panel Profesor' : 'Mi panel'));
    $iconoPanel = tieneRol('directivo') ? 'bi-speedometer2' : (tieneRol('preceptor') ? 'bi-person-gear' : 'bi-person-badge');
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#7a0000">
    <title><?php echo e($titulo); ?> — Campus Virtual</title>
    <link rel="icon" href="img/escudo.png" type="image/png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
          integrity="sha384-9ndCyUaIbzAi2FUVXJi0CjmCapSmO7SnpJef0486qhLnuZ2cdeRhO02iuK6FUUVM" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"
          integrity="sha384-l4UPAMHGzl7zwogLW4nOwaU2XTk6oiM1jhCRQstZEndoIiA2I5bg6fST3wzBSRBD" crossorigin="anonymous">
    <link rel="stylesheet" href="panel-asistencia.css?v=1">
</head>
<body>
<div class="container-fluid">
    <div class="row">

        <div class="col-12 d-md-none mobile-header">
            <div class="d-flex align-items-center">
                <img src="img/escudo.png" alt="Escudo" class="escudo-mobile">
                <span class="institution-name-mobile">E.E.T. N°2 "Independencia"</span>
                <button class="btn ms-auto" type="button" data-bs-toggle="offcanvas" data-bs-target="#menuLateral" aria-controls="menuLateral"
                        style="background-color: var(--color-secondary); color: var(--color-primary); border: 2px solid var(--color-secondary);">
                    <i class="bi bi-list"></i> Menú
                </button>
            </div>
        </div>

        <div class="col-md-3 col-lg-2 offcanvas-md offcanvas-start sidebar p-0" tabindex="-1" id="menuLateral" aria-labelledby="menuLateralTitulo">
            <div class="offcanvas-header d-md-none">
                <h5 class="offcanvas-title" id="menuLateralTitulo"><i class="bi <?php echo $iconoPanel; ?>"></i> <?php echo $etiquetaPanel; ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" data-bs-target="#menuLateral" aria-label="Cerrar"></button>
            </div>
            <div class="offcanvas-body p-0 d-md-block">
                <div class="sidebar-content">
                    <img src="img/escudo.png" alt="Escudo" class="escudo-sidebar">
                    <h4 class="institution-name">E.E.T. N°2<br>"Independencia"</h4>
                    <h4 class="text-center mb-4 d-none d-md-block"><i class="bi <?php echo $iconoPanel; ?>"></i> <?php echo $etiquetaPanel; ?></h4>
                    <div class="text-center mb-2">
                        <div class="panel-perfil"><i class="bi bi-person-fill"></i></div>
                        <div class="panel-info">
                            <strong><?php echo e($nombre); ?></strong>
                            <span class="d-block"><?php echo e($rolesTxt); ?></span>
                        </div>
                    </div>
                    <div class="nav-divider"></div>
                    <nav class="nav flex-column">
                        <?php foreach (menuPanel() as $item): ?>
                            <?php if (is_string($item)): ?>
                                <div class="nav-grupo"><?php echo e($item); ?></div>
                            <?php else: [$clave, $texto, $ico, $url] = $item; ?>
                                <a href="<?php echo e($url); ?>" class="nav-link <?php echo $clave === $activo ? 'active' : ''; ?>"<?php echo $clave === $activo ? ' aria-current="page"' : ''; ?>>
                                    <i class="bi <?php echo $ico; ?>"></i> <?php echo e($texto); ?>
                                    <?php if (!empty($contadores[$clave])): ?><span class="badge bg-warning"><?php echo (int) $contadores[$clave]; ?></span><?php endif; ?>
                                </a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <div class="nav-divider"></div>
                        <a href="logout.php" class="nav-link text-danger"><i class="bi bi-box-arrow-right"></i> Cerrar sesión</a>
                    </nav>
                </div>
            </div>
        </div>

        <div class="col-md-9 col-lg-10 p-0">
            <div class="panel-header no-imprimir">
                <div class="container-fluid px-4">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h2><i class="bi <?php echo e($icono); ?>"></i> <?php echo e($titulo); ?></h2>
                            <?php if ($subtitulo !== ''): ?><p class="mb-0"><?php echo e($subtitulo); ?></p><?php endif; ?>
                        </div>
                        <div class="col-md-4 text-md-end mt-2 mt-md-0">
                            <div class="bg-white bg-opacity-25 rounded-pill px-3 py-1 d-inline-block">
                                <i class="bi bi-calendar-check"></i> <?php echo date('d/m/Y'); ?>
                            </div>
                            <?php if (count($cursosSel) > 1): $actual = cursoActivo(array_map(fn($c) => (int) $c['id'], $cursosSel)); ?>
                                <!-- Curso activo: se recuerda al pasar de una pantalla a otra -->
                                <form method="get" class="selector-curso mt-2">
                                    <?php if (isset($_GET['ver'])): ?><input type="hidden" name="ver" value="<?php echo e((string) $_GET['ver']); ?>"><?php endif; ?>
                                    <label for="curso-activo" class="visually-hidden">Curso</label>
                                    <i class="bi bi-book"></i>
                                    <select id="curso-activo" name="curso" class="form-select form-select-sm" onchange="this.form.submit()">
                                        <?php foreach ($cursosSel as $c): ?>
                                            <option value="<?php echo (int) $c['id']; ?>" <?php echo (int) $c['id'] === $actual ? 'selected' : ''; ?>><?php echo e(nombreCurso($c)); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <noscript><button class="btn btn-light btn-sm">Cambiar</button></noscript>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="container-fluid p-4 contenido">
    <?php
    foreach ($_SESSION['avisos'] ?? [] as [$tipo, $mensaje]) {
        $error = $tipo === 'error';
        echo '<div class="alert ' . ($error ? 'alert-danger error' : 'alert-success exito') . ' alert-dismissible fade show no-imprimir" role="alert">'
           . '<i class="bi ' . ($error ? 'bi-exclamation-triangle' : 'bi-check-circle') . '"></i> ' . e($mensaje)
           . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button></div>';
    }
    unset($_SESSION['avisos']);

    $codigos = $_SESSION['codigos_nuevos'] ?? [];
    unset($_SESSION['codigos_nuevos']);
    if ($codigos) {
        tablaCodigos($codigos);
    }
}

function piePanel(): void {
    ?>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-geWF76RCwLtnZ8qwWowPQNguL3RmwHVBC9FhGdlKrxdiJJigb/j/68SIy3Te4Bkz" crossorigin="anonymous"></script>
</body>
</html>
    <?php
}

/** Abre una tarjeta con encabezado bordó. $derecha: HTML ya escapado para el costado derecho del encabezado. */
function tarjeta(string $titulo, string $icono, string $detalle = '', string $derecha = '', string $clase = ''): void {
    echo '<div class="card ' . e($clase) . '"><div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">'
       . '<h5><i class="bi ' . e($icono) . '"></i> ' . e($titulo)
       . ($detalle !== '' ? ' <small>' . e($detalle) . '</small>' : '') . '</h5>' . $derecha . '</div><div class="card-body">';
}

function finTarjeta(): void {
    echo '</div></div>';
}

/** Mensaje de "no hay nada para mostrar", como en el sistema de asistencias. $html ya escapado. */
function sinDatos(string $html): void {
    echo '<div class="alert alert-info mb-0"><i class="bi bi-info-circle"></i> ' . $html . '</div>';
}

/** Códigos recién generados, listos para imprimir y recortar. */
function tablaCodigos(array $codigos): void {
    $boton = '<button type="button" class="btn btn-light btn-sm no-imprimir" onclick="window.print()"><i class="bi bi-printer"></i> Imprimir</button>';
    tarjeta('Códigos generados', 'bi-key', '(' . count($codigos) . ')', $boton, 'card-codigos');
    ?>
        <div class="alert alert-warning no-imprimir"><i class="bi bi-exclamation-triangle"></i>
            Anótelos o imprímalos ahora: <strong>no se vuelven a mostrar</strong>. Cada uno sirve una sola vez. Entréguelos en persona.</div>
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
    <?php
    finTarjeta();
}

/** Texto y clase de etiqueta (badge) del estado de una cuenta. */
function estadoCuenta(array $u): array {
    if ($u['estado'] === 'bloqueado') {
        return ['Suspendida', 'bg-danger'];
    }
    if ($u['estado'] === 'activo') {
        return ['Activa', 'bg-success'];
    }
    return [!empty($u['codigo_vence']) ? 'Código entregado' : 'Sin activar', 'bg-warning'];
}
