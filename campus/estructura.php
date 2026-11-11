<?php
/* =====================================================================
   ARCHIVO: estructura.php  (solo DIRECTIVOS)
   PARA QUÉ SIRVE: estructura académica del campus.
     - Materias por curso: qué se dicta en cada curso y quién lo dicta.
     - Docentes: vincular a cada docente de la planilla de ProgMesas
       (figura solo por apellido) con su cuenta de profesor del campus.
     - Importar: traer materias y docentes de la planilla de ProgMesas,
       con vista previa de lo que cambia antes de confirmar.
   Toda acción va por POST con token CSRF y queda en el log del servidor.
   ===================================================================== */
require_once 'includes/sesion.php';
require_once 'includes/academico.php';
require_once 'includes/panel.php';

requerirPermiso('estructura');

$pdo = bdAcademico();
$yo  = (int) ($_SESSION['usuario_id'] ?? 0);

$secciones = ['cursos' => 'Materias por curso', 'docentes' => 'Docentes', 'importar' => 'Importar de ProgMesas'];
$ver    = array_key_exists($_GET['ver'] ?? '', $secciones) ? $_GET['ver'] : 'cursos';
$cursoF = (int) ($_GET['curso'] ?? 0);
$filtro = in_array($_GET['f'] ?? '', ['sin', 'con'], true) ? $_GET['f'] : '';
$buscar = trim((string) ($_GET['q'] ?? ''));
$volver = 'estructura.php?' . http_build_query(array_filter(['ver' => $ver, 'curso' => $cursoF ?: null, 'f' => $filtro, 'q' => $buscar]));

/* ------------------------------ ACCIONES ------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valido()) {
        avisar('error', 'La página venció. Vuelva a intentarlo.');
        redirigir($volver);
    }
    $accion = (string) ($_POST['accion'] ?? '');
    $clave  = (string) ($_POST['docente'] ?? '');
    try {
        switch ($accion) {
            case 'importar':
                $plan = planImportacion($pdo, leerPlanillaAsignaciones());
                if (!hash_equals($plan['huella'], (string) ($_POST['huella'] ?? ''))) {
                    throw new RuntimeException('La planilla o los datos del campus cambiaron desde la vista previa. Revísela de nuevo antes de importar.');
                }
                $r = aplicarImportacion($pdo, $plan, !empty($_POST['quitar']));
                auditarEstructura("importó la planilla de ProgMesas: {$r['materias']} materias nuevas, {$r['nuevas']} asignaciones nuevas, {$r['cambios']} con cambios, {$r['quitadas']} quitadas");
                avisar('exito', "Importación lista: {$r['nuevas']} materias de curso nuevas, {$r['cambios']} actualizadas"
                    . ($r['quitadas'] ? ", {$r['quitadas']} quitadas" : '') . '. Ahora vincule a los docentes con sus cuentas.');
                $volver = 'estructura.php?ver=docentes&f=sin';
                break;

            case 'vincular':
                // El campo puede traer el ID directo (botón de sugerencia) o el texto elegido de la lista ("… #123")
                $uid = (int) ($_POST['usuario_id'] ?? 0);
                if (!$uid && preg_match('/#(\d+)\s*$/', (string) ($_POST['cuenta'] ?? ''), $m)) {
                    $uid = (int) $m[1];
                }
                if (!$uid) {
                    throw new RuntimeException('Elija la cuenta de la lista.');
                }
                vincularDocente($pdo, $clave, $uid, $yo);
                auditarEstructura("vinculó al docente «{$clave}» con la cuenta $uid");
                avisar('exito', 'Docente vinculado: la cuenta ya ve esas materias.');
                break;

            case 'desvincular':
                desvincularDocente($pdo, $clave);
                auditarEstructura("desvinculó al docente «{$clave}»");
                avisar('exito', 'Vínculo quitado.');
                break;

            default:
                throw new RuntimeException('Acción desconocida.');
        }
    } catch (RuntimeException $ex) {
        avisar('error', $ex->getMessage());
    } catch (PDOException $ex) {
        error_log('estructura.php: ' . $ex->getMessage());
        avisar('error', 'Error en la base de datos. Intente nuevamente.');
    }
    redirigir($volver);
}

/* ------------------------------ DATOS ------------------------------ */
$cursos = listarCursos($pdo);
$conteo = materiasPorCurso($pdo);

