// Google Drive simulado, SÓLO para las pruebas automáticas (DRIVE_SIMULADO=<carpeta>).
// Imita las partes de la API de Drive que usa drive.js y guarda los archivos en una carpeta local.
// Si existe el archivo "<carpeta>/caido", todas las llamadas fallan como si Drive no respondiera.

import fs from 'fs';
import path from 'path';

async function juntar(stream) {
    const partes = [];
    for await (const parte of stream) partes.push(Buffer.from(parte));
    return Buffer.concat(partes);
}

const sinEscape = (t) => t.replace(/\\(.)/g, '$1');

export function crearDriveSimulado(dir) {
    fs.mkdirSync(dir, { recursive: true });
    const rutaIndice = path.join(dir, 'indice.json');
    const leer = () => {
        try {
            return JSON.parse(fs.readFileSync(rutaIndice, 'utf-8'));
        } catch {
            return { sig: 1, items: {} };
        }
    };
    const guardar = (d) => fs.writeFileSync(rutaIndice, JSON.stringify(d, null, 2));
    const revisar = () => {
        if (fs.existsSync(path.join(dir, 'caido'))) throw new Error('Drive simulado: sin conexión');
    };
    const noExiste = () => Object.assign(new Error('File not found'), { code: 404 });

    const drive = {
        files: {
            async list({ q }) {
                revisar();
                const m = /^'((?:\\.|[^'])*)' in parents and name='((?:\\.|[^'])*)'/.exec(q);
                const [padre, nombre] = m ? [sinEscape(m[1]), sinEscape(m[2])] : [null, null];
                const items = leer().items;
                const files = Object.entries(items)
                    .filter(([, it]) => it.carpeta && it.padre === padre && it.nombre === nombre)
                    .map(([id, it]) => ({ id, name: it.nombre }));
                return { data: { files } };
            },
            async create({ requestBody, media }) {
                revisar();
                const d = leer();
                const id = `sim${d.sig++}`;
                const carpeta = requestBody.mimeType === 'application/vnd.google-apps.folder';
                if (!carpeta) fs.writeFileSync(path.join(dir, id), await juntar(media.body));
                d.items[id] = { nombre: requestBody.name, padre: requestBody.parents?.[0] || 'root', carpeta, publico: false };
                guardar(d);
                return { data: { id } };
            },
            async get({ fileId }) {
                revisar();
                const ruta = path.join(dir, path.basename(fileId));
                if (!fs.existsSync(ruta)) throw noExiste();
                return { data: fs.readFileSync(ruta) };
            },
            async delete({ fileId }) {
                revisar();
                const d = leer();
                if (!d.items[fileId]) throw noExiste();
                delete d.items[fileId];
                guardar(d);
                fs.rmSync(path.join(dir, path.basename(fileId)), { force: true });
            }
        },
        permissions: {
            async create({ fileId, requestBody }) {
                revisar();
                const d = leer();
                if (!d.items[fileId]) throw noExiste();
                d.items[fileId].publico = requestBody?.type === 'anyone';
                guardar(d);
            }
        }
    };

    return { drive, verificar: async () => revisar() };
}
