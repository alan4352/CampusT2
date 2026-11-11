<?php
/* =====================================================================
   ARCHIVO: cuentas.php  (solo DIRECTIVOS)
   PARA QUÉ SIRVE: gestión de todas las cuentas del campus.
     - Personal: crear profesores, preceptores y directivos y darles su
       código de alta; suspender cuentas; agregar roles.
     - Alumnos: ver por curso, crear y generar códigos (también lo hace
       el preceptor de cada curso desde preceptor.php).
     - Preceptores: qué cursos atiende cada uno.
     - Pedidos: recuperaciones pendientes.
   Toda acción va por POST con token CSRF y queda en el log del servidor.
   ===================================================================== */
require_once 'includes/sesion.php';
require_once 'includes/cuentas.php';
require_once 'includes/panel.php';

requerirPermiso('cuentas');

$pdo = bdCuentas();
$yo  = (int) ($_SESSION['usuario_id'] ?? 0);

$secciones = ['personal' => 'Personal', 'alumnos' => 'Alumnos', 'preceptores' => 'Cursos de preceptores', 'pedidos' => 'Pedidos de recuperación'];
$ver    = array_key_exists($_GET['ver'] ?? '', $secciones) ? $_GET['ver'] : 'personal';
$buscar = trim((string) ($_GET['q'] ?? ''));
$cursoF = (int) ($_GET['curso'] ?? 0);
$volver = 'cuentas.php?' . http_build_query(array_filter(['ver' => $ver, 'q' => $buscar, 'curso' => $cursoF ?: null]));

/* ------------------------------ ACCIONES ------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valido()) {
        avisar('error', 'La página venció. Vuelva a intentarlo.');
        redirigir($volver);
    }
    $accion = (string) ($_POST['accion'] ?? '');
    $uid    = (int) ($_POST['usuario_id'] ?? 0);
    try {
        switch ($accion) {
            case 'crear':
                $rol = (string) ($_POST['rol'] ?? '');
                $nuevo = crearCuenta($pdo, $_POST, $rol);
                $c = generarCodigoAcceso($pdo, $nuevo, 'alta', $yo);
                $u = buscarUsuarioPor($pdo, 'id', $nuevo);
                guardarCodigosParaMostrar([$c + ['nombre' => $u['nombre'], 'dni' => $u['dni']]]);
                auditarCuentas("creó la cuenta de {$u['nombre']} ({$rol}) y generó su código de alta");
                avisar('exito', 'Cuenta creada. Entréguele el código para que elija su contraseña.');
                break;

            case 'codigo':
                $u = buscarUsuarioPor($pdo, 'id', $uid);
                if (!$u) {
                    throw new RuntimeException('La cuenta no existe.');
                }
                $c = generarCodigoAcceso($pdo, $uid, $u['clave_hash'] ? 'recuperacion' : 'alta', $yo);
                marcarSolicitudesAtendidas($pdo, $uid, $yo);
                guardarCodigosParaMostrar([$c + ['nombre' => $u['nombre'], 'dni' => $u['dni']]]);
                auditarCuentas("generó un código de {$c['tipo']} para {$u['nombre']}");
                break;

            case 'codigos_curso':
                $generados = [];
                foreach (alumnosDeCurso($pdo, (int) ($_POST['curso_id'] ?? 0)) as $a) {
                    if ($a['estado'] === 'pendiente') {
                        $generados[] = generarCodigoAcceso($pdo, (int) $a['id'], 'alta', $yo) + ['nombre' => $a['nombre'], 'dni' => $a['dni']];
                    }
                }
                if (!$generados) {
                    throw new RuntimeException('No hay alumnos sin activar en ese curso.');
                }
                guardarCodigosParaMostrar($generados);
                auditarCuentas('generó ' . count($generados) . ' códigos de alta para el curso ' . (int) $_POST['curso_id']);
                break;

            case 'bloquear':
            case 'desbloquear':
                if ($uid === $yo) {
                    throw new RuntimeException('No puede suspender su propia cuenta.');
                }
                cambiarEstadoCuenta($pdo, $uid, $accion === 'bloquear' ? 'bloqueado' : 'desbloqueado');
                auditarCuentas(($accion === 'bloquear' ? 'suspendió' : 'reactivó') . " la cuenta $uid");
                avisar('exito', $accion === 'bloquear' ? 'Cuenta suspendida: ya no puede ingresar.' : 'Cuenta reactivada.');
                break;

            case 'agregar_rol':
                $rol = (string) ($_POST['rol'] ?? '');
                agregarRolACuenta($pdo, $uid, $rol, (int) ($_POST['curso_id'] ?? 0));
                auditarCuentas("agregó el rol $rol a la cuenta $uid");
                avisar('exito', 'Rol agregado. Se aplica la próxima vez que esa persona inicie sesión.');
                break;

            case 'asignar_cursos':
                $elegidos = array_map('intval', (array) ($_POST['cursos'] ?? []));
                $actuales = array_map(fn($c) => (int) $c['id'], cursosDePreceptor($pdo, $uid));
                foreach (array_diff($elegidos, $actuales) as $cid) {
                    asignarCursoPreceptor($pdo, $uid, $cid, true);
                }
                foreach (array_diff($actuales, $elegidos) as $cid) {
                    asignarCursoPreceptor($pdo, $uid, $cid, false);
                }
                auditarCuentas("actualizó los cursos del preceptor $uid");
                avisar('exito', 'Cursos del preceptor actualizados.');
                break;

            case 'cambiar_curso':
                $destino = (int) ($_POST['curso_id'] ?? 0);
                $movidos = cambiarCursoAlumnos($pdo, (array) ($_POST['alumnos'] ?? []), $destino);
                auditarCuentas("pasó $movidos alumno(s) al curso $destino");
                avisar('exito', $movidos === 1 ? 'Se cambió el curso de 1 alumno.' : "Se cambió el curso de $movidos alumnos.");
                break;

            case 'descartar':
                descartarSolicitud($pdo, (int) ($_POST['solicitud_id'] ?? 0), $yo);
                avisar('exito', 'Pedido descartado.');
                break;

            default:
                throw new RuntimeException('Acción desconocida.');
        }
    } catch (RuntimeException $ex) {
        avisar('error', $ex->getMessage());
    } catch (PDOException $ex) {
        error_log('cuentas.php: ' . $ex->getMessage());
        avisar('error', 'Error en la base de datos. Intente nuevamente.');
    }
    redirigir($volver);
}

/* ------------------------------ DATOS ------------------------------ */
$cursos = listarCursos($pdo);
$cursoNombre = [];
foreach ($cursos as $c) {
    $cursoNombre[(int) $c['id']] = nombreCurso($c);
}
$pedidos = solicitudesPendientes($pdo);

