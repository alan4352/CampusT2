// Página principal: listado público de programas, ingreso, vinculación del docente y subida.

let programas = [];
let usuario = null;          // null = visitante; si no, lo que devuelve /api/yo
let config = { anioLectivo: new Date().getFullYear(), maxPdfMb: 10, authModo: 'externo', loginUrl: null, logoutUrl: null };
let asignaciones = [];       // las que el usuario puede cargar

const $ = (id) => document.getElementById(id);

function mostrar(el, visible) {
    if (el) el.classList.toggle('hidden', !visible);
}

// ================= INTERFAZ SEGÚN EL ROL =================

function docenteVinculado() {
    return usuario?.role === 'teacher' && usuario.vinculo?.estado === 'confirmado';
}

function aplicarRol() {
    const logueado = Boolean(usuario);
    mostrarPerfil(usuario);
    $('subtituloPagina').textContent = logueado
        ? `Bienvenido/a, ${usuario.nombre || usuario.email}.`
        : 'Consultá los programas de examen de todos los cursos y años.';
    const esDocente = usuario?.role === 'teacher';
    // Docentes y asesora pueden cargar el programa de cualquier curso y materia
    const puedeSubir = usuario?.role === 'advisor' || esDocente;

    // ProgMesas no tiene login propio: se ingresa desde el campus de la escuela
    const ingresar = $('btnIngresarExterno');
    if (config.loginUrl) ingresar.href = config.loginUrl;
    mostrar(ingresar, !logueado && Boolean(config.loginUrl));

    mostrar($('btnLogout'), logueado);
    mostrar($('btnDashboard'), usuario?.role === 'advisor' || usuario?.role === 'viewer');
    mostrar($('zonaSubida'), puedeSubir);
    mostrar($('zonaVinculo'), esDocente && !docenteVinculado());
    if (esDocente && !docenteVinculado()) mostrarEstadoVinculo();

    const banner = $('bannerDocente');
    if (esDocente) {
        const como = usuario.vinculo?.estado === 'confirmado' ? ` (<b>${esc(usuario.vinculo.docente)}</b>)` : '';
        banner.innerHTML = `${icono('docente')}<b>Mis programas:</b> sesión iniciada como <u>${esc(usuario.nombre)}</u>${como}. ` +
            'Podés cargar el programa de cualquier curso; abajo se muestran los de tus materias y los que subiste.';
        mostrar(banner, true);
    } else {
        mostrar(banner, false);
    }
}

// ================= VINCULACIÓN DEL DOCENTE =================

function mostrarEstadoVinculo() {
    cargarNombres();
}

let nombresCargados = false;
async function cargarNombres() {
    if (nombresCargados) return;
    try {
        const nombres = await api('/docentes/nombres');
        $('listaNombres').innerHTML = nombres
            .filter((n) => n.disponible)
            .map((n) => `<option value="${esc(n.nombre)}"></option>`)
            .join('');
        nombresCargados = true;
    } catch (err) {
        showToast(err.message, 'danger');
    }
}

async function enviarVinculo(evento) {
    evento.preventDefault();
    const docente = $('nombreDocente').value.trim();
    if (!docente) return;
    try {
        const r = await api('/docentes/vinculo', { method: 'POST', json: { docente } });
        usuario.vinculo = r.vinculo;
        $('nombreDocente').value = '';
        showToast(`Listo, ${r.vinculo.docente}. Tus materias aparecen primero al cargar.`, 'success');
        aplicarRol();
        await Promise.all([cargarProgramas(), cargarAsignaciones()]);
    } catch (err) {
        showToast(err.message, 'danger');
    }
}

// ================= SESIÓN =================

function logout() {
    cerrarSesion();
    // También se cierra la sesión del campus
    if (config.logoutUrl) location.href = config.logoutUrl;
    else location.reload();
}

// ================= LISTADO =================

async function cargarProgramas() {
    try {
        programas = await api('/programas');
    } catch (err) {
        programas = [];
        showToast(err.message, 'danger');
    }
    actualizarFiltros();
    aplicarFiltros();
}

// Filtros pedidos en la dirección (?curso=…&materia=…&anio=…&q=…), para enlaces compartidos
const filtrosIniciales = (() => {
    const p = new URLSearchParams(location.search);
    return { curso: p.get('curso') || '', materia: p.get('materia') || '', anio: p.get('anio') || '', q: p.get('q') || '' };
})();
let filtrosAplicados = false;

