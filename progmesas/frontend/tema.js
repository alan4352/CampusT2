// Modo claro / oscuro.
// Se carga en el <head> para aplicar el tema antes de que se dibuje la página (sin parpadeo).
// Sin elección guardada sigue la preferencia del sistema; el botón del encabezado la cambia y la recuerda.

(() => {
    'use strict';

    const CLAVE = 'progmesas-tema';
    const raiz = document.documentElement;
    const sistema = window.matchMedia('(prefers-color-scheme: dark)');

    const SVG = (contenido) =>
        '<svg class="icono" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
        `stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">${contenido}</svg>`;
    const LUNA = SVG('<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>');
    const SOL = SVG('<circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/>' +
        '<path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/>');

    let boton = null;

    function guardado() {
        try {
            const t = localStorage.getItem(CLAVE);
            return t === 'claro' || t === 'oscuro' ? t : null;
        } catch {
            return null;
        }
    }

    function actual() {
        return guardado() || (sistema.matches ? 'oscuro' : 'claro');
    }

    function aplicar() {
        const tema = actual();
        raiz.dataset.tema = tema;
        if (boton) {
            const otro = tema === 'oscuro' ? 'claro' : 'oscuro';
            boton.innerHTML = `${tema === 'oscuro' ? SOL : LUNA}Modo ${otro}`;
            boton.title = `Cambiar a modo ${otro}`;
        }
    }

    function alternar() {
        const nuevo = actual() === 'oscuro' ? 'claro' : 'oscuro';
        try {
            // Si coincide con el sistema no se guarda nada: así vuelve a seguirlo
            if (nuevo === (sistema.matches ? 'oscuro' : 'claro')) localStorage.removeItem(CLAVE);
            else localStorage.setItem(CLAVE, nuevo);
        } catch {
            /* sin almacenamiento: el cambio dura hasta recargar */
            raiz.dataset.tema = nuevo;
            return;
        }
        aplicar();
    }

    function crearBoton() {
        // En el menú lateral (como el resto de las opciones); si una página no lo tiene, en el encabezado
        const lugar = document.getElementById('lugarTema') || document.querySelector('.page-header-extra');
        if (!lugar || boton) return;
        boton = document.createElement('button');
        boton.type = 'button';
        boton.className = 'nav-link btn-tema';
        boton.addEventListener('click', alternar);
        lugar.appendChild(boton);
        aplicar();
    }

    aplicar();
    sistema.addEventListener('change', () => {
        if (!guardado()) aplicar();
    });
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', crearBoton);
    else crearBoton();
})();