function botonesCuenta(array $u, int $yo): void {
    $tieneClave = $u['estado'] !== 'pendiente';
    ?>
    <div class="acciones">
        <form method="post"><?php echo csrf_campo(); ?>
            <input type="hidden" name="accion" value="codigo"><input type="hidden" name="usuario_id" value="<?php echo (int) $u['id']; ?>">
            <button class="boton-mini" <?php echo $u['estado'] === 'bloqueado' ? 'disabled' : ''; ?>><?php echo $tieneClave ? 'Código de recuperación' : 'Código de alta'; ?></button>
        </form>
        <?php if ((int) $u['id'] !== $yo): ?>
            <form method="post" onsubmit="return confirm('¿<?php echo $u['estado'] === 'bloqueado' ? 'Reactivar' : 'Suspender'; ?> esta cuenta?');"><?php echo csrf_campo(); ?>
                <input type="hidden" name="accion" value="<?php echo $u['estado'] === 'bloqueado' ? 'desbloquear' : 'bloquear'; ?>">
                <input type="hidden" name="usuario_id" value="<?php echo (int) $u['id']; ?>">
                <button class="boton-mini <?php echo $u['estado'] === 'bloqueado' ? '' : 'boton-mini--peligro'; ?>"><?php echo $u['estado'] === 'bloqueado' ? 'Reactivar' : 'Suspender'; ?></button>
            </form>
        <?php endif; ?>
    </div>
    <?php
}

function chipsRoles(string $roles): string {
    $html = '';
    foreach (array_filter(explode(',', $roles)) as $r) {
        $html .= '<span class="rol-chip">' . e(ETIQUETAS_ROL[$r] ?? $r) . '</span>';
    }
    return $html;
}

