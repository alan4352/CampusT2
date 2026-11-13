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
require_once 'includes/academico.php';   // incluye cuentas.php
require_once 'includes/panel.php';

requerirPermiso('cuentas');

$pdo = bdAcademico();

/** Si el profesor nuevo coincide con docentes de la planilla, avisa para vincularlo. */
function avisarDocentesSugeridos(PDO $pdo, int $usuarioId): void {
    $sugeridos = sugerenciasParaProfesor($pdo, $usuarioId);
    if ($sugeridos) {
        avisar('exito', 'Esta persona podría ser «' . implode('», «', array_column($sugeridos, 'docente_nombre'))
            . '» en la planilla de asignaciones. Confírmelo en Estructura académica → Docentes.');
    }
}
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
                if ($rol === 'profesor') {
                    avisarDocentesSugeridos($pdo, $nuevo);
                }
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
                if ($rol === 'profesor') {
                    avisarDocentesSugeridos($pdo, $uid);
                }
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

            case 'preceptor_curso':      // tabla «Preceptores por curso»: agregar o quitar uno
                $cid = (int) ($_POST['curso_id'] ?? 0);
                $poner = ($_POST['modo'] ?? '') === 'agregar';
                if ($poner && !$uid) {
                    throw new RuntimeException('Elija el preceptor.');
                }
                asignarCursoPreceptor($pdo, $uid, $cid, $poner);
                auditarCuentas(($poner ? 'asignó' : 'quitó') . " el curso $cid " . ($poner ? 'al' : 'del') . " preceptor $uid");
                avisar('exito', $poner ? 'Preceptor asignado al curso.' : 'Preceptor quitado del curso.');
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
    $bloq = $u['estado'] === 'bloqueado';
    ?>
    <div class="d-flex flex-wrap gap-1">
        <form method="post" class="d-inline"><?php echo csrf_campo(); ?>
            <input type="hidden" name="accion" value="codigo"><input type="hidden" name="usuario_id" value="<?php echo (int) $u['id']; ?>">
            <button class="btn btn-outline-primary btn-sm" <?php echo $bloq ? 'disabled' : ''; ?>><i class="bi bi-key"></i> <?php echo $tieneClave ? 'Código de recuperación' : 'Código de alta'; ?></button>
        </form>
        <?php if ((int) $u['id'] !== $yo): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('¿<?php echo $bloq ? 'Reactivar' : 'Suspender'; ?> esta cuenta?');"><?php echo csrf_campo(); ?>
                <input type="hidden" name="accion" value="<?php echo $bloq ? 'desbloquear' : 'bloquear'; ?>">
                <input type="hidden" name="usuario_id" value="<?php echo (int) $u['id']; ?>">
                <button class="btn btn-sm <?php echo $bloq ? 'btn-outline-success' : 'btn-outline-danger'; ?>"><i class="bi <?php echo $bloq ? 'bi-unlock' : 'bi-lock'; ?>"></i> <?php echo $bloq ? 'Reactivar' : 'Suspender'; ?></button>
            </form>
        <?php endif; ?>
    </div>
    <?php
}

function chipsRoles(string $roles): string {
    $html = '';
    foreach (array_filter(explode(',', $roles)) as $r) {
        $html .= '<span class="profesor-badge">' . e(ETIQUETAS_ROL[$r] ?? $r) . '</span>';
    }
    return $html;
}

function opcionesCursos(array $cursos, int $elegido = 0): string {
    $html = '';
    foreach ($cursos as $c) {
        $html .= '<option value="' . (int) $c['id'] . '"' . ((int) $c['id'] === $elegido ? ' selected' : '') . '>' . e(nombreCurso($c)) . '</option>';
    }
    return $html;
}

$encabezados = [
    'personal'    => ['Personal', 'bi-person-badge', 'Cree las cuentas de profesores, preceptores y directivos, y entrégueles su código de alta.'],
    'alumnos'     => ['Alumnos', 'bi-people', 'Alumnos por curso: cuentas, códigos y cambios de curso.'],
    'preceptores' => ['Preceptores por curso', 'bi-person-gear', 'Quién atiende cada curso, y los cursos que todavía no tienen preceptor.'],
    'pedidos'     => ['Pedidos de recuperación', 'bi-key', 'Personas que pidieron recuperar su cuenta.'],
];
[$tit, $ico, $sub] = $encabezados[$ver];
encabezadoPanel($tit, $ico, $sub, "cuentas:$ver", ['cuentas:pedidos' => count($pedidos)]);
?>

