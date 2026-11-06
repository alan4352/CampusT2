<?php
// Panel para el equipo directivo y administrativo.
// Desde acá se habilitan fechas, se arman las mesas, se marcan los abonos y se cargan las notas.
require_once __DIR__ . '/conexion.php';

header('Content-Type: text/html; charset=UTF-8');
date_default_timezone_set('America/Argentina/Buenos_Aires');

$usuario = null;
try {
    $usuario = usuario_mesas('directivo');
} catch (Throwable $e) {
    error_log('administrativo.php (usuario): ' . $e->getMessage());
    if (estaLogueado() && ($_SESSION['rol'] ?? '') === 'directivo') {
        $usuario = [
            'rol'    => 'directivo',
            'nombre' => trim((string) $_SESSION['nombre']),
        ];
    }
}

// Si quien quiso entrar es un alumno, lo mandamos a su pantalla; si no está logueado, vuelve al login
if ($usuario === null) {
    if (estaLogueado() && ($_SESSION['rol'] ?? '') === 'alumno') {
        header('Location: alumnos.php');
        exit;
    }
    header('Location: ' . URL_CAMPUS);
    exit;
}
session_write_close();

$nombreDirectivo = $usuario['nombre'] !== '' ? $usuario['nombre'] : 'Directivo/a';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel Administrativo - Mesas de Examen | EET N°2</title>
    
    <!-- Fuentes e íconos -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Montserrat:wght@600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" />
    
    <link rel="stylesheet" href="styles.css?v=<?= filemtime(__DIR__ . '/styles.css') ?>">
    <script src="src/js/tema.js?v=<?= filemtime(__DIR__ . '/src/js/tema.js') ?>"></script>

    <!-- Librerías para armar las actas volantes en PDF -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