encabezadoPanel('Gestión de cuentas');
?>
<nav class="pestanas-gestion no-imprimir">
    <?php foreach ($secciones as $clave => $titulo): ?>
        <a href="cuentas.php?ver=<?php echo $clave; ?>" class="<?php echo $ver === $clave ? 'activa' : ''; ?>">
            <?php echo e($titulo); ?><?php if ($clave === 'pedidos' && $pedidos): ?><span class="contador"><?php echo count($pedidos); ?></span><?php endif; ?>
        </a>
    <?php endforeach; ?>
</nav>

<?php if ($ver === 'personal'): ?>
    <section class="caja no-imprimir">
        <h2>Crear cuenta de personal</h2>
        <form method="post" class="form-linea"><?php echo csrf_campo(); ?>
            <input type="hidden" name="accion" value="crear">
            <label>Rol
                <select name="rol" required>
                    <option value="profesor">Profesor/a</option>
                    <option value="preceptor">Preceptor/a</option>
                    <option value="directivo">Directivo/a</option>
                </select>
            </label>
            <label>Nombre y apellido <input name="nombre" maxlength="150" required></label>
            <label>DNI <input name="dni" inputmode="numeric" maxlength="8" pattern="[0-9]{7,8}" placeholder="Sin puntos"></label>
            <label>Correo <input type="email" name="correo" maxlength="150" required></label>
            <button class="boton">Crear y generar código</button>
        </form>
        <p style="margin-top:8px;">La persona elige su contraseña con el código. Si ya tiene cuenta (por ejemplo, un profesor que también es preceptor), agréguele el rol en la tabla.</p>
    </section>

    <section class="caja no-imprimir">
        <div class="caja__titulo">
            <h2>Personal</h2>
            <form method="get" class="form-linea"><input type="hidden" name="ver" value="personal">
                <input name="q" value="<?php echo e($buscar); ?>" placeholder="Buscar nombre, DNI o correo">
                <button class="boton-mini">Buscar</button>
            </form>
        </div>
        <?php
        $personal = array_filter(listarCuentas($pdo, '', $buscar), fn($u) => trim(str_replace('alumno', '', (string) $u['roles']), ',') !== '');
        ?>
        <?php if (!$personal): ?>
            <p class="vacio">No hay cuentas de personal<?php echo $buscar !== '' ? ' que coincidan' : ''; ?>.</p>
        <?php else: ?>
            <div class="tabla-scroll"><table class="tabla-gestion">
                <thead><tr><th>Nombre</th><th>DNI</th><th>Correo</th><th>Roles</th><th>Estado</th><th>Último ingreso</th><th>Acciones</th></tr></thead>
                <tbody>
                <?php foreach ($personal as $u): [$txt, $cls] = estadoCuenta($u); ?>
                    <tr>
                        <td><strong><?php echo e($u['nombre']); ?></strong></td>
                        <td><?php echo e($u['dni'] ?? ''); ?></td>
                        <td><?php echo e($u['correo'] ?? ''); ?></td>
                        <td><?php echo chipsRoles((string) $u['roles']); ?></td>
                        <td><span class="estado <?php echo $cls; ?>"><?php echo $txt; ?></span></td>
                        <td><?php echo $u['ultimo_ingreso'] ? e(date('d/m/Y H:i', strtotime($u['ultimo_ingreso']))) : '—'; ?></td>
                        <td>
                            <?php botonesCuenta($u, $yo); ?>
                            <form method="post" class="form-linea" style="margin-top:6px;"><?php echo csrf_campo(); ?>
                                <input type="hidden" name="accion" value="agregar_rol"><input type="hidden" name="usuario_id" value="<?php echo (int) $u['id']; ?>">
                                <select name="rol" style="min-width:0;">
                                    <?php foreach (['profesor', 'preceptor', 'directivo'] as $r): if (strpos((string) $u['roles'], $r) === false): ?>
                                        <option value="<?php echo $r; ?>"><?php echo ETIQUETAS_ROL[$r]; ?></option>
                                    <?php endif; endforeach; ?>
                                </select>
                                <button class="boton-mini">+ Rol</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </section>

