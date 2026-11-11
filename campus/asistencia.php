<?php
/* =====================================================================
   ARCHIVO: asistencia.php  (módulo de asistencias, etapa 3)
   PARA QUÉ SIRVE (según los roles de la cuenta):
     - Tomar asistencia (?ver=tomar): profesor en sus materias, preceptor
       la general de sus cursos. Solo el día de hoy.
     - Justificar (?ver=justificar): preceptor (sus cursos) y directivos.
     - Reportes (?ver=reportes): filtros, totales, resumen por alumno y
       detalle, para imprimir o guardar en PDF. Cada uno ve lo suyo:
       directivo todo, preceptor sus cursos, profesor sus materias,
       alumno sus propias asistencias. El preceptor ve además las alertas
       de inasistencias.
   Toda acción va por POST con token CSRF; el servidor revisa los permisos.
   ===================================================================== */
require_once 'includes/sesion.php';
require_once 'includes/asistencias.php';
require_once 'includes/panel.php';

requerirPermiso('asistencia');

$pdo = bdAsistencias();
$yo  = (int) ($_SESSION['usuario_id'] ?? 0);

$opciones   = opcionesToma($pdo, $yo);
$justifica  = tieneRol('directivo') || tieneRol('preceptor');
$secciones  = array_filter([
    'tomar'      => (tieneRol('profesor') || tieneRol('preceptor')) ? 'Tomar asistencia' : null,
    'justificar' => $justifica ? 'Justificar faltas' : null,
    'reportes'   => 'Reportes',
]);
$ver = array_key_exists($_GET['ver'] ?? '', $secciones) ? $_GET['ver'] : array_key_first($secciones);

/* ------------------------------ ACCIONES ------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $volver = (string) ($_POST['volver'] ?? '');
    $volver = preg_match('/^asistencia\.php(\?[A-Za-z0-9_=&%.\-]*)?$/', $volver) ? $volver : 'asistencia.php';
    if (!csrf_valido()) {
        avisar('error', 'La página venció. Vuelva a intentarlo.');
        redirigir($volver);
    }
    try {
        switch ((string) ($_POST['accion'] ?? '')) {
            case 'tomar':
                $clave = (string) ($_POST['clave'] ?? '');
                $n = guardarToma($pdo, $yo, $clave, (array) ($_POST['asis'] ?? []));
                auditarAsistencias("tomó asistencia de hoy ($clave): $n alumnos");
                avisar('exito', "Asistencia de hoy guardada: $n alumno(s). Puede corregirla durante el día.");
                break;

            case 'justificar':
                $valor = ($_POST['valor'] ?? '') === '1';
                $a = justificarAsistencia($pdo, $yo, (int) ($_POST['asistencia_id'] ?? 0), $valor);
                auditarAsistencias(($valor ? 'justificó' : 'quitó la justificación de') . " la asistencia {$a['id']} ({$a['nombre']}, {$a['fecha']})");
                avisar('exito', $valor ? 'Falta justificada.' : 'Justificación quitada.');
                break;

            case 'descartar_alerta':
                if (!tieneRol('preceptor')) {
                    throw new RuntimeException('Acción no permitida.');
                }
                descartarAlerta($pdo, $yo, (int) ($_POST['alumno_id'] ?? 0));
                avisar('exito', 'Alerta descartada. Vuelve a aparecer si el alumno falta de nuevo.');
                break;

            default:
                throw new RuntimeException('Acción desconocida.');
        }
    } catch (RuntimeException $ex) {
        avisar('error', $ex->getMessage());
    } catch (PDOException $ex) {
        error_log('asistencia.php: ' . $ex->getMessage());
        avisar('error', 'Error en la base de datos. Intente nuevamente.');
    }
    redirigir($volver);
}

/* ------------------------------ VISTA ------------------------------ */
$fechaHoy = date('d/m/Y');
function campoVolver(): string {
    return '<input type="hidden" name="volver" value="' . e('asistencia.php?' . ($_SERVER['QUERY_STRING'] ?? '')) . '">';
}
function tarjetasResumen(array $r): void {
    $items = [['Registros', $r['total'], 'bi-list-check', 'text-primary'], ['Presentes', $r['presente'], 'bi-check-circle', 'text-success'],
              ['Tardes', $r['tarde'], 'bi-clock', 'text-warning'], ['Ausentes', $r['ausente'], 'bi-x-circle', 'text-danger'],
              ['Justificadas', $r['justificadas'], 'bi-patch-check', 'text-info'], ['% Asistencia', $r['porcentaje'] . '%', 'bi-graph-up', 'text-secondary']];
    echo '<div class="row">';
    foreach ($items as [$t, $n, $i, $c]) {
        echo '<div class="col-6 col-md-4 col-xl-2"><div class="card stats-card"><div class="card-body d-flex justify-content-between align-items-center">'
           . '<div><h6 class="text-muted mb-1">' . $t . '</h6><h3 class="' . $c . '">' . e((string) $n) . '</h3></div>'
           . '<i class="bi ' . $i . ' fs-2 ' . $c . ' no-imprimir"></i></div></div></div>';
    }
    echo '</div>';
}