</head>
<body>

    <main class="main-container directivos-mode">

        <!-- Barra superior con datos del directivo y botón para volver al campus -->
        <div class="no-print" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem; width: 100%; margin-bottom: 1rem; padding: 0.65rem 1.1rem; border-radius: 12px; background: rgba(0, 0, 0, 0.35); border: 1px solid rgba(212, 175, 55, 0.35);">
            <a href="<?= e(URL_PANEL_CAMPUS) ?>" style="color: var(--color-gold); text-decoration: none; font-weight: 700; font-size: 0.88rem; display: inline-flex; align-items: center; gap: 0.35rem;">
                <span class="material-symbols-outlined" style="font-size: 1.1rem;">arrow_back</span>
                Volver al Campus Virtual
            </a>
            <div style="display: flex; align-items: center; gap: 0.85rem; flex-wrap: wrap;">
                <div class="lugar-tema"></div>
                <span style="color: #fff; font-size: 0.88rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.35rem;">
                    <span class="material-symbols-outlined" style="font-size: 1.1rem; color: var(--color-gold);">admin_panel_settings</span>
                    <?= e($nombreDirectivo) ?> · <span style="color: var(--color-gold);">Directivo/a</span>
                </span>
                <a href="<?= e(URL_LOGOUT_CAMPUS) ?>" style="color: #ffcdd2; text-decoration: none; font-size: 0.82rem; font-weight: 700; padding: 0.3rem 0.75rem; border-radius: 8px; border: 1px solid rgba(239, 83, 80, 0.5); background: rgba(183, 28, 28, 0.25);">
                    Cerrar sesión
                </a>
            </div>
        </div>
        
        <header class="school-header">
            <div class="escudo-wrapper">
                <img src="imagenes/logo.png" class="escudo" alt="Escudo Escuela Técnica N° 2">
            </div>
            <h1>Panel Administrativo - Mesas de Examen</h1>
        </header>

        <!-- Contenedor general del panel de directivos -->
        <section id="directivosSection" class="directivos-container">
            
            <div class="dir-layout-container">
                <!-- Menú de navegación entre las distintas pestañas -->
                <aside class="dir-sidebar no-print">
                    <div class="dir-sidebar-title">
                        <span class="material-symbols-outlined">admin_panel_settings</span>
                        <span>Menú Directivos</span>
                    </div>
                    <div class="dir-nav-wrapper">
                        <button type="button" class="dir-nav-arrow dir-nav-arrow-prev" id="btnNavPrev" aria-label="Opción anterior" title="Opción anterior">
                            <span class="material-symbols-outlined">chevron_left</span>
                        </button>
                        <nav class="dir-sidebar-nav" id="dirSidebarNav">
                            <button type="button" class="dir-tab-btn active" data-tab="tabHabilitar">
                                <span class="material-symbols-outlined">event_available</span>
                                <span>Habilitar Preinscripciones</span>
                            </button>
                            <button type="button" class="dir-tab-btn" data-tab="tabMesas">
                                <span class="material-symbols-outlined">edit_calendar</span>
                                <span>Crear Mesas</span>
                            </button>
                            <button type="button" class="dir-tab-btn" data-tab="tabAbonos">
                                <span class="material-symbols-outlined">payments</span>
                                <span>Registro de Abonos</span>
                            </button>
                            <button type="button" class="dir-tab-btn" data-tab="tabListados">
                                <span class="material-symbols-outlined">assignment</span>
                                <span>Listados por Mesa</span>
                            </button>
                            <button type="button" class="dir-tab-btn" data-tab="tabNotas">
                                <span class="material-symbols-outlined">grade</span>
                                <span>Carga de Notas</span>
                            </button>
                            <button type="button" class="dir-tab-btn" data-tab="tabStats">
                                <span class="material-symbols-outlined">analytics</span>
                                <span>Estadísticas</span>
                            </button>
                        </nav>
                        <button type="button" class="dir-nav-arrow dir-nav-arrow-next" id="btnNavNext" aria-label="Siguiente opción" title="Siguiente opción">
                            <span class="material-symbols-outlined">chevron_right</span>
                        </button>
                    </div>
                    <div class="dir-sidebar-footer">
                        <div id="lugarTema" class="lugar-tema"></div>
                    </div>
                </aside>

                <!-- Área de Contenido Principal -->
                <div class="dir-content-area">

                    <!-- SECCIÓN 1: HABILITAR PREINSCRIPCIONES (PRIMERA OPCIÓN) -->
                    <div id="tabHabilitar" class="dir-tab-content active">
                        <div class="form-card-block">
                            <div class="section-title">
                                <span class="material-symbols-outlined">event_available</span>
                                Habilitar Período de Preinscripciones
                            </div>
                            <p style="color: var(--color-text-muted); font-size: 0.88rem; margin-top: -0.5rem; margin-bottom: 1.25rem;">
                                Configure el rango de fechas en el cual los alumnos podrán acceder al formulario y preinscribirse a las mesas de examen. Fuera de este rango, o si la opción está desactivada, el acceso de los alumnos permanecerá bloqueado con un aviso informativo.
                            </p>

                            <!-- Tarjeta de Estado en Tiempo Real -->
                            <div id="configStatusCard" class="config-status-card" style="margin-bottom: 1.5rem; padding: 1.1rem 1.3rem; border-radius: 12px; background: rgba(76, 175, 80, 0.08); border: 1px solid rgba(76, 175, 80, 0.3); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
                                <div style="display: flex; align-items: center; gap: 0.85rem;">
                                    <span id="configStatusIcon" class="material-symbols-outlined" style="font-size: 2.2rem; color: #4caf50;">check_circle</span>
                                    <div>
                                        <div style="font-size: 0.74rem; text-transform: uppercase; letter-spacing: 0.5px; color: var(--color-text-muted);">Estado del Sistema de Alumnos</div>
                                        <div id="configStatusTitle" style="font-size: 1.1rem; font-weight: 700; color: #fff;">Preinscripciones Habilitadas</div>
                                        <div id="configStatusDesc" style="font-size: 0.82rem; color: rgba(255,255,255,0.7);">Los alumnos pueden acceder normalmente al formulario y cargar materias adeudadas.</div>
                                    </div>
                                </div>
                                <span id="configStatusBadge" class="status-badge" style="background: rgba(76, 175, 80, 0.2); color: #4caf50; border: 1px solid rgba(76, 175, 80, 0.4); padding: 6px 14px; border-radius: 20px; font-weight: 600; font-size: 0.85rem;">Habilitado</span>
                            </div>

                            <!-- Interruptor Principal y Nota Informativa -->
                            <div class="form-row-2col" style="align-items: center; margin-bottom: 1.25rem;">
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label class="form-label" style="margin-bottom: 0.5rem;">
                                        <span class="material-symbols-outlined">toggle_on</span>
                                        Habilitación General *
                                    </label>
                                    <div style="display: flex; align-items: center; gap: 0.75rem; background: rgba(0,0,0,0.25); padding: 0.75rem 1rem; border-radius: 10px; border: 1px solid rgba(255,255,255,0.1);">
                                        <input type="checkbox" id="configEnableToggle" checked style="width: 22px; height: 22px; accent-color: var(--color-gold); cursor: pointer;">
                                        <label for="configEnableToggle" style="cursor: pointer; font-size: 0.92rem; font-weight: 600; color: #fff; margin: 0;">
                                            Habilitar preinscripciones de alumnos
                                        </label>
                                    </div>
                                </div>
                                <div class="form-group" style="margin-bottom: 0;">
                                    <div style="font-size: 0.8rem; color: var(--color-text-muted); line-height: 1.4; padding: 0.5rem 0.75rem; border-left: 3px solid var(--color-gold); background: rgba(212, 175, 55, 0.05); border-radius: 0 8px 8px 0;">
                                        <strong>Control escolar:</strong> Desmarcar esta casilla suspende inmediatamente el acceso de los alumnos sin borrar las fechas guardadas.
                                    </div>
                                </div>
                            </div>

                            <!-- Selector de Rango de Fechas -->
                            <div class="form-row-2col" style="margin-bottom: 1.5rem;">
                                <div class="form-group">
                                    <label for="configFechaInicio" class="form-label">
                                        <span class="material-symbols-outlined">calendar_today</span>
                                        Fecha de Inicio (Habilitar Desde) *
                                    </label>
                                    <input type="date" id="configFechaInicio" class="form-control" max="2031-12-31" required>
                                </div>

                                <div class="form-group">
                                    <label for="configFechaFin" class="form-label">
                                        <span class="material-symbols-outlined">event_busy</span>
                                        Fecha de Cierre (Habilitar Hasta) *
                                    </label>
                                    <input type="date" id="configFechaFin" class="form-control" min="<?= date('Y-m-d') ?>" max="2031-12-31" required>
                                </div>
                            </div>

                            <div class="form-group" style="margin-bottom: 1.5rem;">
                                <label for="configImporteInscripcion" class="form-label">
                                    <span class="material-symbols-outlined">payments</span>
                                    Importe de Inscripción *
                                </label>
                                <input type="number" id="configImporteInscripcion" class="form-control" min="2500" max="30000" step="50" placeholder="3000" required>
                            </div>

                            <div style="display: flex; align-items: center; justify-content: center; gap: 1rem; padding-top: 1.25rem; border-top: 1px solid rgba(255, 255, 255, 0.1);">
                                <button type="button" id="btnSaveConfigHabilitar" class="btn-ingresar" style="width: auto; min-width: 280px; padding: 0.85rem 2.2rem; font-size: 1.05rem; font-weight: 800; letter-spacing: 0.5px; box-shadow: 0 4px 18px rgba(212, 175, 55, 0.4); text-transform: uppercase;">
                                    <span class="material-symbols-outlined">save</span>
                                    Guardar Configuración
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- SECCIÓN 2: CREAR Y GESTIONAR MESAS DE EXAMEN -->
                    <div id="tabMesas" class="dir-tab-content">
                        <div class="form-card-block">
                            <div class="section-title">
                                <span class="material-symbols-outlined">add_box</span>
                                Crear Nueva Mesa de Examen
                            </div>

                            <form id="formCreateMesa" novalidate>
                                <!-- Selector de Cursos Dinámico (Hasta 3 a la vez, sin egresado) -->
                                <div class="form-group" style="margin-bottom: 1.1rem;">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; flex-wrap: wrap; gap: 6px;">
                                        <label class="form-label" style="margin-bottom: 0;">
                                            <span class="material-symbols-outlined">school</span>
                                            Cursos de la Mesa * <span style="font-size: 0.82rem; font-weight: 500; opacity: 0.85;">(Selecciona hasta 3 cursos)</span>
                                        </label>
                                        <span id="mesaCourseCountBadge" class="subject-badge-count">0 de 3 seleccionados</span>
                                    </div>
                                    <div class="course-chips-grid" id="mesaCourseChipsGrid">
                                        <button type="button" class="course-chip" data-course="1ro">1er AÑO</button>
                                        <button type="button" class="course-chip" data-course="2do">2do AÑO</button>
                                        <button type="button" class="course-chip" data-course="3ro">3er AÑO</button>
                                        <button type="button" class="course-chip" data-course="4to">4to AÑO</button>
                                        <button type="button" class="course-chip" data-course="5to">5to AÑO</button>
                                        <button type="button" class="course-chip" data-course="6to">6to AÑO</button>
                                        <button type="button" class="course-chip" data-course="7mo">7mo AÑO</button>
                                    </div>
                                    <input type="hidden" id="mesaCourseSelect" value="">
                                </div>

                                <!-- Tecnicatura (visible si se seleccionó algún curso de 4to a 7mo) -->
                                <div class="form-group conditional-field" id="mesaSpecialtyContainer" style="display: none; margin-bottom: 1.1rem;">
                                    <label for="mesaSpecialtySelect" class="form-label">
                                        <span class="material-symbols-outlined">engineering</span>
                                        Tecnicatura / Especialidad *
                                    </label>
                                    <select id="mesaSpecialtySelect" class="form-select" disabled>
                                        <option value="" disabled selected>-- Seleccionar Tecnicatura --</option>
                                        <option value="TAE">Técnico en Administración de Empresas (TAE)</option>
                                        <option value="TC">Técnico en Computación (TC)</option>
                                        <option value="TG">Técnico en Gastronomía (TG)</option>
                                    </select>
                                </div>

                                <!-- Selector de Asignaturas / Materias (materias en común y familias) -->
                                <div class="form-group" style="margin-bottom: 1.1rem;">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; flex-wrap: wrap; gap: 6px;">
                                        <label class="form-label" style="margin-bottom: 0;">
                                            <span class="material-symbols-outlined">auto_stories</span>
                                            Asignatura / Materia(s) * <span style="font-size: 0.82rem; font-weight: 500; opacity: 0.85;">(materias con alumnos inscriptos)</span>
                                        </label>
                                        <span id="mesaSubjectStatusBadge" class="subject-badge-count" style="display: none;">0 seleccionada(s)</span>
                                    </div>
                                    <div id="mesaSubjectPickerContainer" class="mesa-subject-picker">
                                        <div class="picker-placeholder">
                                            <span class="material-symbols-outlined" style="font-size: 1.3rem; vertical-align: middle;">info</span>
                                            Selecciona al menos un curso arriba para ver las materias disponibles.
                                        </div>
                                    </div>
                                    <input type="hidden" id="mesaSubjectSelect" value="">
                                </div>

                                <div class="form-row-2col">
                                    <div class="form-group">
                                        <label for="mesaTurnSelect" class="form-label">
                                            <span class="material-symbols-outlined">event</span>
                                            Instancia / Turno *
                                        </label>
                                        <select id="mesaTurnSelect" class="form-select" required>
                                            <option value="Febrero / Marzo" selected>Febrero / Marzo</option>
                                            <option value="Julio / Agosto">Julio / Agosto</option>
                                            <option value="Noviembre / Diciembre">Noviembre / Diciembre</option>
                                        </select>
                                    </div>

                                    <div class="form-group">
                                        <label for="mesaClassroomInput" class="form-label">
                                            <span class="material-symbols-outlined">meeting_room</span>
                                            Aula / Laboratorio
                                        </label>
                                        <select id="mesaClassroomInput" class="form-select">
                                            <option value="" selected>-- Seleccionar Aula --</option>
                                            <option value="1">1</option>
                                            <option value="2">2</option>
                                            <option value="3">3</option>
                                            <option value="4">4</option>
                                            <option value="5">5</option>
                                            <option value="6">6</option>
                                            <option value="7">7</option>
                                            <option value="8">8</option>
                                            <option value="9">9</option>
                                            <option value="10">10</option>
                                            <option value="11">11</option>
                                            <option value="13">13</option>
                                            <option value="14 A">14 A</option>
                                            <option value="14 B">14 B</option>
                                            <option value="15">15</option>
                                            <option value="16">16</option>
                                            <option value="17">17</option>
                                            <option value="18">18</option>
                                            <option value="19">19</option>
                                            <option value="20 A">20 A</option>
                                            <option value="20 B">20 B</option>
                                            <option value="21">21</option>
                                            <option value="Laboratorio">Laboratorio</option>
                                            <option value="Imprenta">Imprenta</option>
                                            <option value="Taller G. 1">Taller G. 1</option>
                                            <option value="Taller G. 2">Taller G. 2</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-row-2col">
                                    <div class="form-group">
                                        <label for="mesaDateInput" class="form-label">
                                            <span class="material-symbols-outlined">calendar_today</span>
                                            Fecha del Examen *
                                        </label>
                                        <input type="date" id="mesaDateInput" class="form-control" min="<?= date('Y-m-d') ?>" max="2031-12-31" required>
                                    </div>

                                    <div class="form-group">
                                        <label for="mesaTimeInput" class="form-label">
                                            <span class="material-symbols-outlined">schedule</span>
                                            Hora de Inicio *
                                        </label>
                                        <input type="time" id="mesaTimeInput" class="form-control" value="08:00" min="07:00" max="19:00" required>
                                    </div>
                                </div>

                                <!-- Tribunal Docente: Presidente de Mesa y Vocales -->
                                <div class="tribunal-section">
                                    <label class="form-label" style="margin-bottom: 0.6rem;">
                                        <span class="material-symbols-outlined">badge</span>
                                        Tribunal Docente (Obligatorio Nombre y Apellido) *
                                    </label>
                                    
                                    <!-- Presidente de Mesa con Distinción Especial -->
                                    <div class="form-group tribunal-field-wrapper pres-wrapper" style="margin-bottom: 0.75rem;">
                                        <div class="tribunal-badge-header">
                                            <span class="tribunal-badge pres-badge">
                                                <span class="material-symbols-outlined">star</span>
                                                Presidente de Mesa (Titular) *
                                            </span>
                                        </div>
                                        <input type="text" id="mesaPresDocente" class="form-control tribunal-input-pres" placeholder="Nombre y Apellido del Presidente *" required autocomplete="off">
                                    </div>

                                    <!-- 1° Vocal -->
                                    <div class="form-group tribunal-field-wrapper" style="margin-bottom: 0.6rem;">
                                        <div class="tribunal-badge-header">
                                            <span class="tribunal-badge vocal-badge">
                                                <span class="material-symbols-outlined">person</span>
                                                1° Vocal *
                                            </span>
                                        </div>
                                        <input type="text" id="mesaVocal1Docente" class="form-control" placeholder="Nombre y Apellido del 1° Vocal *" required autocomplete="off">
                                    </div>

                                    <!-- 2° Vocal -->
                                    <div class="form-group tribunal-field-wrapper" style="margin-bottom: 0.6rem;">
                                        <div class="tribunal-badge-header">
                                            <span class="tribunal-badge vocal-badge">
                                                <span class="material-symbols-outlined">person</span>
                                                2° Vocal *
                                            </span>
                                        </div>
                                        <input type="text" id="mesaVocal2Docente" class="form-control" placeholder="Nombre y Apellido del 2° Vocal *" required autocomplete="off">
                                    </div>
                                </div>

                                <div style="display: flex; gap: 0.8rem; margin-top: 0.8rem;">
                                    <button type="submit" id="btnSubmitMesa" class="btn-ingresar" style="flex: 1;">
                                        <span class="material-symbols-outlined" id="btnSubmitMesaIcon">event_available</span>
                                        <span id="btnSubmitMesaText">Crear Mesa de Examen</span>
                                    </button>
                                    <button type="button" id="btnCancelEditMesa" class="btn-secondary-auth" style="display: none; padding: 12px 20px; border-radius: 10px; align-items: center; gap: 6px;">
                                        <span class="material-symbols-outlined">cancel</span> Cancelar Edición
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- Lista de Mesas Creadas -->
                        <div class="form-card-block" style="margin-top: 1.5rem;">
                            <div class="section-title">
                                <span class="material-symbols-outlined">calendar_month</span>
                                Mesas de Examen Programadas
                            </div>
                            <div class="table-responsive">
                                <table class="dir-data-table">
                                    <thead>
                                        <tr>
                                            <th>Año Materia</th>
                                            <th>Materia</th>
                                            <th>Turno</th>
                                            <th>Día y Hora</th>
                                            <th>Aula</th>
                                            <th>Inscriptos</th>
                                            <th>Acción</th>
                                        </tr>
                                    </thead>
                                    <tbody id="mesasTableBody"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- SECCIÓN 3: REGISTRO DE ABONOS Y PAGOS -->
                    <div id="tabAbonos" class="dir-tab-content">
                        <div class="form-card-block">
                            <div class="section-title" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                    <span class="material-symbols-outlined">payments</span>
                                    Gestión y Registro de Abono de Aranceles
                                </div>
                                <div style="display: flex; align-items: center; gap: 0.75rem;">
                                    <span id="abonosFilterCount" class="badge-count" style="font-size: 0.85rem; padding: 4px 12px; background: var(--color-gold); color: #500000; font-weight: 800; border-radius: 20px;">0 alumno(s)</span>
                                    <button type="button" id="btnClearAbonosTable" class="btn-vaciar-tabla" title="Vaciar la tabla de alumnos del turno al terminar el llamado" style="display: inline-flex; align-items: center; gap: 5px; background: rgba(229, 57, 53, 0.18); color: #ff8a80; border: 1px solid rgba(229, 57, 53, 0.45); padding: 5px 12px; border-radius: 8px; font-size: 0.82rem; font-weight: 600; cursor: pointer; transition: all 0.2s ease;">
                                        <span class="material-symbols-outlined" style="font-size: 1.05rem;">delete_sweep</span>
                                        Vaciar Tabla
                                    </button>
                                </div>
                            </div>

                            <!-- Filtros Dinámicos -->
                            <div class="filter-bar">
                                <select id="filterCourseAbono" class="form-select filter-item">
                                    <option value="">Año</option>
                                    <option value="1er AÑO">1er AÑO</option>
                                    <option value="2do AÑO">2do AÑO</option>
                                    <option value="3er AÑO">3er AÑO</option>
                                    <option value="4to TAE">4to TAE</option>
                                    <option value="5to TAE">5to TAE</option>
                                    <option value="6to TAE">6to TAE</option>
                                    <option value="7mo TAE">7mo TAE</option>
                                    <option value="4to TC">4to TC</option>
                                    <option value="5to TC">5to TC</option>
                                    <option value="6to TC">6to TC</option>
                                    <option value="7mo TC">7mo TC</option>
                                    <option value="4to TG">4to TG</option>
                                    <option value="5to TG">5to TG</option>
                                    <option value="6to TG">6to TG</option>
                                    <option value="7mo TG">7mo TG</option>
                                </select>

                                <select id="filterTurnAbono" class="form-select filter-item">
                                    <option value="Febrero / Marzo">Febrero / Marzo</option>
                                    <option value="Julio / Agosto">Julio / Agosto</option>
                                    <option value="Noviembre / Diciembre">Noviembre / Diciembre</option>
                                </select>

                                <select id="filterStatusAbono" class="form-select filter-item">
                                    <option value="">Estado</option>
                                    <option value="paid">ABONADO</option>
                                    <option value="unpaid">PENDIENTE</option>
                                </select>

                                <input type="text" id="searchAbonoInput" class="form-control filter-item" placeholder="Buscar por materia, alumno o DNI...">
                            </div>

                            <div class="table-responsive" style="margin-top: 1.2rem;">
                                <table class="dir-data-table">
                                    <thead>
                                        <tr>
                                            <th>Alumno</th>
                                            <th>Año Materia</th>
                                            <th>Materia Solicitada</th>
                                            <th>Turno</th>
                                            <th>Estado Abono</th>
                                            <th>Acción</th>
                                        </tr>
                                    </thead>
                                    <tbody id="abonosTableBody"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- SECCIÓN 4: LISTADOS PARA MESAS (DOCENTES) -->
                    <div id="tabListados" class="dir-tab-content">
                        <div class="form-card-block no-print">
                            <div class="section-title">
                                <span class="material-symbols-outlined">print_connect</span>
                                Generar Acta de Examen para Tribunal Docente
                            </div>

                            <div class="roster-toolbar-grid">
                                <div class="form-group roster-select-group">
                                    <label for="selectMesaListado" class="form-label">
                                        <span class="material-symbols-outlined">event_note</span>
                                        Seleccionar Mesa de Examen Programada *
                                    </label>
                                    <select id="selectMesaListado" class="form-select"></select>
                                </div>
                                <div class="roster-buttons-group">
                                    <button type="button" id="btnDownloadRosterPdf" class="btn-ingresar">
                                        <span class="material-symbols-outlined">download</span>
                                        <span>Descargar PDF</span>
                                    </button>
                                    <button type="button" id="btnPrintRoster" class="btn-secondary-auth">
                                        <span class="material-symbols-outlined">print</span>
                                        <span>Imprimir</span>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Plantilla Oficial CGE de Acta de Examen para Docentes (Calco exacto del modelo oficial A4) -->
                        <div id="rosterPrintContainer" class="roster-print-sheet" style="display: none;">
                            <!-- PÁGINA 1: ACTA VOLANTE DE EXÁMEN -->
                            <div class="roster-page-1">
                                <!-- Encabezado Superior Oficial -->
                                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 6px; font-family: Arial, Helvetica, sans-serif; color: #000;">
                                    <div style="width: 27%; display: flex; flex-direction: column; align-items: flex-start;">
                                        <div style="display: flex; align-items: flex-start; gap: 14px; width: 100%;">
                                            <div style="font-size: 8pt; color: #000; line-height: 2.3; padding-top: 2px; min-width: 52px;">
                                                <div>Tomo:....</div>
                                                <div>Folio:.....</div>
                                            </div>
                                            <div style="display: flex; justify-content: center; flex: 1; padding-right: 18px;">
                                                <img src="imagenes/escudo.png" id="rosterEscudoImg" style="width: 44px; height: auto;" alt="Escudo Entre Ríos">
                                            </div>
                                        </div>
                                        <div style="font-size: 6.5pt; line-height: 1.35; color: #000; text-align: center; width: 185px; margin-top: 3px;">
                                            PROVINCIA DE ENTRE RIOS<br>
                                            CONSEJO GENERAL DE EDUCACIÓN<br>
                                            DIRECCIÓN DE EDUCACIÓN TÉCNICO<br>
                                            PROFESIONAL
                                        </div>
                                    </div>

                                    <div style="width: 37%; text-align: left; padding-top: 20px; padding-left: 22px;">
                                        <div style="font-size: 10.5pt; font-weight: normal; margin: 0; color: #000; letter-spacing: 0.2px;">
                                            E.E.T. N° 2 "INDEPENDENCIA"
                                        </div>
                                        <div style="font-size: 7pt; font-weight: normal; margin: 2px 0 0 0; color: #000;">
                                            ANEXO DE FORMACIÓN PROFESIONAL
                                        </div>
                                        <div style="margin-top: 22px; font-size: 8pt; text-align: left; color: #000;">
                                            Exámen de alumnos:
                                        </div>
                                    </div>

                                    <div style="width: 36%; display: flex; flex-direction: column; align-items: flex-end;">
                                        <div style="display: flex; align-items: center; justify-content: flex-end; gap: 10px; width: 100%; margin-bottom: 2px;">
                                            <div style="font-size: 9pt; font-weight: bold; text-align: right; color: #000; letter-spacing: 0.2px; padding-top: 6px;">
                                                ACTA VOLANTE DE EXÁMEN
                                            </div>
                                            <img src="imagenes/logo.png" id="rosterLogoImg" style="width: 52px; height: auto;" alt="Logo EET N°2">
                                        </div>
                                        <div style="width: 210px;">
                                            <div style="font-size: 7.5pt; text-align: center; color: #000; margin-bottom: 2px; padding-right: 20px;">
                                                FECHA
                                            </div>
                                            <table style="width: 100%; border: 1.5px solid #000; border-collapse: collapse; text-align: center; font-size: 7.5pt;">
                                                <thead>
                                                    <tr style="font-weight: normal; color: #000; height: 17px;">
                                                        <th style="border: 1px solid #000; padding: 1px 2px; width: 25%; font-weight: normal;">DÍA</th>
                                                        <th style="border: 1px solid #000; padding: 1px 2px; width: 25%; font-weight: normal;">MES</th>
                                                        <th style="border: 1px solid #000; padding: 1px 2px; width: 25%; font-weight: normal;">AÑO</th>
                                                        <th style="border: 1px solid #000; padding: 1px 2px; width: 25%; font-weight: normal;">HORA</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr style="height: 19px; font-size: 8pt; font-weight: normal; color: #000;">
                                                        <td style="border: 1px solid #000;" id="rosterFechaDia"></td>
                                                        <td style="border: 1px solid #000;" id="rosterFechaMes"></td>
                                                        <td style="border: 1px solid #000;" id="rosterFechaAnio"></td>
                                                        <td style="border: 1px solid #000;" id="rosterFechaHora"></td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>

                                <!-- Fila de Datos: Asignatura / Año / Div / Turno -->
                                <div style="margin-top: 4px; margin-bottom: 10px; padding-left: 19%; font-family: Arial, Helvetica, sans-serif; font-size: 8pt; display: flex; align-items: flex-end; gap: 3px; width: 100%; color: #000; box-sizing: border-box;">
                                    <span style="padding-bottom: 3px; white-space: nowrap;">Asignatura:</span>
                                    <span id="rosterSubjectName" style="flex: 1; border-bottom: 1px dotted #000; padding: 0 6px 5px 6px; min-width: 140px; text-align: left; line-height: 1.35; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"></span>
                                    <span style="padding-bottom: 3px; white-space: nowrap;">Año:</span>
                                    <span id="rosterCourse" style="width: 44px; border-bottom: 1px dotted #000; padding: 0 2px 5px 2px; text-align: center; line-height: 1.35; white-space: nowrap;"></span>
                                    <span style="padding-bottom: 3px; white-space: nowrap;">Div</span>
                                    <span id="rosterDivision" style="width: 36px; border-bottom: 1px dotted #000; padding: 0 2px 5px 2px; text-align: center; line-height: 1.35; white-space: nowrap;"></span>
                                    <span style="padding-bottom: 3px; white-space: nowrap;">Turno:</span>
                                    <span id="rosterTurn" style="min-width: 96px; border-bottom: 1px dotted #000; padding: 0 4px 5px 4px; text-align: center; line-height: 1.35; white-space: nowrap;"></span>
                                </div>

                                <!-- Tabla Oficial de Calificaciones (30 Filas Exactas) -->
                                <table class="roster-main-table" style="width: 100%; border-collapse: collapse; font-family: Arial, Helvetica, sans-serif; font-size: 7.5pt; border: 1.5px solid #000;">
                                    <thead>
                                        <tr style="background: transparent; text-align: center; color: #000; height: 38px;">
                                            <th style="border: 1px solid #000; border-bottom: 1.5px solid #000; padding: 2px 2px; width: 46px; font-size: 7pt; font-weight: normal; vertical-align: middle; line-height: 1.2;">
                                                N° DE<br>ORDEN
                                            </th>
                                            <th style="border: 1px solid #000; border-bottom: 1.5px solid #000; padding: 2px 2px; width: 82px; font-size: 7.5pt; font-weight: normal; vertical-align: middle;">
                                                L° Y F°
                                            </th>
                                            <th style="border: 1px solid #000; border-bottom: 1.5px solid #000; padding: 2px 2px; width: 86px; font-size: 7.5pt; font-weight: normal; vertical-align: middle;">
                                                D.N.I
                                            </th>
                                            <th style="border: 1px solid #000; border-bottom: 1.5px solid #000; padding: 2px 6px; font-size: 7.5pt; font-weight: normal; text-align: center; vertical-align: middle;">
                                                APELLIDO Y NOMBRES
                                            </th>
                                            <th colspan="3" style="border: 1px solid #000; border-bottom: 1.5px solid #000; padding: 0; width: 156px; font-size: 7.5pt; font-weight: normal; text-align: center; vertical-align: top;">
                                                <div style="border-bottom: 1px solid #000; height: 19px; display: flex; align-items: center; justify-content: center; width: 100%; box-sizing: border-box;">
                                                    CALIFICACIONES
                                                </div>
                                                <div style="display: grid; grid-template-columns: 52px 52px 52px; height: 19px; width: 100%; box-sizing: border-box;">
                                                    <div style="border-right: 1px solid #000; display: flex; align-items: center; justify-content: center;">Escrito</div>
                                                    <div style="border-right: 1px solid #000; display: flex; align-items: center; justify-content: center;">Oral</div>
                                                    <div style="display: flex; align-items: center; justify-content: center;">Promedio</div>
                                                </div>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody id="rosterTableBody"></tbody>
                                </table>

                                <!-- Firmas del Tribunal Docente (3 Columnas) -->
                                <div class="roster-signatures-grid" style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 18px; margin-top: 32px; padding: 0 12px; font-family: Arial, Helvetica, sans-serif; font-size: 7.5pt; text-align: center; color: #000;">
                                    <div>
                                        <div style="width: 145px; margin: 0 auto 4px auto; border-bottom: 1px dotted #000; height: 10px;"></div>
                                        <div>(1) PRESIDENTE</div>
                                        <div style="margin-top: 8px; display: flex; align-items: flex-end; justify-content: center; padding: 0 6px; gap: 3px;">
                                            <span style="padding-bottom: 3px;">Aclaración:</span>
                                            <span id="rosterPresAcl" style="flex: 1; border-bottom: 1px dotted #000; text-align: left; padding: 0 3px 5px 3px; min-height: 14px; line-height: 1.35;"></span>
                                        </div>
                                    </div>
                                    <div>
                                        <div style="width: 155px; margin: 0 auto 4px auto; border-bottom: 1px dotted #000; height: 10px;"></div>
                                        <div>(2) VOCAL</div>
                                        <div style="margin-top: 8px; display: flex; align-items: flex-end; justify-content: center; padding: 0 6px; gap: 3px;">
                                            <span style="padding-bottom: 3px;">Aclaración:</span>
                                            <span id="rosterV1Acl" style="flex: 1; border-bottom: 1px dotted #000; text-align: left; padding: 0 3px 5px 3px; min-height: 14px; line-height: 1.35;"></span>
                                        </div>
                                    </div>
                                    <div>
                                        <div style="width: 165px; margin: 0 auto 4px auto; border-bottom: 1px dotted #000; height: 10px;"></div>
                                        <div>(3) VOCAL</div>
                                        <div style="margin-top: 8px; display: flex; align-items: flex-end; justify-content: center; padding: 0 6px; gap: 3px;">
                                            <span style="padding-bottom: 3px;">Aclaración:</span>
                                            <span id="rosterV2Acl" style="flex: 1; border-bottom: 1px dotted #000; text-align: left; padding: 0 3px 5px 3px; min-height: 14px; line-height: 1.35;"></span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Resumen de Alumnos -->
                                <div style="display: flex; justify-content: flex-end; margin-top: 16px; padding-right: 18px; font-family: Arial, Helvetica, sans-serif; font-size: 8pt; color: #000;">
                                    <div style="display: grid; grid-template-columns: auto 88px; column-gap: 6px; row-gap: 6px; align-items: end;">
                                        <div style="text-align: right; padding-bottom: 3px;">Total de alumnos:</div>
                                        <div id="rosterTotalAlumnos" style="border-bottom: 1px dotted #000; text-align: center; min-height: 14px; padding: 0 2px 5px 2px; line-height: 1.35;"></div>
                                        <div style="text-align: right; padding-bottom: 3px;">Aprobados:</div>
                                        <div style="border-bottom: 1px dotted #000; min-height: 14px;"></div>
                                        <div style="text-align: right; padding-bottom: 3px;">Desaprobados:</div>
                                        <div style="border-bottom: 1px dotted #000; min-height: 14px;"></div>
                                        <div style="text-align: right; padding-bottom: 3px;">Ausentes:</div>
                                        <div style="border-bottom: 1px dotted #000; min-height: 14px;"></div>
                                    </div>
                                </div>
                            </div>

                            <!-- PÁGINA 2: REVERSO - ACTA DE DESAPROBACIÓN -->
                            <div class="roster-page-break"></div>
                            <div class="roster-page-2-container">
                                <div class="roster-page-2-content">
                                    <div style="text-align: center; margin-bottom: 10px;">
                                        <div style="font-size: 11.5pt; font-weight: normal; margin: 0 0 4px 0; color: #000; letter-spacing: 0.3px; text-transform: uppercase;">
                                            ACTA DE DESAPROBACIÓN
                                        </div>
                                        <div style="font-size: 8.5pt; font-weight: normal; margin: 0; color: #000;">
                                            NOTIFICACIÓN DE LOS ALUMNOS NO APROBADOS EN MESA DE EXÁMEN (S./ Resolución 1582/11 C.G.E.)
                                        </div>
                                    </div>

                                    <table class="roster-desaprobacion-table" style="width: 100%; flex: 1; border-collapse: collapse; font-size: 7.5pt; border: 1.5px solid #000;">
                                        <thead>
                                            <tr style="background: #ffffff; text-align: center; font-weight: normal; color: #000; height: 28px;">
                                                <th style="border: 1px solid #000; padding: 2px 6px; width: 23%; font-weight: normal; vertical-align: middle;">Apellido y Nombre del Alumno</th>
                                                <th style="border: 1px solid #000; padding: 2px 6px; width: 49%; font-weight: normal; vertical-align: middle;">Criterios de Evaluación y Contenidos no alcanzados</th>
                                                <th style="border: 1px solid #000; padding: 2px 4px; width: 14%; font-weight: normal; vertical-align: middle;">Firma del<br>Alumno</th>
                                                <th style="border: 1px solid #000; padding: 2px 4px; width: 14%; font-weight: normal; vertical-align: middle;">Firma del Presidente<br>de Mesa</th>
                                            </tr>
                                        </thead>
                                        <tbody id="rosterDesaprobacionTableBody">
                                            <tr style="height: 100%;">
                                                <td style="border: 1px solid #000;"></td>
                                                <td style="border: 1px solid #000;"></td>
                                                <td style="border: 1px solid #000;"></td>
                                                <td style="border: 1px solid #000;"></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- SECCIÓN 5: CARGA DE CALIFICACIONES (1 AL 10) -->
                    <div id="tabNotas" class="dir-tab-content">
                        <div class="form-card-block">
                            <div class="section-title" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                    <span class="material-symbols-outlined">edit_note</span>
                                    Carga de Calificaciones y Notas 
                                </div>
                                <div style="display: flex; align-items: center; gap: 0.75rem;">
                                    <span id="notasFilterCount" class="badge-count" style="font-size: 0.85rem; padding: 4px 12px; background: var(--color-gold); color: #500000; font-weight: 800; border-radius: 20px;">0 alumno(s)</span>
                                    <button type="button" id="btnClearNotasTable" class="btn-vaciar-tabla" title="Vaciar la tabla de alumnos del turno al terminar el llamado" style="display: inline-flex; align-items: center; gap: 5px; background: rgba(229, 57, 53, 0.18); color: #ff8a80; border: 1px solid rgba(229, 57, 53, 0.45); padding: 5px 12px; border-radius: 8px; font-size: 0.82rem; font-weight: 600; cursor: pointer; transition: all 0.2s ease;">
                                        <span class="material-symbols-outlined" style="font-size: 1.05rem;">delete_sweep</span>
                                        Vaciar Tabla
                                    </button>
                                </div>
                            </div>

                            <div class="filter-bar">
                                <select id="filterCourseNota" class="form-select filter-item">
                                    <option value="">Año</option>
                                    <option value="1er AÑO">1er AÑO</option>
                                    <option value="2do AÑO">2do AÑO</option>
                                    <option value="3er AÑO">3er AÑO</option>
                                    <option value="4to TAE">4to TAE</option>
                                    <option value="5to TAE">5to TAE</option>
                                    <option value="6to TAE">6to TAE</option>
                                    <option value="7mo TAE">7mo TAE</option>
                                    <option value="4to TC">4to TC</option>
                                    <option value="5to TC">5to TC</option>
                                    <option value="6to TC">6to TC</option>
                                    <option value="7mo TC">7mo TC</option>
                                    <option value="4to TG">4to TG</option>
                                    <option value="5to TG">5to TG</option>
                                    <option value="6to TG">6to TG</option>
                                    <option value="7mo TG">7mo TG</option>
                                </select>

                                <select id="filterSubjectNota" class="form-select filter-item">
                                    <option value="">Materia</option>
                                </select>

                                <select id="filterTurnNota" class="form-select filter-item">
                                    <option value="Febrero / Marzo">Febrero / Marzo</option>
                                    <option value="Julio / Agosto">Julio / Agosto</option>
                                    <option value="Noviembre / Diciembre">Noviembre / Diciembre</option>
                                </select>

                                <select id="filterApprovalNota" class="form-select filter-item">
                                    <option value="">Estado</option>
                                    <option value="pending">Sin Calificar</option>
                                    <option value="passed">Aprobados</option>
                                    <option value="failed">Desaprobados</option>
                                </select>
                            </div>

                            <div class="table-responsive" style="margin-top: 0.8rem;">
                                <table class="dir-data-table">
                                    <thead>
                                        <tr>
                                            <th>Alumno</th>
                                            <th>Año Materia</th>
                                            <th>Materia</th>
                                            <th>Turno</th>
                                            <th>Resultado</th>
                                            <th>Asignar Nota (1-10)</th>
                                        </tr>
                                    </thead>
                                    <tbody id="notasTableBody"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- SECCIÓN 6: ESTADÍSTICAS Y RENDIMIENTO -->
                    <div id="tabStats" class="dir-tab-content">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; flex-wrap: wrap; gap: 0.8rem; background: rgba(0,0,0,0.22); padding: 0.85rem 1.25rem; border-radius: 12px; border: 1px solid rgba(255,255,255,0.08);">
                            <div style="display: flex; align-items: center; gap: 0.65rem;">
                                <span class="material-symbols-outlined" style="color: var(--color-gold); font-size: 1.6rem;">analytics</span>
                                <div>
                                    <div style="font-weight: 700; color: #fff; font-size: 1rem;">Estadísticas del Ciclo Lectivo</div>
                                    <div style="font-size: 0.78rem; color: var(--color-text-muted);">Las métricas se renuevan automáticamente en cada ciclo lectivo anual.</div>
                                </div>
                            </div>
                            <div style="display: flex; align-items: center; gap: 0.6rem;">
                                <label for="statYearSelect" style="font-size: 0.86rem; color: var(--color-gold); font-weight: 700;">Año Lectivo:</label>
                                <select id="statYearSelect" class="form-select filter-item" style="width: auto; min-width: 140px; margin: 0; padding: 6px 14px; font-weight: 700; border-color: var(--color-gold);">
                                    <option value="2026" selected>2026 (Actual)</option>
                                    <option value="2027">2027</option>
                                    <option value="2028">2028</option>
                                    <option value="2029">2029</option>
                                    <option value="2030">2030</option>
                                    <option value="2031">2031</option>
                                </select>
                            </div>
                        </div>

                        <div class="metrics-grid">
                            <div class="metric-card">
                                <span class="material-symbols-outlined metric-icon">groups</span>
                                <div class="metric-info">
                                    <span class="metric-value" id="statTotalStudents">0</span>
                                    <span class="metric-label">Alumnos Inscriptos</span>
                                </div>
                            </div>
                            <div class="metric-card">
                                <span class="material-symbols-outlined metric-icon">menu_book</span>
                                <div class="metric-info">
                                    <span class="metric-value" id="statTotalSubjects">0</span>
                                    <span class="metric-label">Exámenes Solicitados</span>
                                </div>
                            </div>
                            <div class="metric-card">
                                <span class="material-symbols-outlined metric-icon">payments</span>
                                <div class="metric-info">
                                    <span class="metric-value" id="statPaidPercentage">0%</span>
                                    <span class="metric-label">Permisos Abonados</span>
                                </div>
                            </div>
                            <div class="metric-card">
                                <span class="material-symbols-outlined metric-icon">grade</span>
                                <div class="metric-info">
                                    <span class="metric-value" id="statAvgGrade">N/A</span>
                                    <span class="metric-label">Promedio General</span>
                                </div>
                            </div>
                        </div>

                        <!-- Buscador de Materias (Alumnos Inscriptos) -->
                        <div class="form-card-block" style="margin-top: 1.4rem;">
                            <div class="section-title">
                                <span class="material-symbols-outlined">search</span>
                                Buscador de Materias (Alumnos Inscriptos)
                            </div>
                            <p style="color: var(--color-text-muted); font-size: 0.85rem; margin-top: -0.4rem; margin-bottom: 0.85rem;">
                                Ingresa el nombre de una materia para ver al instante los alumnos inscriptos y su estado de abono.
                            </p>
                            <input type="text" id="searchStatSubjectInput" class="form-control" placeholder="Buscar materia (ej: Matemática, Programación, Contabilidad, Inglés...)">
                            <div id="searchStatSubjectResults" class="top-subjects-container" style="margin-top: 0.75rem; max-height: 290px; overflow-y: auto; padding-right: 0.25rem; display: none;"></div>
                        </div>

                        <!-- Destacado: Turno con Mayor Tasa de Aprobación -->
                        <div class="form-card-block" style="margin-top: 1.4rem;">
                            <div class="section-title">
                                <span class="material-symbols-outlined">emoji_events</span>
                                Turno con Mayor Índice de Aprobación
                            </div>
                            <div class="best-turn-box">
                                <div class="best-turn-main">
                                    <span class="best-turn-label">Turno con Mayor Tasa de Aprobación:</span>
                                    <h2 id="statBestTurnName" class="best-turn-title">-</h2>
                                    <span class="best-turn-badge" id="statBestTurnRate">0% de Aprobados</span>
                                </div>
                            </div>
                            <div id="turnBreakdownContainer" class="turn-breakdown-grid" style="margin-top: 1.2rem;"></div>
                        </div>

                        <!-- Ranking de Materias más Solicitadas para Rendir -->
                        <div class="form-card-block" style="margin-top: 1.4rem;">
                            <div class="section-title">
                                <span class="material-symbols-outlined">trending_up</span>
                                Asignaturas más Solicitadas para Rendir
                            </div>
                            <div style="font-size: 0.78rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--color-gold); margin-bottom: 0.6rem;">
                                Top 3 Materias con más Alumnos Inscriptos
                            </div>
                            <div id="topSubjectsList" class="top-subjects-container"></div>
                        </div>
                    </div>

                </div>
            </div>

            <!-- Apartado de Ayuda / Tutorial en Video para Directivos y Administrativos -->
            <aside class="help-tutorial-banner no-print" aria-label="Ayuda para directivos" style="margin-top: 1.5rem; max-width: 100%;">
                <div class="help-tutorial-content">
                    <div class="help-tutorial-icon">
                        <span class="material-symbols-outlined">help</span>
                    </div>
                    <div class="help-tutorial-text">
                        <span class="help-tutorial-question">¿Tienes problemas?</span>
                        <span class="help-tutorial-cta">¡Aquí tienes ayuda! :D</span>
                    </div>
                </div>
                <a href="https://youtu.be/dqTh9vQUdFg" target="_blank" rel="noopener noreferrer" class="help-tutorial-btn">
                    <span class="material-symbols-outlined">play_circle</span>
                    <span>Ver video tutorial</span>
                </a>
            </aside>

        </section>

    </main>

    <!-- MODAL CONFIRMACIÓN DE SALIDA (HABILITAR PREINSCRIPCIONES / EDICIÓN DE MESAS) -->
    <div id="modalConfirmLeave" class="modal-overlay" style="z-index: 99999;">
        <div class="modal-card" style="max-width: 480px; text-align: center;">
            <div class="modal-icon">
                <span class="material-symbols-outlined" id="confirmLeaveIcon" style="font-size: 3.2rem; color: var(--color-gold);">warning</span>
            </div>
            <div class="modal-title" id="confirmLeaveTitle" style="color: #ffffff; font-size: 1.25rem; margin-bottom: 0.6rem; font-weight: 700;">¿Desea salir sin guardar la configuración?</div>
            <div class="modal-body-text" id="confirmLeaveMessage" style="color: rgba(255,255,255,0.85); font-size: 0.92rem; line-height: 1.5; margin-bottom: 1.4rem;">
                Tienes modificaciones pendientes en la configuración.
            </div>
            <div class="modal-actions" style="display: flex; gap: 0.75rem; justify-content: center; flex-wrap: wrap;">
                <button type="button" id="btnConfirmLeaveCancel" class="btn-secondary-auth" style="min-width: 140px; padding: 0.65rem 1.2rem;">
                    <span class="material-symbols-outlined">close</span> Permanecer aquí
                </button>
                <button type="button" id="btnConfirmLeaveAccept" class="btn-ingresar" style="min-width: 140px; padding: 0.65rem 1.2rem; background: linear-gradient(135deg, #b71c1c 0%, #7f0000 100%); border: 2px solid #ef5350; color: #ffffff !important; font-weight: 700;">
                    <span class="material-symbols-outlined" style="color: #ffffff;">logout</span> <span id="confirmLeaveAcceptText" style="color: #ffffff;">Salir</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Script Assets Base64 -->
    <script src="src/js/assets.js"></script>
    <script src="src/js/core.js?v=<?= filemtime(__DIR__ . '/src/js/core.js') ?>"></script>
    <!-- Script Exclusivo Administrativo -->
    <script src="src/js/administrativo.bundle.js?v=<?= filemtime(__DIR__ . '/src/js/administrativo.bundle.js') ?>"></script>
</body>
</html>