<?php elseif ($ver === 'alumnos'): ?>
    <section class="caja no-imprimir">
        <h2>Crear cuenta de alumno</h2>
        <form method="post" class="form-linea"><?php echo csrf_campo(); ?>
            <input type="hidden" name="accion" value="crear"><input type="hidden" name="rol" value="alumno">
            <label>Nombre y apellido <input name="nombre" maxlength="150" required></label>
            <label>DNI <input name="dni" inputmode="numeric" maxlength="8" pattern="[0-9]{7,8}" required placeholder="Sin puntos"></label>
            <label>Curso
                <select name="curso_id" required>
                    <?php foreach ($cursos as $c): ?>
                        <option value="<?php echo (int) $c['id']; ?>" <?php echo (int) $c['id'] === $cursoF ? 'selected' : ''; ?>><?php echo e(nombreCurso($c)); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="boton">Crear y generar código</button>
        </form>
        <p style="margin-top:8px;">Normalmente lo hace el preceptor de cada curso desde su panel.</p>
    </section>

    <section class="caja no-imprimir">
        <div class="caja__titulo">
            <h2>Alumnos</h2>
            <form method="get" class="form-linea"><input type="hidden" name="ver" value="alumnos">
                <select name="curso">
                    <option value="0">Todos los cursos</option>
                    <option value="-1" <?php echo $cursoF === -1 ? 'selected' : ''; ?>>Sin curso asignado</option>
                    <?php foreach ($cursos as $c): ?>
                        <option value="<?php echo (int) $c['id']; ?>" <?php echo (int) $c['id'] === $cursoF ? 'selected' : ''; ?>><?php echo e(nombreCurso($c)); ?></option>
                    <?php endforeach; ?>
                </select>
                <input name="q" value="<?php echo e($buscar); ?>" placeholder="Nombre o DNI">
                <button class="boton-mini">Ver</button>
            </form>
        </div>
        <?php
        $sinCurso = count(alumnosDeCurso($pdo, null));
        if ($sinCurso && $cursoF !== -1): ?>
            <p class="aviso-codigos">Hay <strong><?php echo $sinCurso; ?> alumno(s) sin curso</strong>: no aparecen en el panel de ningún preceptor.
                <a href="cuentas.php?ver=alumnos&amp;curso=-1">Asignarles curso →</a></p>
        <?php endif;
        $alumnos = [];
        if ($cursoF) {
            $alumnos = alumnosDeCurso($pdo, $cursoF === -1 ? null : $cursoF);
            if ($buscar !== '') {
                $alumnos = array_filter($alumnos, fn($a) => stripos($a['nombre'] . ' ' . $a['dni'], $buscar) !== false);
            }
        } elseif ($buscar !== '') {
            $alumnos = listarCuentas($pdo, 'alumno', $buscar);
        }
        ?>
        <?php if (!$cursoF && $buscar === ''): ?>
            <p class="vacio">Elija un curso o busque por nombre o DNI.</p>
        <?php elseif (!$alumnos): ?>
            <p class="vacio">No hay alumnos para mostrar.</p>
        <?php else: ?>
            <?php if ($cursoF > 0): ?>
                <form method="post" style="margin-bottom:10px;" onsubmit="return confirm('Se generan códigos nuevos para todos los alumnos sin activar de este curso. Los códigos anteriores dejan de servir. ¿Continuar?');"><?php echo csrf_campo(); ?>
                    <input type="hidden" name="accion" value="codigos_curso"><input type="hidden" name="curso_id" value="<?php echo $cursoF; ?>">
                    <button class="boton boton--chico">Generar códigos para los que no activaron</button>
                </form>
            <?php endif; ?>
            <!-- Las casillas de la tabla pertenecen a este formulario (atributo form) -->
            <form method="post" id="mover-curso" class="form-linea" style="margin-bottom:10px;"><?php echo csrf_campo(); ?>
                <input type="hidden" name="accion" value="cambiar_curso">
                <label>Pasar los alumnos marcados a
                    <select name="curso_id" required>
                        <option value="">Elegir curso…</option>
                        <?php foreach ($cursos as $c): ?>
                            <option value="<?php echo (int) $c['id']; ?>"><?php echo e(nombreCurso($c)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button class="boton-mini">Cambiar curso</button>
            </form>
            <div class="tabla-scroll"><table class="tabla-gestion">
                <thead><tr>
                    <th><input type="checkbox" aria-label="Marcar todos" onclick="document.querySelectorAll('.marca-alumno').forEach(c => c.checked = this.checked)"></th>
                    <th>Nombre</th><th>DNI</th><th>Curso</th><th>Estado</th><th>Último ingreso</th><th>Acciones</th>
                </tr></thead>
                <tbody>
                <?php foreach ($alumnos as $u): [$txt, $cls] = estadoCuenta($u); $cid = (int) ($u['curso_id'] ?? 0); ?>
                    <tr>
                        <td><input type="checkbox" class="marca-alumno" name="alumnos[]" value="<?php echo (int) $u['id']; ?>" form="mover-curso" aria-label="Marcar a <?php echo e($u['nombre']); ?>"></td>
                        <td><strong><?php echo e($u['nombre']); ?></strong></td>
                        <td><?php echo e($u['dni'] ?? ''); ?></td>
                        <td><?php echo e($cursoNombre[$cid] ?? 'Sin curso'); ?></td>
                        <td><span class="estado <?php echo $cls; ?>"><?php echo $txt; ?></span></td>
                        <td><?php echo $u['ultimo_ingreso'] ? e(date('d/m/Y H:i', strtotime($u['ultimo_ingreso']))) : '—'; ?></td>
                        <td><?php botonesCuenta($u, $yo); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </section>

<?php elseif ($ver === 'preceptores'): ?>
    <?php $preceptores = listarCuentas($pdo, 'preceptor'); ?>
    <?php if (!$preceptores): ?>
        <section class="caja"><p class="vacio">Todavía no hay preceptores. Créelos en la pestaña «Personal».</p></section>
    <?php endif; ?>
    <?php foreach ($preceptores as $p): $asignados = array_map(fn($c) => (int) $c['id'], cursosDePreceptor($pdo, (int) $p['id'])); ?>
        <section class="caja no-imprimir">
            <h2><?php echo e($p['nombre']); ?> <small style="color:#6b7280;font-weight:400;"><?php echo e($p['correo'] ?? ''); ?> · <?php echo count($asignados); ?> curso(s)</small></h2>
            <form method="post"><?php echo csrf_campo(); ?>
                <input type="hidden" name="accion" value="asignar_cursos"><input type="hidden" name="usuario_id" value="<?php echo (int) $p['id']; ?>">
                <div class="cursos-grilla">
                    <?php foreach ($cursos as $c): ?>
                        <label><input type="checkbox" name="cursos[]" value="<?php echo (int) $c['id']; ?>" <?php echo in_array((int) $c['id'], $asignados, true) ? 'checked' : ''; ?>> <?php echo e(nombreCurso($c)); ?></label>
                    <?php endforeach; ?>
                </div>
                <button class="boton boton--chico">Guardar cursos</button>
            </form>
        </section>
    <?php endforeach; ?>

<?php else: ?>
    <section class="caja no-imprimir">
        <h2>Pedidos de recuperación pendientes</h2>
        <p>Antes de entregar el código, verifique que quien lo pide es la persona de la cuenta.</p>
        <?php if (!$pedidos): ?>
            <p class="vacio">No hay pedidos pendientes.</p>
        <?php else: ?>
            <div class="tabla-scroll"><table class="tabla-gestion">
                <thead><tr><th>Nombre</th><th>DNI</th><th>Rol</th><th>Curso</th><th>Pedido</th><th>Acciones</th></tr></thead>
                <tbody>
                <?php foreach ($pedidos as $s): ?>
                    <tr>
                        <td><strong><?php echo e($s['nombre']); ?></strong></td>
                        <td><?php echo e($s['dni'] ?? ''); ?></td>
                        <td><?php echo chipsRoles((string) $s['roles']); ?></td>
                        <td><?php echo e($s['curso_id'] ? ($cursoNombre[(int) $s['curso_id']] ?? '') : ''); ?></td>
                        <td><?php echo e(date('d/m/Y H:i', strtotime($s['pedida_en']))); ?></td>
                        <td><div class="acciones">
                            <form method="post"><?php echo csrf_campo(); ?>
                                <input type="hidden" name="accion" value="codigo"><input type="hidden" name="usuario_id" value="<?php echo (int) $s['usuario_id']; ?>">
                                <button class="boton-mini">Generar código</button>
                            </form>
                            <form method="post"><?php echo csrf_campo(); ?>
                                <input type="hidden" name="accion" value="descartar"><input type="hidden" name="solicitud_id" value="<?php echo (int) $s['id']; ?>">
                                <button class="boton-mini boton-mini--peligro">Descartar</button>
                            </form>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php piePanel(); ?>