$titulos = [
    'tomar'      => ['Tomar asistencia', 'bi-clipboard-check', "Planilla del día de hoy ($fechaHoy)."],
    'justificar' => ['Justificar faltas', 'bi-patch-check', 'Ausencias y llegadas tarde de un día.'],
    'reportes'   => tieneRol('alumno') && count(rolesSesion()) === 1
        ? ['Mis asistencias', 'bi-calendar-check', 'Sus asistencias por día y materia.']
        : ['Reportes de asistencia', 'bi-graph-up', 'Filtre, revise e imprima las asistencias.'],
];
[$tit, $ico, $sub] = $titulos[$ver];
encabezadoPanel($tit, $ico, $sub, "asistencia:$ver");

/* ============================== TOMAR ============================== */
if ($ver === 'tomar' && !$opciones):
    tarjeta('Tomar asistencia', 'bi-clipboard-check');
    sinDatos(tieneRol('preceptor')
        ? 'Todavía no tiene cursos asignados. Pídale a un directivo que se los asigne.'
        : 'Todavía no tiene materias asignadas. Pídale a un directivo que vincule su cuenta con su nombre en la planilla de asignaciones (Estructura académica → Docentes).');
    finTarjeta();

elseif ($ver === 'tomar'):
    $clave = array_key_exists($_GET['clase'] ?? '', $opciones) ? $_GET['clase'] : array_key_first($opciones);
    $op = $opciones[$clave];
    $alumnos = alumnosParaToma($pdo, $op['curso_id']);
    $cargado = asistenciasDelDia($pdo, $op['curso_id'], $op['cm_id'], hoy());
    $selector = '<form method="get" class="d-flex gap-2"><input type="hidden" name="ver" value="tomar">'
        . '<select name="clase" class="form-select form-select-sm" aria-label="Curso y materia" onchange="this.form.submit()">';
    foreach ($opciones as $k => $o) {
        $selector .= '<option value="' . e($k) . '"' . ($k === $clave ? ' selected' : '') . '>' . e($o['etiqueta']) . '</option>';
    }
    $selector .= '</select><noscript><button class="btn btn-light btn-sm">Ver</button></noscript></form>';
    tarjeta($op['curso'] . ' · ' . $op['materia'], 'bi-clipboard-check', count($alumnos) . ' alumno(s)', $selector);
