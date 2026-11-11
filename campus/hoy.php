<?php
/* =====================================================================
   ARCHIVO: hoy.php  (PRECEPTORES y DIRECTIVOS)
   PARA QUÉ SIRVE: la pantalla de inicio del trabajo diario.
     - Preceptor: una tarjeta por curso con lo de hoy (asistencia tomada
       o no, ausentes, alertas, pedidos, alumnos sin activar) y los
       botones para resolverlo.
     - Directivo: lo pendiente para que el campus funcione (pedidos,
       alumnos sin curso, cursos sin preceptor, docentes sin vincular…)
       y el estado de la asistencia de hoy en todos los cursos.
   Solo muestra datos; las acciones se hacen en cada pantalla.
   ===================================================================== */
require_once 'includes/sesion.php';
require_once 'includes/asistencias.php';
require_once 'includes/panel.php';

requerirLogin();
if (!tieneRol('preceptor') && !tieneRol('directivo')) {
    header('Location: dashboard.php');
    exit;
}

$pdo = bdAsistencias();
$yo  = (int) ($_SESSION['usuario_id'] ?? 0);
$dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

/** «Sin tomar» / «22 presentes · 3 ausentes» de la asistencia general de un curso. */
function textoGeneral(array $t): string {
    if (!$t['general']) {
        return '<span class="badge bg-warning">Sin tomar</span>';
    }
    $g = $t['general'];
    return '<span class="badge bg-success"><i class="bi bi-check-lg"></i> Tomada</span> <small>'
         . ($g['registros'] - $g['ausentes'] - $g['tardes']) . ' presentes · ' . $g['ausentes'] . ' ausentes'
         . ($g['tardes'] ? ' · ' . $g['tardes'] . ' tarde' . ($g['tardes'] > 1 ? 's' : '') : '') . '</small>';
}

encabezadoPanel(tieneRol('directivo') ? 'Hoy y pendientes' : 'Hoy', 'bi-sun',
    ucfirst($dias[(int) date('w')]) . ' ' . date('d/m/Y') . ': lo que hay que hacer, en un solo lugar.', 'hoy');

/* ============================== PRECEPTOR ============================== */
if (tieneRol('preceptor')):
    $misCursos = cursosDePreceptor($pdo, $yo);
    $ids = array_map(fn($c) => (int) $c['id'], $misCursos);
    if (!$ids) {
        sinDatos('Todavía no tiene cursos asignados. Pídale a un directivo que se los asigne.');
    } else {
        $tab = tableroHoy($pdo, $ids);
        $alertas = [];
        foreach (alertasPreceptor($pdo, $yo) as $a) {
            $alertas[(int) $a['curso_id']][] = $a['nombre'];
        }
        $pedidos = [];
        foreach (solicitudesPendientes($pdo, $ids) as $s) {
            $pedidos[(int) $s['curso_id']] = ($pedidos[(int) $s['curso_id']] ?? 0) + 1;
        }
        $activo = cursoActivo($ids);
        echo '<h4 class="text-white mb-3"><i class="bi bi-book"></i> Mis cursos</h4><div class="row">';
        foreach ($tab as $cid => $t):
            $q = '&amp;curso=' . $cid; ?>
            <div class="col-lg-6 col-xxl-4">
                <?php tarjeta(nombreCurso($t['curso']), 'bi-book', $cid === $activo ? 'curso activo' : '', '', 'curso-hoy'); ?>
                    <div class="dato"><span><i class="bi bi-clipboard-check"></i> Asistencia general</span><span><?php echo textoGeneral($t); ?></span></div>
                    <div class="dato"><span><i class="bi bi-journal-check"></i> Materias con asistencia hoy</span><span><strong><?php echo $t['materias_hoy']; ?></strong> de <?php echo $t['materias']; ?></span></div>
                    <div class="dato"><span><i class="bi bi-exclamation-triangle"></i> Alertas de inasistencia</span>
                        <span><?php echo !empty($alertas[$cid]) ? '<span class="badge bg-danger">' . count($alertas[$cid]) . '</span> <small>' . e(implode(', ', array_slice($alertas[$cid], 0, 3))) . (count($alertas[$cid]) > 3 ? '…' : '') . '</small>' : '<span class="badge bg-success">0</span>'; ?></span></div>
                    <div class="dato"><span><i class="bi bi-key"></i> Pedidos de recuperación</span><span><?php echo !empty($pedidos[$cid]) ? '<span class="badge bg-warning">' . $pedidos[$cid] . '</span>' : '<span class="badge bg-success">0</span>'; ?></span></div>
                    <div class="dato"><span><i class="bi bi-people"></i> Alumnos</span><span><strong><?php echo $t['alumnos']; ?></strong><?php echo $t['sin_activar'] ? ' <small>(' . $t['sin_activar'] . ' sin activar)</small>' : ''; ?></span></div>
                    <div class="d-flex flex-wrap gap-2 mt-3">
                        <a class="btn btn-primary btn-sm" href="asistencia.php?ver=tomar<?php echo $q; ?>"><i class="bi bi-clipboard-check"></i> <?php echo $t['general'] ? 'Corregir asistencia' : 'Tomar asistencia'; ?></a>
                        <a class="btn btn-outline-primary btn-sm" href="asistencia.php?ver=justificar<?php echo $q; ?>"><i class="bi bi-patch-check"></i> Justificar</a>
                        <a class="btn btn-outline-primary btn-sm" href="asistencia.php?ver=reportes<?php echo $q; ?>"><i class="bi bi-graph-up"></i> Reportes</a>
                        <a class="btn btn-outline-primary btn-sm" href="preceptor.php?curso=<?php echo $cid; ?>"><i class="bi bi-people"></i> Alumnos<?php echo !empty($pedidos[$cid]) ? ' y pedidos' : ''; ?></a>
                    </div>
                <?php finTarjeta(); ?>
            </div>
        <?php endforeach;
        echo '</div>';
    }
