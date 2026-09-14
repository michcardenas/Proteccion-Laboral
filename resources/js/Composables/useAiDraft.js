// Pide un borrador a la IA y espera a que esté escrito.
//
// La generación NO es síncrona: `POST .../ai/generate` encola y responde 202 con el
// id de la fila; el texto se recoge sondeando `GET .../ai/generations/{id}` hasta que
// el estado deja de ser `pendiente`.
//
// Es así porque las plantillas `draft_*` piden un escrito jurídico completo, así que
// el modelo agota siempre los 4.096 tokens de salida: ~80 s fijos, medidos contra
// producción. La respuesta directa no sobrevivía al gateway de Hostinger — daba un
// HTTP 504 seco y, peor, mataba el PHP antes de que quedara registro del intento.

const SONDEO_MS = 3000;
const ESPERA_MAXIMA_MS = 5 * 60 * 1000;

/** Fallos de red seguidos que se toleran antes de rendirse. */
const MAX_FALLOS_SEGUIDOS = 5;

const espera = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/**
 * Encola la generación y devuelve el payload completo de la fila ya terminada.
 *
 * @param {number|string} processId
 * @param {{template: string, placeholders?: object}} payload
 * @returns {Promise<object>} { id, estado: 'ok', borrador, modelo, tokens, costo_usd, latencia_ms }
 * @throws {Error} si la generación termina en error, o si se agota la espera.
 */
export async function generarBorrador(processId, payload) {
    const { data } = await window.axios.post(
        route('admin.processes.ai.generate', { process: processId }),
        payload
    );

    if (!data?.id) {
        throw new Error('El servidor no devolvió el identificador de la generación.');
    }

    return esperarGeneracion(processId, data.id);
}

/**
 * Pregunta por la generación hasta que deja de estar `pendiente`.
 *
 * Se exporta suelto para las rutas que encolan por su cuenta (la respuesta a un
 * correo, `emails.draft`) y solo necesitan esperar el resultado.
 *
 * Un corte de red suelto no aborta el sondeo: el borrador se sigue escribiendo en el
 * servidor, y rendirse al primer fallo lo daría por perdido después de haberlo pagado.
 * Una respuesta CON estado (403, 404…) sí es definitiva y se propaga tal cual.
 */
export async function esperarGeneracion(processId, generationId) {
    const url = route('admin.processes.ai.show', {
        process: processId,
        generation: generationId,
    });
    const limite = Date.now() + ESPERA_MAXIMA_MS;
    let fallosSeguidos = 0;

    while (Date.now() < limite) {
        await espera(SONDEO_MS);

        let data;
        try {
            ({ data } = await window.axios.get(url));
            fallosSeguidos = 0;
        } catch (e) {
            if (e.response || ++fallosSeguidos > MAX_FALLOS_SEGUIDOS) throw e;
            continue;
        }

        // Fuera del try a propósito: si este throw cayera dentro, su propio catch lo
        // trataría como un fallo de red y seguiría sondeando una fila ya cerrada.
        if (data.estado === 'ok') return data;
        if (data.estado === 'error') {
            throw new Error(data.error ?? 'No se pudo generar el borrador.');
        }
    }

    throw new Error(
        'La redacción está tardando más de lo normal. No se ha perdido: queda guardada ' +
        'y puedes volver a intentarlo en unos minutos.'
    );
}
