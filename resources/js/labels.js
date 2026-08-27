/**
 * Etiquetas legibles para los valores internos de la base de datos.
 *
 * En la interfaz nunca debería verse `en_curso` ni `diagnostico_implementacion`: son
 * nombres técnicos. Aquí se traducen una sola vez para toda la plataforma.
 */
const DICCIONARIOS = {
    estadoProceso: {
        abierto: 'Abierto',
        en_curso: 'En curso',
        en_revision: 'En revisión',
        cerrado: 'Cerrado',
        archivado: 'Archivado',
    },

    modalidad: {
        permanente: 'Asesoría permanente',
        por_evento: 'Por evento',
        judicial: 'Representación judicial',
        estrategico: 'Acompañamiento estratégico',
        capacitacion: 'Capacitación',
        prediagnostico: 'Prediagnóstico',
        diagnostico_implementacion: 'Diagnóstico e implementación',
    },

    estadoTarea: {
        pendiente: 'Pendiente',
        en_curso: 'En curso',
        bloqueada: 'Bloqueada',
        completada: 'Completada',
        cancelada: 'Cancelada',
    },

    prioridad: {
        baja: 'Baja',
        media: 'Media',
        alta: 'Alta',
        urgente: 'Urgente',
    },

    metodoPago: {
        efectivo: 'Efectivo',
        transferencia: 'Transferencia',
        consignacion: 'Consignación',
        tarjeta: 'Tarjeta',
        cheque: 'Cheque',
        otro: 'Otro',
    },

    estadoCorreo: {
        pending: 'Pendiente',
        classified: 'Clasificado',
        processed: 'Procesado',
        needs_review: 'Requiere revisión',
        descartado: 'Descartado',
        failed: 'Falló',
    },
};

/**
 * Traduce un valor. Si no está en el diccionario, lo humaniza igual
 * (`algo_raro` → `Algo raro`) en vez de mostrar el valor crudo.
 */
export const etiqueta = (diccionario, valor) => {
    if (valor === null || valor === undefined || valor === '') return '—';

    const dic = DICCIONARIOS[diccionario] ?? {};
    if (dic[valor]) return dic[valor];

    const texto = String(valor).replace(/_/g, ' ');
    return texto.charAt(0).toUpperCase() + texto.slice(1);
};

export default DICCIONARIOS;