endif;

/* ============================== DIRECTIVO ============================== */
if (tieneRol('directivo')):
    $pend = pendientesDirectivo($pdo);
    $hay = array_filter($pend, fn($p) => $p[1] > 0);
    tarjeta('Pendientes', 'bi-list-task', $hay ? count($hay) . ' para resolver' : 'todo al día');
    echo '<div class="row g-2">';
    foreach ($pend as [$txt, $n, $url, $ico, $expl]): ?>
        <div class="col-md-6">
            <a class="pendiente <?php echo $n ? '' : 'listo'; ?>" href="<?php echo e($url); ?>">
                <i class="bi <?php echo $ico; ?>"></i>
                <span class="flex-fill"><strong><?php echo e($txt); ?></strong><br><small><?php echo e($expl); ?></small></span>
                <?php echo $n ? '<span class="badge bg-danger">' . $n . '</span>' : '<span class="badge bg-success"><i class="bi bi-check-lg"></i></span>'; ?>
            </a>
        </div>
    <?php endforeach;
    echo '</div>';
    finTarjeta();

    $tab = tableroHoy($pdo);
    $tomados = count(array_filter($tab, fn($t) => $t['general']));
    tarjeta('Asistencia de hoy por curso', 'bi-clipboard-data', "$tomados de " . count($tab) . ' cursos con la asistencia general tomada'); ?>
        <div class="table-responsive"><table class="table table-sm table-hover">
            <thead><tr><th>Curso</th><th>Preceptor</th><th class="text-center">Alumnos</th><th>Asistencia general</th><th class="text-center">Materias hoy</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($tab as $cid => $t): ?>
                <tr>
                    <td class="text-nowrap"><strong><?php echo e(nombreCurso($t['curso'])); ?></strong></td>
                    <td><?php echo $t['preceptores'] !== '' ? e($t['preceptores']) : '<a class="badge bg-warning text-decoration-none" href="cuentas.php?ver=preceptores">Sin preceptor</a>'; ?></td>
                    <td class="text-center"><?php echo $t['alumnos'] ?: '<span class="badge bg-warning">0</span>'; ?></td>
                    <td><?php echo $t['alumnos'] ? textoGeneral($t) : '<small class="text-muted">—</small>'; ?></td>
                    <td class="text-center"><?php echo $t['materias_hoy']; ?> / <?php echo $t['materias']; ?></td>
                    <td class="text-end"><a class="btn btn-outline-primary btn-sm" href="asistencia.php?ver=reportes&amp;curso=<?php echo $cid; ?>&amp;desde=<?php echo hoy(); ?>"><i class="bi bi-eye"></i> Ver</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php finTarjeta();
endif;

piePanel();