<?php if ($ver === 'personal'): ?>
    <?php tarjeta('Crear cuenta de personal', 'bi-person-plus', '', '', 'no-imprimir'); ?>
        <form method="post" class="row g-3 align-items-end"><?php echo csrf_campo(); ?>
            <input type="hidden" name="accion" value="crear">
            <div class="col-md-2">
                <label class="form-label" for="rol">Rol</label>
                <select id="rol" name="rol" class="form-select" required>
                    <option value="profesor">Profesor/a</option>
                    <option value="preceptor">Preceptor/a</option>
                    <option value="directivo">Directivo/a</option>
                </select>
            </div>
            <div class="col-md-3"><label class="form-label" for="nombre">Nombre y apellido</label><input id="nombre" name="nombre" class="form-control" maxlength="150" required></div>
            <div class="col-md-2"><label class="form-label" for="dni">DNI</label><input id="dni" name="dni" class="form-control" inputmode="numeric" maxlength="8" pattern="[0-9]{7,8}" placeholder="Sin puntos"></div>
            <div class="col-md-3"><label class="form-label" for="correo">Correo</label><input id="correo" type="email" name="correo" class="form-control" maxlength="150" required></div>
            <div class="col-md-2"><button class="btn btn-primary w-100"><i class="bi bi-key"></i> Crear y generar código</button></div>
        </form>
        <p class="mt-3 mb-0 small">La persona elige su contraseña con el código. Si ya tiene cuenta (por ejemplo, un profesor que también es preceptor), agréguele el rol en la tabla.</p>
    <?php finTarjeta(); ?>

    <?php
    $personal = array_filter(listarCuentas($pdo, '', $buscar), fn($u) => trim(str_replace('alumno', '', (string) $u['roles']), ',') !== '');
    $buscador = '<form method="get" class="d-flex gap-2"><input type="hidden" name="ver" value="personal">'
              . '<input name="q" class="form-control form-control-sm" value="' . e($buscar) . '" placeholder="Nombre, DNI o correo" aria-label="Buscar personal">'
              . '<button class="btn btn-light btn-sm"><i class="bi bi-search"></i></button></form>';
    tarjeta('Personal', 'bi-person-badge', '(' . count($personal) . ')', $buscador, 'no-imprimir');
    ?>
        <?php if (!$personal): ?>
            <?php sinDatos('No hay cuentas de personal' . ($buscar !== '' ? ' que coincidan' : '') . '.'); ?>
        <?php else: ?>
            <div class="table-responsive"><table class="table table-hover">
                <thead><tr><th>Nombre</th><th>DNI</th><th>Correo</th><th>Roles</th><th>Estado</th><th>Último ingreso</th><th>Acciones</th></tr></thead>
                <tbody>
                <?php foreach ($personal as $u): [$txt, $cls] = estadoCuenta($u); ?>
                    <tr>
                        <td><strong><?php echo e($u['nombre']); ?></strong></td>
                        <td><?php echo e($u['dni'] ?? ''); ?></td>
                        <td><?php echo e($u['correo'] ?? ''); ?></td>
                        <td><?php echo chipsRoles((string) $u['roles']); ?></td>
                        <td><span class="badge <?php echo $cls; ?>"><?php echo $txt; ?></span></td>
                        <td><?php echo $u['ultimo_ingreso'] ? e(date('d/m/Y H:i', strtotime($u['ultimo_ingreso']))) : '—'; ?></td>
                        <td>
                            <?php botonesCuenta($u, $yo); ?>
                            <?php $faltan = array_filter(['profesor', 'preceptor', 'directivo'], fn($r) => strpos((string) $u['roles'], $r) === false); ?>
                            <?php if ($faltan): ?>
                                <form method="post" class="d-flex gap-1 mt-1"><?php echo csrf_campo(); ?>
                                    <input type="hidden" name="accion" value="agregar_rol"><input type="hidden" name="usuario_id" value="<?php echo (int) $u['id']; ?>">
                                    <select name="rol" class="form-select form-select-sm" aria-label="Rol para agregar">
                                        <?php foreach ($faltan as $r): ?><option value="<?php echo $r; ?>"><?php echo ETIQUETAS_ROL[$r]; ?></option><?php endforeach; ?>
                                    </select>
                                    <button class="btn btn-outline-primary btn-sm text-nowrap"><i class="bi bi-plus"></i> Rol</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    <?php finTarjeta(); ?>