?>
    <?php if ($cargado): ?>
        <div class="alert alert-info"><i class="bi bi-info-circle"></i> La asistencia de hoy ya se tomó (<?php echo count($cargado); ?> alumnos). Puede corregirla hasta que termine el día.</div>
    <?php endif; ?>
    <?php if (!$alumnos): ?>
        <?php sinDatos('Este curso todavía no tiene alumnos cargados.'); ?>
    <?php else: ?>
        <form method="post" id="planilla"><?php echo csrf_campo(), campoVolver(); ?>
            <input type="hidden" name="accion" value="tomar"><input type="hidden" name="clave" value="<?php echo e($clave); ?>">
            <div class="d-flex flex-wrap gap-2 mb-3 no-imprimir">
                <button type="button" class="btn btn-outline-primary btn-sm" data-todos="presente"><i class="bi bi-check-all"></i> Todos presentes</button>
                <button type="button" class="btn btn-outline-danger btn-sm" data-todos="ausente"><i class="bi bi-x-lg"></i> Todos ausentes</button>
            </div>
            <div class="table-responsive"><table class="table table-hover">
                <thead><tr><th>Alumno</th><th class="d-none d-md-table-cell">DNI</th><th class="text-center">Estado</th><th class="text-center">Hora de llegada<br><small>(si llegó tarde)</small></th></tr></thead>
                <tbody>
                <?php foreach ($alumnos as $a): $id = (int) $a['id']; $act = $cargado[$id]['estado'] ?? 'presente'; $hora = substr((string) ($cargado[$id]['hora_llegada'] ?? ''), 0, 5) ?: date('H:i'); ?>
                    <tr>
                        <td><strong><?php echo e($a['nombre']); ?></strong>
                            <?php if (!empty($cargado[$id]['justificada'])): ?> <span class="badge bg-info">Justificada</span><?php endif; ?></td>
                        <td class="d-none d-md-table-cell"><?php echo e($a['dni']); ?></td>
                        <td class="text-center">
                            <div class="btn-group" role="group" aria-label="Estado de <?php echo e($a['nombre']); ?>">
                                <?php foreach (['presente' => ['success', 'bi-check-lg'], 'tarde' => ['warning', 'bi-clock'], 'ausente' => ['danger', 'bi-x-lg']] as $est => [$col, $ic]): ?>
                                    <input type="radio" class="btn-check estado-radio" name="asis[<?php echo $id; ?>][estado]" id="e<?php echo $est . $id; ?>" value="<?php echo $est; ?>" <?php echo $act === $est ? 'checked' : ''; ?> data-alumno="<?php echo $id; ?>">
                                    <label class="btn btn-outline-<?php echo $col; ?> btn-sm" for="e<?php echo $est . $id; ?>"><i class="bi <?php echo $ic; ?>"></i> <?php echo ESTADOS_ASISTENCIA[$est]; ?></label>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <td class="text-center">
                            <input type="time" name="asis[<?php echo $id; ?>][hora]" id="h<?php echo $id; ?>" value="<?php echo e($hora); ?>" class="form-control form-control-sm mx-auto hora-llegada" style="max-width:120px;<?php echo $act === 'tarde' ? '' : 'display:none;'; ?>" aria-label="Hora de llegada de <?php echo e($a['nombre']); ?>">
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <button class="btn btn-primary btn-lg mt-3"><i class="bi bi-save"></i> Guardar asistencia de hoy</button>
        </form>
        <script>
        (function () {
            const form = document.getElementById('planilla');
            const sync = r => { const h = document.getElementById('h' + r.dataset.alumno); if (h) h.style.display = r.value === 'tarde' ? '' : 'none'; };
            form.querySelectorAll('.estado-radio').forEach(r => r.addEventListener('change', () => sync(r)));
            form.querySelectorAll('[data-todos]').forEach(b => b.addEventListener('click', () => {
                form.querySelectorAll('.estado-radio[value="' + b.dataset.todos + '"]').forEach(r => { r.checked = true; sync(r); });
            }));
        })();
        </script>
    <?php endif; ?>
<?php
    finTarjeta();