encabezadoPanel('Estructura académica');
?>
<nav class="pestanas-gestion no-imprimir">
    <?php foreach ($secciones as $k => $titulo): ?>
        <a href="estructura.php?ver=<?php echo $k; ?>" class="<?php echo $ver === $k ? 'activa' : ''; ?>"><?php echo e($titulo); ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($ver === 'cursos'): ?>
    <section class="caja">
        <h2>Cursos</h2>
        <?php if (!$conteo): ?>
            <p class="aviso-codigos">Todavía no hay materias cargadas. <a href="estructura.php?ver=importar">Impórtelas de ProgMesas →</a></p>
        <?php endif; ?>
        <div class="cursos-grilla">
            <?php foreach ($cursos as $c): $cid = (int) $c['id']; ?>
                <a class="curso-enlace <?php echo $cid === $cursoF ? 'activa' : ''; ?>" href="estructura.php?ver=cursos&amp;curso=<?php echo $cid; ?>">
                    <?php echo e(nombreCurso($c)); ?> <span class="contador-suave"><?php echo (int) ($conteo[$cid] ?? 0); ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <?php if ($cursoF): $curso = array_values(array_filter($cursos, fn($c) => (int) $c['id'] === $cursoF))[0] ?? null; ?>
        <section class="caja">
            <h2><?php echo $curso ? e(nombreCurso($curso)) : 'Curso'; ?></h2>
            <?php $materias = $curso ? materiasDeCurso($pdo, $cursoF) : []; ?>
            <?php if (!$materias): ?>
                <p class="vacio">Este curso no tiene materias cargadas.</p>
            <?php else: ?>
                <div class="tabla-scroll"><table class="tabla-gestion">
                    <thead><tr><th>Materia</th><th>Docentes</th><th>Programa</th><th>Nota de la planilla</th></tr></thead>
                    <tbody>
                    <?php foreach ($materias as $m): ?>
                        <tr>
                            <td><strong><?php echo e($m['materia']); ?></strong></td>
                            <td>
                                <?php if (!$m['docentes']): ?><span class="estado estado--pendiente">Sin docente</span><?php endif; ?>
                                <?php foreach ($m['docentes'] as $d): ?>
                                    <div class="docente-linea">
                                        <?php echo e($d['nombre']); ?>
                                        <?php if ($d['cuenta']): ?>
                                            <span class="estado estado--activo" title="Cuenta del campus"><?php echo e($d['cuenta']); ?></span>
                                        <?php else: ?>
                                            <a class="estado estado--pendiente" href="estructura.php?ver=docentes&amp;q=<?php echo urlencode($d['nombre']); ?>">Sin vincular</a>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </td>
                            <td><?php echo $m['requiere'] ? 'Sí' : 'No'; ?></td>
                            <td><?php echo e($m['revisar']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

<?php elseif ($ver === 'docentes'): ?>
    <?php
    $docentes = docentesEsperados($pdo);
    $sinVincular = count(array_filter($docentes, fn($d) => !$d['usuario_id']));
    $lista = array_filter($docentes, fn($d) => ($filtro === '' || ($filtro === 'sin') === !$d['usuario_id'])
        && ($buscar === '' || stripos($d['nombre'] . ' ' . ($d['cuenta'] ?? ''), $buscar) !== false));
    $detalle = asignacionesPorDocente($pdo);
    $profesores = listarCuentas($pdo, 'profesor');
    ?>
    <section class="caja">
        <div class="caja__titulo">
            <h2>Docentes de la planilla <small class="sub"><?php echo count($docentes); ?> en total · <?php echo $sinVincular; ?> sin vincular</small></h2>
            <form method="get" class="form-linea"><input type="hidden" name="ver" value="docentes">
                <select name="f">
                    <option value="">Todos</option>
                    <option value="sin" <?php echo $filtro === 'sin' ? 'selected' : ''; ?>>Sin vincular</option>
                    <option value="con" <?php echo $filtro === 'con' ? 'selected' : ''; ?>>Vinculados</option>
                </select>
                <input name="q" value="<?php echo e($buscar); ?>" placeholder="Apellido">
                <button class="boton-mini">Ver</button>
            </form>
        </div>
        <p>En ProgMesas cada docente figura por apellido. Vincúlelo con su cuenta de profesor para que vea sus materias
            (y, más adelante, tome asistencia). Si un docente figura de dos formas («Bailoni» y «Bailoni Cecilia»), vincule las dos
            con la misma cuenta. Si todavía no tiene cuenta, créela en <a href="cuentas.php?ver=personal">Gestión de cuentas</a>.</p>

        <datalist id="profesores">
            <?php foreach ($profesores as $p): ?>
                <option value="<?php echo e($p['nombre'] . ($p['correo'] ? ' — ' . $p['correo'] : '') . ' #' . $p['id']); ?>"></option>
            <?php endforeach; ?>
        </datalist>

        <?php if (!$docentes): ?>
            <p class="vacio">No hay docentes: primero <a href="estructura.php?ver=importar">importe la planilla</a>.</p>
        <?php elseif (!$lista): ?>
            <p class="vacio">No hay docentes para mostrar.</p>
        <?php else: ?>
            <div class="tabla-scroll"><table class="tabla-gestion">
                <thead><tr><th>Docente en la planilla</th><th>Materias</th><th>Cuenta del campus</th></tr></thead>
                <tbody>
                <?php foreach ($lista as $d): ?>
                    <tr>
                        <td><strong><?php echo e($d['nombre']); ?></strong></td>
                        <td>
                            <details><summary><?php echo (int) $d['materias']; ?> materia(s)</summary>
                                <ul class="lista-chica"><?php foreach ($detalle[$d['docente_clave']] ?? [] as $t): ?><li><?php echo e($t); ?></li><?php endforeach; ?></ul>
                            </details>
                        </td>
                        <td>
                            <?php if ($d['usuario_id']): ?>
                                <div class="acciones">
                                    <span class="estado estado--activo"><?php echo e($d['cuenta']); ?></span>
                                    <form method="post" onsubmit="return confirm('¿Quitar el vínculo? Esa cuenta deja de ver estas materias.');"><?php echo csrf_campo(); ?>
                                        <input type="hidden" name="accion" value="desvincular"><input type="hidden" name="docente" value="<?php echo e($d['docente_clave']); ?>">
                                        <button class="boton-mini boton-mini--peligro">Desvincular</button>
                                    </form>
                                </div>
                            <?php else: ?>
                                <?php foreach ($d['sugeridos'] as $s): ?>
                                    <form method="post" class="form-sugerencia"><?php echo csrf_campo(); ?>
                                        <input type="hidden" name="accion" value="vincular"><input type="hidden" name="docente" value="<?php echo e($d['docente_clave']); ?>">
                                        <input type="hidden" name="usuario_id" value="<?php echo (int) $s['id']; ?>">
                                        <button class="boton-mini">Vincular con <?php echo e($s['nombre']); ?></button> <span class="rol-chip">Sugerido</span>
                                    </form>
                                <?php endforeach; ?>
                                <form method="post" class="form-linea"><?php echo csrf_campo(); ?>
                                    <input type="hidden" name="accion" value="vincular"><input type="hidden" name="docente" value="<?php echo e($d['docente_clave']); ?>">
                                    <input name="cuenta" list="profesores" placeholder="<?php echo $d['sugeridos'] ? 'Otra cuenta…' : 'Buscar cuenta de profesor…'; ?>" aria-label="Cuenta de profesor para <?php echo e($d['nombre']); ?>" required>
                                    <button class="boton-mini">Vincular</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </section>

<?php else: ?>
    <?php
    $plan = null;
    try {
        $plan = planImportacion($pdo, leerPlanillaAsignaciones());
    } catch (RuntimeException $ex) {
        echo '<div class="error">' . e($ex->getMessage()) . '</div>';
    }
    if ($plan):
        $nombres = [];
        foreach ($cursos as $c) {
            $nombres[(int) $c['id']] = nombreCurso($c);
        }
        $hayCambios = $plan['nuevas'] || $plan['cambios'] || $plan['solo_campus'];
    ?>
        <section class="caja">
            <h2>Vista previa de la importación</h2>
            <p>Planilla: <code>progmesas/backend/data/asignaciones.xlsx</code> · <?php echo (int) $plan['filas']; ?> filas.
                Nada cambia hasta que confirme abajo. Los vínculos de docentes ya hechos se conservan.</p>
            <ul class="resumen-import">
                <li><strong><?php echo count($plan['materias_nuevas']); ?></strong> materias nuevas en el catálogo</li>
                <li><strong><?php echo count($plan['nuevas']); ?></strong> materias de curso para agregar</li>
                <li><strong><?php echo count($plan['cambios']); ?></strong> con cambios (docentes, programa o nota)</li>
                <li><strong><?php echo count($plan['solo_campus']); ?></strong> están en el campus y ya no en la planilla</li>
                <li><strong><?php echo (int) $plan['sin_docente']; ?></strong> sin docente en la planilla</li>
            </ul>
            <?php if ($plan['cursos_desconocidos']): ?>
                <div class="error">Estos cursos de la planilla no existen en el campus y sus filas no se importan:
                    <?php echo e(implode(', ', array_map(fn($c, $n) => "$c ($n)", array_keys($plan['cursos_desconocidos']), $plan['cursos_desconocidos']))); ?>.</div>
            <?php endif; ?>
            <?php if ($plan['turnos_distintos']): ?>
                <p class="aviso-codigos">La planilla indica otro turno para:
                    <?php echo e(implode(', ', array_map(fn($c, $t) => "$c (planilla: $t)", array_keys($plan['turnos_distintos']), $plan['turnos_distintos']))); ?>.
                    Se importa igual; el turno del curso no cambia.</p>
            <?php endif; ?>

            <?php if (!$hayCambios): ?>
                <p class="exito">El campus ya está igual que la planilla. No hay nada para importar.</p>
            <?php else: ?>
                <form method="post" onsubmit="return confirm('¿Importar estos cambios?');"><?php echo csrf_campo(); ?>
                    <input type="hidden" name="accion" value="importar">
                    <input type="hidden" name="huella" value="<?php echo e($plan['huella']); ?>">
                    <?php if ($plan['solo_campus']): ?>
                        <label class="casilla"><input type="checkbox" name="quitar" value="1">
                            Quitar del campus las <?php echo count($plan['solo_campus']); ?> materias de curso que ya no están en la planilla</label>
                    <?php endif; ?>
                    <button class="boton">Importar</button>
                </form>
            <?php endif; ?>
        </section>

        <?php if ($plan['materias_nuevas']): ?>
            <section class="caja"><details><summary><strong>Materias nuevas (<?php echo count($plan['materias_nuevas']); ?>)</strong></summary>
                <p><?php echo e(implode(' · ', $plan['materias_nuevas'])); ?></p>
            </details></section>
        <?php endif; ?>

        <?php foreach (['nuevas' => 'Materias de curso para agregar', 'cambios' => 'Con cambios'] as $k => $titulo): if ($plan[$k]): ?>
            <section class="caja"><details <?php echo $k === 'cambios' ? 'open' : ''; ?>><summary><strong><?php echo $titulo; ?> (<?php echo count($plan[$k]); ?>)</strong></summary>
                <div class="tabla-scroll"><table class="tabla-gestion">
                    <thead><tr><th>Curso</th><th>Materia</th><th>Docentes en la planilla</th><?php if ($k === 'cambios'): ?><th>Qué cambia</th><?php else: ?><th>Nota</th><?php endif; ?></tr></thead>
                    <tbody>
                    <?php foreach ($plan[$k] as $a): ?>
                        <tr>
                            <td><?php echo e($a['curso']); ?></td>
                            <td><?php echo e($a['materia']); ?></td>
                            <td><?php echo $a['docentes'] ? e(implode(' / ', $a['docentes'])) : '<span class="estado estado--pendiente">Sin docente</span>'; ?></td>
                            <td><?php echo e($k === 'cambios' ? implode('; ', $a['detalle']) : $a['revisar']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </details></section>
        <?php endif; endforeach; ?>

        <?php if ($plan['solo_campus']): ?>
            <section class="caja"><details open><summary><strong>En el campus pero no en la planilla (<?php echo count($plan['solo_campus']); ?>)</strong></summary>
                <ul class="lista-chica">
                    <?php foreach ($plan['solo_campus'] as $s): ?><li><?php echo e(($nombres[$s['curso_id']] ?? 'Curso ' . $s['curso_id']) . ' — ' . $s['materia']); ?></li><?php endforeach; ?>
                </ul>
            </details></section>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>

<?php piePanel(); ?>