function opciones(select, valores, etiquetaTodos, pedido = '') {
    const actual = select.value || pedido;
    const lista = valores.map(String);
    // Un curso o materia pedido en el enlace se muestra aunque todavía no tenga programas
    if (actual && !lista.includes(actual)) lista.push(actual);
    select.innerHTML = `<option value="">${esc(etiquetaTodos)}</option>` +
        lista.map((v) => `<option value="${esc(v)}">${esc(v)}</option>`).join('');
    if (lista.includes(actual)) select.value = actual;
}

function actualizarFiltros() {
    const unicos = (campo) => [...new Set(programas.map((p) => p[campo]).filter(Boolean))];
    const pedido = filtrosAplicados ? {} : filtrosIniciales;
    opciones($('filtroMateria'), unicos('materia').sort((a, b) => a.localeCompare(b, 'es')), 'Todas las materias', pedido.materia);
    opciones($('filtroCurso'), unicos('curso').sort(compararCursos), 'Todos los cursos', pedido.curso);
    opciones($('filtroAnio'), unicos('year').sort((a, b) => b - a), 'Todos los años', pedido.anio);
    if (!filtrosAplicados) {
        $('busqueda').value = filtrosIniciales.q;
        filtrosAplicados = true;
    }
}

function filtrosActuales() {
    return {
        curso: $('filtroCurso').value,
        materia: $('filtroMateria').value,
        anio: $('filtroAnio').value,
        q: $('busqueda').value.trim()
    };
}

/** Deja los filtros en la dirección (para compartirla) y muestra el enlace y el ZIP del curso. */
function actualizarAccionesFiltro() {
    const f = filtrosActuales();
    const url = enlaceListado(f);
    if (url !== location.href) history.replaceState(null, '', url);

    const hayFiltros = Object.values(f).some(Boolean);
    let html = '';
    if (hayFiltros) {
        html += `<button type="button" class="btn-secondary-auth btn-chico" data-accion="copiar-enlace">${icono('enlace')}Copiar enlace a esta búsqueda</button>`;
    }
    if (f.curso) {
        const anios = f.anio ? `de ${esc(f.anio)}` : 'de todos los años';
        html += `<button type="button" class="btn-secondary-auth btn-chico" data-accion="zip-curso">${icono('zip')}Descargar los programas de ${esc(f.curso)} ${anios} (ZIP)</button>`;
    }
    $('accionesFiltro').innerHTML = html;
}

async function descargarZipCurso() {
    const { curso, anio } = filtrosActuales();
    if (!curso) return;
    const ruta = `/cursos/zip?curso=${encodeURIComponent(curso)}${anio ? `&anio=${encodeURIComponent(anio)}` : ''}`;
    try {
        showToast('Preparando el ZIP…');
        await descargar(ruta, `Programas ${curso}${anio ? ' ' + anio : ''}.zip`);
    } catch (err) {
        showToast(err.message, 'danger');
    }
}

function aplicarFiltros() {
    const texto = $('busqueda').value.trim().toLowerCase();
    const materia = $('filtroMateria').value;
    const curso = $('filtroCurso').value;
    const anio = $('filtroAnio').value;

    const filtrados = programas.filter((p) => {
        if (materia && p.materia !== materia) return false;
        if (curso && p.curso !== curso) return false;
        if (anio && String(p.year) !== anio) return false;
        if (texto) {
            const enTexto = [p.title, p.materia, p.curso, p.profesor].some((v) => String(v || '').toLowerCase().includes(texto));
            if (!enTexto) return false;
        }
        return true;
    });
    render(filtrados);
    actualizarAccionesFiltro();
}

function render(lista) {
    const contenedor = $('lista');

    if (lista.length === 0) {
        const msg = programas.length === 0 && usuario?.role === 'teacher'
            ? 'Todavía no hay programas de tus materias. Podés cargar el primero con el formulario de arriba.'
            : 'No se encontraron programas de examen.';
        contenedor.innerHTML = `<div class="alert-custom">${esc(msg)}</div>`;
        return;
    }

    const porAnio = new Map();
    for (const p of lista) {
        const y = p.year || 'Sin año';
        if (!porAnio.has(y)) porAnio.set(y, []);
        porAnio.get(y).push(p);
    }

    const anios = [...porAnio.keys()].sort((a, b) => b - a);
    contenedor.innerHTML = anios.map((anio, i) => {
        const items = porAnio.get(anio).slice().sort((a, b) => compararCursos(a.curso, b.curso) || a.materia.localeCompare(b.materia, 'es'));
        return `
        <details class="sub-collapsible bloque-anio" ${i === 0 ? 'open' : ''}>
            <summary>
                <span>${icono('calendario')}Programas del año <b>${esc(anio)}</b></span>
                <span class="coverage-badge coverage-high badge-chico">${items.length} cargado(s)</span>
            </summary>
            <div class="bloque-contenido">
                ${items.map(tarjeta).join('')}
            </div>
        </details>`;
    }).join('');
}

