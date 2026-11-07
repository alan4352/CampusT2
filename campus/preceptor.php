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

encabezadoPanel('Alumnos y cuentas');
?>

<?php if (!$misCursos): ?>
    <section class="caja"><p class="vacio">Todavía no tiene cursos asignados. Pídale a un directivo que se los asigne en «Gestión de cuentas».</p></section>
<?php else: ?>

    <?php if ($pedidos): ?>
        <section class="caja no-imprimir">
            <h2>Pedidos de recuperación (<?php echo count($pedidos); ?>)</h2>
            <p>Verifique que quien lo pide es el alumno antes de entregarle el código.</p>
            <div class="tabla-scroll"><table class="tabla-gestion">
                <thead><tr><th>Alumno</th><th>DNI</th><th>Curso</th><th>Pedido</th><th>Acciones</th></tr></thead>
                <tbody>
                <?php foreach ($pedidos as $s): ?>
                    <tr>
                        <td><strong><?php echo e($s['nombre']); ?></strong></td>
                        <td><?php echo e($s['dni'] ?? ''); ?></td>
                        <td><?php echo e($cursoNombre[(int) $s['curso_id']] ?? ''); ?></td>
                        <td><?php echo e(date('d/m/Y H:i', strtotime($s['pedida_en']))); ?></td>
                        <td><div class="acciones">
                            <form method="post"><?php echo csrf_campo(); ?>
                                <input type="hidden" name="accion" value="codigo"><input type="hidden" name="usuario_id" value="<?php echo (int) $s['usuario_id']; ?>">
                                <button class="boton-mini">Generar código</button>
                            </form>
                            <form method="post"><?php echo csrf_campo(); ?>
                                <input type="hidden" name="accion" value="descartar"><input type="hidden" name="usuario_id" value="<?php echo (int) $s['usuario_id']; ?>">
                                <input type="hidden" name="solicitud_id" value="<?php echo (int) $s['id']; ?>">
                                <button class="boton-mini boton-mini--peligro">Descartar</button>
                            </form>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </section>
    <?php endif; ?>

    <nav class="pestanas-gestion no-imprimir">
        <?php foreach ($misCursos as $c): ?>
            <a href="preceptor.php?curso=<?php echo (int) $c['id']; ?>" class="<?php echo (int) $c['id'] === $cursoSel ? 'activa' : ''; ?>"><?php echo e(nombreCurso($c)); ?></a>
        <?php endforeach; ?>
    </nav>

    <section class="caja no-imprimir">
        <h2>Cargar alumno en <?php echo e($cursoNombre[$cursoSel] ?? ''); ?></h2>
        <form method="post" class="form-linea"><?php echo csrf_campo(); ?>
            <input type="hidden" name="accion" value="crear_alumno"><input type="hidden" name="curso_id" value="<?php echo $cursoSel; ?>">
            <label>Nombre y apellido <input name="nombre" maxlength="150" required></label>
            <label>DNI <input name="dni" inputmode="numeric" maxlength="8" pattern="[0-9]{7,8}" required placeholder="Sin puntos"></label>
            <button class="boton">Cargar y generar código</button>
        </form>
        <p style="margin-top:8px;">Si el DNI ya tiene cuenta (por ejemplo, cambió de curso), pídale a un directivo que lo actualice.</p>
    </section>

    <section class="caja no-imprimir">
        <div class="caja__titulo">
            <h2><?php echo e($cursoNombre[$cursoSel] ?? ''); ?> · <?php echo count($alumnos); ?> alumno(s), <?php echo $sinActivar; ?> sin activar</h2>
            <?php if ($sinActivar): ?>
                <form method="post" onsubmit="return confirm('Se generan códigos nuevos para los <?php echo $sinActivar; ?> alumnos sin activar. Los códigos anteriores dejan de servir. ¿Continuar?');"><?php echo csrf_campo(); ?>
                    <input type="hidden" name="accion" value="codigos_curso"><input type="hidden" name="curso_id" value="<?php echo $cursoSel; ?>">
                    <button class="boton boton--chico">Generar códigos para imprimir</button>
                </form>
            <?php endif; ?>
        </div>
        <?php if (!$alumnos): ?>
            <p class="vacio">Todavía no hay alumnos cargados en este curso.</p>
        <?php else: ?>
            <div class="tabla-scroll"><table class="tabla-gestion">
                <thead><tr><th>Alumno</th><th>DNI</th><th>Estado</th><th>Último ingreso</th><th>Acciones</th></tr></thead>
                <tbody>
                <?php foreach ($alumnos as $a): [$txt, $cls] = estadoCuenta($a); ?>
                    <tr>
                        <td><strong><?php echo e($a['nombre']); ?></strong></td>
                        <td><?php echo e($a['dni']); ?></td>
                        <td><span class="estado <?php echo $cls; ?>"><?php echo $txt; ?></span></td>
                        <td><?php echo $a['ultimo_ingreso'] ? e(date('d/m/Y H:i', strtotime($a['ultimo_ingreso']))) : '—'; ?></td>
                        <td>
                            <?php if ($a['estado'] === 'bloqueado'): ?>
                                <span class="vacio">Suspendida por un directivo</span>
                            <?php else: ?>
                                <form method="post"><?php echo csrf_campo(); ?>
                                    <input type="hidden" name="accion" value="codigo"><input type="hidden" name="usuario_id" value="<?php echo (int) $a['id']; ?>">
                                    <button class="boton-mini"><?php echo $a['estado'] === 'activo' ? 'Código de recuperación' : 'Código de alta'; ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php piePanel(); ?>
