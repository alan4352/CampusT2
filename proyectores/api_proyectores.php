<?php
/**
 * API de proyectores — tabla `proyectores` de MySQL.
 *
 *   GET    api_proyectores.php           → lista completa
 *   GET    api_proyectores.php?imagen=N  → la imagen (LONGBLOB) del proyector N, lista para un <img>
 *   POST   api_proyectores.php  (JSON)   → agrega uno nuevo
 *   PUT    api_proyectores.php?id=N      → edita todos los campos menos el ID
 *   DELETE api_proyectores.php?id=N      → elimina (sus reservas se borran en cascada)
 *
 * Requiere sesión del campus (ver conexion.php):
 *   · GET (lista e imagen): directivo y profesor, porque la página los necesita para mostrar los proyectores.
 *   · POST / PUT / DELETE (administrar): SOLO directivo. Un profesor recibe 403.
 *   · sin sesión / alumno → 401.
 *
 * Imagen en POST / PUT (campos opcionales del JSON):
 *   imagen_data   : "data:image/png;base64,...."  → guarda esa imagen en la columna `Imagen`
 *   quitar_imagen : true                          → deja la columna `Imagen` vacía (solo PUT)
 *   (si no viene ninguno de los dos, en PUT la imagen actual no se toca)
 */
ini_set('display_errors', '0');   // antes de cargar nada: un warning de includes/ no debe mezclarse con el JSON/imagen
require_once __DIR__ . '/conexion.php';

/** Tamaño máximo de la imagen ya decodificada. */
const IMAGEN_MAX_BYTES = 5 * 1024 * 1024;

/** Tipos de imagen aceptados (no SVG: puede llevar scripts). */
const IMAGEN_TIPOS = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

/** Valida y normaliza el body. Devuelve [error|null, valores en orden de columnas|null]. */
function datos_proyector(array $b): array
{
    $t = static fn(string $k): string => isset($b[$k]) && is_scalar($b[$k]) ? trim((string) $b[$k]) : '';

    $nombre = $t('nombre');
    $marca  = $t('marca');
    $estado = $t('estado') ?: 'operativo';

    if ($nombre === '' || $marca === '') {
        return ['Nombre y marca son obligatorios', null];
    }
    if (!in_array($estado, ['operativo', 'inoperante'], true)) {
        return ['Estado inválido', null];
    }
    if (mb_strlen($nombre) > 50 || mb_strlen($marca) > 50) {
        return ['Nombre y marca: máximo 50 caracteres', null];
    }
    return [null, [
        $nombre,
        $marca,
        mb_substr($t('resolucion'), 0, 50),
        mb_substr($t('brillo'), 0, 50),
        mb_substr($t('conexiones'), 0, 100),
        $estado,
    ]];
}

/**
 * Interpreta la imagen del body.
 * Devuelve [error|null, acción, binario|null] con acción = 'mantener' | 'quitar' | 'reemplazar'.
 */
function datos_imagen(array $b): array
{
    if (isset($b['imagen_data']) && is_string($b['imagen_data']) && $b['imagen_data'] !== '') {
        $s   = $b['imagen_data'];
        $pos = strpos($s, ';base64,');
        if (!str_starts_with($s, 'data:image/') || $pos === false) {
            return ['La imagen no tiene un formato válido', 'mantener', null];
        }
        $bin = base64_decode(substr($s, $pos + 8), true);
        if ($bin === false || $bin === '') {
            return ['No se pudo leer la imagen', 'mantener', null];
        }
        if (strlen($bin) > IMAGEN_MAX_BYTES) {
            return ['La imagen supera los ' . (IMAGEN_MAX_BYTES / 1048576) . ' MB', 'mantener', null];
        }
        // el tipo se verifica mirando los bytes reales, no lo que diga el navegador
        $info = @getimagesizefromstring($bin);
        if ($info === false || !in_array($info['mime'] ?? '', IMAGEN_TIPOS, true)) {
            return ['El archivo no es una imagen válida (usá PNG, JPG, WEBP o GIF)', 'mantener', null];
        }
        return [null, 'reemplazar', $bin];
    }
    if (!empty($b['quitar_imagen'])) {
        return [null, 'quitar', ''];
    }
    return [null, 'mantener', null];
}

/** ID de la URL (?id=N) o 0 si no es válido. */
function id_url(string $clave = 'id'): int
{
    $id = $_GET[$clave] ?? '';
    return is_string($id) && ctype_digit($id) ? (int) $id : 0;
}

/** "8M" / "128K" / "1G" → bytes. */
function bytes_ini(string $v): int
{
    $v = trim($v);
    $n = (int) $v;
    return match (strtolower(substr($v, -1))) {
        'g' => $n * 1024 ** 3,
        'm' => $n * 1024 ** 2,
        'k' => $n * 1024,
        default => $n,
    };
}

/** Si el body vino vacío porque PHP lo descartó por superar post_max_size. */
function body_excede_php(): bool
{
    $limite = bytes_ini((string) ini_get('post_max_size'));
    return $limite > 0 && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $limite;
}