function tarjeta(p) {
    const borrar = usuario?.role === 'advisor'
        ? `<button type="button" class="btn-delete" data-accion="eliminar" data-id="${esc(p.id)}">${icono('eliminar')}Eliminar</button>`
        : '';
    const versiones = p.versiones > 0
        ? `<button type="button" class="btn-secondary-auth btn-chico" data-accion="versiones" data-id="${esc(p.id)}" aria-expanded="false"
                aria-controls="versiones-${esc(p.id)}">${icono('historial')}Versiones anteriores (${p.versiones})</button>`
        : '';
    return `
    <div class="card">
        <div class="card-info">
            <b>${esc(p.title)}</b>
            ${esc(p.materia)} — Curso: ${esc(p.curso)}<br>
            <small>Docente: ${esc(p.profesor || 'Sin datos')} | Fecha: ${esc(p.fecha || 'Sin fecha')}</small>
        </div>
        <div class="card-actions">
            ${versiones}
            <button type="button" class="btn-ver" data-accion="ver" data-id="${esc(p.id)}">${icono('documento')}Ver PDF</button>
            ${borrar}
        </div>
    </div>
    ${p.versiones > 0 ? `<div id="versiones-${esc(p.id)}" class="lista-versiones hidden"></div>` : ''}`;
}

// ================= VERSIONES ANTERIORES =================

async function alternarVersiones(boton) {
    const id = boton.dataset.id;
    const caja = $(`versiones-${id}`);
    if (!caja) return;
    const abrir = caja.classList.contains('hidden');
    mostrar(caja, abrir);
    boton.setAttribute('aria-expanded', String(abrir));
    if (!abrir || caja.dataset.cargado) return;
    caja.innerHTML = '<p class="texto-suave">Cargando…</p>';
    try {
        const { versiones } = await api(`/programas/${encodeURIComponent(id)}/versiones`);
        caja.dataset.cargado = '1';
        caja.innerHTML = `<p class="texto-suave">Se conservan las versiones reemplazadas, de la más nueva a la más vieja:</p>` +
            versiones.map((v) => `
            <div class="fila-version">
                <div><b>${esc(v.title)}</b><br>
                    <small>Subido el ${esc(v.fecha || '-')}${v.profesor ? ` · ${esc(v.profesor)}` : ''} ·
                    reemplazado el ${esc(new Date(v.reemplazado_at).toLocaleDateString('es-AR'))}${v.reemplazado_por ? ` por ${esc(v.reemplazado_por)}` : ''}</small>
                </div>
                <button type="button" class="btn-secondary-auth btn-chico" data-accion="ver-version"
                    data-url="${esc(v.file_url)}" data-titulo="${esc(v.title)}">${icono('documento')}Ver PDF</button>
            </div>`).join('');
    } catch (err) {
        caja.innerHTML = `<p class="texto-aviso">${esc(err.message)}</p>`;
    }
}

// ================= VISOR =================

function verPrograma(id) {
    const p = programas.find((x) => String(x.id) === String(id));
    abrirVisor(p?.file_url, p?.title);
}

function abrirVisor(fileUrl, titulo) {
    const url = urlPdfSegura(fileUrl);
    if (!url) {
        showToast('El archivo no tiene una dirección válida', 'danger');
        return;
    }
    $('tituloVisor').textContent = `Visualizando: ${titulo}`;
    $('visorPDF').src = url;
    mostrar($('seccionVisor'), true);
    $('seccionVisor').scrollIntoView({ behavior: 'smooth' });
}

function cerrarVisor() {
    mostrar($('seccionVisor'), false);
    $('visorPDF').removeAttribute('src');
}

// ================= ELIMINAR =================

async function eliminarPrograma(id) {
    const p = programas.find((x) => String(x.id) === String(id));
    if (!p || !confirm(`¿Eliminar "${p.title}" (${p.materia} — ${p.curso})? También se borran el archivo y sus versiones anteriores.`)) return;
    try {
        await api(`/programas/${encodeURIComponent(id)}`, { method: 'DELETE' });
        showToast('Programa eliminado', 'success');
        await Promise.all([cargarProgramas(), cargarAsignaciones()]);
    } catch (err) {
        showToast(err.message, 'danger');
    }
}

