<?php
/**
 * API de reservas — tabla `reservas` de MySQL.
 *
 *   GET    api_reservas.php?fecha=YYYY-MM-DD            → { proyectorId: { "HHMM": { id, materia, curso, nombre, puede_eliminar } } }
 *   POST   api_reservas.php  (JSON)                     → crea una reserva
 *          { fecha, proyId, horaInicio:"HH:MM", horaFin:"HH:MM", materia, curso, nombre }
 *   DELETE api_reservas.php?fecha=...&proy=...&slot=HHMM → elimina la reserva
 *
 * Requiere sesión del campus (ver conexion.php):
 *   · directivo → reserva a nombre de quien indique y puede cancelar cualquier reserva.
 *   · profesor  → el docente de la reserva es SIEMPRE su nombre (se toma de la base, el que
 *                 venga en el JSON se ignora) y solo puede cancelar las reservas a su nombre.
 *   · sin sesión / alumno → 401 / 401.
 *
 * Cada reserva es una fila: proyector_id, dia (fecha a las 00:00), hora_inicio, hora_fin,
 * estado_reserva = 'reservado', materia, curso, docente.
 */
ini_set('display_errors', '0');   // antes de cargar nada: un warning de includes/ no debe mezclarse con el JSON
require_once __DIR__ . '/conexion.php';

/** Cuántas semanas anteriores a la actual se conservan (la página muestra hasta 1 semana atrás). Lo anterior se borra solo. */
const RETENCION_SEMANAS = 1;

/** Zona horaria de la escuela (para saber qué turnos ya pasaron). */
const ZONA_HORARIA = 'America/Argentina/Buenos_Aires';

/** Materia: solo letras (con tildes, ñ, ü…), números, espacios y puntos. Misma regla que MATERIA_VALIDA en reserva_proyectores.php. */
const MATERIA_REGEX = '/^[\p{L}\p{M}0-9 .]+$/u';

/** Hasta cuántos días hacia adelante se puede reservar (la página muestra como mucho la semana siguiente). */
const RESERVA_DIAS_MAX = 14;

/** Bloques horarios [desde, hasta] — mismos que TURNOS en reserva_proyectores.php. Se dividen en turnos de 40 min. */
const BLOQUES_HORARIOS = [
    ['07:20', '08:40'], ['08:50', '10:10'], ['10:20', '11:40'], ['11:45', '13:05'],
    ['13:30', '14:50'], ['15:00', '16:20'], ['16:30', '17:50'], ['17:55', '19:05'],
];

/** Cursos permitidos (mismos valores que el desplegable de la página). */
const CURSOS_VALIDOS = [
    '1°1ra',
    '1°2da',
    '1°3ra',
    '1°4ta',
    '1°5ta',
    '2°1ra',
    '2°2da',
    '2°3ra',
    '2°4ta',
    '2°5ta',
    '3°1ra',
    '3°2da',
    '3°3ra',
    '3°4to',
    '4°1ra TC',
    '4°1ra TAE',
    '4°2da TAE',
    '4°1ra TG',
    '5°1ra TC',
    '5°1ra TAE',
    '5°2da TAE',
    '5°1ra TG',
    '6°1ra TC',
    '6°1ra TAE',
    '6°1ra TG',
    '7°1ra TC',
    '7°1ra TAE',
    '7°1ra TG',
];

function fecha_valida(string $f): bool
{
    $d = DateTime::createFromFormat('!Y-m-d', $f);
    return $d !== false && $d->format('Y-m-d') === $f;
}

function hora_valida(string $h): bool
{
    return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $h) === 1;
}

/** "07:20:00" → "0720" (el id de turno que usa la página). */
function clave_slot(string $hora): string
{
    return substr(str_replace(':', '', $hora), 0, 4);
}

/** "HH:MM" → minutos desde las 00:00. */
function minutos(string $h): int
{
    return (int) substr($h, 0, 2) * 60 + (int) substr($h, 3, 2);
}