/** Envía la imagen guardada en la base y termina. */
function servir_imagen(PDO $pdo, int $id): void
{
    $st = $pdo->prepare('SELECT Imagen FROM proyectores WHERE ID = ?');
    $st->execute([$id]);
    $bin = $st->fetchColumn();
    if (is_resource($bin)) {
        $bin = stream_get_contents($bin);
    }
    $info = (is_string($bin) && $bin !== '') ? @getimagesizefromstring($bin) : false;
    if ($info === false || !in_array($info['mime'] ?? '', IMAGEN_TIPOS, true)) {
        http_response_code(404);   // vacía, o es una ruta de texto antigua (esa no pasa por acá)
        exit;
    }

    $etag = '"' . md5($bin) . '"';
    header('ETag: ' . $etag);
    // private: la imagen exige sesión, así que un proxy/caché compartido no debe guardarla ni servirla a otros.
    // La URL lleva ?v=huella: si cambia la imagen, cambia la URL (por eso puede cachearse tanto tiempo).
    header('Cache-Control: private, max-age=31536000, immutable');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'");
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        exit;
    }
    header('Content-Type: ' . $info['mime']);
    header('Content-Length: ' . strlen($bin));
    echo $bin;
    exit;
}

try {
    $pdo = conectar();
    $metodo = $_SERVER['REQUEST_METHOD'];
    $usuario = exigir_usuario_api($metodo !== 'GET');   // leer: directivo o profesor · modificar: solo directivo

    if ($metodo === 'GET') {
        if (isset($_GET['imagen'])) {
            $id = id_url('imagen');
            if ($id === 0) {
                responder_json(['error' => 'ID inválido'], 400);
            }
            servir_imagen($pdo, $id);
        }
        responder_json(listar_proyectores($pdo));
    }

    if ($metodo === 'POST') {
        $body = leer_json();
        if (!$body && body_excede_php()) {
            responder_json(['error' => 'La imagen es demasiado pesada para el servidor (post_max_size)'], 413);
        }
        [$error, $vals] = datos_proyector($body);
        if ($error) {
            responder_json(['error' => $error], 400);
        }
        [$errImg, , $bin] = datos_imagen($body);
        if ($errImg) {
            responder_json(['error' => $errImg], 400);
        }
        $st = $pdo->prepare(
            'INSERT INTO proyectores (Nombre, Marca, Resolucion, Brillo, Conexiones, Estado, Imagen) VALUES (?,?,?,?,?,?,?)'
        );
        foreach ($vals as $i => $v) {
            $st->bindValue($i + 1, $v);
        }
        $st->bindValue(7, $bin ?? '', PDO::PARAM_LOB);   // sin imagen: cadena vacía (la columna no admite NULL)
        $st->execute();
        $nuevoId = (int) $pdo->lastInsertId();
        auditar($usuario, "agregó el proyector #$nuevoId ({$vals[0]})");
        responder_json(['ok' => true, 'id' => $nuevoId], 201);
    }

    if ($metodo === 'PUT') {
        $id   = id_url();
        $body = leer_json();
        if (!$body && body_excede_php()) {
            responder_json(['error' => 'La imagen es demasiado pesada para el servidor (post_max_size)'], 413);
        }
        [$error, $vals] = datos_proyector($body);
        if ($id === 0) {
            responder_json(['error' => 'ID inválido'], 400);
        }
        if ($error) {
            responder_json(['error' => $error], 400);
        }
        [$errImg, $accion, $bin] = datos_imagen($body);
        if ($errImg) {
            responder_json(['error' => $errImg], 400);
        }

        // la imagen solo se incluye en el UPDATE si hay que cambiarla o quitarla
        $sql = 'UPDATE proyectores SET Nombre=?, Marca=?, Resolucion=?, Brillo=?, Conexiones=?, Estado=?'
             . ($accion === 'mantener' ? '' : ', Imagen=?') . ' WHERE ID=?';
        $st = $pdo->prepare($sql);
        $n = 0;
        foreach ($vals as $v) {
            $st->bindValue(++$n, $v);
        }
        if ($accion !== 'mantener') {
            $st->bindValue(++$n, $bin, PDO::PARAM_LOB);
        }
        $st->bindValue(++$n, $id, PDO::PARAM_INT);
        $st->execute();

        if ($st->rowCount() === 0) {   // 0 también si guardó los mismos valores: confirmar que exista
            $ex = $pdo->prepare('SELECT 1 FROM proyectores WHERE ID = ?');
            $ex->execute([$id]);
            if (!$ex->fetchColumn()) {
                responder_json(['error' => 'Proyector no encontrado'], 404);
            }
        }
        auditar($usuario, "editó el proyector #$id ({$vals[0]})" . ($accion === 'mantener' ? '' : " · imagen: $accion"));
        responder_json(['ok' => true]);
    }

    if ($metodo === 'DELETE') {
        $id = id_url();
        if ($id === 0) {
            responder_json(['error' => 'ID inválido'], 400);
        }
        $st = $pdo->prepare('DELETE FROM proyectores WHERE ID = ?');
        $st->execute([$id]);
        if ($st->rowCount() === 0) {
            responder_json(['error' => 'Proyector no encontrado'], 404);
        }
        auditar($usuario, "eliminó el proyector #$id (y sus reservas)");
        responder_json(['ok' => true]);
    }

    responder_json(['error' => 'Método no permitido'], 405);
} catch (Throwable $e) {
    error_log('api_proyectores.php: ' . $e->getMessage());
    responder_json(['error' => 'Error de base de datos'], 500);
}
