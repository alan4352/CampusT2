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
                    . ($r['quitadas'] ? ", {$r['quitadas']} quitadas" : '')
                    . ($r['conservadas'] ? ", {$r['conservadas']} se conservaron porque ya tienen asistencias" : '') . '. Ahora vincule a los docentes con sus cuentas.');
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

$encabezados = [
    'cursos'   => ['Materias por curso', 'bi-journal', 'Qué se dicta en cada curso y quién lo dicta.'],
    'docentes' => ['Docentes', 'bi-person-check', 'Vincule a cada docente de la planilla de ProgMesas con su cuenta de profesor.'],
    'importar' => ['Importar de ProgMesas', 'bi-cloud-download', 'Traiga las materias y los docentes de la planilla de asignaciones.'],
];
[$tit, $ico, $sub] = $encabezados[$ver];
encabezadoPanel($tit, $ico, $sub, "estructura:$ver");
?>

<?php if ($ver === 'cursos'): ?>
    <?php tarjeta('Cursos', 'bi-book', count($cursos) . ' cursos · ' . array_sum($conteo) . ' materias de curso'); ?>
        <?php if (!$conteo): ?>
            <div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> Todavía no hay materias cargadas. <a href="estructura.php?ver=importar">Impórtelas de ProgMesas →</a></div>
        <?php endif; ?>
        <div class="cursos-grilla mb-0">
            <?php foreach ($cursos as $c): $cid = (int) $c['id']; ?>
                <a class="curso-enlace <?php echo $cid === $cursoF ? 'active' : ''; ?>" href="estructura.php?ver=cursos&amp;curso=<?php echo $cid; ?>">
                    <?php echo e(nombreCurso($c)); ?> <span class="badge bg-primary"><?php echo (int) ($conteo[$cid] ?? 0); ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php finTarjeta(); ?>

    <?php if ($cursoF): $curso = array_values(array_filter($cursos, fn($c) => (int) $c['id'] === $cursoF))[0] ?? null; ?>
        <?php $materias = $curso ? materiasDeCurso($pdo, $cursoF) : []; ?>
        <?php tarjeta($curso ? nombreCurso($curso) : 'Curso', 'bi-journal', count($materias) . ' materia(s)'); ?>
            <?php if (!$materias): ?>
                <?php sinDatos('Este curso no tiene materias cargadas.'); ?>
            <?php else: ?>
                <div class="table-responsive"><table class="table table-hover">
                    <thead><tr><th>Materia</th><th>Docentes</th><th>Programa</th><th>Nota de la planilla</th></tr></thead>
                    <tbody>
                    <?php foreach ($materias as $m): ?>
                        <tr>
                            <td><strong><?php echo e($m['materia']); ?></strong></td>
                            <td>
                                <?php if (!$m['docentes']): ?><span class="badge bg-warning">Sin docente</span><?php endif; ?>
                                <?php foreach ($m['docentes'] as $d): ?>
                                    <div class="d-flex flex-wrap align-items-center gap-1 my-1">
                                        <?php echo e($d['nombre']); ?>
                                        <?php if ($d['cuenta']): ?>
                                            <span class="badge bg-success" title="Cuenta del campus"><i class="bi bi-person-check"></i> <?php echo e($d['cuenta']); ?></span>
                                        <?php else: ?>
                                            <a class="badge bg-warning text-decoration-none" href="estructura.php?ver=docentes&amp;q=<?php echo urlencode($d['nombre']); ?>">Sin vincular</a>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </td>
                            <td><?php echo $m['requiere'] ? '<i class="bi bi-check-circle text-success"></i> Sí' : 'No'; ?></td>
                            <td><small><?php echo e($m['revisar']); ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
        <?php finTarjeta(); ?>
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
    <div class="row">
        <?php foreach ([['Docentes en la planilla', count($docentes), 'bi-people', 'text-primary'], ['Vinculados', count($docentes) - $sinVincular, 'bi-person-check', 'text-success'],
                        ['Sin vincular', $sinVincular, 'bi-person-dash', 'text-warning'], ['Cuentas de profesor', count($profesores), 'bi-person-badge', 'text-info']] as [$t, $n, $i, $col]): ?>
            <div class="col-6 col-md-3">
                <div class="card stats-card"><div class="card-body d-flex justify-content-between align-items-center">
                    <div><h6 class="text-muted mb-1"><?php echo $t; ?></h6><h3 class="<?php echo $col; ?>"><?php echo (int) $n; ?></h3></div>
                    <i class="bi <?php echo $i; ?> fs-1 <?php echo $col; ?>"></i>
                </div></div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php
    $filtroHtml = '<form method="get" class="d-flex flex-wrap gap-2"><input type="hidden" name="ver" value="docentes">'
        . '<select name="f" class="form-select form-select-sm w-auto" aria-label="Filtro"><option value="">Todos</option>'
        . '<option value="sin"' . ($filtro === 'sin' ? ' selected' : '') . '>Sin vincular</option>'
        . '<option value="con"' . ($filtro === 'con' ? ' selected' : '') . '>Vinculados</option></select>'
        . '<input name="q" class="form-control form-control-sm w-auto" value="' . e($buscar) . '" placeholder="Apellido" aria-label="Buscar docente">'
        . '<button class="btn btn-light btn-sm"><i class="bi bi-search"></i> Ver</button></form>';
    tarjeta('Docentes de la planilla', 'bi-person-check', '', $filtroHtml);
    ?>
        <p class="small">En ProgMesas cada docente figura por apellido. Vincúlelo con su cuenta de profesor para que vea sus materias
            (y, más adelante, tome asistencia). Si un docente figura de dos formas («Bailoni» y «Bailoni Cecilia»), vincule las dos
            con la misma cuenta. Si todavía no tiene cuenta, créela en <a href="cuentas.php?ver=personal">Personal</a>.</p>

        <datalist id="profesores">
            <?php foreach ($profesores as $p): ?>
                <option value="<?php echo e($p['nombre'] . ($p['correo'] ? ' — ' . $p['correo'] : '') . ' #' . $p['id']); ?>"></option>
            <?php endforeach; ?>
        </datalist>

        <?php if (!$docentes): ?>
            <?php sinDatos('No hay docentes: primero <a href="estructura.php?ver=importar">importe la planilla</a>.'); ?>
        <?php elseif (!$lista): ?>
            <?php sinDatos('No hay docentes para mostrar.'); ?>
        <?php else: ?>
            <div class="table-responsive"><table class="table table-hover">
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
                                <div class="d-flex flex-wrap align-items-center gap-2">
                                    <span class="badge bg-success"><i class="bi bi-person-check"></i> <?php echo e($d['cuenta']); ?></span>
                                    <form method="post" class="d-inline" onsubmit="return confirm('¿Quitar el vínculo? Esa cuenta deja de ver estas materias.');"><?php echo csrf_campo(); ?>
                                        <input type="hidden" name="accion" value="desvincular"><input type="hidden" name="docente" value="<?php echo e($d['docente_clave']); ?>">
                                        <button class="btn btn-outline-danger btn-sm"><i class="bi bi-x-circle"></i> Desvincular</button>
                                    </form>
                                </div>
                            <?php else: ?>
                                <?php foreach ($d['sugeridos'] as $s): ?>
                                    <form method="post" class="d-flex align-items-center gap-2 mb-1"><?php echo csrf_campo(); ?>
                                        <input type="hidden" name="accion" value="vincular"><input type="hidden" name="docente" value="<?php echo e($d['docente_clave']); ?>">
                                        <input type="hidden" name="usuario_id" value="<?php echo (int) $s['id']; ?>">
                                        <button class="btn btn-primary btn-sm"><i class="bi bi-link-45deg"></i> Vincular con <?php echo e($s['nombre']); ?></button>
                                        <span class="badge bg-warning">Sugerido</span>
                                    </form>
                                <?php endforeach; ?>
                                <form method="post" class="d-flex gap-1"><?php echo csrf_campo(); ?>
                                    <input type="hidden" name="accion" value="vincular"><input type="hidden" name="docente" value="<?php echo e($d['docente_clave']); ?>">
                                    <input name="cuenta" list="profesores" class="form-control form-control-sm" placeholder="<?php echo $d['sugeridos'] ? 'Otra cuenta…' : 'Buscar cuenta de profesor…'; ?>" aria-label="Cuenta de profesor para <?php echo e($d['nombre']); ?>" required>
                                    <button class="btn btn-outline-primary btn-sm"><i class="bi bi-link-45deg"></i> Vincular</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    <?php finTarjeta(); ?>

