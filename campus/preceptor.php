<?php
/* =====================================================================
   ARCHIVO: preceptor.php  (solo PRECEPTORES)
   PARA QUÉ SIRVE: el preceptor gestiona las cuentas de los alumnos de
   SUS cursos (los asigna un directivo en cuentas.php):
     - cargar alumnos (nombre, DNI) en el padrón del curso;
     - generar los códigos de primer ingreso (de a uno o todo el curso,
       para imprimir y repartir);
     - atender los pedidos de recuperación de sus alumnos.
   El servidor verifica en cada acción que el alumno sea de un curso suyo.
   ===================================================================== */
require_once 'includes/sesion.php';
require_once 'includes/cuentas.php';
require_once 'includes/panel.php';

requerirPermiso('padron');

$pdo = bdCuentas();
$yo  = (int) ($_SESSION['usuario_id'] ?? 0);

$misCursos = cursosDePreceptor($pdo, $yo);
$misIds = array_map(fn($c) => (int) $c['id'], $misCursos);
$cursoSel = (int) ($_GET['curso'] ?? ($misIds[0] ?? 0));
if (!in_array($cursoSel, $misIds, true)) {
    $cursoSel = $misIds[0] ?? 0;
}
$volver = 'preceptor.php' . ($cursoSel ? '?curso=' . $cursoSel : '');

/* ------------------------------ ACCIONES ------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valido()) {
        avisar('error', 'La página venció. Vuelva a intentarlo.');
        redirigir($volver);
    }
    $accion = (string) ($_POST['accion'] ?? '');
    $uid    = (int) ($_POST['usuario_id'] ?? 0);
    try {
        // Lo que se hace sobre un alumno: tiene que ser de un curso de este preceptor
        if (in_array($accion, ['codigo', 'descartar'], true) && !preceptorGestionaAlumno($pdo, $yo, $uid)) {
            throw new RuntimeException('Ese alumno no es de uno de sus cursos.');
        }
        switch ($accion) {
            case 'crear_alumno':
                $cid = (int) ($_POST['curso_id'] ?? 0);
                if (!in_array($cid, $misIds, true)) {
                    throw new RuntimeException('Solo puede cargar alumnos en sus cursos.');
                }
                $nuevo = crearCuenta($pdo, ['nombre' => $_POST['nombre'] ?? '', 'dni' => $_POST['dni'] ?? '', 'curso_id' => $cid], 'alumno');
                $c = generarCodigoAcceso($pdo, $nuevo, 'alta', $yo);
                $u = buscarUsuarioPor($pdo, 'id', $nuevo);
                guardarCodigosParaMostrar([$c + ['nombre' => $u['nombre'], 'dni' => $u['dni']]]);
                auditarCuentas("cargó al alumno {$u['nombre']} (DNI {$u['dni']}) en el curso $cid");
                avisar('exito', 'Alumno cargado. Entréguele el código para su primer ingreso.');
                break;

            case 'codigo':
                $u = buscarUsuarioPor($pdo, 'id', $uid);
                $c = generarCodigoAcceso($pdo, $uid, $u['clave_hash'] ? 'recuperacion' : 'alta', $yo);
                marcarSolicitudesAtendidas($pdo, $uid, $yo);
                guardarCodigosParaMostrar([$c + ['nombre' => $u['nombre'], 'dni' => $u['dni']]]);
                auditarCuentas("generó un código de {$c['tipo']} para {$u['nombre']}");
                break;

            case 'codigos_curso':
                $cid = (int) ($_POST['curso_id'] ?? 0);
                if (!in_array($cid, $misIds, true)) {
                    throw new RuntimeException('Ese curso no es suyo.');
                }
                $generados = [];
                foreach (alumnosDeCurso($pdo, $cid) as $a) {
                    if ($a['estado'] === 'pendiente') {
                        $generados[] = generarCodigoAcceso($pdo, (int) $a['id'], 'alta', $yo) + ['nombre' => $a['nombre'], 'dni' => $a['dni']];
                    }
                }
                if (!$generados) {
                    throw new RuntimeException('Todos los alumnos de este curso ya activaron su cuenta.');
                }
                guardarCodigosParaMostrar($generados);
                auditarCuentas('generó ' . count($generados) . " códigos de alta para el curso $cid");
                break;

            case 'descartar':
                $st = $pdo->prepare("SELECT id FROM solicitudes_recuperacion WHERE id = ? AND usuario_id = ? AND estado = 'pendiente'");
                $st->execute([(int) ($_POST['solicitud_id'] ?? 0), $uid]);
                if ($sid = $st->fetchColumn()) {
                    descartarSolicitud($pdo, (int) $sid, $yo);
                }
                avisar('exito', 'Pedido descartado.');
                break;

            default:
                throw new RuntimeException('Acción desconocida.');
        }
    } catch (RuntimeException $ex) {
        avisar('error', $ex->getMessage());
    } catch (PDOException $ex) {
        error_log('preceptor.php: ' . $ex->getMessage());
        avisar('error', 'Error en la base de datos. Intente nuevamente.');
    }
    redirigir($volver);
}

/* ------------------------------ DATOS ------------------------------ */
$alumnos = $cursoSel ? alumnosDeCurso($pdo, $cursoSel) : [];
$pedidos = solicitudesPendientes($pdo, $misIds);
$cursoNombre = [];
foreach ($misCursos as $c) {
    $cursoNombre[(int) $c['id']] = nombreCurso($c);
}
$sinActivar = count(array_filter($alumnos, fn($a) => $a['estado'] === 'pendiente'));
$activos = count(array_filter($alumnos, fn($a) => $a['estado'] === 'activo'));