<?php elseif ($ver === 'alumnos'): ?>
    <?php tarjeta('Crear cuenta de alumno', 'bi-person-plus', '', '', 'no-imprimir'); ?>
        <form method="post" class="row g-3 align-items-end"><?php echo csrf_campo(); ?>
            <input type="hidden" name="accion" value="crear"><input type="hidden" name="rol" value="alumno">
            <div class="col-md-4"><label class="form-label" for="nombre">Nombre y apellido</label><input id="nombre" name="nombre" class="form-control" maxlength="150" required></div>
            <div class="col-md-2"><label class="form-label" for="dni">DNI</label><input id="dni" name="dni" class="form-control" inputmode="numeric" maxlength="8" pattern="[0-9]{7,8}" required placeholder="Sin puntos"></div>
            <div class="col-md-3"><label class="form-label" for="curso_id">Curso</label><select id="curso_id" name="curso_id" class="form-select" required><?php echo opcionesCursos($cursos, $cursoF); ?></select></div>
            <div class="col-md-3"><button class="btn btn-primary w-100"><i class="bi bi-key"></i> Crear y generar código</button></div>
        </form>
        <p class="mt-3 mb-0 small">Normalmente lo hace el preceptor de cada curso desde su panel.</p>
    <?php finTarjeta(); ?>

    <?php
    $filtroAlumnos = '<form method="get" class="d-flex flex-wrap gap-2"><input type="hidden" name="ver" value="alumnos">'
        . '<select name="curso" class="form-select form-select-sm" aria-label="Curso"><option value="0">Todos los cursos</option>'
        . '<option value="-1"' . ($cursoF === -1 ? ' selected' : '') . '>Sin curso asignado</option>' . opcionesCursos($cursos, $cursoF) . '</select>'
        . '<input name="q" class="form-control form-control-sm" value="' . e($buscar) . '" placeholder="Nombre o DNI" aria-label="Buscar alumno">'
        . '<button class="btn btn-light btn-sm"><i class="bi bi-search"></i> Ver</button></form>';
    tarjeta('Alumnos', 'bi-people', '', $filtroAlumnos, 'no-imprimir');

    $sinCurso = count(alumnosDeCurso($pdo, null));
    if ($sinCurso && $cursoF !== -1): ?>
        <div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> Hay <strong><?php echo $sinCurso; ?> alumno(s) sin curso</strong>: no aparecen en el panel de ningún preceptor.
            <a href="cuentas.php?ver=alumnos&amp;curso=-1">Asignarles curso →</a></div>
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
            <?php sinDatos('Elija un curso o busque por nombre o DNI.'); ?>
        <?php elseif (!$alumnos): ?>
            <?php sinDatos('No hay alumnos para mostrar.'); ?>
        <?php else: ?>
            <div class="d-flex flex-wrap gap-3 align-items-end mb-3">
                <?php if ($cursoF > 0): ?>
                    <form method="post" onsubmit="return confirm('Se generan códigos nuevos para todos los alumnos sin activar de este curso. Los códigos anteriores dejan de servir. ¿Continuar?');"><?php echo csrf_campo(); ?>
                        <input type="hidden" name="accion" value="codigos_curso"><input type="hidden" name="curso_id" value="<?php echo $cursoF; ?>">
                        <button class="btn btn-primary btn-sm"><i class="bi bi-printer"></i> Generar códigos para los que no activaron</button>
                    </form>
                <?php endif; ?>
                <!-- Las casillas de la tabla pertenecen a este formulario (atributo form) -->
                <form method="post" id="mover-curso" class="d-flex flex-wrap gap-2 align-items-center"><?php echo csrf_campo(); ?>
                    <input type="hidden" name="accion" value="cambiar_curso">
                    <label class="form-label mb-0" for="mover-destino">Pasar los marcados a</label>
                    <select id="mover-destino" name="curso_id" class="form-select form-select-sm w-auto" required>
                        <option value="">Elegir curso…</option><?php echo opcionesCursos($cursos); ?>
                    </select>
                    <button class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-left-right"></i> Cambiar curso</button>
                </form>
            </div>
            <div class="table-responsive"><table class="table table-hover">
                <thead><tr>
                    <th><input type="checkbox" class="form-check-input" aria-label="Marcar todos" onclick="document.querySelectorAll('.marca-alumno').forEach(c => c.checked = this.checked)"></th>
                    <th>Nombre</th><th>DNI</th><th>Curso</th><th>Estado</th><th>Último ingreso</th><th>Acciones</th>
                </tr></thead>
                <tbody>
                <?php foreach ($alumnos as $u): [$txt, $cls] = estadoCuenta($u); $cid = (int) ($u['curso_id'] ?? 0); ?>
                    <tr>
                        <td><input type="checkbox" class="form-check-input marca-alumno" name="alumnos[]" value="<?php echo (int) $u['id']; ?>" form="mover-curso" aria-label="Marcar a <?php echo e($u['nombre']); ?>"></td>
                        <td><strong><?php echo e($u['nombre']); ?></strong></td>
                        <td><?php echo e($u['dni'] ?? ''); ?></td>
                        <td><?php echo e($cursoNombre[$cid] ?? 'Sin curso'); ?></td>
                        <td><span class="badge <?php echo $cls; ?>"><?php echo $txt; ?></span></td>
                        <td><?php echo $u['ultimo_ingreso'] ? e(date('d/m/Y H:i', strtotime($u['ultimo_ingreso']))) : '—'; ?></td>
                        <td><?php botonesCuenta($u, $yo); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    <?php finTarjeta(); ?>