// ================= SUBIDA =================

async function cargarAsignaciones() {
    if (!(usuario?.role === 'advisor' || usuario?.role === 'teacher')) return;
    const anio = Number($('anio').value) || config.anioLectivo;
    let datos;
    try {
        datos = await api(`/asignaciones/mias?anio=${anio}`);
    } catch (err) {
        showToast(err.message, 'danger');
        return;
    }
    asignaciones = datos.asignaciones;
    mostrarFechaLimite(datos.fechaLimite, datos.anio);

    const aviso = $('avisoSinAsignaciones');
    if (asignaciones.length === 0) {
        aviso.innerHTML = 'No hay asignaciones cargadas. Generá el archivo con el importador de horarios.';
        mostrar(aviso, true);
        mostrar($('formSubida'), false);
        return;
    }
    mostrar(aviso, false);
    mostrar($('formSubida'), true);

    const select = $('asignacion');
    const elegido = select.value;
    const porCurso = new Map();
    for (const a of asignaciones) {
        if (!porCurso.has(a.curso)) porCurso.set(a.curso, []);
        porCurso.get(a.curso).push(a);
    }
    const cursos = [...porCurso.keys()].sort(compararCursos);
    // Las materias del docente (según la lista de horarios) aparecen primero
    const mias = asignaciones.filter((a) => a.propia)
        .sort((a, b) => compararCursos(a.curso, b.curso) || a.materia.localeCompare(b.materia, 'es'));
    const grupoMias = mias.length
        ? '<optgroup label="Mis materias">' +
          mias.map((a) => `<option value="${esc(a.id)}">${esc(a.curso)} — ${esc(a.materia)}${a.programa ? ' (ya cargado)' : ''}</option>`).join('') +
          '</optgroup>'
        : '';
    select.innerHTML = '<option value="">Elegí curso y materia…</option>' + grupoMias + cursos.map((c) => {
        const items = porCurso.get(c).sort((a, b) => a.materia.localeCompare(b.materia, 'es'));
        return `<optgroup label="${esc(c)}${items[0].turno ? ' — ' + esc(items[0].turno) : ''}">` +
            items.map((a) => `<option value="${esc(a.id)}">${esc(a.materia)}${a.programa ? ' (ya cargado)' : ''}</option>`).join('') +
            '</optgroup>';
    }).join('');
    if (asignaciones.some((a) => a.id === elegido)) select.value = elegido;
    mostrarInfoAsignacion();
}

function mostrarInfoAsignacion() {
    const a = asignaciones.find((x) => x.id === $('asignacion').value);
    const info = $('infoAsignacion');
    if (!a) {
        info.textContent = '';
        return;
    }
    let html = `Docentes: <b>${esc(a.docentes.join(' / ') || 'sin datos')}</b>`;
    if (a.programa) {
        html += `<br><span class="texto-aviso">Ya hay un programa cargado para ${esc($('anio').value)} ` +
            `("${esc(a.programa.title)}", ${esc(a.programa.fecha)}). Si subís otro, lo reemplaza.</span>`;
    }
    if (!a.requiere) html += '<br><small>Esta materia está marcada como "no requiere programa".</small>';
    if (a.anterior) {
        html += `<br><span class="fila-reutilizar">Hay un programa de <b>${esc(a.anterior.year)}</b> para este curso y materia
            ("${esc(a.anterior.title)}").
            <button type="button" class="btn-secondary-auth btn-chico" data-accion="reutilizar">${icono('copiar')}Usar el de ${esc(a.anterior.year)}</button></span>`;
    }
    info.innerHTML = html;
}

function mostrarFechaLimite(fecha, anio) {
    const p = $('infoFechaLimite');
    if (!fecha) {
        mostrar(p, false);
        return;
    }
    const dias = diasHasta(fecha);
    p.className = `info-fecha ${dias < 0 ? 'info-fecha-vencida' : dias <= 7 ? 'info-fecha-cerca' : ''}`;
    p.innerHTML = `${icono('reloj')}Fecha límite para entregar los programas de ${esc(anio)}: <b>${esc(fechaLegible(fecha))}</b> (${esc(textoFechaLimite(fecha))}).`;
}

