<?php
/**
 * Reserva de proyectores — página principal.
 * Proyectores: tabla `proyectores` · Reservas: tabla `reservas` (base de datos del campus).
 * La conexión y la sesión están en conexion.php (usan includes/ del campus);
 * los cambios se guardan vía api_reservas.php / api_proyectores.php.
 *
 * ACCESO (sesión del campus):
 *   · directivo → ve todo, incluido "Administrar proyectores"
 *   · profesor  → reserva solo a su nombre y cancela solo sus reservas; sin "Administrar proyectores"
 *   · cualquier otro caso (sin sesión, alumno) → vuelve al campus principal
 */
require_once __DIR__ . '/conexion.php';

$proyectoresIniciales = null;
$errorBD = null;
$usuario = null;

try {
    $usuario = usuario_reservas();   // el nombre del profesor sale de la tabla `profesores`
} catch (Throwable $e) {
    error_log('reserva_proyectores (usuario): ' . $e->getMessage());
    $errorBD = 'No se pudo conectar con la base de datos del campus.';
    // sin base no se puede verificar al profesor, pero la sesión ya dice qué rol tiene
    if (tienePermiso('proyector')) {
        $usuario = ['rol' => (string) $_SESSION['rol'], 'nombre' => trim((string) $_SESSION['nombre']), 'id' => null];
    }
}

if ($usuario === null) {            // sin sesión, alumno, o profesor que ya no existe en la base
    header('Location: ' . URL_CAMPUS);
    exit;
}
session_write_close();              // la página no escribe en la sesión

// Cabeceras de seguridad: evita que otro sitio la meta en un <iframe> (clickjacking sobre los botones de
// cancelar/eliminar), que el navegador adivine tipos de contenido y que la página quede en cachés compartidas.
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: frame-ancestors 'self'; base-uri 'self'; form-action 'self'; object-src 'none'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: private, no-cache');

$esDirectivo = $usuario['rol'] === 'directivo';
$esProfesor  = $usuario['rol'] === 'profesor';

if ($errorBD === null) {
    try {
        $proyectoresIniciales = listar_proyectores(conectar());
    } catch (Throwable $e) {
        error_log('reserva_proyectores: ' . $e->getMessage());
        $errorBD = 'No se pudo conectar con la base de datos del campus.';
    }
}
// Reservas de la semana actual: se incrustan en la página para que se vean al instante, sin esperar otra petición.
$reservasIniciales = null;
if ($errorBD === null) {
    try {
        $hoyAR   = new DateTimeImmutable('now', new DateTimeZone('America/Argentina/Buenos_Aires'));
        $lunesAR = $hoyAR->modify('-' . ((int) $hoyAR->format('N') - 1) . ' days');
        $reservasIniciales = reservas_por_dia(conectar(), $usuario, $lunesAR->format('Y-m-d'), $lunesAR->modify('+7 days')->format('Y-m-d'));
    } catch (Throwable $e) {
        error_log('reserva_proyectores (reservas): ' . $e->getMessage());   // la página las pedirá por la API
    }
}
// Directivo: docentes de la tabla `profesores`, juntando a los que tienen el mismo nombre ("Nombre Apellido"):
// $docentes = [['nombre' => ..., 'correos' => [...]], ...]. Si un nombre tiene varios correos, al elegirlo se abre el cuadro
// lateral para escoger el correo. Los profesores NO reciben esta lista (no ven correos ajenos).
$docentes = [];
$profesoresError = false;
if ($esDirectivo && $errorBD === null) {
    try {
        $docentes = docentes_agrupados(profesores_detalle(conectar()));
    } catch (Throwable $e) {
        $profesoresError = true;   // se avisa en la lista; el directivo igual puede escribir el nombre con "Otro"
        error_log('reserva_proyectores (profesores): ' . $e->getMessage());
    }
}
$profesores = array_column($docentes, 'nombre');   // un nombre por docente, sin repetidos
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <link rel="stylesheet" href="./style.css?v=<?= (int) @filemtime(__DIR__ . '/style.css') ?>">
  <script src="./tema.js?v=<?= (int) @filemtime(__DIR__ . '/tema.js') ?>"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link
    href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700;900&family=DM+Mono:wght@300;400;500&display=swap"
rel="stylesheet">

</head>

