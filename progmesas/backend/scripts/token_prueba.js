// Simula el campus: genera un pase firmado como el que manda al abrir ProgMesas.
// Sirve para probar ProgMesas en una computadora sin el campus instalado.
//
//   node scripts/token_prueba.js --rol profesor --nombre "Valeria Grimberg" --id 1234 --email vgrimberg@escuela.edu.ar
//   node scripts/token_prueba.js --rol directivo --nombre "Ana Pérez"
//
// Usa EXTERNO_JWT_SECRET del .env (firma HS256) y los nombres de campo EXTERNO_CLAIM_* configurados.
// Muestra el token y el enlace para abrir ProgMesas con esa identidad.

import jwt from 'jsonwebtoken';
import { EXTERNO, PORT } from '../config.js';

function argumento(nombre, defecto = '') {
    const i = process.argv.indexOf(`--${nombre}`);
    return i > 0 && process.argv[i + 1] !== undefined ? process.argv[i + 1] : defecto;
}

if (process.argv.includes('--ayuda') || process.argv.length <= 2) {
    console.log(`Uso: node scripts/token_prueba.js --rol <rol> --nombre "<nombre>" [--id <id>] [--email <email>] [--horas <n>]
Roles del campus: profesor, directivo (asesora), alumno; también preceptor (se traducen con ROL_* del .env).`);
    process.exit(0);
}

if (!EXTERNO.secreto) {
    console.error('Falta EXTERNO_JWT_SECRET en el .env: es la clave compartida con el campus.');
    process.exit(1);
}

/** Pone un valor en un campo, admitiendo nombres anidados como "usuario.id". */
function poner(obj, ruta, valor) {
    const partes = ruta.split('.');
    let actual = obj;
    for (const p of partes.slice(0, -1)) actual = actual[p] ||= {};
    actual[partes.at(-1)] = valor;
}

const nombre = argumento('nombre', 'Usuario de Prueba');
const datos = {};
poner(datos, EXTERNO.claimId, argumento('id', `prueba-${Date.now()}`));
poner(datos, EXTERNO.claimNombre, nombre);
poner(datos, EXTERNO.claimRol, argumento('rol', 'profesor'));
poner(datos, EXTERNO.claimEmail, argumento('email', ''));

const horas = Number(argumento('horas', '8')) || 8;
const token = jwt.sign(datos, EXTERNO.secreto, { algorithm: 'HS256', expiresIn: `${horas}h` });

console.log('\nDatos del token:', JSON.stringify(datos));
console.log(`\nToken (vence en ${horas} h):\n${token}`);
console.log(`\nAbrí ProgMesas con esta identidad:\nhttp://localhost:${PORT}/?token=${token}\n`);
