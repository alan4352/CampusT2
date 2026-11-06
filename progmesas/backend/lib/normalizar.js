// Funciones de normalización de texto compartidas por el importador y el servidor.

/** Quita acentos y diéresis (á → a, ü → u). */
export function sinAcentos(texto) {
    return String(texto ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '');
}

/** Clave de comparación: sin acentos, minúsculas, sólo letras/números y espacios simples. */
export function claveTexto(texto) {
    return sinAcentos(texto)
        .toLowerCase()
        .replace(/[^a-z0-9ñ]+/g, ' ')
        .trim();
}

/** Clave compacta (sin espacios). Útil para comparar abreviaturas de materias. */
export function claveCompacta(texto) {
    return claveTexto(texto).replace(/\s+/g, '');
}

/**
 * Clave de curso a partir de cualquier forma de escribirlo:
 * "4° 2° TAE", "4 2 TAE", "4º2º tae" → "4-2-TAE"; "1° 1°" → "1-1".
 */
export function cursoClave(curso) {
    const t = sinAcentos(curso).toUpperCase();
    const numeros = t.match(/\d+/g) || [];
    const letras = (t.replace(/\b(TM|TT)\b/g, ' ').match(/[A-Z]{2,4}/g) || []);
    return [...numeros.slice(0, 2), ...letras.slice(0, 1)].join('-');
}

/** Texto visible de un curso a partir de sus partes. */
export function cursoVisible(anio, division, especialidad = '') {
    return `${anio}° ${division}°${especialidad ? ' ' + especialidad : ''}`;
}

/** Escapa comillas simples para consultas de la API de Google Drive. */
export function escaparConsultaDrive(texto) {
    return String(texto ?? '').replace(/\\/g, '\\\\').replace(/'/g, "\\'");
}