<?php elseif ($ver === 'preceptores'): ?>
    <?php
    $preceptores = array_filter(listarCuentas($pdo, 'preceptor'), fn($p) => $p['estado'] !== 'bloqueado');
    $porCurso = [];
    foreach ($pdo->query('SELECT pc.curso_id, u.id, u.nombre FROM preceptor_cursos pc JOIN usuarios u ON u.id = pc.usuario_id ORDER BY u.nombre') as $r) {
        $porCurso[(int) $r['curso_id']][(int) $r['id']] = $r['nombre'];
    }
    $alumnosPor = $pdo->query("SELECT a.curso_id, COUNT(*) FROM alumnos a JOIN usuario_roles r ON r.usuario_id = a.usuario_id AND r.rol = 'alumno'
                               WHERE a.curso_id IS NOT NULL GROUP BY a.curso_id")->fetchAll(PDO::FETCH_KEY_PAIR);
    $cuentaPrec = [];
    foreach ($porCurso as $ps) {
        foreach ($ps as $pid => $_) {
            $cuentaPrec[$pid] = ($cuentaPrec[$pid] ?? 0) + 1;
        }
    }
    $sinPrec = count(array_filter($cursos, fn($c) => empty($porCurso[(int) $c['id']])));
    tarjeta('Preceptores por curso', 'bi-person-gear', $sinPrec ? "$sinPrec curso(s) sin preceptor" : 'todos los cursos tienen preceptor', '', 'no-imprimir');
    ?>
        <?php if (!$preceptores): ?>
            <?php sinDatos('Todavía no hay preceptores. Créelos en <a href="cuentas.php?ver=personal">Personal</a>.'); ?>
        <?php else: ?>
            <p class="small">Un curso puede tener más de un preceptor. Entre paréntesis, cuántos cursos atiende ya cada uno.</p>
            <div class="table-responsive"><table class="table table-sm table-hover">
                <thead><tr><th>Curso</th><th class="text-center">Alumnos</th><th>Preceptor</th><th>Asignar</th></tr></thead>
                <tbody>
                <?php foreach ($cursos as $c): $cid = (int) $c['id']; $ps = $porCurso[$cid] ?? []; ?>
                    <tr>
                        <td class="text-nowrap"><strong><?php echo e(nombreCurso($c)); ?></strong></td>
                        <td class="text-center"><?php echo (int) ($alumnosPor[$cid] ?? 0); ?></td>
                        <td>
                            <?php if (!$ps): ?><span class="badge bg-warning">Sin preceptor</span><?php endif; ?>
                            <?php foreach ($ps as $pid => $pn): ?>
                                <form method="post" class="d-inline-flex align-items-center gap-1 me-2 mb-1" onsubmit="return confirm('¿Quitar a <?php echo e(addslashes($pn)); ?> de este curso?');"><?php echo csrf_campo(); ?>
                                    <input type="hidden" name="accion" value="preceptor_curso"><input type="hidden" name="modo" value="quitar">
                                    <input type="hidden" name="curso_id" value="<?php echo $cid; ?>"><input type="hidden" name="usuario_id" value="<?php echo $pid; ?>">
                                    <span class="profesor-badge"><?php echo e($pn); ?>
                                        <button class="btn btn-link btn-sm p-0 ms-1 text-danger" title="Quitar" aria-label="Quitar a <?php echo e($pn); ?> de <?php echo e(nombreCurso($c)); ?>"><i class="bi bi-x-circle"></i></button></span>
                                </form>
                            <?php endforeach; ?>
                        </td>
                        <td>
                            <?php $libres = array_filter($preceptores, fn($p) => !isset($ps[(int) $p['id']])); ?>
                            <?php if ($libres): ?>
                                <form method="post" class="d-flex gap-1"><?php echo csrf_campo(); ?>
                                    <input type="hidden" name="accion" value="preceptor_curso"><input type="hidden" name="modo" value="agregar">
                                    <input type="hidden" name="curso_id" value="<?php echo $cid; ?>">
                                    <select name="usuario_id" class="form-select form-select-sm" required aria-label="Preceptor para <?php echo e(nombreCurso($c)); ?>">
                                        <option value="">Elegir…</option>
                                        <?php foreach ($libres as $p): ?><option value="<?php echo (int) $p['id']; ?>"><?php echo e($p['nombre']) . ' (' . (int) ($cuentaPrec[(int) $p['id']] ?? 0) . ')'; ?></option><?php endforeach; ?>
                                    </select>
                                    <button class="btn btn-outline-primary btn-sm"><i class="bi bi-plus"></i> Asignar</button>
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
    <?php tarjeta('Pedidos de recuperación pendientes', 'bi-key', '(' . count($pedidos) . ')', '', 'no-imprimir'); ?>
        <p>Antes de entregar el código, verifique que quien lo pide es la persona de la cuenta.</p>
        <?php if (!$pedidos): ?>
            <?php sinDatos('No hay pedidos pendientes.'); ?>
        <?php else: ?>
            <div class="table-responsive"><table class="table table-hover">
                <thead><tr><th>Nombre</th><th>DNI</th><th>Rol</th><th>Curso</th><th>Pedido</th><th>Acciones</th></tr></thead>
                <tbody>
                <?php foreach ($pedidos as $s): ?>
                    <tr>
                        <td><strong><?php echo e($s['nombre']); ?></strong></td>
                        <td><?php echo e($s['dni'] ?? ''); ?></td>
                        <td><?php echo chipsRoles((string) $s['roles']); ?></td>
                        <td><?php echo e($s['curso_id'] ? ($cursoNombre[(int) $s['curso_id']] ?? '') : ''); ?></td>
                        <td><?php echo e(date('d/m/Y H:i', strtotime($s['pedida_en']))); ?></td>
                        <td><div class="d-flex flex-wrap gap-1">
                            <form method="post" class="d-inline"><?php echo csrf_campo(); ?>
                                <input type="hidden" name="accion" value="codigo"><input type="hidden" name="usuario_id" value="<?php echo (int) $s['usuario_id']; ?>">
                                <button class="btn btn-primary btn-sm"><i class="bi bi-key"></i> Generar código</button>
                            </form>
                            <form method="post" class="d-inline"><?php echo csrf_campo(); ?>
                                <input type="hidden" name="accion" value="descartar"><input type="hidden" name="solicitud_id" value="<?php echo (int) $s['id']; ?>">
                                <button class="btn btn-outline-danger btn-sm"><i class="bi bi-x-circle"></i> Descartar</button>
                            </form>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    <?php finTarjeta(); ?>
<?php endif; ?>

<?php piePanel(); ?>
