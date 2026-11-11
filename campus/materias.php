<?php
/* =====================================================================
   ARCHIVO: materias.php  (PROFESORES y PRECEPTORES, solo lectura)
   PARA QUÉ SIRVE:
     - profesor: las materias y cursos que dicta, según el vínculo que
       hizo un directivo entre su cuenta y su apellido en la planilla;
     - preceptor: las materias y docentes de sus cursos.
   ===================================================================== */
require_once 'includes/sesion.php';
require_once 'includes/academico.php';
require_once 'includes/panel.php';

requerirPermiso('materias');

$pdo = bdAcademico();
$yo  = (int) ($_SESSION['usuario_id'] ?? 0);

$esProfesor = tieneRol('profesor');
encabezadoPanel($esProfesor ? 'Mis materias' : 'Materias de mis cursos', 'bi-journal-bookmark',
    $esProfesor ? 'Los cursos y materias que dicta.' : 'Qué materias tiene cada uno de sus cursos y quién las dicta.', 'materias');

if ($esProfesor):
    $porCurso = [];
    foreach (materiasDeProfesor($pdo, $yo) as $m) {
        $porCurso[nombreCurso($m)][] = $m['materia'];
    }
    tarjeta('Mis materias', 'bi-journal-bookmark', $porCurso ? count($porCurso) . ' curso(s)' : '');
?>
        <?php if (!$porCurso): ?>
            <?php sinDatos('Todavía no tiene materias asignadas. Si dicta clases en la escuela, pídale a un directivo que vincule su cuenta con su nombre en la planilla de asignaciones.'); ?>
        <?php else: ?>
            <div class="table-responsive"><table class="table table-hover">
                <thead><tr><th>Curso</th><th>Materias</th></tr></thead>
                <tbody>
                <?php foreach ($porCurso as $curso => $materias): ?>
                    <tr>
                        <td class="text-nowrap"><strong><i class="bi bi-book"></i> <?php echo e($curso); ?></strong></td>
                        <td><?php foreach ($materias as $m): ?><span class="profesor-badge"><?php echo e($m); ?></span><?php endforeach; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <p class="mt-3 mb-0 small">¿Falta o sobra alguna? Avísele a un directivo: los datos salen de la planilla de asignaciones de ProgMesas.</p>
        <?php endif; ?>
<?php
    finTarjeta();
endif;

if (tieneRol('preceptor')):
    $cursos = cursosDePreceptor($pdo, $yo);
    if (!$cursos) {
        tarjeta('Mis cursos', 'bi-book');
        sinDatos('Todavía no tiene cursos asignados. Pídaselo a un directivo.');
        finTarjeta();
    }
    foreach ($cursos as $c):
        $materias = materiasDeCurso($pdo, (int) $c['id']);
        tarjeta(nombreCurso($c), 'bi-book', count($materias) . ' materia(s)');
?>
            <?php if (!$materias): ?>
                <?php sinDatos('Este curso no tiene materias cargadas.'); ?>
            <?php else: ?>
                <div class="table-responsive"><table class="table table-hover">
                    <thead><tr><th>Materia</th><th>Docentes</th></tr></thead>
                    <tbody>
                    <?php foreach ($materias as $m): ?>
                        <tr>
                            <td><strong><?php echo e($m['materia']); ?></strong></td>
                            <td><?php echo $m['docentes'] ? e(implode(' / ', array_map(fn($d) => $d['cuenta'] ?: $d['nombre'], $m['docentes']))) : '<span class="badge bg-warning">Sin docente</span>'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
<?php
        finTarjeta();
    endforeach;
endif;

piePanel();