<?php else: ?>
    <?php
    $plan = null;
    try {
        $plan = planImportacion($pdo, leerPlanillaAsignaciones());
    } catch (RuntimeException $ex) {
        echo '<div class="alert alert-danger error"><i class="bi bi-exclamation-triangle"></i> ' . e($ex->getMessage()) . '</div>';
    }
    if ($plan):
        $nombres = [];
        foreach ($cursos as $c) {
            $nombres[(int) $c['id']] = nombreCurso($c);
        }
        $hayCambios = $plan['nuevas'] || $plan['cambios'] || $plan['solo_campus'];
    ?>
        <div class="row">
            <?php foreach ([['Materias nuevas', count($plan['materias_nuevas']), 'bi-journal-plus', 'text-primary'], ['Para agregar', count($plan['nuevas']), 'bi-plus-circle', 'text-success'],
                            ['Con cambios', count($plan['cambios']), 'bi-pencil-square', 'text-warning'], ['Ya no en la planilla', count($plan['solo_campus']), 'bi-dash-circle', 'text-danger']] as [$t, $n, $i, $col]): ?>
                <div class="col-6 col-md-3">
                    <div class="card stats-card"><div class="card-body d-flex justify-content-between align-items-center">
                        <div><h6 class="text-muted mb-1"><?php echo $t; ?></h6><h3 class="<?php echo $col; ?>"><?php echo (int) $n; ?></h3></div>
                        <i class="bi <?php echo $i; ?> fs-1 <?php echo $col; ?>"></i>
                    </div></div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php tarjeta('Vista previa de la importación', 'bi-eye', (int) $plan['filas'] . ' filas en la planilla'); ?>
            <p>Planilla: <code>progmesas/backend/data/asignaciones.xlsx</code>. Nada cambia hasta que confirme abajo. Los vínculos de docentes ya hechos se conservan.</p>
            <ul class="mb-3">
                <li><strong><?php echo count($plan['materias_nuevas']); ?></strong> materias nuevas en el catálogo</li>
                <li><strong><?php echo count($plan['nuevas']); ?></strong> materias de curso para agregar</li>
                <li><strong><?php echo count($plan['cambios']); ?></strong> con cambios (docentes, programa o nota)</li>
                <li><strong><?php echo count($plan['solo_campus']); ?></strong> están en el campus y ya no en la planilla</li>
                <li><strong><?php echo (int) $plan['sin_docente']; ?></strong> sin docente en la planilla</li>
            </ul>
            <?php if ($plan['cursos_desconocidos']): ?>
                <div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> Estos cursos de la planilla no existen en el campus y sus filas no se importan:
                    <?php echo e(implode(', ', array_map(fn($c, $n) => "$c ($n)", array_keys($plan['cursos_desconocidos']), $plan['cursos_desconocidos']))); ?>.</div>
            <?php endif; ?>
            <?php if ($plan['turnos_distintos']): ?>
                <div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> La planilla indica otro turno para:
                    <?php echo e(implode(', ', array_map(fn($c, $t) => "$c (planilla: $t)", array_keys($plan['turnos_distintos']), $plan['turnos_distintos']))); ?>.
                    Se importa igual; el turno del curso no cambia.</div>
            <?php endif; ?>

            <?php if (!$hayCambios): ?>
                <div class="alert alert-success mb-0"><i class="bi bi-check-circle"></i> El campus ya está igual que la planilla. No hay nada para importar.</div>
            <?php else: ?>
                <form method="post" onsubmit="return confirm('¿Importar estos cambios?');"><?php echo csrf_campo(); ?>
                    <input type="hidden" name="accion" value="importar">
                    <input type="hidden" name="huella" value="<?php echo e($plan['huella']); ?>">
                    <?php if ($plan['solo_campus']): ?>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="quitar" value="1" id="quitar">
                            <label class="form-check-label" for="quitar">Quitar del campus las <?php echo count($plan['solo_campus']); ?> materias de curso que ya no están en la planilla</label>
                        </div>
                    <?php endif; ?>
                    <button class="btn btn-primary"><i class="bi bi-cloud-download"></i> Importar</button>
                </form>
            <?php endif; ?>
        <?php finTarjeta(); ?>

        <?php if ($plan['materias_nuevas']): ?>
            <?php tarjeta('Materias nuevas', 'bi-journal-plus', '(' . count($plan['materias_nuevas']) . ')'); ?>
                <details><summary>Ver la lista</summary><p class="mt-2 mb-0"><?php echo e(implode(' · ', $plan['materias_nuevas'])); ?></p></details>
            <?php finTarjeta(); ?>
        <?php endif; ?>

        <?php foreach (['nuevas' => ['Materias de curso para agregar', 'bi-plus-circle'], 'cambios' => ['Con cambios', 'bi-pencil-square']] as $k => [$titulo, $icoT]): if ($plan[$k]): ?>
            <?php tarjeta($titulo, $icoT, '(' . count($plan[$k]) . ')'); ?>
                <details <?php echo $k === 'cambios' ? 'open' : ''; ?>><summary>Ver el detalle</summary>
                <div class="table-responsive mt-2"><table class="table table-sm table-hover">
                    <thead><tr><th>Curso</th><th>Materia</th><th>Docentes en la planilla</th><th><?php echo $k === 'cambios' ? 'Qué cambia' : 'Nota'; ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($plan[$k] as $a): ?>
                        <tr>
                            <td><?php echo e($a['curso']); ?></td>
                            <td><?php echo e($a['materia']); ?></td>
                            <td><?php echo $a['docentes'] ? e(implode(' / ', $a['docentes'])) : '<span class="badge bg-warning">Sin docente</span>'; ?></td>
                            <td><small><?php echo e($k === 'cambios' ? implode('; ', $a['detalle']) : $a['revisar']); ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                </details>
            <?php finTarjeta(); ?>
        <?php endif; endforeach; ?>

        <?php if ($plan['solo_campus']): ?>
            <?php tarjeta('En el campus pero no en la planilla', 'bi-dash-circle', '(' . count($plan['solo_campus']) . ')'); ?>
                <ul class="lista-chica mb-0">
                    <?php foreach ($plan['solo_campus'] as $s): ?><li><?php echo e(($nombres[$s['curso_id']] ?? 'Curso ' . $s['curso_id']) . ' — ' . $s['materia']); ?></li><?php endforeach; ?>
                </ul>
            <?php finTarjeta(); ?>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>

<?php piePanel(); ?>
