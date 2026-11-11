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

encabezadoPanel('Materias');

if (tieneRol('profesor')):
    $mias = materiasDeProfesor($pdo, $yo);
    $porCurso = [];
    foreach ($mias as $m) {
        $porCurso[nombreCurso($m)][] = $m['materia'];
    }
?>
    <section class="caja">
        <h2>Mis materias</h2>
        <?php if (!$porCurso): ?>
            <p class="vacio">Todavía no tiene materias asignadas. Si dicta clases en la escuela, pídale a un directivo que vincule su
                cuenta con su nombre en la planilla de asignaciones.</p>
        <?php else: ?>
            <div class="tabla-scroll"><table class="tabla-gestion">
                <thead><tr><th>Curso</th><th>Materias</th></tr></thead>
                <tbody>
                <?php foreach ($porCurso as $curso => $materias): ?>
                    <tr><td><strong><?php echo e($curso); ?></strong></td><td><?php echo e(implode(' · ', $materias)); ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <p style="margin-top:8px;">¿Falta o sobra alguna? Avísele a un directivo: los datos salen de la planilla de asignaciones de ProgMesas.</p>
        <?php endif; ?>
    </section>
<?php endif;

if (tieneRol('preceptor')):
    $cursos = cursosDePreceptor($pdo, $yo);
?>
    <?php if (!$cursos): ?>
        <section class="caja"><h2>Mis cursos</h2><p class="vacio">Todavía no tiene cursos asignados. Pídaselo a un directivo.</p></section>
    <?php endif; ?>
    <?php foreach ($cursos as $c): $materias = materiasDeCurso($pdo, (int) $c['id']); ?>
        <section class="caja">
            <h2><?php echo e(nombreCurso($c)); ?></h2>
            <?php if (!$materias): ?>
                <p class="vacio">Este curso no tiene materias cargadas.</p>
            <?php else: ?>
                <div class="tabla-scroll"><table class="tabla-gestion">
                    <thead><tr><th>Materia</th><th>Docentes</th></tr></thead>
                    <tbody>
                    <?php foreach ($materias as $m): ?>
                        <tr>
                            <td><strong><?php echo e($m['materia']); ?></strong></td>
                            <td><?php echo $m['docentes'] ? e(implode(' / ', array_map(fn($d) => $d['cuenta'] ?: $d['nombre'], $m['docentes']))) : '<span class="vacio">Sin docente</span>'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
<?php endif;

piePanel();
