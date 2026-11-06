// Modo claro / oscuro para Reserva de Proyectores (mismo funcionamiento e íconos que ProgMesas).
// Se carga en el <head> para aplicar el tema elegido sin parpadeos y comparte la preferencia en localStorage.

(() => {
    'use strict';

    const CLAVE = 'progmesas-tema';
    const raiz = document.documentElement;
    const sistema = window.matchMedia('(prefers-color-scheme: dark)');

    const SVG = (contenido) =>
        '<svg class="icono-tema" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
        `stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">${contenido}</svg>`;
    const LUNA = SVG('<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>');
    const SOL = SVG('<circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/>' +
        '<path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/>');

    const botones = [];

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
        const otro = tema === 'oscuro' ? 'claro' : 'oscuro';
        for (const b of botones) {
            b.innerHTML = `${tema === 'oscuro' ? SOL : LUNA}<span>Modo ${otro}</span>`;
            b.title = `Cambiar a modo ${otro}`;
        }
    }

    function alternar() {
        const nuevo = actual() === 'oscuro' ? 'claro' : 'oscuro';
        try {
            if (nuevo === (sistema.matches ? 'oscuro' : 'claro')) localStorage.removeItem(CLAVE);
            else localStorage.setItem(CLAVE, nuevo);
        } catch {
            raiz.dataset.tema = nuevo;
            return;
        }
        aplicar();
    }

    function crearBotones() {
        const lugares = document.querySelectorAll('#lugarTema, .lugar-tema');
        lugares.forEach((lugar) => {
            if (lugar.querySelector('.btn-tema')) return;
            const boton = document.createElement('button');
            boton.type = 'button';
            boton.className = 'btn-tema';
            boton.addEventListener('click', alternar);
            lugar.appendChild(boton);
            botones.push(boton);
        });
        aplicar();
    }

    aplicar();
    sistema.addEventListener('change', () => {
        if (!guardado()) aplicar();
    });
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', crearBotones);
    else crearBotones();
})();