/** Turnos válidos: ['07:20' => '08:00', ...] (igual que slotsFromBloques() de la página). */
function turnos_validos(): array
{
    static $t = null;
    if ($t !== null) {
        return $t;
    }
    $t = [];
    foreach (BLOQUES_HORARIOS as [$desde, $hasta]) {
        $i = minutos($desde);
        $fin = minutos($hasta);
        while ($i < $fin) {
            $f = min($i + 40, $fin);
            $t[sprintf('%02d:%02d', intdiv($i, 60), $i % 60)] = sprintf('%02d:%02d', intdiv($f, 60), $f % 60);
            $i = $f;
        }
    }
    return $t;
}

function ahora(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone(ZONA_HORARIA));
}

/** ¿El turno que termina a $horaFin del día $fecha ya pasó? */
function turno_terminado(string $fecha, string $horaFin): bool
{
    $n = ahora();
    $hoy = $n->format('Y-m-d');
    return $fecha < $hoy || ($fecha === $hoy && $horaFin <= $n->format('H:i'));
}

/** [inicio, fin) del día como texto, para filtrar `dia` con índice (en vez de DATE(dia) = ?, que no puede usarlo). */
function rango_dia(string $fecha): array
{
    $d = DateTime::createFromFormat('!Y-m-d', $fecha);
    return [$fecha . ' 00:00:00', $d->modify('+1 day')->format('Y-m-d') . ' 00:00:00'];
}

/**
 * Borra de la base las reservas que ya no se pueden ver en la página (anteriores al lunes de la semana pasada).
 * Corre como mucho UNA vez por día —la primera vez que alguien abre la página— y no necesita cron ni permisos de SQL.
 * Un fallo aquí nunca debe afectar a la petición que la disparó.
 */
function limpiar_reservas_viejas(PDO $pdo): void
{
    $marca = sys_get_temp_dir() . '/reservas_limpieza_' . md5(__DIR__) . '.txt';
    $hoy   = ahora()->format('Y-m-d');
    if (@file_get_contents($marca) === $hoy) {
        return;                                   // ya se hizo hoy
    }
    if (@file_put_contents($marca, $hoy, LOCK_EX) === false) {
        return;                                   // sin dónde anotar el día: mejor no repetirla en cada petición
    }
    try {
        $corte = ahora()->modify('monday this week')->modify('-' . RETENCION_SEMANAS . ' weeks')->format('Y-m-d') . ' 00:00:00';
        $st = $pdo->prepare('DELETE FROM reservas WHERE dia < ?');
        $st->execute([$corte]);
        if ($st->rowCount() > 0) {
            auditar(['nombre' => 'sistema', 'rol' => 'limpieza'], "borró {$st->rowCount()} reservas anteriores a $corte");
        }
    } catch (Throwable $e) {
        @unlink($marca);                          // que se reintente en la próxima petición
        error_log('api_reservas.php (limpieza): ' . $e->getMessage());
    }
}

/** Parámetro GET como string ('' si falta o no es texto). */
function param(string $k): string
{
    $v = $_GET[$k] ?? '';
    return is_string($v) ? $v : '';
}

/** Campo del JSON como string recortado. */
function campo(array $d, string $k): string
{
    return isset($d[$k]) && is_scalar($d[$k]) ? trim((string) $d[$k]) : '';
}