/* ============================ JUSTIFICAR ============================ */
elseif ($ver === 'justificar'):
    $cursosJ = tieneRol('directivo') ? listarCursos($pdo) : cursosDePreceptor($pdo, $yo);
    $idsJ = array_map(fn($c) => (int) $c['id'], $cursosJ);
    $cursoJ = in_array((int) ($_GET['curso'] ?? 0), $idsJ, true) ? (int) $_GET['curso'] : ($idsJ[0] ?? 0);
    $fechaJ = fechaValida((string) ($_GET['fecha'] ?? '')) ?? hoy();
    $filtro = '<form method="get" class="d-flex flex-wrap gap-2"><input type="hidden" name="ver" value="justificar">'
        . '<select name="curso" class="form-select form-select-sm w-auto" aria-label="Curso">';
    foreach ($cursosJ as $c) {
        $filtro .= '<option value="' . (int) $c['id'] . '"' . ((int) $c['id'] === $cursoJ ? ' selected' : '') . '>' . e(nombreCurso($c)) . '</option>';
    }
    $filtro .= '</select><input type="date" name="fecha" class="form-control form-control-sm w-auto" value="' . e($fechaJ) . '" max="' . hoy() . '" aria-label="Fecha">'
             . '<button class="btn btn-light btn-sm"><i class="bi bi-search"></i> Ver</button></form>';
    tarjeta('Faltas y llegadas tarde', 'bi-patch-check', date('d/m/Y', strtotime($fechaJ)), $filtro);

    if (!$cursoJ) {
        sinDatos('No tiene cursos asignados.');
    } else {
        $res = buscarAsistencias($pdo, alcanceConsulta($pdo, $yo), filtrosAsistencia(['curso' => $cursoJ, 'desde' => $fechaJ, 'hasta' => $fechaJ]));
        $faltas = array_filter($res['filas'], fn($f) => $f['estado'] !== 'presente');
        if (!$faltas) {
            sinDatos('No hay ausencias ni llegadas tarde registradas ese día en este curso.');
        } else { ?>
            <div class="table-responsive"><table class="table table-hover">
                <thead><tr><th>Alumno</th><th>Materia</th><th>Estado</th><th>Tomada por</th><th>Justificación</th></tr></thead>
                <tbody>
                <?php foreach ($faltas as $f): ?>
                    <tr>
                        <td><strong><?php echo e($f['alumno']); ?></strong></td>
                        <td><?php echo e($f['materia'] ?? 'Asistencia general'); ?></td>
                        <td><?php echo etiquetaEstado($f['estado'], (bool) $f['justificada']); ?><?php echo $f['hora_llegada'] ? ' <small>' . e(substr($f['hora_llegada'], 0, 5)) . '</small>' : ''; ?></td>
                        <td><small><?php echo e($f['tomada_por'] ?? ''); ?></small></td>
                        <td>
                            <form method="post" class="d-inline"><?php echo csrf_campo(), campoVolver(); ?>
                                <input type="hidden" name="accion" value="justificar"><input type="hidden" name="asistencia_id" value="<?php echo (int) $f['id']; ?>">
                                <input type="hidden" name="valor" value="<?php echo $f['justificada'] ? '0' : '1'; ?>">
                                <?php if ($f['justificada']): ?>
                                    <button class="btn btn-outline-danger btn-sm"><i class="bi bi-x-circle"></i> Quitar justificación</button>
                                <?php else: ?>
                                    <button class="btn btn-primary btn-sm"><i class="bi bi-patch-check"></i> Justificar</button>
                                <?php endif; ?>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php }
    }
    finTarjeta();