async function reutilizar() {
    const a = asignaciones.find((x) => x.id === $('asignacion').value);
    if (!a?.anterior) return;
    const anio = $('anio').value;
    const pregunta = a.programa
        ? `Ya hay un programa de ${a.materia} (${a.curso}) para ${anio}. ¿Reemplazarlo por el de ${a.anterior.year}?`
        : `¿Usar para ${anio} el programa de ${a.anterior.year} de ${a.materia} (${a.curso})?`;
    if (!confirm(pregunta)) return;
    try {
        await api('/programas/reutilizar', { method: 'POST', json: { asignacion_id: a.id, anio: Number(anio) } });
        showToast(`Listo: se usa el programa de ${a.anterior.year}`, 'success');
        await Promise.all([cargarProgramas(), cargarAsignaciones()]);
    } catch (err) {
        showToast(err.message, 'danger');
    }
}

async function subir(evento) {
    evento.preventDefault();
    const asignacionId = $('asignacion').value;
    const anio = $('anio').value;
    const archivo = $('pdf').files[0];

    if (!asignacionId) return showToast('Elegí el curso y la materia', 'danger');
    if (!archivo) return showToast('Elegí el archivo PDF', 'danger');
    if (!/\.pdf$/i.test(archivo.name) && archivo.type !== 'application/pdf') return showToast('El archivo tiene que ser un PDF', 'danger');
    if (archivo.size > config.maxPdfMb * 1024 * 1024) return showToast(`El PDF supera los ${config.maxPdfMb} MB`, 'danger');

    const a = asignaciones.find((x) => x.id === asignacionId);
    if (a?.programa && !confirm(`Ya hay un programa de ${a.materia} (${a.curso}) para ${anio}. ¿Reemplazarlo?`)) return;

    const datos = new FormData();
    datos.append('asignacion_id', asignacionId);
    datos.append('anio', anio);
    datos.append('pdf', archivo);

    const boton = $('btnSubir');
    boton.disabled = true;
    boton.textContent = 'Subiendo…';
    try {
        const r = await api('/programas/subir', { method: 'POST', body: datos });
        showToast(r.reemplazado ? 'Programa reemplazado' : '¡Programa subido!', 'success');
        if (r.aviso) showToast(r.aviso, 'warning', 8000);
        $('pdf').value = '';
        await Promise.all([cargarProgramas(), cargarAsignaciones()]);
    } catch (err) {
        showToast(err.message, 'danger');
    } finally {
        boton.disabled = false;
        boton.textContent = 'Guardar programa';
    }
}

// ================= INICIO =================

function conectarEventos() {
    $('btnLogout').addEventListener('click', logout);
    $('formVinculo').addEventListener('submit', enviarVinculo);
    $('btnCerrarVisor').addEventListener('click', cerrarVisor);
    $('formSubida').addEventListener('submit', subir);
    $('asignacion').addEventListener('change', mostrarInfoAsignacion);

    $('busqueda').addEventListener('input', aplicarFiltros);
    for (const id of ['filtroMateria', 'filtroCurso', 'filtroAnio']) $(id).addEventListener('change', aplicarFiltros);

    // Un solo listener para los botones de todas las tarjetas
    $('lista').addEventListener('click', (e) => {
        const boton = e.target.closest('button[data-accion]');
        if (!boton) return;
        if (boton.dataset.accion === 'ver') verPrograma(boton.dataset.id);
        if (boton.dataset.accion === 'eliminar') eliminarPrograma(boton.dataset.id);
        if (boton.dataset.accion === 'versiones') alternarVersiones(boton);
        if (boton.dataset.accion === 'ver-version') abrirVisor(boton.dataset.url, `${boton.dataset.titulo} (versión anterior)`);
    });
    $('accionesFiltro').addEventListener('click', (e) => {
        const boton = e.target.closest('button[data-accion]');
        if (boton?.dataset.accion === 'copiar-enlace') copiarTexto(location.href, 'Enlace copiado');
        if (boton?.dataset.accion === 'zip-curso') descargarZipCurso();
    });
    $('infoAsignacion').addEventListener('click', (e) => {
        if (e.target.closest('button[data-accion="reutilizar"]')) reutilizar();
    });
}

async function init() {
    conectarEventos();
    mostrarAvisoPendiente();
    try {
        config = await api('/config');
    } catch {
        /* se usan los valores por defecto */
    }
    mostrarVolverCampus(config);
    // Los programas se cargan siempre para el año lectivo actual: se muestra y no se puede cambiar
    $('anio').value = String(config.anioLectivo);
    $('maxPdf').textContent = `(máximo ${config.maxPdfMb} MB)`;

    usuario = await cargarIdentidad();
    aplicarRol();
    await Promise.all([cargarProgramas(), cargarAsignaciones()]);
}

init();