try {
    $pdo = conectar();
    $usuario = exigir_usuario_api();   // directivo o profesor; si no, responde 401 y termina
    $metodo = $_SERVER['REQUEST_METHOD'];

    /* ── GET: reservas de un día ─────────────────────────────── */
    if ($metodo === 'GET') {
        limpiar_reservas_viejas($pdo);   // 1 vez por día
        $fecha = param('fecha');
        if (!fecha_valida($fecha)) {
            responder_json(['error' => 'Fecha inválida'], 400);
        }
        $st = $pdo->prepare(
            "SELECT ID, proyector_id, docente_id, hora_inicio, materia, curso, docente
             FROM reservas
             WHERE dia >= ? AND dia < ? AND estado_reserva = 'reservado'"
        );
        $st->execute(rango_dia($fecha));

        $mapa = [];
        foreach ($st as $r) {
            $mapa[(string) $r['proyector_id']][clave_slot($r['hora_inicio'])] = [
                'id'      => (int) $r['ID'],
                'materia' => $r['materia'],
                'curso'   => $r['curso'],
                'nombre'  => $r['docente'],
                'puede_eliminar' => puede_eliminar_reserva($usuario, $r),
            ];
        }
        foreach ($mapa as $pid => $turnos) {   // objetos (no listas) aunque haya una sola clave
            $mapa[$pid] = (object) $turnos;
        }
        responder_json((object) $mapa);
    }

    /* ── POST: crear reserva ─────────────────────────────────── */
    if ($metodo === 'POST') {
        $d       = leer_json();
        $fecha   = campo($d, 'fecha');
        $proyId  = campo($d, 'proyId');
        $ini     = campo($d, 'horaInicio');
        $fin     = campo($d, 'horaFin');
        $materia = campo($d, 'materia');
        $curso   = campo($d, 'curso');
        // El profesor solo reserva a su nombre (el de la base de datos); el directivo escribe el que quiera.
        $nombre  = $usuario['rol'] === 'profesor' ? $usuario['nombre'] : campo($d, 'nombre');

        if ($fecha === '' || $proyId === '' || $ini === '' || $fin === '' || $materia === '' || $curso === '' || $nombre === '') {
            responder_json(['error' => 'Faltan campos requeridos'], 400);
        }
        if (!fecha_valida($fecha) || !ctype_digit($proyId) || !hora_valida($ini) || !hora_valida($fin) || $fin <= $ini) {
            responder_json(['error' => 'Datos inválidos'], 400);
        }
        if (!in_array($curso, CURSOS_VALIDOS, true)) {
            responder_json(['error' => 'Curso inválido'], 400);
        }
        if (mb_strlen($materia) > 200 || mb_strlen($curso) > 50 || mb_strlen($nombre) > 200) {
            responder_json(['error' => 'Algún campo supera el largo máximo'], 400);
        }
        // la materia no admite caracteres especiales (símbolos, emojis, etiquetas…; el punto sí); los espacios repetidos se unifican
        $materia = preg_replace('/ {2,}/', ' ', $materia) ?? $materia;
        if (preg_match(MATERIA_REGEX, $materia) !== 1) {
            responder_json(['error' => 'La materia solo puede contener letras, números, espacios y puntos'], 400);
        }
        // el horario debe ser uno de los turnos reales (no cualquier hora que mande el cliente)
        if ((turnos_validos()[$ini] ?? null) !== $fin) {
            responder_json(['error' => 'Horario inválido'], 400);
        }
        // fecha: día de semana, no pasada y dentro de la ventana permitida
        $dFecha = DateTime::createFromFormat('!Y-m-d', $fecha);
        if ((int) $dFecha->format('N') > 5) {
            responder_json(['error' => 'Solo se puede reservar de lunes a viernes'], 400);
        }
        if (turno_terminado($fecha, $fin)) {
            responder_json(['error' => 'Ese turno ya pasó'], 400);
        }
        if ($fecha > ahora()->modify('+' . RESERVA_DIAS_MAX . ' days')->format('Y-m-d')) {
            responder_json(['error' => 'Todavía no se puede reservar para esa fecha'], 400);
        }

        $pid = (int) $proyId;
        $st = $pdo->prepare('SELECT Estado FROM proyectores WHERE ID = ?');
        $st->execute([$pid]);
        $estado = $st->fetchColumn();
        if ($estado === false) {
            responder_json(['error' => 'Proyector no encontrado'], 404);
        }
        if ($estado !== 'operativo') {
            responder_json(['error' => 'El proyector está fuera de servicio'], 400);
        }

        // ¿ya está reservado ese turno?
        [$desde, $hasta] = rango_dia($fecha);
        $st = $pdo->prepare(
            "SELECT 1 FROM reservas
             WHERE proyector_id = ? AND dia >= ? AND dia < ? AND hora_inicio = ? AND estado_reserva = 'reservado' LIMIT 1"
        );
        $st->execute([$pid, $desde, $hasta, $ini . ':00']);
        if ($st->fetchColumn()) {
            responder_json(['error' => 'El turno ya está reservado', 'existing' => true], 409);
        }

        // limpiar filas 'disponible' del mismo turno para que no choquen con la clave única
        $pdo->prepare(
            "DELETE FROM reservas
             WHERE proyector_id = ? AND dia >= ? AND dia < ? AND hora_inicio = ? AND estado_reserva = 'disponible'"
        )->execute([$pid, $desde, $hasta, $ini . ':00']);

        $docenteId = ($usuario['rol'] === 'profesor') ? ($usuario['id'] ?? null) : null;
        try {
            $pdo->prepare(
                "INSERT INTO reservas (proyector_id, docente_id, dia, hora_inicio, hora_fin, estado_reserva, materia, curso, docente)
                 VALUES (?, ?, ?, ?, ?, 'reservado', ?, ?, ?)"
            )->execute([$pid, $docenteId, $fecha . ' 00:00:00', $ini . ':00', $fin . ':00', $materia, $curso, $nombre]);
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {   // otro usuario reservó justo antes
                responder_json(['error' => 'El turno ya está reservado', 'existing' => true], 409);
            }
            throw $e;
        }

        $nuevoId = (int) $pdo->lastInsertId();
        auditar($usuario, "creó la reserva #$nuevoId (proyector $pid, $fecha $ini, $curso, docente: $nombre)");
        responder_json([
            'ok'   => true,
            'data' => [
                'id' => $nuevoId, 'materia' => $materia, 'curso' => $curso, 'nombre' => $nombre,
                'puede_eliminar' => true,   // la acaba de crear: el profesor la ve a su nombre y el directivo puede con todas
            ],
        ], 201);
    }

    /* ── DELETE: cancelar reserva ────────────────────────────── */
    if ($metodo === 'DELETE') {
        $fecha = param('fecha');
        $proy  = param('proy');
        $slot  = param('slot');
        $hora  = preg_match('/^\d{4}$/', $slot) ? substr($slot, 0, 2) . ':' . substr($slot, 2, 2) : '';

        if (!fecha_valida($fecha) || !ctype_digit($proy) || !hora_valida($hora)) {
            responder_json(['error' => 'Parámetros inválidos'], 400);
        }
        // primero se busca la reserva para saber a nombre de quién está
        [$desde, $hasta] = rango_dia($fecha);
        $st = $pdo->prepare(
            "SELECT ID, docente_id, docente, hora_fin FROM reservas
             WHERE proyector_id = ? AND dia >= ? AND dia < ? AND hora_inicio = ? AND estado_reserva = 'reservado' LIMIT 1"
        );
        $st->execute([(int) $proy, $desde, $hasta, $hora . ':00']);
        $reserva = $st->fetch();
        if (!$reserva) {
            responder_json(['error' => 'Reserva no encontrada'], 404);
        }
        // el profesor solo puede cancelar las suyas; el directivo, cualquiera
        if (!puede_eliminar_reserva($usuario, $reserva)) {
            responder_json(['error' => 'Solo podés cancelar las reservas hechas a tu nombre'], 403);
        }
        // igual que la página: una reserva de un turno que ya pasó queda como registro y no se cancela
        if (turno_terminado($fecha, substr((string) $reserva['hora_fin'], 0, 5))) {
            responder_json(['error' => 'No se puede cancelar una reserva que ya pasó'], 400);
        }

        $st = $pdo->prepare('DELETE FROM reservas WHERE ID = ?');
        $st->execute([(int) $reserva['ID']]);
        if ($st->rowCount() > 0) {
            auditar($usuario, "canceló la reserva #{$reserva['ID']} (proyector $proy, $fecha $hora, docente: {$reserva['docente']})");
            responder_json(['ok' => true]);
        }
        responder_json(['error' => 'Reserva no encontrada'], 404);
    }

    responder_json(['error' => 'Método no permitido'], 405);
} catch (Throwable $e) {
    error_log('api_reservas.php: ' . $e->getMessage());
    responder_json(['error' => 'Error de base de datos'], 500);
}
