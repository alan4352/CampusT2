// Marco común de todas las páginas (igual que el sistema principal de la escuela):
// menú lateral que en el celular se abre con el botón "Menú", y la fecha de hoy en el encabezado.
// Se carga con defer en el <head>.

(() => {
    'use strict';

    function iniciar() {
        const boton = document.getElementById('btnMenu');
        const menu = document.getElementById('menuLateral');
        const velo = document.getElementById('veloMenu');
        const cerrarBtn = document.getElementById('btnCerrarMenu');

        const abierto = () => document.body.classList.contains('menu-abierto');
        function abrir() {
            document.body.classList.add('menu-abierto');
            boton?.setAttribute('aria-expanded', 'true');
            if (velo) velo.hidden = false;
            menu?.querySelector('a, button')?.focus();
        }
        function cerrar(devolverFoco = true) {
            if (!abierto()) return;
            document.body.classList.remove('menu-abierto');
            boton?.setAttribute('aria-expanded', 'false');
            if (velo) velo.hidden = true;
            if (devolverFoco) boton?.focus();
        }

        boton?.addEventListener('click', () => (abierto() ? cerrar() : abrir()));
        cerrarBtn?.addEventListener('click', () => cerrar());
        velo?.addEventListener('click', () => cerrar());
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') cerrar();
        });
        // Al elegir una opción del menú en el celular, se cierra
        menu?.addEventListener('click', (e) => {
            if (e.target.closest('a, button:not(#btnCerrarMenu)') && window.matchMedia('(max-width: 900px)').matches) cerrar(false);
        });
        window.matchMedia('(min-width: 901px)').addEventListener('change', (m) => m.matches && cerrar(false));

        // Fecha de hoy en el encabezado
        const hoy = document.getElementById('fechaHoy');
        if (hoy) hoy.textContent = new Date().toLocaleDateString('es-AR');
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar);
    else iniciar();
})();
