/**
 * Catálogo de ayuda de la plataforma.
 *
 * Una sola fuente de verdad para explicar QUÉ hace cada módulo, CÓMO se usa y qué pasa
 * solo. Lo consumen `PageHeader` (resumen bajo el título) y `HelpPanel` (el desplegable
 * "¿Cómo funciona?"), así que la explicación vive junto a la pantalla que describe y no
 * en un documento aparte que se desactualiza.
 *
 * Cada entrada:
 *   resumen     — una línea, se muestra siempre bajo el título de la página.
 *   paraQue     — por qué existe el módulo, en lenguaje del despacho.
 *   pasos       — cómo se usa, en orden.
 *   automatico  — lo que hace el sistema sin que nadie lo pida.
 *   ojo         — advertencias y malentendidos frecuentes.
 */
export const help = {
    dashboard: {
        titulo: 'Inicio',
        resumen: 'Resumen del estado del despacho: procesos, tareas y correos que necesitan atención.',
        paraQue:
            'Es el punto de entrada: en vez de revisar módulo por módulo, aquí ves de un vistazo qué está pendiente hoy.',
        pasos: [
            'Revisa lo que requiere atención y entra al proceso o la tarjeta desde el propio listado.',
            'Usa el menú de la izquierda para ir a un módulo concreto.',
        ],
        automatico: ['Los indicadores se recalculan en cada carga; no hay que actualizar nada a mano.'],
    },

    processes: {
        titulo: 'Procesos',
        resumen: 'Cada caso o servicio contratado por un cliente, con su plan de trabajo, equipo y avance.',
        paraQue:
            'El proceso es la unidad central de la plataforma: todo lo demás (correos, tareas, documentos, pagos, visitas) cuelga de él. Si algo no está dentro de un proceso, el cliente no lo ve y la IA no lo tiene en cuenta.',
        pasos: [
            'Crea el proceso eligiendo cliente y tipo de servicio.',
            'Al crearlo se copian automáticamente las etapas, entregables y tarjetas del servicio elegido.',
            'Asigna el abogado líder y el resto del equipo.',
            'Trabaja el caso desde su detalle: etapas, tareas, correos, documentos y pagos.',
        ],
        automatico: [
            'El plan de trabajo (etapas + entregables) y las tarjetas del tablero se generan solos según el tipo de servicio.',
            'Los correos que llegan al buzón del despacho se enlazan al proceso cuando la IA los reconoce.',
        ],
        ojo: [
            'La plantilla solo se aplica al crear el proceso: si cambias la plantilla del servicio, los procesos ya existentes no se actualizan solos.',
        ],
    },

    processShow: {
        titulo: 'Detalle del proceso',
        resumen: 'Todo el caso en un solo lugar: avance, tareas, correos, documentos, pagos y visitas.',
        paraQue:
            'Concentra el expediente completo. Es también lo que alimenta a la IA: cuanto más completo esté el proceso, mejores son los borradores y las clasificaciones.',
        pasos: [
            'Etapas: marca los entregables a medida que los cumples; al primer chulo la etapa pasa a "en curso".',
            'Cuando termines una etapa usa "Completar etapa": valida que no falte ningún entregable obligatorio y activa la siguiente.',
            'Correos: responde desde la pestaña "Correos" para que la respuesta quede registrada en el expediente.',
            'Documentos: sube lo que produzcas; marca "visible para el cliente" solo lo que quieras que vea en su portal.',
            'Pagos: registra cada pago recibido; el cliente los ve como constancia en su portal.',
        ],
        automatico: [
            'El porcentaje de avance que ve el cliente en su portal sale de las etapas completadas.',
            'Al completar una etapa, la siguiente pasa sola a "en curso".',
            'La IA lee el expediente (correos, documentos, historial) al generar cualquier borrador.',
        ],
        ojo: [
            'Solo se envía al portal del cliente lo marcado como visible: documentos, visitas y los pagos registrados.',
            'Completar la etapa es distinto de marcar entregables: son dos permisos separados, y no todos los roles pueden cerrar etapas.',
        ],
    },

    board: {
        titulo: 'Tablero de tareas',
        resumen: 'Las tareas de todos los procesos en columnas, más una bandeja con los correos por responder.',
        paraQue:
            'Es la vista de trabajo del día: qué hay que hacer, quién lo tiene y qué está frenado, sin entrar proceso por proceso.',
        pasos: [
            'Arrastra una tarjeta entre columnas para cambiarle el estado.',
            'Usa los chips de arriba para ver un solo proceso o todos.',
            'Haz clic en una tarjeta para abrir el panel de detalle: responsable, prioridad, fecha límite, adjuntos y correos.',
            'En la columna "Bandeja" haz clic en un correo para responderlo sin salir del tablero.',
        ],
        automatico: [
            'Las tarjetas se crean solas al abrir un proceso, según el servicio o el plan importado con IA.',
            'Un correo respondido queda marcado como "Respondido" con su fecha.',
        ],
        ojo: [
            'Las tarjetas autogeneradas nacen sin responsable: hay que asignarlas desde el panel de detalle.',
            'Los correos de la bandeja no se arrastran: siguen perteneciendo al proceso, no son tareas.',
        ],
    },

    clients: {
        titulo: 'Clientes',
        resumen: 'Las empresas atendidas, sus datos, documentos y acceso al portal.',
        paraQue:
            'Además del directorio, es donde vive el conocimiento del cliente: los documentos de nivel cliente (contratos, diagnósticos) alimentan a la IA en TODOS sus procesos.',
        pasos: [
            'Crea el cliente con su razón social y NIT (el NIT es la clave con la que entra a su portal).',
            'Adjunta en la pestaña "Documentos" el contrato y todo lo transversal al cliente.',
            'Activa el portal cuando el cliente deba poder consultar su avance; el sistema entrega las credenciales una sola vez.',
        ],
        automatico: [
            'Al subir o borrar un documento se regenera la ficha de conocimiento del cliente que usa la IA.',
        ],
        ojo: [
            'Los documentos que subes aquí son del cliente, no de un proceso concreto: aplican a todos sus casos.',
            'Ver clientes y crearlos son permisos distintos: varios roles ven todo pero no pueden crear.',
        ],
    },

    clientKnowledge: {
        titulo: 'Ficha de conocimiento',
        resumen: 'Resumen que la IA hace de todos los documentos del cliente y usa en cada borrador.',
        paraQue:
            'Los documentos completos no caben en cada consulta a la IA. La ficha es un resumen fiel de todo el material del cliente que sí cabe siempre, y se acompaña del texto literal de lo más reciente.',
        pasos: [
            'Sube los documentos del cliente y la ficha se genera sola.',
            'Si la ves marcada como "Desactualizada", usa "Regenerar" para ponerla al día.',
        ],
        automatico: [
            'Se regenera al subir o borrar un documento del cliente.',
            'Se alimenta también de los documentos sincronizados desde la unidad compartida de Drive.',
        ],
        ojo: [
            'Regenerarla consume servicio de IA: no la regeneres sin necesidad.',
            'Un PDF escaneado sin OCR no aporta texto; aparecerá como documento pero la IA no podrá leerlo.',
        ],
    },

    contracts: {
        titulo: 'Contratos',
        resumen: 'Los contratos firmados con cada cliente y su vigencia.',
        paraQue: 'Dejan por escrito el alcance y las fechas que después se reflejan en los procesos y sus etapas.',
        pasos: ['Registra el contrato asociado al cliente.', 'Adjunta el documento firmado y su vigencia.'],
    },

    emails: {
        titulo: 'Correos',
        resumen: 'Los correos del despacho, clasificados por IA y enlazados al proceso que les corresponde.',
        paraQue:
            'Evita que el correo viva aparte del expediente: lo que llega se clasifica, se archiva en su proceso y se responde desde aquí, quedando registrado.',
        pasos: [
            'Revisa los correos que la IA dejó en "requiere revisión" y enlázalos al proceso correcto.',
            'Para responder, entra al proceso, pestaña "Correos", y usa "Responder".',
            'Si quieres ayuda con la redacción, usa "Redactar con IA" y edita el resultado antes de enviar.',
        ],
        automatico: [
            'El sistema revisa el buzón conectado cada pocos minutos e ingiere lo nuevo.',
            'La IA clasifica cada correo y, si reconoce el cliente y el servicio con suficiente certeza, lo enlaza o incluso abre el proceso.',
            'Los adjuntos se guardan como documentos del proceso.',
        ],
        ojo: [
            'Responder desde la app envía el correo REAL e inmediato por la cuenta del despacho, enhebrado con el original.',
            'Que figure "Respondido" significa enviado, no entregado ni leído: Gmail no da acuse por destinatario. Un rebote llega como correo nuevo.',
        ],
    },

    payments: {
        titulo: 'Facturación',
        resumen: 'Reporte de todo lo recaudado: por mes, por método de pago y por proceso.',
        paraQue: 'Da la foto financiera del despacho sin salir de la plataforma ni cruzar planillas a mano.',
        pasos: [
            'Los pagos se registran dentro de cada proceso, en su pestaña "Pagos".',
            'Aquí ves el consolidado; filtra por mes o método y haz clic en el gráfico para bajar al detalle.',
            'Adjunta el soporte o la factura a cada pago desde el proceso.',
        ],
        ojo: [
            'Este módulo no emite facturas: es el registro y el reporte de los pagos recibidos.',
            'Cada quien ve el recaudo de los procesos que le corresponden según su rol.',
        ],
    },

    aiUsage: {
        titulo: 'Uso de IA',
        resumen: 'Cuánto se ha usado la IA y cuánto ha costado, por mes, persona y modelo.',
        paraQue: 'Cada generación tiene un costo real; este módulo lo hace visible para poder controlarlo.',
        pasos: ['Filtra por mes, usuario o modelo.', 'Revisa las generaciones con error para detectar problemas.'],
        automatico: ['Toda llamada a la IA queda registrada aquí, venga de donde venga.'],
    },

    playground: {
        titulo: 'Laboratorio de IA',
        resumen: 'Espacio de pruebas para experimentar con la IA sin afectar ningún expediente.',
        paraQue: 'Sirve para probar redacciones y afinar instrucciones antes de usarlas en un caso real.',
        ojo: [
            'Es un sandbox: lo que generes aquí NO se envía por correo ni queda guardado en ningún proceso.',
            'Para generar y enviar de verdad: entra al proceso → pestaña "Correos" → Responder → Redactar con IA.',
        ],
    },

    planImport: {
        titulo: 'Importar plan o contrato con IA',
        resumen: 'Sube el plan de trabajo o el contrato y la IA propone las etapas, entregables y tareas.',
        paraQue: 'Evita transcribir a mano un plan que ya está escrito en un documento.',
        pasos: [
            'Sube el documento (PDF, Word o texto).',
            'Revisa y edita la propuesta: puedes añadir, quitar o corregir cualquier etapa, entregable o tarea.',
            'Aplica. Si quieres, guárdalo también como plantilla del servicio para los próximos procesos.',
        ],
        ojo: ['Nada se aplica sin que lo confirmes: la propuesta siempre pasa por tu revisión.'],
    },

    gmail: {
        titulo: 'Conexión con Gmail',
        resumen: 'La cuenta de correo del despacho desde la que la plataforma lee y responde.',
        paraQue: 'Es el puente entre el correo y los expedientes: sin esta conexión no hay ingesta ni respuestas.',
        pasos: [
            'Conecta la cuenta del despacho y autoriza los permisos que pide Google.',
            'Si la plataforma pide permisos nuevos, vuelve a conectar: Google no amplía una autorización ya dada.',
        ],
        ojo: [
            'Es una sola cuenta compartida por todo el despacho: desconectarla detiene la ingesta y las respuestas para todos.',
        ],
    },

    drive: {
        titulo: 'Documentos desde Drive',
        resumen: 'La unidad compartida del despacho se sincroniza para que la IA conozca a cada cliente.',
        paraQue:
            'Los documentos que el despacho ya trabaja en Drive pasan a formar parte del expediente y del conocimiento que la IA tiene de cada cliente, sin volver a subirlos.',
        pasos: [
            'Cada cliente se asocia a su carpeta de la unidad compartida.',
            'La sincronización trae los documentos nuevos o modificados y extrae su texto.',
        ],
        automatico: [
            'Solo se vuelve a traer lo que cambió en Drive.',
            'Al detectar cambios se actualiza la ficha de conocimiento del cliente.',
        ],
        ojo: [
            'Es de solo lectura: la plataforma nunca modifica ni borra nada en Drive.',
            'Un PDF escaneado sin OCR se registra pero no aporta texto legible.',
        ],
    },

    users: {
        titulo: 'Usuarios y roles',
        resumen: 'Quién entra a la plataforma y qué puede hacer cada quien.',
        paraQue: 'El rol define qué ve y qué puede modificar cada persona; no es solo una etiqueta.',
        ojo: [
            'Desactivar un usuario le corta el acceso de inmediato, pero conserva su historial y lo que produjo.',
        ],
    },

    portalDashboard: {
        titulo: 'Sus procesos',
        resumen: 'El estado de cada servicio que el despacho adelanta para usted.',
        paraQue: 'Para consultar el avance cuando lo necesite, sin tener que preguntar por correo.',
        pasos: ['Entre a un proceso para ver su plan de trabajo, documentos, visitas y pagos.'],
        ojo: ['Esta vista es de consulta: la información la actualiza el equipo del despacho.'],
    },

    portalProcess: {
        titulo: 'Detalle del proceso',
        resumen: 'Plan de trabajo, avance, documentos, visitas y constancia de pagos.',
        paraQue: 'Reúne en un solo lugar todo lo que el despacho ha adelantado y registrado en su caso.',
        automatico: ['El avance se actualiza a medida que el equipo completa las etapas del plan.'],
        ojo: ['Verá únicamente los documentos y visitas que el despacho marcó como visibles para usted.'],
    },
};

/**
 * Devuelve la entrada de ayuda de un módulo (o null si no existe), para que un
 * `PageHeader` sin ayuda definida simplemente no muestre el desplegable.
 */
export const getHelp = (key) => help[key] ?? null;