encabezadoPanel('Alumnos y cuentas', 'bi-key', 'Cargue a los alumnos de sus cursos y entrégueles el código de primer ingreso.', 'padron');
?>

<?php if (!$misCursos): ?>
    <?php sinDatos('Todavía no tiene cursos asignados. Pídale a un directivo que se los asigne en «Gestión de cuentas».'); ?>
<?php else: ?>

    <ul class="nav nav-pills mb-3 no-imprimir">
        <?php foreach ($misCursos as $c): $act = (int) $c['id'] === $cursoSel; ?>
            <li class="nav-item"><a href="preceptor.php?curso=<?php echo (int) $c['id']; ?>" class="nav-link <?php echo $act ? 'active' : ''; ?>"<?php echo $act ? ' aria-current="page"' : ''; ?>><i class="bi bi-book"></i> <?php echo e(nombreCurso($c)); ?></a></li>
        <?php endforeach; ?>
    </ul>

    <div class="row no-imprimir">
        <?php foreach ([['Alumnos', count($alumnos), 'bi-people', 'text-primary'], ['Cuentas activas', $activos, 'bi-person-check', 'text-success'],
                        ['Sin activar', $sinActivar, 'bi-hourglass-split', 'text-warning'], ['Pedidos de recuperación', count($pedidos), 'bi-key', 'text-danger']] as [$t, $n, $i, $col]): ?>
            <div class="col-6 col-md-3">
                <div class="card stats-card"><div class="card-body d-flex justify-content-between align-items-center">
                    <div><h6 class="text-muted mb-1"><?php echo $t; ?></h6><h3 class="<?php echo $col; ?>"><?php echo (int) $n; ?></h3></div>
                    <i class="bi <?php echo $i; ?> fs-1 <?php echo $col; ?>"></i>
                </div></div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="row no-imprimir">
        <div class="col-lg-6">
            <?php tarjeta('Cargar alumno en ' . ($cursoNombre[$cursoSel] ?? ''), 'bi-person-plus', '', '', 'h-100'); ?>
                <form method="post"><?php echo csrf_campo(); ?>
                    <input type="hidden" name="accion" value="crear_alumno"><input type="hidden" name="curso_id" value="<?php echo $cursoSel; ?>">
                    <div class="mb-3"><label class="form-label" for="nombre">Nombre y apellido</label><input id="nombre" name="nombre" class="form-control" maxlength="150" required></div>
                    <div class="mb-3"><label class="form-label" for="dni">DNI</label><input id="dni" name="dni" class="form-control" inputmode="numeric" maxlength="8" pattern="[0-9]{7,8}" required placeholder="Sin puntos"></div>
                    <button class="btn btn-primary"><i class="bi bi-key"></i> Cargar y generar código</button>
                </form>
                <p class="mt-3 mb-0 small">Si el DNI ya tiene cuenta (por ejemplo, cambió de curso), pídale a un directivo que lo actualice.</p>
            <?php finTarjeta(); ?>
        </div>
        <div class="col-lg-6">
            <?php tarjeta('Solicitudes de recuperación', 'bi-person-gear', '(' . count($pedidos) . ')', '', 'h-100'); ?>
                <?php if (!$pedidos): ?>
                    <?php sinDatos('No hay solicitudes de recuperación pendientes.'); ?>
                <?php else: ?>
                    <p class="small">Verifique que quien lo pide es el alumno antes de entregarle el código.</p>
                    <div class="table-responsive"><table class="table table-sm table-hover">
                        <thead><tr><th>Alumno</th><th>Curso</th><th>Acción</th></tr></thead>
                        <tbody>
                        <?php foreach ($pedidos as $s): ?>
                            <tr>
                                <td><?php echo e($s['nombre']); ?><br><small class="text-muted">DNI: <?php echo e($s['dni'] ?? ''); ?> · <?php echo e(date('d/m H:i', strtotime($s['pedida_en']))); ?></small></td>
                                <td><?php echo e($cursoNombre[(int) $s['curso_id']] ?? ''); ?></td>
                                <td><div class="d-flex flex-wrap gap-1">
                                    <form method="post" class="d-inline"><?php echo csrf_campo(); ?>
                                        <input type="hidden" name="accion" value="codigo"><input type="hidden" name="usuario_id" value="<?php echo (int) $s['usuario_id']; ?>">
                                        <button class="btn btn-primary btn-sm"><i class="bi bi-key"></i> Generar</button>
                                    </form>
                                    <form method="post" class="d-inline"><?php echo csrf_campo(); ?>
                                        <input type="hidden" name="accion" value="descartar"><input type="hidden" name="usuario_id" value="<?php echo (int) $s['usuario_id']; ?>">
                                        <input type="hidden" name="solicitud_id" value="<?php echo (int) $s['id']; ?>">
                                        <button class="btn btn-outline-danger btn-sm"><i class="bi bi-x-circle"></i> Descartar</button>
                                    </form>
                                </div></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                <?php endif; ?>
            <?php finTarjeta(); ?>
        </div>
    </div>

    <?php
    $botonCodigos = '';
    if ($sinActivar) {
        $botonCodigos = '<form method="post" onsubmit="return confirm(\'Se generan códigos nuevos para los ' . $sinActivar . ' alumnos sin activar. Los códigos anteriores dejan de servir. ¿Continuar?\');">'
            . csrf_campo() . '<input type="hidden" name="accion" value="codigos_curso"><input type="hidden" name="curso_id" value="' . $cursoSel . '">'
            . '<button class="btn btn-light btn-sm"><i class="bi bi-printer"></i> Generar códigos para imprimir</button></form>';
    }
    tarjeta($cursoNombre[$cursoSel] ?? 'Curso', 'bi-people', count($alumnos) . ' alumno(s), ' . $sinActivar . ' sin activar', $botonCodigos, 'no-imprimir');
    ?>
        <?php if (!$alumnos): ?>
            <?php sinDatos('Todavía no hay alumnos cargados en este curso.'); ?>
        <?php else: ?>
            <div class="table-responsive"><table class="table table-hover">
                <thead><tr><th>Alumno</th><th>DNI</th><th>Estado</th><th>Último ingreso</th><th>Acciones</th></tr></thead>
                <tbody>
                <?php foreach ($alumnos as $a): [$txt, $cls] = estadoCuenta($a); ?>
                    <tr>
                        <td><strong><?php echo e($a['nombre']); ?></strong></td>
                        <td><?php echo e($a['dni']); ?></td>
                        <td><span class="badge <?php echo $cls; ?>"><?php echo $txt; ?></span></td>
                        <td><?php echo $a['ultimo_ingreso'] ? e(date('d/m/Y H:i', strtotime($a['ultimo_ingreso']))) : '—'; ?></td>
                        <td>
                            <?php if ($a['estado'] === 'bloqueado'): ?>
                                <small class="text-muted fst-italic">Suspendida por un directivo</small>
                            <?php else: ?>
                                <form method="post" class="d-inline"><?php echo csrf_campo(); ?>
                                    <input type="hidden" name="accion" value="codigo"><input type="hidden" name="usuario_id" value="<?php echo (int) $a['id']; ?>">
                                    <button class="btn btn-outline-primary btn-sm"><i class="bi bi-key"></i> <?php echo $a['estado'] === 'activo' ? 'Código de recuperación' : 'Código de alta'; ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    <?php finTarjeta(); ?>
<?php endif; ?>

<?php piePanel(); ?>