<body>

  <!-- Barra de navegación institucional y retorno al campus -->
  <header style="background: rgba(40, 0, 5, 0.95); border-bottom: 1px solid rgba(245, 245, 220, 0.15); padding: 0.75rem 1.25rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem;">
    <a href="<?= htmlspecialchars(URL_PANEL_CAMPUS, ENT_QUOTES, 'UTF-8') ?>" style="display: inline-flex; align-items: center; gap: 0.5rem; color: #f5f5dc; text-decoration: none; font-size: 0.88rem; font-weight: 600; padding: 0.4rem 0.8rem; border-radius: 6px; background: rgba(245, 245, 220, 0.08); border: 1px solid rgba(245, 245, 220, 0.2);">
      ← Volver al Campus Virtual
    </a>
    <div style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
      <span id="lugarTema" class="lugar-tema"></span>
      <span style="color: #f5f5dc; font-size: 0.85rem; opacity: 0.9;">
        <strong><?= htmlspecialchars($usuario['nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?></strong>
        <span style="opacity: 0.7; font-size: 0.78rem;">(<?= htmlspecialchars(ucfirst($usuario['rol'] ?? ''), ENT_QUOTES, 'UTF-8') ?>)</span>
      </span>
      <a href="<?= htmlspecialchars(URL_LOGOUT_CAMPUS, ENT_QUOTES, 'UTF-8') ?>" style="color: #ffcdd2; text-decoration: none; font-size: 0.8rem; font-weight: 600; padding: 0.35rem 0.7rem; border-radius: 6px; border: 1px solid rgba(239, 83, 80, 0.4); background: rgba(183, 28, 28, 0.25);">
        Cerrar sesión
      </a>
    </div>
  </header>

 <div class="page-title">
  <svg class="title-icon" viewBox="0 0 64 40" width="64" height="40" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <rect x="4" y="8" width="44" height="22" rx="4"/>
    <circle cx="36" cy="19" r="6"/>
    <circle cx="36" cy="19" r="2" fill="currentColor"/>
    <path d="M12 14h10M12 19h6"/>
    <path d="M10 30v5M42 30v5"/>
    <path d="M52 14l9-5M52 19h10M52 24l9 5" class="beam"/>
  </svg>
  <h1><span class="t-small">RESERVA DE</span><span class="t-big">PRO<em>YECTORES</em></span></h1>
  <div class="title-divider"><span></span><i></i><span></span></div>
  <p class="title-sub">Sistema de reservas</p>
</div>

  <!-- Overlay de zoom: specs (izq) + imagen ampliada (der) -->
  <div class="zoom-overlay" id="zoom-overlay">
    <div class="zoom-content">
      <div class="zoom-specs-panel" id="zoom-specs">
        <!-- Se llena dinámicamente con JS -->
      </div>
      <div class="zoom-image-area">
        <img id="zoom-img" src="" alt="Zoom">
      </div>
    </div>
  </div>

  <div class="main">
    <div class="top-bar">
      <div class="legend">
        <div class="legend-item"><span class="slot-dot dot-free"></span>Disponible</div>
        <div class="legend-item"><span class="slot-dot dot-taken"></span>Ocupado</div>
      </div>
      <?php if ($esDirectivo): ?>
      <button class="btn-admin" type="button" onclick="openAdmin()">⚙ Administrar proyectores</button>
      <?php endif; ?>
      <div class="fecha-selector">
        <button class="fecha-nav" id="btn-prev" onclick="cambiarSemana(-1)">&#8249;</button>
        <div class="semana-label" id="semana-label"></div>
        <div class="fecha-tabs" id="fecha-tabs"></div>
        <button class="fecha-nav" id="btn-next" onclick="cambiarSemana(1)">&#8250;</button>
      </div>
    </div>
    <div id="root"></div>
  </div>

  <div class="status-bar">
    <span id="status-text">Cargando...</span>
    <span id="status-time"></span>
  </div>

  <div class="modal-overlay" id="modal">
    <div class="modal-grupo">
    <div class="modal">
      <button class="close-modal" onclick="closeModal()">✕</button>
      <h2>Reservar turno</h2>
      <div class="modal-meta" id="modal-meta"></div>
      <div class="reserva-campos">
      <div class="form-group campo-materia">
        <label class="form-label" for="input-materia">Materia *</label>
        <input type="text" class="form-input" id="input-materia" placeholder="Ej: Física II, Lengua, Historia..." autocomplete="off">
        <div class="error-msg" id="err-materia">Ingresá la materia</div>
      </div>
      <div class="form-group campo-curso">
        <label class="form-label" for="input-curso">Curso *</label>
        <select class="form-input" id="input-curso">
          <option value="" selected disabled>Elegí un curso...</option>
          <option value="1°1ra">1°1ra</option>
          <option value="1°2da">1°2da</option>
          <option value="1°3ra">1°3ra</option>
          <option value="1°4ta">1°4ta</option>
          <option value="1°5ta">1°5ta</option>
          <option value="2°1ra">2°1ra</option>
          <option value="2°2da">2°2da</option>
          <option value="2°3ra">2°3ra</option>
          <option value="2°4ta">2°4ta</option>
          <option value="2°5ta">2°5ta</option>
          <option value="3°1ra">3°1ra</option>
          <option value="3°2da">3°2da</option>
          <option value="3°3ra">3°3ra</option>
          <option value="3°4to">3°4to</option>
          <option value="4°1ra TC">4°1ra TC</option>
          <option value="4°1ra TAE">4°1ra TAE</option>
          <option value="4°2da TAE">4°2da TAE</option>
          <option value="4°1ra TG">4°1ra TG</option>
          <option value="5°1ra TC">5°1ra TC</option>
          <option value="5°1ra TAE">5°1ra TAE</option>
          <option value="5°2da TAE">5°2da TAE</option>
          <option value="5°1ra TG">5°1ra TG</option>
          <option value="6°1ra TC">6°1ra TC</option>
          <option value="6°1ra TAE">6°1ra TAE</option>
          <option value="6°1ra TG">6°1ra TG</option>
          <option value="7°1ra TC">7°1ra TC</option>
          <option value="7°1ra TAE">7°1ra TAE</option>
          <option value="7°1ra TG">7°1ra TG</option>
        </select>
        <div class="error-msg" id="err-curso">Elegí un curso</div>
      </div>
      <div class="form-group campo-docente">
        <label class="form-label" for="<?= $esDirectivo ? 'select-docente' : 'input-nombre' ?>">Nombre completo del docente *</label>
        <?php if ($esDirectivo): ?>
        <!-- Directivo: se elige de la tabla `profesores` (así el nombre coincide con el del profesor y él puede cancelar su reserva)
             o se elige "Otro" y se escribe a mano, también para quien no figura en la tabla -->
        <select class="form-input" id="select-docente">
          <option value="" selected disabled><?= $profesoresError ? 'No se pudo cargar la lista — usá "Otro"' : ($profesores ? 'Elegí un docente...' : 'No hay profesores cargados — usá "Otro"') ?></option>
          <?php foreach ($profesores as $prof): ?>
          <option value="<?= htmlspecialchars($prof, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($prof, ENT_QUOTES, 'UTF-8') ?></option>
          <?php endforeach; ?>
          <option value="__otro__">Otro (escribir manualmente)…</option>
        </select>
        <input type="text" class="form-input docente-manual" id="input-nombre" placeholder="Nombre y apellido" autocomplete="off" hidden>
        <?php else: ?>
        <input type="text" class="form-input" id="input-nombre" placeholder="Nombre y apellido" value="<?= htmlspecialchars($usuario['nombre'], ENT_QUOTES, 'UTF-8') ?>" readonly tabindex="-1" title="Las reservas se hacen siempre a tu nombre">
        <?php endif; ?>
        <div class="error-msg" id="err-nombre">Elegí un docente</div>
      </div>
      </div><!-- /.reserva-campos -->
      <div class="modal-actions">
        <button class="btn-confirm" onclick="confirmarReserva()">Confirmar reserva</button>
        <button class="btn-cancel" onclick="closeModal()">Cancelar</button>
      </div>
    </div>
    <?php if ($esDirectivo): ?>
    <!-- Cuadro a la derecha: aparece solo si el docente elegido comparte nombre con otros; se elige su correo.
         El correo NO se guarda en la reserva: el servidor lo usa para buscar el ID del profesor. -->
    <aside class="modal-lado" id="panel-correo" hidden aria-live="polite">
      <h3>Hay varios docentes con este nombre</h3>
      <p class="modal-lado-sub" id="panel-correo-nombre"></p>
      <div class="correo-lista" id="correo-lista" role="radiogroup" aria-label="Correo del docente"></div>
      <div class="error-msg" id="err-correo">Elegí el correo del docente</div>
    </aside>
    <?php endif; ?>
    </div><!-- /.modal-grupo -->
  </div>

  <!-- Botón flotante (solo teléfono): lleva al turno tarde -->
  <button class="afternoon-fab" id="goto-afternoon" type="button" aria-label="Ir al turno tarde" title="Ir al turno tarde">
    <svg viewBox="0 0 48 48" width="30" height="30" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <!-- sol poniéndose -->
      <path d="M12 30a12 12 0 0 1 24 0"/>
      <path d="M4 30h6M38 30h6"/>
      <path d="M8.5 19.5l4.2 4.2M39.5 19.5l-4.2 4.2"/>
      <path d="M24 9v5"/>
      <path d="M7 36h34"/>
      <!-- flecha hacia abajo -->
      <path d="M24 39v6"/>
      <path d="M20.5 42l3.5 3.5 3.5-3.5"/>
    </svg>
  </button>
  <?php if ($esDirectivo): ?>
  <!-- Panel: administrar proyectores (solo directivos) -->
  <div class="modal-overlay" id="admin-modal">
    <div class="modal admin-modal">
      <button class="close-modal" onclick="closeAdmin()" aria-label="Cerrar">✕</button>
      <h2>Administrar proyectores</h2>
      <div class="modal-meta" id="admin-meta"></div>
      <div id="admin-body"></div>
    </div>
  </div>
  <?php endif; ?>
  <div class="toast" id="toast"></div>

  <!-- Etiqueta de audio oculta para el efecto de sonido al pasar el cursor sobre los horarios disponibles -->
  <audio id="hover-sound" src="Song/freesound_community-item-equip-6904.mp3" preload="auto"></audio>

  <script>
    // Cargados desde MySQL por PHP al generar la página
    const PROYECTORES_INICIAL = <?= json_encode($proyectoresIniciales, $jsonFlags) ?>;
    const ERROR_BD = <?= json_encode($errorBD, $jsonFlags) ?>;
    // Usuario del campus (la seguridad real la aplican las API en el servidor; esto solo adapta la pantalla)
    const USUARIO = <?= json_encode(['rol' => $usuario['rol'], 'nombre' => $usuario['nombre']], $jsonFlags) ?>;   // el ID de profesor nunca llega al navegador
    const RESERVAS_INICIAL = <?= json_encode($reservasIniciales, $jsonFlags) ?>;   // reservas de la semana actual (fecha → mapa)
    const DOCENTES = <?= json_encode($docentes, $jsonFlags) ?>;                    // solo directivo: [{nombre, correos[]}]
    const ES_DIRECTIVO = <?= json_encode($esDirectivo) ?>;
    const ES_PROFESOR = <?= json_encode($esProfesor) ?>;
    const URL_CAMPUS = <?= json_encode(URL_CAMPUS) ?>;

    const DIAS_ES = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
    const MESES_ES = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

    function isoDate(d) { return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; }
    function lunesDe(d) { const day = d.getDay(); const diff = day === 0 ? -6 : 1 - day; const l = new Date(d); l.setDate(d.getDate() + diff); l.setHours(0, 0, 0, 0); return l; }
    function diasLaborales(lunes) { return [0, 1, 2, 3, 4].map(i => { const d = new Date(lunes); d.setDate(lunes.getDate() + i); return d; }); }
    function semanaLabel(lunes) { const v = new Date(lunes); v.setDate(lunes.getDate() + 4); return `${lunes.getDate()} ${MESES_ES[lunes.getMonth()]} – ${v.getDate()} ${MESES_ES[v.getMonth()]}`; }

    const TODAY = new Date(); TODAY.setHours(0, 0, 0, 0);
    const TODAY_ISO = isoDate(TODAY);
    const LUNES_ESTA = lunesDe(TODAY);
    const LUNES_SIG = new Date(LUNES_ESTA); LUNES_SIG.setDate(LUNES_ESTA.getDate() + 7);
    const LUNES_ANT = new Date(LUNES_ESTA); LUNES_ANT.setDate(LUNES_ESTA.getDate() - 7);

    function getLunesPorOffset(offset) {
      if (offset === -1) return LUNES_ANT;
      if (offset === 1) return LUNES_SIG;
      return LUNES_ESTA;
    }

    // día inicial: hoy si es laboral, sino lunes de esta semana
    const todayDay = TODAY.getDay();
    let fechaSeleccionada = (todayDay >= 1 && todayDay <= 5) ? TODAY_ISO : isoDate(LUNES_ESTA);
    let semanaOffset = 0;

    let PROYECTORES = [];   // se carga desde la tabla `proyectores` (ver cargarProyectores)

    function addMin(h, m, d) { let t = h * 60 + m + d; return [Math.floor(t / 60), t % 60]; }
    function fmt(h, m) { return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`; }
    function sid(h, m) { return `${String(h).padStart(2, '0')}${String(m).padStart(2, '0')}`; }
    function slotsFromBloques(bloques) {
      const slots = [];
      for (const b of bloques) {
        let [h, m] = b.desde; const endMin = b.hasta[0] * 60 + b.hasta[1];
        while (h * 60 + m < endMin) {
          let [eh, em] = addMin(h, m, 40);
          if (eh * 60 + em > endMin) { eh = b.hasta[0]; em = b.hasta[1]; }
          slots.push({ id: sid(h, m), time: fmt(h, m), endTime: fmt(eh, em) });
          h = eh; m = em;
        }
      }
      return slots;
    }

    const TURNOS = [
      { key: 'manana', label: 'Turno Mañana', hours: '7:20 — 13:05', slots: slotsFromBloques([{ desde: [7, 20], hasta: [8, 40] }, { desde: [8, 50], hasta: [10, 10] }, { desde: [10, 20], hasta: [11, 40] }, { desde: [11, 45], hasta: [13, 5] }]) },
      { key: 'tarde', label: 'Turno Tarde', hours: '13:30 — 19:05', slots: slotsFromBloques([{ desde: [13, 30], hasta: [14, 50] }, { desde: [15, 0], hasta: [16, 20] }, { desde: [16, 30], hasta: [17, 50] }, { desde: [17, 55], hasta: [19, 5] }]) }
    ];

    let reservations = {};
    let cancelPend = null;      // reserva esperando confirmación de cancelación: { proyId, slotId }
    let cancelando = false;     // evita doble envío mientras se cancela

    /* ══════════════════════════════════════════════════════════════
     * API — Las reservas viven en la tabla `reservas` de MySQL.
     * La página habla con api_reservas.php (rutas relativas, misma carpeta).
     * Si la sesión del campus venció (401) se vuelve al campus principal.
     * ══════════════════════════════════════════════════════════════ */

    const API_RESERVAS = 'api_reservas.php';
    // Las API rechazan POST/PUT/DELETE sin esta cabecera (protección CSRF): ver exigir_peticion_propia() en conexion.php
    const HDR_PROPIO = 'reservas';

    function sesionVencida(res) {
      if (res.status === 401) { window.location.href = URL_CAMPUS; return true; }
      return false;
    }

    const api = {
      // Las reservas de TODA la semana (lunes a domingo) de esa fecha, en una sola petición:
      // { 'YYYY-MM-DD': { proyectorId: { 'HHMM': { id, materia, curso, nombre, puede_eliminar } } } }
      async getSemana(fecha) {
        const res = await fetch(`${API_RESERVAS}?semana=${encodeURIComponent(fecha)}`, { cache: 'no-store' });
        if (sesionVencida(res)) return null;
        if (!res.ok) throw new Error('Error al obtener reservas');
        return await res.json();
      },

      // Crear una reserva (retorna { conflict: true } si el turno ya está ocupado)
      async setReserva(fecha, proyId, data) {
        const res = await fetch(API_RESERVAS, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-Requested-With': HDR_PROPIO },
          body: JSON.stringify({ fecha, proyId, ...data })
        });
        const json = await res.json().catch(() => ({}));
        if (sesionVencida(res)) return { conflict: false };
        if (res.status === 409 && json.existing) return { conflict: true };
        if (!res.ok) throw new Error(json.error || 'Error al guardar');
        return json;
      },

      // Eliminar una reserva
      async deleteReserva(fecha, proyId, slotId) {
        const qs = `fecha=${encodeURIComponent(fecha)}&proy=${encodeURIComponent(proyId)}&slot=${encodeURIComponent(slotId)}`;
        const res = await fetch(`${API_RESERVAS}?${qs}`, { method: 'DELETE', headers: { 'X-Requested-With': HDR_PROPIO } });
        if (sesionVencida(res)) return { ok: false };
        if (res.status === 404) return { ok: false };
        if (res.status === 403) {   // un profesor intentó cancelar una reserva ajena
          const j = await res.json().catch(() => ({}));
          throw new Error(j.error || 'No tenés permiso para cancelar esta reserva');
        }
        if (!res.ok) throw new Error('Error al cancelar');
        return await res.json();
      }
    };

    /* ══════════════════════════════════════════════════════════════
     * Caché de reservas por día
     * · Una petición trae la semana completa y queda guardada por fecha: pasar de un día a otro de la misma semana
     *   es instantáneo (no hay petición). La primera semana viene incrustada en la página, y las semanas vecinas
     *   se precargan en segundo plano.
     * · Una fecha solo permite reservar si sus reservas se cargaron hace menos de CACHE_TTL_MS. Mientras se cargan
     *   (o si los datos quedaron viejos) los horarios libres aparecen bloqueados: así nadie reserva "a ciegas" sobre
     *   un turno que otra persona ya ocupó. (El servidor además rechaza el duplicado con un 409.)
     * ══════════════════════════════════════════════════════════════ */
    const CACHE_TTL_MS = 45000;      // mayor que el refresco periódico (30 s)
    const cacheDias = {};            // 'YYYY-MM-DD' → { data, ts }
    let reqSeq = 0;                  // descarta respuestas de una fecha que ya no se está mirando
    let precargando = false;

    const normalizarDia = m => (!m || Array.isArray(m)) ? {} : m;
    const lunesISODe = fecha => isoDate(lunesDe(new Date(fecha + 'T00:00:00')));
    function diaListo(fecha) { const c = cacheDias[fecha]; return !!c && (Date.now() - c.ts) < CACHE_TTL_MS; }
    function guardarSemana(semana) {
      const ts = Date.now();
      for (const [f, m] of Object.entries(semana || {})) cacheDias[f] = { data: normalizarDia(m), ts };
    }

    function actualizarEstado() {
      const total = Object.values(reservations).reduce((a, v) => a + Object.keys(v).length, 0);
      document.getElementById('status-text').textContent = `${total} reserva${total !== 1 ? 's' : ''} activa${total !== 1 ? 's' : ''}`;
      const c = cacheDias[fechaSeleccionada];
      document.getElementById('status-time').textContent = c ? `Actualizado: ${new Date(c.ts).toLocaleTimeString('es-AR')}` : '';
    }

    /* forzar = false → si la fecha ya está cargada y fresca se muestra al instante, sin pedir nada.
     * forzar = true  → se pide igual (refresco periódico); si los datos eran frescos no se bloquea nada ni se redibuja salvo que cambien. */
    async function loadReservations(forzar = false) {
      const fecha = fechaSeleccionada;
      const previo = cacheDias[fecha];
      const listo = diaListo(fecha);

      if (listo && !forzar) {
        reservations = previo.data;
        renderAll(); actualizarEstado(); precargarSemanas();
        return;
      }
      const antes = previo ? JSON.stringify(previo.data) : null;
      const seq = ++reqSeq;
      if (!listo) {   // sin datos confiables: se muestra lo que haya, con los horarios libres bloqueados
        reservations = previo ? previo.data : {};
        renderAll();
        document.getElementById('status-text').textContent = 'Cargando reservas...';
      }
      try {
        const semana = await api.getSemana(lunesISODe(fecha));
        if (!semana) return;   // sesión vencida: ya se redirigió
        guardarSemana(semana);
        if (!cacheDias[fecha]) cacheDias[fecha] = { data: {}, ts: Date.now() };
        if (seq !== reqSeq || fecha !== fechaSeleccionada) return;   // mientras tanto se eligió otro día: lo atiende su propia carga
        const nuevo = cacheDias[fecha].data;
        reservations = nuevo;
        if (!listo || antes !== JSON.stringify(nuevo)) renderAll();
        actualizarEstado(); precargarSemanas();
      } catch (e) {
        console.error(e);
        if (seq === reqSeq && fecha === fechaSeleccionada) {
          document.getElementById('status-text').textContent = 'Error al cargar datos — reintentando…';
          if (!listo) setTimeout(() => { if (fecha === fechaSeleccionada && !diaListo(fecha)) loadReservations(true); }, 4000);
        }
      }
    }

    // Trae en segundo plano las semanas (anterior / actual / siguiente) que todavía no están en la caché.
    async function precargarSemanas() {
      if (precargando || document.hidden) return;
      precargando = true;
      try {
        for (const off of [0, 1, -1]) {
          const lunes = isoDate(getLunesPorOffset(off));
          if (cacheDias[lunes]) continue;
          const semana = await api.getSemana(lunes);
          if (!semana) break;
          guardarSemana(semana);
        }
      } catch (e) { /* no es grave: se pedirá cuando se abra esa semana */ }
      finally { precargando = false; }
    }

    function renderSelector() {
      const lunes = getLunesPorOffset(semanaOffset);
      document.getElementById('semana-label').textContent = semanaLabel(lunes);
      document.getElementById('btn-prev').disabled = semanaOffset === -1;
      document.getElementById('btn-next').disabled = semanaOffset === 1;

      const dias = diasLaborales(lunes);
      const tabsEl = document.getElementById('fecha-tabs');
      tabsEl.innerHTML = '';

      dias.forEach(d => {
        const iso = isoDate(d);
        const isActive = fechaSeleccionada === iso;
        const isPast = d < TODAY;
        const isHoy = iso === TODAY_ISO;

        const btn = document.createElement('button');
        let cls = 'fecha-tab';
        if (isActive) cls += ' active';
        else if (isPast) cls += ' past-tab';
        btn.className = cls;

        btn.innerHTML = `<span class="tab-dia-nom">${DIAS_ES[d.getDay()]}</span><span class="tab-dia-num">${d.getDate()}</span>${isHoy ? '<span class="tab-hoy-badge">HOY</span>' : ''}`;

        // Todos los días son clickeables (pasados = solo ver)
        btn.onclick = () => { cancelPend = null; fechaSeleccionada = iso; renderSelector(); loadReservations(); };
        tabsEl.appendChild(btn);
      });
    }

    function cambiarSemana(delta) {
      cancelPend = null;
      semanaOffset = Math.max(-1, Math.min(1, semanaOffset + delta));
      const lunes = getLunesPorOffset(semanaOffset);
      const dias = diasLaborales(lunes);
      // Seleccionar hoy si está en la semana, sino el primer día
      const hoyEnSemana = dias.find(d => isoDate(d) === TODAY_ISO);
      if (hoyEnSemana) {
        fechaSeleccionada = TODAY_ISO;
      } else {
        fechaSeleccionada = isoDate(dias[0]);
      }
      renderSelector(); loadReservations();
    }

    function esFechaPasada() {
      const sel = new Date(fechaSeleccionada + 'T00:00:00');
      return sel < TODAY;
    }

    /* Verificar si un slot ya pasó HOY (comparar hora actual con endTime del slot) */
    function esSlotPasadoHoy(slotEndTime) {
      if (fechaSeleccionada !== TODAY_ISO) return false;
      const now = new Date();
      const [eh, em] = slotEndTime.split(':').map(Number);
      const endMinutes = eh * 60 + em;
      const nowMinutes = now.getHours() * 60 + now.getMinutes();
      return nowMinutes >= endMinutes;
    }

    /* Datos extendidos de cada proyector (imagen + specs), armados desde la tabla */
    let PROYECTOR_DATA = {};

    function aplicarProyectores(rows) {
      PROYECTORES = rows.map(r => ({ id: String(r.id), name: r.nombre, estado: r.estado }));
      PROYECTOR_DATA = {};
      rows.forEach(r => {
        PROYECTOR_DATA[String(r.id)] = {
          img: r.imagen || '',
          specs: [
            { label: 'Marca', value: esc(r.marca) },
            { label: 'Resolución', value: esc(r.resolucion || '—') },
            { label: 'Brillo', value: esc(r.brillo || '—') },
            { label: 'Conexiones', value: esc(r.conexiones || '—') },
            { label: 'Estado', value: r.estado === 'operativo' ? 'Operativo ✓' : 'Inoperante ✕' }
          ]
        };
      });
    }

    const proyApi = {
      async _req(url, opt) {
        let r;
        try { r = await fetch(url, { cache: 'no-store', ...opt, headers: { 'X-Requested-With': HDR_PROPIO, ...((opt && opt.headers) || {}) } }); } catch (e) { throw new Error('No se pudo conectar con el servidor'); }
        if (r.status === 401) { window.location.href = URL_CAMPUS; throw new Error('Sesión vencida'); }
        let j = null; try { j = await r.json(); } catch (e) { }
        if (!r.ok) throw new Error((j && j.error) || 'Error del servidor');
        return j;
      },
      list() { return this._req('api_proyectores.php'); },
      create(d) { return this._req('api_proyectores.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(d) }); },
      update(id, d) { return this._req('api_proyectores.php?id=' + id, { method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(d) }); },
      remove(id) { return this._req('api_proyectores.php?id=' + id, { method: 'DELETE' }); }
    };

    async function cargarProyectores() {
      try { aplicarProyectores(await proyApi.list()); return true; }
      catch (e) {
        console.error(e);
        document.getElementById('status-text').textContent = 'Sin conexión con la base de datos';
        return false;
      }
    }

    // Refresca proyectores y reservas desde MySQL
    function refrescarTodo() { return cargarProyectores().then(ok => { if (ok) loadReservations(true); }); }

    /* ── Zoom interactivo con animación futurista ── */
    let zoomAnimTimers = [];  // Para limpiar timeouts al cerrar

    function openZoom(proyId) {
      const data = PROYECTOR_DATA[proyId];
      const proy = PROYECTORES.find(p => p.id === proyId);
      const overlay = document.getElementById('zoom-overlay');
      const zoomImg = document.getElementById('zoom-img');
      const specsPanel = document.getElementById('zoom-specs');

      // Limpiar animaciones previas
      zoomAnimTimers.forEach(t => clearTimeout(t));
      zoomAnimTimers = [];

      zoomImg.onerror = () => { zoomImg.style.display = 'none'; };   // imagen rota/inexistente: no mostrar el ícono roto
      zoomImg.style.display = data.img ? '' : 'none';
      zoomImg.src = data.img || '';

      // Armar HTML de specs (sin clases de animación aún)
      const specsHTML = data.specs.map(s =>
        `<li><strong>${s.label}:</strong> ${s.value}</li>`
      ).join('');

      specsPanel.innerHTML = `
        <span class="zoom-proy-name">${esc(proy.name)}</span>
        <ul class="proyector-specs">${specsHTML}</ul>
        <span class="zoom-close-hint">▸ Click o ESC para cerrar</span>`;

      // Activar overlay (dispara animaciones CSS del panel e imagen)
      overlay.classList.add('active');

      // Animar cada línea de spec secuencialmente
      const specItems = specsPanel.querySelectorAll('.proyector-specs li');
      const BASE_DELAY = 750;   // esperar a que el panel deslice
      const STAGGER = 150;      // ms entre cada línea

      specItems.forEach((li, i) => {
        // 1) Aparecer + deslizar
        const t1 = setTimeout(() => {
          li.classList.add('spec-visible', 'spec-scan', 'spec-flash');
        }, BASE_DELAY + i * STAGGER);

        // 2) Quitar flash (color vuelve a normal)
        const t2 = setTimeout(() => {
          li.classList.remove('spec-flash');
        }, BASE_DELAY + i * STAGGER + 500);

        zoomAnimTimers.push(t1, t2);
      });
    }

    function closeZoom() {
      // Limpiar animaciones pendientes
      zoomAnimTimers.forEach(t => clearTimeout(t));
      zoomAnimTimers = [];
      document.getElementById('zoom-overlay').classList.remove('active');
    }

    document.getElementById('zoom-overlay').addEventListener('click', function (e) {
      if (!e.target.closest('.zoom-specs-panel')) closeZoom();
    });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeZoom(); });

    function renderAll() {
      const root = document.getElementById('root');
      root.innerHTML = '';
      const isPastDay = esFechaPasada();
      const isToday = fechaSeleccionada === TODAY_ISO;
      const listo = diaListo(fechaSeleccionada);   // false → las reservas de este día aún no se confirmaron

      for (const turno of TURNOS) {
        const block = document.createElement('div');
        block.className = 'turno-block';
        block.id = 'turno-' + turno.key;
        block.innerHTML = `
      <div class="turno-header">
        <span class="turno-label">${turno.label}</span>
        <span class="turno-hours">${turno.hours}</span>
        <span class="turno-line"></span>
      </div>
      <div class="proyectores-grid" id="grid-${turno.key}"></div>`;
        root.appendChild(block);

        const grid = block.querySelector(`#grid-${turno.key}`);
        for (const proy of PROYECTORES) {
          const res = reservations[proy.id] || {};

          const slotsHTML = turno.slots.map(slot => {
            const r = res[slot.id];
            const slotPasado = isPastDay || (isToday && esSlotPasadoHoy(slot.endTime));

            // Slot con reserva
            if (r) {
              // Si es pasado, mostrar reserva pero sin botón de eliminar
              if (slotPasado) {
                return `<div class="slot taken past">
              <span class="slot-dot dot-taken"></span>
              <span class="slot-time">${slot.time}–${slot.endTime}</span>
              <span class="slot-status">
                <span class="slot-materia">${esc(r.materia)}</span>
                <span class="slot-sub">${esc(r.curso)} · ${esc(r.nombre)}</span>
              </span>
            </div>`;
              }
              // Al pedir cancelar, la confirmación "¿…? Sí / No" REEMPLAZA el texto de la reserva (materia y curso):
              // ocupa el mismo lugar, así que nunca se sale del recuadro. Igual para todos los usuarios.
              const pend = r.puede_eliminar && cancelPend && cancelPend.proyId === proy.id && cancelPend.slotId === slot.id;
              const textoSlot = pend
                ? `<span class="slot-status slot-confirm">
              <span class="admin-confirm">¿Seguro que querés cancelar?</span>
              <span class="slot-confirm-btns">
                <button class="admin-btn danger" data-act="cancelyes" data-proy="${proy.id}" data-slot="${slot.id}">Sí</button>
                <button class="admin-btn" data-act="cancelno">No</button>
              </span>
            </span>`
                : `<span class="slot-status">
              <span class="slot-materia">${esc(r.materia)}</span>
              <span class="slot-sub">${esc(r.curso)} · ${esc(r.nombre)}</span>
            </span>`;
              const botonX = (r.puede_eliminar && !pend)
                ? `<button class="delete-btn" data-act="cancel" data-proy="${proy.id}" data-slot="${slot.id}" title="Cancelar reserva" aria-label="Cancelar reserva">✕</button>`
                : '';
              return `<div class="slot taken${pend ? ' confirmando' : ''}">
            <span class="slot-dot dot-taken"></span>
            <span class="slot-time">${slot.time}–${slot.endTime}</span>
            ${textoSlot}
            ${botonX}
          </div>`;
            }

            // Slot sin reserva pero ya pasado
            if (slotPasado) {
              return `<div class="slot past">
            <span class="slot-dot" style="width:7px;height:7px;border-radius:50%;flex-shrink:0;background:#2a0a0a;"></span>
            <span class="slot-time">${slot.time}–${slot.endTime}</span>
            <span class="slot-status">— ${isPastDay ? 'sin reserva' : 'horario superado'} —</span>
          </div>`;
            }

            // Reservas todavía sin cargar: el horario no se puede tomar hasta saber si está ocupado
            if (!listo) {
              return `<div class="slot cargando" aria-disabled="true">
            <span class="slot-dot"></span>
            <span class="slot-time">${slot.time}–${slot.endTime}</span>
            <span class="slot-status">cargando reservas…</span>
          </div>`;
            }

            // Slot libre y disponible
            return `<div class="slot free" onclick="openModal('${proy.id}','${slot.id}','${slot.time}','${slot.endTime}')">
          <span class="slot-dot dot-free"></span>
          <span class="slot-time">${slot.time}–${slot.endTime}</span>
          <span class="slot-status">disponible — click para reservar</span>
        </div>`;
          }).join('');

          const card = document.createElement('div');
          card.className = 'proyector-card';
          card.innerHTML = `
        <div class="proyector-header" onclick="openZoom('${proy.id}')">
          <span class="proyector-name">${esc(proy.name)}</span>
          <span class="info-hint">▸ ver info</span>
        </div>
        <div class="slots-container">${proy.estado === 'inoperante' ? '<div class="slot past"><span class="slot-status">— proyector fuera de servicio —</span></div>' : slotsHTML}</div>`;
          grid.appendChild(card);
        }
      }
    }

    let selectedSlot = null, selectedProy = null;

    function openModal(proyId, slotId, time, endTime) {
      if (!diaListo(fechaSeleccionada)) { showToast('Esperá un momento: se están cargando las reservas'); return; }
      const proyName = (PROYECTORES.find(p => p.id === proyId) || {}).name || '';
      selectedSlot = { slotId, time, endTime }; selectedProy = { proyId, proyName };
      const dObj = new Date(fechaSeleccionada + 'T00:00:00');
      const dLabel = `${DIAS_ES[dObj.getDay()]} ${dObj.getDate()} ${MESES_ES[dObj.getMonth()]}`;
      document.getElementById('modal-meta').textContent = `${proyName}  ·  ${dLabel}  ·  ${time} – ${endTime}`;
      ['materia', 'curso', 'nombre'].forEach(f => {
        // profesor: el nombre queda fijo (el servidor igualmente usa el de la base de datos)
        document.getElementById('input-' + f).value = (f === 'nombre' && ES_PROFESOR) ? USUARIO.nombre : '';
        document.getElementById('err-' + f).classList.remove('show');
      });
      document.getElementById('err-materia').textContent = 'Ingresá la materia';
      const selDoc = document.getElementById('select-docente');   // solo existe para el directivo
      if (selDoc) { selDoc.value = ''; mostrarDocenteManual(false); actualizarDocente(''); }
      document.getElementById('modal').classList.add('open');
      setTimeout(() => document.getElementById('input-materia').focus(), 80);
    }

    function closeModal() { document.getElementById('modal').classList.remove('open'); selectedSlot = null; selectedProy = null; if (typeof actualizarDocente === 'function') actualizarDocente(''); }

    /* ── Materia: solo letras (con tildes, ñ, ü…), números, espacios y puntos ──
     * Lo que no sea eso se descarta al escribir o pegar, y se vuelve a comprobar al confirmar.
     * (El servidor aplica la misma regla en api_reservas.php: MATERIA_REGEX.) */
    const MATERIA_FUERA = /[^\p{L}\p{M}0-9 .]/gu;      // cualquier carácter que NO sea letra, número, espacio o punto
    const MATERIA_VALIDA = /^[\p{L}\p{M}0-9 .]+$/u;
    const MATERIA_AVISO = 'La materia solo puede tener letras, números, espacios y puntos';
    const limpiarMateria = s => s.normalize('NFC').replace(/\s+/g, ' ').trim();

    const inputMateria = document.getElementById('input-materia');
    function filtrarMateria(e) {
      if (e && e.isComposing) return;   // no romper una tilde a medio escribir (tecla muerta / teclado IME)
      const original = inputMateria.value;
      const pos = inputMateria.selectionStart ?? original.length;
      const base = s => s.normalize('NFC').replace(/[^\S ]/g, ' ');       // tabulaciones, saltos y espacios raros → espacio
      const limpio = s => base(s).replace(MATERIA_FUERA, '');
      const nuevo = limpio(original);
      const aviso = document.getElementById('err-materia');
      if (nuevo !== original) {
        const nuevaPos = limpio(original.slice(0, pos)).length;
        inputMateria.value = nuevo;
        inputMateria.setSelectionRange(nuevaPos, nuevaPos);
      }
      if (nuevo.length !== base(original).length) { aviso.textContent = MATERIA_AVISO; aviso.classList.add('show'); }
      else if (aviso.textContent === MATERIA_AVISO) aviso.classList.remove('show');
    }
    inputMateria.addEventListener('input', filtrarMateria);
    inputMateria.addEventListener('compositionend', () => filtrarMateria());

    /* ── Docente ──
     * Profesor: el nombre queda fijo. Directivo: lista de la tabla `profesores` + opción "Otro" para escribirlo a mano. */
    const DOCENTE_OTRO = '__otro__';
    function mostrarDocenteManual(mostrar) {
      const inp = document.getElementById('input-nombre');
      inp.hidden = !mostrar;
      if (!mostrar) inp.value = '';
      else inp.focus();
    }
    function valorDocente() {
      const inp = document.getElementById('input-nombre');
      const sel = document.getElementById('select-docente');
      if (!sel) return inp.value.trim();
      return sel.value === DOCENTE_OTRO ? inp.value.replace(/\s+/g, ' ').trim() : sel.value.trim();
    }

    /* Correos por docente (solo el directivo recibe DOCENTES). Si el nombre elegido lo comparten varios profesores se abre
     * el cuadro de la derecha para elegir el correo; con uno solo se usa ese correo sin preguntar. Al confirmar, el servidor
     * busca por nombre + correo el ID del profesor y guarda ese ID en la reserva (el correo no se guarda). */
    const CORREOS_DOCENTE = new Map(DOCENTES.map(d => [d.nombre, d.correos]));
    const panelCorreo = document.getElementById('panel-correo');
    let correoElegido = '';

    // El botón "Confirmar reserva" queda deshabilitado mientras el cuadro de correos esté abierto y no se haya elegido uno.
    function sincronizarConfirmar() {
      const b = document.querySelector('#modal .btn-confirm'); if (!b) return;
      const falta = !!panelCorreo && !panelCorreo.hidden && !correoElegido;
      b.disabled = falta || reservando;
      b.title = falta ? 'Elegí el correo del docente para poder confirmar' : '';
    }

    function actualizarDocente(nombre) {
      correoElegido = '';
      if (!panelCorreo) { sincronizarConfirmar(); return; }
      const correos = CORREOS_DOCENTE.get(nombre) || [];
      document.getElementById('err-correo').classList.remove('show');
      if (correos.length > 1) {
        document.getElementById('panel-correo-nombre').textContent = nombre;
        const lista = document.getElementById('correo-lista');
        lista.textContent = '';
        correos.forEach(c => {
          const b = document.createElement('button');
          b.type = 'button'; b.className = 'correo-opcion'; b.dataset.correo = c;
          b.setAttribute('role', 'radio'); b.setAttribute('aria-checked', 'false');
          b.textContent = c;
          lista.appendChild(b);
        });
        panelCorreo.hidden = false;
      } else {
        panelCorreo.hidden = true;
        if (correos.length === 1) correoElegido = correos[0];
      }
      sincronizarConfirmar();
    }
    document.getElementById('correo-lista')?.addEventListener('click', e => {
      const b = e.target.closest('.correo-opcion'); if (!b) return;
      correoElegido = b.dataset.correo;
      document.querySelectorAll('#correo-lista .correo-opcion').forEach(x => x.setAttribute('aria-checked', x === b ? 'true' : 'false'));
      document.getElementById('err-correo').classList.remove('show');
      sincronizarConfirmar();
    });
    document.getElementById('select-docente')?.addEventListener('change', e => {
      const v = e.target.value;
      mostrarDocenteManual(v === DOCENTE_OTRO);
      actualizarDocente(v === DOCENTE_OTRO ? '' : v);
      document.getElementById('err-nombre').classList.remove('show');
    });

    let reservando = false;   // evita enviar dos veces la misma reserva (doble clic / Enter repetido)
    async function confirmarReserva() {
      if (reservando) return;
      const materia = limpiarMateria(document.getElementById('input-materia').value);
      const curso = document.getElementById('input-curso').value.trim();
      const nombre = valorDocente();
      const selDoc = document.getElementById('select-docente');
      let ok = true;
      const errMat = document.getElementById('err-materia');
      if (!materia) { errMat.textContent = 'Ingresá la materia'; errMat.classList.add('show'); ok = false; }
      else if (!MATERIA_VALIDA.test(materia)) { errMat.textContent = MATERIA_AVISO; errMat.classList.add('show'); ok = false; }
      else errMat.classList.remove('show');
      if (!curso) { document.getElementById('err-curso').classList.add('show'); ok = false; } else document.getElementById('err-curso').classList.remove('show');
      const errNom = document.getElementById('err-nombre');
      if (!nombre) {
        errNom.textContent = (selDoc && selDoc.value !== DOCENTE_OTRO) ? 'Elegí un docente de la lista' : 'Escribí el nombre completo del docente';
        errNom.classList.add('show'); ok = false;
      } else errNom.classList.remove('show');
      // directivo + docente de nombre compartido: hay que haber elegido el correo en el cuadro de la derecha
      const eligioLista = !!(selDoc && selDoc.value && selDoc.value !== DOCENTE_OTRO);
      const errCor = document.getElementById('err-correo');
      if (eligioLista && (CORREOS_DOCENTE.get(selDoc.value) || []).length > 1 && !correoElegido) { errCor.classList.add('show'); ok = false; }
      else if (errCor) errCor.classList.remove('show');
      if (!ok) return;
      if (!diaListo(fechaSeleccionada)) {   // los datos quedaron viejos (p. ej. falló un refresco): se actualizan antes de reservar
        showToast('Actualizando las reservas… intentá de nuevo en unos segundos');
        loadReservations(true);
        return;
      }
      const datos = { horaInicio: selectedSlot.time, horaFin: selectedSlot.endTime, materia, curso, nombre };
      if (eligioLista && correoElegido) datos.profesorCorreo = correoElegido;   // el profesor no envía nada más: su ID lo toma el servidor de la sesión
      const btnConfirmar = document.querySelector('#modal .btn-confirm');
      reservando = true; if (btnConfirmar) btnConfirmar.disabled = true;
      try {
        const result = await api.setReserva(fechaSeleccionada, selectedProy.proyId, datos);
        if (result.conflict) {
          await loadReservations(true); closeModal();
          showToast('Ese turno acaba de ser reservado — actualizando...');
          return;
        }
        if (!reservations[selectedProy.proyId]) reservations[selectedProy.proyId] = {};
        const d = result.data || {};
        reservations[selectedProy.proyId][selectedSlot.slotId] = {
          id: d.id, materia: d.materia || materia, curso: d.curso || curso,
          nombre: d.nombre || nombre, puede_eliminar: d.puede_eliminar !== false
        };
        closeModal(); renderAll();
        const total = Object.values(reservations).reduce((a, v) => a + Object.keys(v).length, 0);
        document.getElementById('status-text').textContent = `${total} reservas activas`;
        showToast('¡Reserva confirmada!');
      } catch (e) { showToast(e.message || 'Error al guardar — intentá nuevamente'); }
      finally { reservando = false; sincronizarConfirmar(); }
    }

    // La cancelación se confirma en la propia reserva (data-act="cancel" → "¿Cancelar? Sí / No"); aquí se ejecuta tras el "Sí".
    async function eliminar(proyId, slotId) {
      if (cancelando) return;
      cancelando = true;
      try {
        const r = await api.deleteReserva(fechaSeleccionada, proyId, slotId);
        cancelPend = null;
        if (r && r.ok === false) { await loadReservations(true); showToast('Esa reserva ya no existe — actualizando...'); return; }
        if (reservations[proyId]) delete reservations[proyId][slotId];
        renderAll();
        const total = Object.values(reservations).reduce((a, v) => a + Object.keys(v).length, 0);
        document.getElementById('status-text').textContent = `${total} reservas activas`;
        showToast('Reserva cancelada');
      } catch (e) {
        cancelPend = null; renderAll();
        showToast(e.message || 'Error al cancelar');
      } finally { cancelando = false; }
    }

    document.getElementById('root').addEventListener('click', e => {
      const b = e.target.closest('[data-act]'); if (!b || cancelando) return;
      switch (b.dataset.act) {
        case 'cancel': cancelPend = { proyId: b.dataset.proy, slotId: b.dataset.slot }; renderAll(); break;
        case 'cancelno': cancelPend = null; renderAll(); break;
        case 'cancelyes': b.disabled = true; eliminar(b.dataset.proy, b.dataset.slot); break;
      }
    });

    function showToast(msg) { const t = document.getElementById('toast'); t.textContent = msg; t.classList.add('show'); setTimeout(() => t.classList.remove('show'), 3000); }
    function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
    function escA(s) { return String(s).replace(/'/g, '&#39;'); }

    // La ventana de reserva NO se cierra al tocar fuera de ella (así un toque accidental no hace perder lo escrito):
    // se cierra solo con la ✕, con "Cancelar", con Esc o al confirmar.
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape') { closeModal(); if (cancelPend) { cancelPend = null; renderAll(); } }
      // Enter confirma, salvo sobre una lista desplegable o un botón (ahí Enter tiene su propio efecto)
      if (e.key === 'Enter' && document.getElementById('modal').classList.contains('open')
          && !['SELECT', 'BUTTON'].includes(e.target.tagName)) confirmarReserva();
    });

    /* ── Reproducción de sonido en Hover ──
     * Este evento detecta cuando el mouse pasa sobre un horario libre (.slot.free).
     * Al detectar el hover, reproduce el archivo de audio. 
     * El bloque catch evita errores en la consola si el navegador bloquea el auto-play 
     * antes de que el usuario interactúe con la página.
     */
    document.addEventListener('mouseover', function(e) {
      const slot = e.target.closest('.slot.free');
      if (slot) {
        if (e.relatedTarget && slot.contains(e.relatedTarget)) return;
        const audio = document.getElementById('hover-sound');
        if (audio) {
          audio.currentTime = 0;
          audio.play().catch(() => {});
        }
      }
    });


    /* ══════════════ Administrar proyectores ══════════════ */
    let adminRows = [], adminView = 'list', adminEditId = null, adminConfirmId = null;
    const $a = () => document.getElementById('admin-body');

    async function openAdmin() {
      if (!ES_DIRECTIVO) return;   // solo directivos (la API también lo exige)
      adminView = 'list'; adminConfirmId = null; adminImgReset();
      const body = $a();
      body.classList.remove('animating'); void body.offsetWidth;   // reinicia la animación
      body.classList.add('animating');
      document.getElementById('admin-modal').classList.add('open');
      body.innerHTML = '<p class="admin-empty">Cargando…</p>';
      await refreshAdmin();
      setTimeout(() => body.classList.remove('animating'), 1800);
    }
    function closeAdmin() { const m = document.getElementById('admin-modal'); if (m) m.classList.remove('open'); }

    async function refreshAdmin() {
      try { adminRows = await proyApi.list(); renderAdmin(); }
      catch (e) { $a().innerHTML = `<p class="admin-empty">${esc(e.message)}</p>`; }
    }

    function renderAdmin() {
      const meta = document.getElementById('admin-meta');
      if (adminView === 'form') {
        const r = adminRows.find(x => x.id === adminEditId) || { nombre: '', marca: '', resolucion: '', brillo: '', conexiones: '', estado: 'operativo', imagen: '' };
        const f = (k, label, ph) => `<div class="form-group"><label class="form-label">${label}</label><input type="text" class="form-input" id="af-${k}" value="${esc(r[k])}" placeholder="${ph}"></div>`;
        meta.textContent = adminEditId == null ? 'Nuevo proyector' : 'Editando proyector';
        $a().innerHTML = `
          ${f('nombre', 'Nombre *', 'Ej: Epson X49')}
          ${f('marca', 'Marca *', 'Ej: Epson')}
          <div class="admin-grid">
            ${f('resolucion', 'Resolución', 'Ej: 1080p Full HD')}
            ${f('brillo', 'Brillo', 'Ej: 3.600 lúmenes')}
          </div>
          <div class="admin-grid">
            ${f('conexiones', 'Conexiones', 'Ej: HDMI, USB, VGA')}
            <div class="form-group"><label class="form-label">Estado</label>
              <select class="form-input" id="af-estado">
                <option value="operativo"${r.estado === 'operativo' ? ' selected' : ''}>Operativo</option>
                <option value="inoperante"${r.estado === 'inoperante' ? ' selected' : ''}>Inoperante</option>
              </select></div>
          </div>
          <div class="form-group">
            <label class="form-label">Subir imagen</label>
            <div class="img-picker">
              <div class="img-preview" id="af-prev"></div>
              <div class="img-picker-side">
                <input type="file" id="af-file" accept="image/png,image/jpeg,image/webp,image/gif" hidden>
                <div class="img-picker-botones">
                  <button type="button" class="admin-btn" data-act="imgelegir">Elegir archivo…</button>
                  <button type="button" class="admin-btn danger" id="af-quitar" data-act="imgquitar">Quitar imagen</button>
                </div>
                <span class="img-ayuda">Formatos admitidos: PNG, JPG, WEBP y GIF</span>
                <span class="img-nombre" id="af-fname"></span>
              </div>
            </div>
          </div>
          <div class="error-msg" id="af-err"></div>
          <div class="modal-actions">
            <button class="btn-confirm" data-act="save">${adminEditId == null ? 'Agregar' : 'Guardar cambios'}</button>
            <button class="btn-cancel" data-act="back">Volver</button>
          </div>`;
        adminActualizarPreview();
        return;
      }
      meta.textContent = '';
      const rows = adminRows.map((r, i) => {
        const acciones = adminConfirmId === r.id
          ? `<span class="admin-confirm">¿Eliminar?</span>
             <button class="admin-btn danger" data-act="delyes" data-id="${r.id}">Sí</button>
             <button class="admin-btn" data-act="delno">No</button>`
          : `<button class="admin-btn" data-act="edit" data-id="${r.id}">Editar</button>
             <button class="admin-btn danger" data-act="del" data-id="${r.id}">Eliminar</button>`;
        const thumb = r.imagen
          ? `<img class="admin-thumb" src="${esc(r.imagen)}" alt="" loading="lazy" onerror="this.style.visibility='hidden'">`
          : '<span class="admin-thumb admin-thumb-vacio">sin img</span>';
        return `<div class="admin-row" style="--i:${i}">
          <div class="admin-main">
            ${thumb}
            <div class="admin-info">
              <div class="admin-name">${esc(r.nombre)}
                <span class="admin-badge ${r.estado === 'operativo' ? 'ok' : 'off'}">${r.estado}</span></div>
              <div class="admin-specs">${esc([r.marca, r.resolucion, r.brillo, r.conexiones].filter(Boolean).join(' · '))}</div>
            </div>
          </div>
          <div class="admin-actions">${acciones}</div>
        </div>`;
      }).join('');
      $a().innerHTML = `<div class="admin-list">${rows || '<p class="admin-empty">No hay proyectores cargados.</p>'}</div>
        <div class="admin-footer">
          <button class="btn-confirm admin-add" data-act="add">+ Agregar proyector</button>
          <button class="btn-cancel" data-act="close">Cerrar</button>
        </div>`;
    }

    /* ── Imagen del proyector: se elige un archivo local y se guarda en la columna LONGBLOB ── */
    let adminImg = { dataUrl: null, quitar: false };      // cambio pendiente de imagen (null/false = no tocar)
    const adminImgReset = () => { adminImg = { dataUrl: null, quitar: false }; };

    const IMG_TIPOS = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];
    const IMG_SIN_ACHICAR = 1.5 * 1024 * 1024;   // hasta este peso se sube tal cual
    const IMG_MAX_LADO = 1600;                    // si pesa más, se reduce a este largo máximo (px)
    const IMG_MAX_FINAL = 4 * 1024 * 1024;        // tope tras reducir (el servidor acepta hasta 5 MB)

    function blobADataURL(blob) {
      return new Promise((ok, fail) => {
        const fr = new FileReader();
        fr.onload = () => ok(fr.result);
        fr.onerror = () => fail(new Error('No se pudo leer el archivo'));
        fr.readAsDataURL(blob);
      });
    }

    /** Valida el archivo elegido y devuelve un data URL listo para enviar (achicándolo si es muy pesado). */
    async function prepararImagen(file) {
      if (!IMG_TIPOS.includes(file.type)) throw new Error('Formato no válido: usá PNG, JPG, WEBP o GIF');
      let salida = file;
      if (file.size > IMG_SIN_ACHICAR && file.type !== 'image/gif') {   // los GIF no se tocan (pueden ser animados)
        let bmp;
        try { bmp = await createImageBitmap(file); } catch (e) { throw new Error('No se pudo abrir la imagen'); }
        const k = Math.min(1, IMG_MAX_LADO / Math.max(bmp.width, bmp.height));
        const cv = document.createElement('canvas');
        cv.width = Math.max(1, Math.round(bmp.width * k));
        cv.height = Math.max(1, Math.round(bmp.height * k));
        cv.getContext('2d').drawImage(bmp, 0, 0, cv.width, cv.height);
        if (bmp.close) bmp.close();
        const blob = await new Promise(res => cv.toBlob(res, file.type, 0.88));
        if (blob && blob.size < file.size) salida = blob;
      }
      if (salida.size > IMG_MAX_FINAL) throw new Error('La imagen es demasiado pesada (máx. 4 MB). Probá con una más chica.');
      return blobADataURL(salida);
    }

    /** Muestra en el formulario la imagen que quedaría guardada (la nueva, la actual, o ninguna). */
    function adminActualizarPreview() {
      const prev = document.getElementById('af-prev');
      if (!prev) return;
      const actual = (adminRows.find(x => x.id === adminEditId) || {}).imagen || '';
      const src = adminImg.dataUrl || (adminImg.quitar ? '' : actual);
      prev.textContent = '';
      if (src) {
        const im = document.createElement('img');
        im.alt = 'Vista previa';
        im.onerror = () => { prev.textContent = 'No se pudo mostrar la imagen'; };
        im.src = src;
        prev.appendChild(im);
      } else {
        prev.textContent = 'Sin imagen';
      }
      const q = document.getElementById('af-quitar');
      if (q) q.style.display = src ? '' : 'none';
    }

    async function adminSave() {
      const v = k => document.getElementById('af-' + k).value.trim();
      const d = { nombre: v('nombre'), marca: v('marca'), resolucion: v('resolucion'), brillo: v('brillo'), conexiones: v('conexiones'), estado: v('estado') };
      if (adminImg.dataUrl) d.imagen_data = adminImg.dataUrl;        // imagen nueva elegida desde el equipo
      else if (adminImg.quitar) d.quitar_imagen = true;              // dejar el proyector sin imagen
      const err = document.getElementById('af-err');
      err.classList.remove('show');
      if (!d.nombre || !d.marca) { err.textContent = 'Completá nombre y marca'; err.classList.add('show'); return; }
      const btn = document.querySelector('#admin-body [data-act="save"]');
      const txtBtn = btn ? btn.textContent : '';
      if (btn) { btn.disabled = true; btn.textContent = 'Guardando…'; }
      try {
        if (adminEditId == null) await proyApi.create(d); else await proyApi.update(adminEditId, d);
        showToast(adminEditId == null ? 'Proyector agregado' : 'Cambios guardados');
        adminView = 'list'; adminEditId = null; adminImgReset();
        await refreshAdmin(); await cargarProyectores(); renderAll();
      } catch (e) {
        err.textContent = e.message; err.classList.add('show');
        if (btn) { btn.disabled = false; btn.textContent = txtBtn; }
      }
    }

    async function adminDelete(id) {
      try {
        await proyApi.remove(id);
        showToast('Proyector eliminado');
        adminConfirmId = null;
        await refreshAdmin(); await cargarProyectores(); renderAll();
      } catch (e) { showToast(e.message); }
    }

    document.getElementById('admin-body')?.addEventListener('click', e => {
      const b = e.target.closest('[data-act]'); if (!b) return;
      const id = b.dataset.id ? Number(b.dataset.id) : null;
      switch (b.dataset.act) {
        case 'add': adminView = 'form'; adminEditId = null; adminImgReset(); renderAdmin(); break;
        case 'edit': adminView = 'form'; adminEditId = id; adminImgReset(); renderAdmin(); break;
        case 'back': adminView = 'list'; adminEditId = null; adminImgReset(); renderAdmin(); break;
        case 'close': adminImgReset(); adminView = 'list'; adminEditId = null; closeAdmin(); break;
        case 'save': adminSave(); break;
        case 'imgelegir': document.getElementById('af-file').click(); break;
        case 'imgquitar':
          adminImg = { dataUrl: null, quitar: true };
          document.getElementById('af-file').value = '';
          document.getElementById('af-fname').textContent = '';
          adminActualizarPreview();
          break;
        case 'del': adminConfirmId = id; renderAdmin(); break;
        case 'delno': adminConfirmId = null; renderAdmin(); break;
        case 'delyes': adminDelete(id); break;
      }
    });
    // al elegir un archivo: se valida, se reduce si hace falta y se muestra la vista previa
    document.getElementById('admin-body')?.addEventListener('change', async e => {
      if (e.target.id !== 'af-file') return;
      const file = e.target.files && e.target.files[0];
      if (!file) return;
      const err = document.getElementById('af-err');
      const nom = document.getElementById('af-fname');
      err.classList.remove('show');
      nom.textContent = 'Procesando…';
      try {
        adminImg = { dataUrl: await prepararImagen(file), quitar: false };
        nom.textContent = 'Nueva imagen: ' + file.name;
        adminActualizarPreview();
      } catch (ex) {
        e.target.value = '';
        nom.textContent = '';
        err.textContent = ex.message; err.classList.add('show');
      }
    });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeAdmin(); });

    renderSelector();
    if (PROYECTORES_INICIAL) {
      aplicarProyectores(PROYECTORES_INICIAL);
      if (RESERVAS_INICIAL) guardarSemana(RESERVAS_INICIAL);   // semana actual ya cargada por PHP: se muestra sin esperar
      loadReservations();
    } else {
      document.getElementById('status-text').textContent = 'Sin conexión con la base de datos';
      showToast(ERROR_BD || 'No se pudo conectar con la base de datos');
    }
    // Cada 30 s se actualizan las reservas (y el paso del tiempo); la lista de proyectores —que calcula el hash de
    // cada imagen en MySQL— solo cada 5 min, o enseguida si todavía no se pudo cargar. Con la pestaña oculta no se consulta.
    let proyTs = Date.now();
    function refrescarPeriodico() {
      if (document.hidden) return;
      if (!PROYECTORES.length || Date.now() - proyTs >= 5 * 60 * 1000) { proyTs = Date.now(); refrescarTodo(); }
      else loadReservations(true);
    }
    setInterval(refrescarPeriodico, 30000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refrescarPeriodico(); });

/* ── Botón flotante "Turno Tarde" (teléfono) ──
 * Aparece desde abajo, lleva al turno tarde y desaparece al presionarlo.
 * Vuelve a aparecer cuando se regresa a la parte superior de la página.
 */
(function () {
  const fab = document.getElementById('goto-afternoon');
  const mq = window.matchMedia('(max-width: 620px)');
  let dismissed = false;

  function update() {
    const tarde = document.getElementById('turno-tarde');
    const visible = mq.matches && !dismissed && tarde &&
      tarde.getBoundingClientRect().top > window.innerHeight * 0.6;
    fab.classList.toggle('show', !!visible);
  }

  fab.addEventListener('click', () => {
    dismissed = true;
    fab.classList.remove('show');
    const tarde = document.getElementById('turno-tarde');
    if (tarde) tarde.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });

  window.addEventListener('scroll', () => {
    if (dismissed && window.scrollY < 120) dismissed = false;
    update();
  }, { passive: true });
  window.addEventListener('resize', update);
  mq.addEventListener?.('change', update);

  // el contenido se re-renderiza (cambio de día, refresco): reevaluar
  new MutationObserver(update).observe(document.getElementById('root'), { childList: true });
  update();
})();
  </script>
</body>

</html>