/* ============================= REPORTES ============================= */
else:
    $al = alcanceConsulta($pdo, $yo);
    $soloAlumno = !empty($al['alumno']) && count($al) === 1;
    $f = filtrosAsistencia($_GET);
    if ($soloAlumno) {
        $f['alumno'] = 0;   // el alumno ve solo lo suyo: el alcance ya lo limita
    }

    // Cursos que puede elegir
    if (!empty($al['todo'])) {
        $cursosR = listarCursos($pdo);
    } else {
        $ids = array_merge($al['cursos'] ?? [], array_map(fn($m) => (int) $m['curso_id'], materiasDeProfesor($pdo, $yo)));
        if (!empty($al['alumno']) && ($c = cursoDeAlumno($pdo, $yo))) {
            $ids[] = $c;
        }
        $cursosR = array_values(array_filter(listarCursos($pdo, false), fn($c) => in_array((int) $c['id'], $ids, true)));
    }
    $idsR = array_map(fn($c) => (int) $c['id'], $cursosR);
    if ($f['curso'] && !in_array($f['curso'], $idsR, true)) {
        $f['curso'] = 0;
    }
    if (count($idsR) === 1) {
        $f['curso'] = $idsR[0];
    }

    // Materias del curso elegido
    $materiasR = [];
    if ($f['curso']) {
        $todoCurso = !empty($al['todo']) || in_array($f['curso'], $al['cursos'] ?? [], true) || $soloAlumno;
        foreach (materiasDeCurso($pdo, $f['curso']) as $m) {
            if ($todoCurso || in_array($m['id'], $al['materias'] ?? [], true)) {
                $materiasR[$m['id']] = $m['materia'];
            }
        }
    }
    $alumnosR = (!$soloAlumno && $f['curso']) ? alumnosDeCursos($pdo, [$f['curso']]) : [];

    $res = buscarAsistencias($pdo, $al, $f);
    $nombreCursoF = '';
    foreach ($cursosR as $c) {
        if ((int) $c['id'] === $f['curso']) {
            $nombreCursoF = nombreCurso($c);
        }
    }

    // Alertas del preceptor
    if (tieneRol('preceptor')):
        $alertas = alertasPreceptor($pdo, $yo);
        if ($alertas):
            tarjeta('Alertas importantes', 'bi-exclamation-triangle', ALERTA_FALTAS . ' o más días con faltas sin justificar en los últimos ' . ALERTA_DIAS . ' días', '', 'no-imprimir'); ?>
            <div class="table-responsive"><table class="table table-sm table-hover">
                <thead><tr><th>Alumno</th><th>Curso</th><th>Días con falta</th><th>Última</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($alertas as $x): ?>
                    <tr>
                        <td><strong><?php echo e($x['nombre']); ?></strong></td>
                        <td><?php echo e(nombreCurso($x)); ?></td>
                        <td><span class="badge bg-danger"><?php echo (int) $x['dias']; ?></span></td>
                        <td><?php echo e(date('d/m', strtotime($x['ultima']))); ?></td>
                        <td class="text-end">
                            <a class="btn btn-outline-primary btn-sm" href="asistencia.php?ver=reportes&amp;curso=<?php echo (int) $x['curso_id']; ?>&amp;alumno=<?php echo (int) $x['alumno_id']; ?>&amp;desde=<?php echo date('Y-m-d', strtotime('-' . (ALERTA_DIAS - 1) . ' days')); ?>"><i class="bi bi-eye"></i> Ver</a>
                            <form method="post" class="d-inline"><?php echo csrf_campo(), campoVolver(); ?>
                                <input type="hidden" name="accion" value="descartar_alerta"><input type="hidden" name="alumno_id" value="<?php echo (int) $x['alumno_id']; ?>">
                                <button class="btn btn-outline-danger btn-sm"><i class="bi bi-bell-slash"></i> Descartar</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php finTarjeta();
        endif;
    endif;

    // Filtros
    tarjeta('Filtros', 'bi-funnel', '', '', 'no-imprimir'); ?>
        <form method="get" class="row g-3 align-items-end"><input type="hidden" name="ver" value="reportes">
            <?php if (count($cursosR) > 1): ?>
                <div class="col-md-3"><label class="form-label" for="f-curso">Curso</label>
                    <select id="f-curso" name="curso" class="form-select" onchange="this.form.materia.value='';if(this.form.alumno)this.form.alumno.value='';this.form.submit()">
                        <option value="0">Todos</option>
                        <?php foreach ($cursosR as $c): ?><option value="<?php echo (int) $c['id']; ?>" <?php echo (int) $c['id'] === $f['curso'] ? 'selected' : ''; ?>><?php echo e(nombreCurso($c)); ?></option><?php endforeach; ?>
                    </select></div>
            <?php endif; ?>
            <div class="col-md-3"><label class="form-label" for="f-materia">Materia</label>
                <select id="f-materia" name="materia" class="form-select">
                    <option value="">Todas</option>
                    <option value="g" <?php echo $f['materia'] === 'g' ? 'selected' : ''; ?>>Asistencia general (preceptor)</option>
                    <?php foreach ($materiasR as $id => $n): ?><option value="<?php echo (int) $id; ?>" <?php echo $f['materia'] === (int) $id ? 'selected' : ''; ?>><?php echo e($n); ?></option><?php endforeach; ?>
                </select></div>
            <?php if ($alumnosR): ?>
                <div class="col-md-3"><label class="form-label" for="f-alumno">Alumno</label>
                    <select id="f-alumno" name="alumno" class="form-select"><option value="0">Todos</option>
                        <?php foreach ($alumnosR as $id => $n): ?><option value="<?php echo (int) $id; ?>" <?php echo $f['alumno'] === (int) $id ? 'selected' : ''; ?>><?php echo e($n); ?></option><?php endforeach; ?>
                    </select></div>
            <?php endif; ?>
            <div class="col-6 col-md-3 col-xl-2"><label class="form-label" for="f-desde">Desde</label><input id="f-desde" type="date" name="desde" class="form-control" value="<?php echo e($f['desde']); ?>"></div>
            <div class="col-6 col-md-3 col-xl-2"><label class="form-label" for="f-hasta">Hasta</label><input id="f-hasta" type="date" name="hasta" class="form-control" value="<?php echo e($f['hasta']); ?>"></div>
            <div class="col-6 col-md-3 col-xl-2"><label class="form-label" for="f-estado">Estado</label>
                <select id="f-estado" name="estado" class="form-select"><option value="">Todos</option>
                    <?php foreach (ESTADOS_ASISTENCIA as $k => $n): ?><option value="<?php echo $k; ?>" <?php echo $f['estado'] === $k ? 'selected' : ''; ?>><?php echo $n; ?></option><?php endforeach; ?>
                </select></div>
            <div class="col-6 col-md-3 col-xl-2"><label class="form-label" for="f-just">Justificada</label>
                <select id="f-just" name="just" class="form-select"><option value="">Todas</option>
                    <option value="si" <?php echo $f['just'] === 'si' ? 'selected' : ''; ?>>Sí</option><option value="no" <?php echo $f['just'] === 'no' ? 'selected' : ''; ?>>No</option>
                </select></div>
            <div class="col-md-3 col-xl-2 d-flex gap-2">
                <button class="btn btn-primary flex-fill"><i class="bi bi-search"></i> Filtrar</button>
                <a class="btn btn-outline-primary" href="asistencia.php?ver=reportes" title="Limpiar filtros" aria-label="Limpiar filtros"><i class="bi bi-arrow-counterclockwise"></i></a>
            </div>
        </form>
    <?php finTarjeta(); ?>

    <div class="imprimible">
        <div class="d-none d-print-block mb-3 text-dark">
            <h4>E.E.T. N°2 "Independencia" — Reporte de asistencia</h4>
            <p class="mb-0"><?php echo e(($nombreCursoF ?: 'Todos los cursos') . ' · ' . ($f['materia'] === 'g' ? 'Asistencia general' : ($materiasR[$f['materia']] ?? 'Todas las materias'))
                . ' · del ' . date('d/m/Y', strtotime($f['desde'])) . ' al ' . date('d/m/Y', strtotime($f['hasta']))
                . ($f['alumno'] ? ' · ' . ($alumnosR[$f['alumno']] ?? '') : '') . ' · impreso el ' . date('d/m/Y H:i') . ' por ' . ($_SESSION['nombre'] ?? '')); ?></p>
        </div>
        <?php tarjetasResumen($res['resumen']); ?>

        <?php if ($res['cortado']): ?>
            <div class="alert alert-warning no-imprimir"><i class="bi bi-exclamation-triangle"></i> Se muestran los primeros <?php echo LIMITE_FILAS; ?> registros: acote las fechas o elija un curso.</div>
        <?php endif; ?>

        <?php if (!$soloAlumno && count($res['por_alumno']) > 1): ?>
            <?php tarjeta('Resumen por alumno', 'bi-people', count($res['por_alumno']) . ' alumno(s)'); ?>
                <div class="table-responsive"><table class="table table-sm table-hover">
                    <thead><tr><th>Alumno</th><?php if (!$f['curso']): ?><th>Curso</th><?php endif; ?><th class="text-center">Presentes</th><th class="text-center">Tardes</th><th class="text-center">Ausentes</th><th class="text-center">Justificadas</th><th class="text-center">% Asistencia</th></tr></thead>
                    <tbody>
                    <?php foreach ($res['por_alumno'] as $aid => $x): ?>
                        <tr>
                            <td><a class="text-decoration-none" href="asistencia.php?<?php echo e(http_build_query(['ver' => 'reportes', 'curso' => $f['curso'] ?: '', 'alumno' => $aid, 'desde' => $f['desde'], 'hasta' => $f['hasta']])); ?>"><strong><?php echo e($x['alumno']); ?></strong></a></td>
                            <?php if (!$f['curso']): ?><td><small><?php echo e($x['curso']); ?></small></td><?php endif; ?>
                            <td class="text-center"><?php echo $x['presente']; ?></td>
                            <td class="text-center"><?php echo $x['tarde']; ?></td>
                            <td class="text-center"><?php echo $x['ausente'] ? '<span class="badge bg-danger">' . $x['ausente'] . '</span>' : 0; ?></td>
                            <td class="text-center"><?php echo $x['justificadas']; ?></td>
                            <td class="text-center"><strong class="<?php echo $x['porcentaje'] < 75 ? 'text-danger' : ''; ?>"><?php echo $x['porcentaje']; ?>%</strong></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php finTarjeta(); ?>
        <?php endif; ?>

        <?php
        $imprimir = $res['filas'] ? '<button type="button" class="btn btn-light btn-sm no-imprimir" onclick="window.print()"><i class="bi bi-printer"></i> Imprimir / PDF</button>' : '';
        tarjeta($soloAlumno ? 'Historial' : 'Detalle', 'bi-list-check', count($res['filas']) . ' registro(s)', $imprimir);
        if (!$res['filas']):
            sinDatos('No hay asistencias registradas con esos filtros.');
        else: ?>
            <div class="table-responsive"><table class="table table-sm table-hover">
                <thead><tr><th>Fecha</th><?php if (!$soloAlumno): ?><th>Alumno</th><?php endif; ?><?php if (!$f['curso']): ?><th>Curso</th><?php endif; ?><th>Materia</th><th>Estado</th><th>Llegada</th><th>Tomada por</th></tr></thead>
                <tbody>
                <?php foreach ($res['filas'] as $x): ?>
                    <tr>
                        <td class="text-nowrap"><?php echo e(date('d/m/Y', strtotime($x['fecha']))); ?></td>
                        <?php if (!$soloAlumno): ?><td><?php echo e($x['alumno']); ?></td><?php endif; ?>
                        <?php if (!$f['curso']): ?><td><small><?php echo e(nombreCurso($x)); ?></small></td><?php endif; ?>
                        <td><?php echo e($x['materia'] ?? 'Asistencia general'); ?></td>
                        <td><?php echo etiquetaEstado($x['estado'], (bool) $x['justificada']); ?></td>
                        <td><?php echo e(substr((string) $x['hora_llegada'], 0, 5)); ?></td>
                        <td><small><?php echo e($x['tomada_por'] ?? ''); ?></small></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif;
        finTarjeta(); ?>
    </div>
<?php endif; ?>

<?php piePanel(); ?>
