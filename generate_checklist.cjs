const fs = require('fs');
const path = require('path');

const globalModules = 'C:/Users/HP/AppData/Roaming/npm/node_modules';
process.env.NODE_PATH = globalModules;
require('module').Module._initPaths();

const {
    Document, Packer, Paragraph, TextRun, Table, TableRow, TableCell,
    Header, Footer, AlignmentType, LevelFormat, HeadingLevel,
    BorderStyle, WidthType, ShadingType, PageNumber, PageBreak,
} = require('docx');

const border = { style: BorderStyle.SINGLE, size: 4, color: 'D5D9E0' };
const borders = { top: border, bottom: border, left: border, right: border };

const checkItem = (text) => new Paragraph({
    numbering: { reference: 'checks', level: 0 },
    spacing: { after: 80 },
    children: [new TextRun({ text, font: 'Arial', size: 22 })],
});
const subItem = (text) => new Paragraph({
    numbering: { reference: 'subchecks', level: 0 },
    spacing: { after: 60 },
    children: [new TextRun({ text, font: 'Arial', size: 20, color: '4F5A6B' })],
});
const questionItem = (text) => new Paragraph({
    numbering: { reference: 'questions', level: 0 },
    spacing: { after: 100 },
    children: [new TextRun({ text, font: 'Arial', size: 22 })],
});
const sectionTitle = (text) => new Paragraph({
    heading: HeadingLevel.HEADING_1,
    spacing: { before: 360, after: 200 },
    children: [new TextRun({ text, font: 'Arial', size: 30, bold: true, color: '0F172A' })],
});
const subSection = (text) => new Paragraph({
    heading: HeadingLevel.HEADING_2,
    spacing: { before: 240, after: 120 },
    children: [new TextRun({ text, font: 'Arial', size: 24, bold: true, color: '404857' })],
});
const para = (text, opts = {}) => new Paragraph({
    spacing: { after: 120 },
    children: [new TextRun({ text, font: 'Arial', size: 22, ...opts })],
});
const small = (text) => new Paragraph({
    spacing: { after: 80 },
    children: [new TextRun({ text, font: 'Arial', size: 18, color: '647082', italics: true })],
});
const tableHeaderCell = (text, width = 4680) => new TableCell({
    borders,
    width: { size: width, type: WidthType.DXA },
    shading: { fill: '0F172A', type: ShadingType.CLEAR, color: 'auto' },
    margins: { top: 100, bottom: 100, left: 140, right: 140 },
    children: [new Paragraph({ children: [new TextRun({ text, font: 'Arial', size: 20, bold: true, color: 'FFFFFF' })] })],
});
const tableCell = (text, width = 4680, bold = false) => new TableCell({
    borders,
    width: { size: width, type: WidthType.DXA },
    margins: { top: 100, bottom: 100, left: 140, right: 140 },
    children: [new Paragraph({ children: [new TextRun({ text, font: 'Arial', size: 20, bold })] })],
});
const calloutPara = (text) => new Paragraph({
    spacing: { before: 120, after: 120 },
    border: {
        left: { style: BorderStyle.SINGLE, size: 24, color: '4F46E5', space: 8 },
        top: { style: BorderStyle.SINGLE, size: 4, color: 'E4E4F4', space: 6 },
        bottom: { style: BorderStyle.SINGLE, size: 4, color: 'E4E4F4', space: 6 },
        right: { style: BorderStyle.SINGLE, size: 4, color: 'E4E4F4', space: 6 },
    },
    shading: { fill: 'F5F5FF', type: ShadingType.CLEAR, color: 'auto' },
    children: [new TextRun({ text, font: 'Arial', size: 21, color: '0F172A' })],
});

const children = [];

children.push(new Paragraph({
    alignment: AlignmentType.CENTER,
    spacing: { after: 60 },
    children: [new TextRun({ text: 'Checklist + Preguntas al Cliente', font: 'Arial', size: 36, bold: true, color: '0F172A' })],
}));
children.push(new Paragraph({
    alignment: AlignmentType.CENTER,
    spacing: { after: 60 },
    children: [new TextRun({ text: 'Protección Laboral – Soluciones Legales SAS', font: 'Arial', size: 26, bold: true, color: '4F46E5' })],
}));
children.push(new Paragraph({
    alignment: AlignmentType.CENTER,
    spacing: { after: 240 },
    children: [new TextRun({ text: 'Implementación del sistema custom: IA, correos, hosting, migración y módulo de diagnóstico', font: 'Arial', size: 20, italics: true, color: '647082' })],
}));
children.push(new Paragraph({
    alignment: AlignmentType.CENTER,
    spacing: { after: 360 },
    border: { bottom: { style: BorderStyle.SINGLE, size: 6, color: '0F172A', space: 1 } },
    children: [new TextRun({ text: '', font: 'Arial', size: 20 })],
}));

children.push(new Paragraph({
    spacing: { after: 200 },
    children: [new TextRun({ text: 'PARTE A — CHECKLIST DE REQUERIMIENTOS', font: 'Arial', size: 28, bold: true, color: '4F46E5' })],
}));

children.push(sectionTitle('1. IA — Claude API (Anthropic)'));
children.push(small('Necesario para borradores de tutelas/derechos de petición, resumir casos, recomendaciones del diagnóstico, migrar histórico.'));
children.push(subSection('Cuenta y credenciales'));
children.push(checkItem('Crear cuenta en Anthropic (https://console.anthropic.com)'));
children.push(checkItem('Cargar saldo inicial USD 20–50 (recomendado USD 20 para arrancar)'));
children.push(checkItem('Generar API key — nombrarla "proteccion-laboral-prod"'));
children.push(checkItem('Configurar límite de gasto mensual (recomendado USD 100/mes)'));
children.push(checkItem('Entregar la API key al equipo de desarrollo (formato sk-ant-api03-...)'));
children.push(subSection('Decisiones'));
children.push(checkItem('Modelo a usar: Claude Sonnet (calidad alta) o Claude Haiku (más económico)'));
children.push(checkItem('Política de privacidad: ¿IA puede usar casos cerrados como contexto? (sí/no)'));
children.push(checkItem('Política de aprobación: TODA respuesta IA queda como BORRADOR — siempre revisa abogado'));

children.push(sectionTitle('2. Hosting — VPS'));
children.push(subSection('Selección de proveedor'));
children.push(checkItem('Elegir VPS:'));
children.push(subItem('Hostinger VPS — recomendado, USD 8–15/mes, soporte en español'));
children.push(subItem('DigitalOcean — USD 6–12/mes, más técnico'));
children.push(subItem('AWS Lightsail — USD 10/mes'));
children.push(subSection('Especificaciones mínimas'));
children.push(checkItem('2 vCPU · 4 GB RAM · 80 GB SSD'));
children.push(checkItem('Ubuntu 22.04 LTS · PHP 8.2+ · MySQL 8 · Node 20+ · Redis (opcional)'));
children.push(subSection('Acceso y dominio'));
children.push(checkItem('Entregar credenciales del VPS (usuario / contraseña / SSH key)'));
children.push(checkItem('Definir dominio definitivo (sugerido: app.proteccionlaboral.co)'));
children.push(checkItem('Acceso al panel de DNS (Cloudflare / GoDaddy / etc.)'));
children.push(checkItem('Activar backups diarios del VPS (USD 2/mes en Hostinger)'));

children.push(sectionTitle('3. Correos — Gmail e integración'));
children.push(small('CRÍTICO — el correo es el corazón del flujo. Reemplaza el copiar manual de adjuntos al Drive.'));
children.push(subSection('Tipo de correo actual'));
children.push(checkItem('Confirmar tipo de correo del despacho:'));
children.push(subItem('Google Workspace (Gmail empresarial pago) — recomendado'));
children.push(subItem('Gmail personal con dominio propio'));
children.push(subItem('Outlook / Office 365'));
children.push(subItem('Otro: _____________________'));
children.push(subSection('Integración entrante (recibir casos)'));
children.push(checkItem('Confirmar correo donde llegan procesos (info@, procesos@, etc.)'));
children.push(checkItem('Identificar admin del correo (autoriza OAuth con un click)'));
children.push(checkItem('Estimar volumen de correos/día con casos (10, 50, 200…)'));
children.push(checkItem('Aprobar conexión OAuth con Gmail'));
children.push(subSection('Integración saliente (notificaciones)'));
children.push(checkItem('Decidir proveedor:'));
children.push(subItem('Resend — recomendado, USD 0–20/mes, 3.000 correos gratis'));
children.push(subItem('Postmark — USD 15/mes, mejor entregabilidad'));
children.push(subItem('Mailgun — USD 35/mes'));
children.push(checkItem('Crear cuenta en proveedor elegido'));
children.push(checkItem('Verificar dominio proteccionlaboral.co en el proveedor'));
children.push(checkItem('Configurar DNS — SPF, DKIM, DMARC (3 registros TXT)'));
children.push(checkItem('Confirmar remitente oficial (sugerido: notificaciones@proteccionlaboral.co)'));
children.push(checkItem('Entregar API key del proveedor de correos'));

children.push(new Paragraph({ children: [new PageBreak()] }));
children.push(sectionTitle('4. Migración de casos existentes'));
children.push(subSection('Inventario actual'));
children.push(checkItem('Cantidad de casos ACTIVOS: _______'));
children.push(checkItem('Cantidad de casos CERRADOS a preservar: _______'));
children.push(checkItem('Antigüedad: hasta cuántos años hacia atrás'));
children.push(checkItem('Ubicación de archivos:'));
children.push(subItem('Google Drive del despacho'));
children.push(subItem('Carpetas en Gmail individual'));
children.push(subItem('Disco local'));
children.push(subItem('Mezcla de fuentes'));
children.push(checkItem('Sistema actual de organización (carpetas por cliente / código / año)'));
children.push(subSection('Estrategia según volumen'));
children.push(checkItem('Hasta 50 casos → Carga manual asistida con plantilla CSV (2–3 días)'));
children.push(checkItem('50–300 casos → Migración semi-automática con IA: ZIP del Drive + Claude clasifica (1 semana, USD 50–150)'));
children.push(checkItem('Más de 300 + Gmail histórico → Importador masivo desde Gmail con IA (2–3 semanas, USD 100–400)'));
children.push(checkItem('Aprobar que IA preprocese y abogado valide cada caso'));
children.push(checkItem('Designar responsable interno para la revisión'));

children.push(sectionTitle('5. Módulo de Diagnóstico (rescate plataforma anterior)'));
children.push(calloutPara('Detectado en el ZIP enviado: la plataforma anterior tiene 4 líneas, 262 preguntas, 524 opciones y 60 empresas diagnosticadas. Funciona como árbol de decisión que calcula % de cumplimiento por área. Se integra como módulo de "Prediagnóstico" en el nuevo sistema.'));
children.push(subSection('Datos a migrar de la plataforma anterior'));
children.push(checkItem('Migrar 4 líneas / áreas de diagnóstico (con descripción y peso %)'));
children.push(checkItem('Migrar 262 preguntas (con literal, importancia, nivel, jerarquía padre-hijo)'));
children.push(checkItem('Migrar 524 opciones (con porcentaje, consecuencia y pregunta siguiente)'));
children.push(checkItem('Migrar explicaciones por nivel (25 / 50 / 75 / 100 %) por línea'));
children.push(checkItem('Migrar 60 empresas y sus 5.996 respuestas históricas (opcional pero útil para IA)'));
children.push(subSection('Decisiones del cliente'));
children.push(checkItem('¿Vale la pena migrar las respuestas históricas o arrancamos limpios?'));
children.push(checkItem('¿Se quiere conservar la lógica de árbol de preguntas tal cual o renovarla?'));
children.push(checkItem('¿Las explicaciones por nivel se mantienen o las reescribe la IA?'));
children.push(checkItem('¿El cliente final responde el diagnóstico solo o asistido por consultor?'));
children.push(checkItem('¿Al cerrar el diagnóstico se genera plan de acción automático con IA? (sí/no)'));
children.push(subSection('Quién hace qué'));
children.push(checkItem('Equipo de desarrollo: importa SQL, adapta esquema, integra con módulo de Procesos'));
children.push(checkItem('Cliente: revisa preguntas y opciones (¿vigentes? ¿cambios normativos?)'));
children.push(checkItem('Cliente: aprueba textos de explicaciones por nivel (o aprueba que IA los genere)'));

children.push(sectionTitle('6. Decisiones transversales'));
children.push(subSection('Privacidad y cumplimiento'));
children.push(checkItem('Autorizar que Claude (Anthropic) procese textos legales — SOC 2 certificado, no entrena con datos de API'));
children.push(checkItem('Definir retención de documentos cerrados (recomendado 5–10 años)'));
children.push(checkItem('Activar backup diario de la base de datos'));
children.push(subSection('Portal del cliente externo'));
children.push(checkItem('¿Cada empresa-cliente tendrá login propio? (sí/no)'));
children.push(checkItem('¿Qué información ve el cliente desde su portal?'));
children.push(subItem('Solo procesos abiertos'));
children.push(subItem('También documentos compartidos'));
children.push(subItem('También facturas y pagos'));
children.push(subItem('Su diagnóstico y plan de acción'));
children.push(subSection('Equipo interno'));
children.push(checkItem('Confirmar lista de usuarios y roles del equipo'));
children.push(checkItem('Confirmar que cada usuario tenga correo @proteccionlaboral.co activo'));

children.push(sectionTitle('Resumen de costos mensuales'));
children.push(new Table({
    width: { size: 9360, type: WidthType.DXA },
    columnWidths: [4680, 4680],
    rows: [
        new TableRow({ tableHeader: true, children: [tableHeaderCell('Servicio'), tableHeaderCell('Costo / mes (USD)')] }),
        new TableRow({ children: [tableCell('VPS Hostinger'), tableCell('10')] }),
        new TableRow({ children: [tableCell('Backups VPS'), tableCell('2')] }),
        new TableRow({ children: [tableCell('Claude API (uso real)'), tableCell('30 – 80')] }),
        new TableRow({ children: [tableCell('Resend (correos)'), tableCell('0 – 20')] }),
        new TableRow({ children: [tableCell('Dominio .co (anual prorrateado)'), tableCell('2.5')] }),
        new TableRow({ children: [tableCell('TOTAL ESTIMADO', 4680, true), tableCell('USD 45 – 115 / mes', 4680, true)] }),
    ],
}));
children.push(small('NO se requiere n8n: toda la automatización va nativa en Laravel. Vs. costo anterior de Notion + Make + ChatGPT + Drive: USD 60–120/mes sin integración custom.'));

children.push(sectionTitle('Prioridad — Lo mínimo para arrancar IA'));
children.push(para('Si el cliente solo entrega 3 cosas hoy:', { bold: true }));
children.push(checkItem('API key de Claude'));
children.push(checkItem('"Borrador siempre lo revisa abogado" — sí'));
children.push(checkItem('"Casos cerrados se pueden usar como contexto" — sí'));
children.push(small('Con esto, primera versión de IA en 1 semana. Hosting y correos en paralelo, no bloquean.'));

children.push(sectionTitle('Cronograma estimado'));
children.push(new Table({
    width: { size: 9360, type: WidthType.DXA },
    columnWidths: [1872, 7488],
    rows: [
        new TableRow({ tableHeader: true, children: [tableHeaderCell('Semana', 1872), tableHeaderCell('Entregable', 7488)] }),
        new TableRow({ children: [tableCell('1', 1872, true), tableCell('Botón "Generar borrador con IA" + integración Claude API + auditoría', 7488)] }),
        new TableRow({ children: [tableCell('2', 1872, true), tableCell('Conexión Gmail (entrada de correos asociada a procesos)', 7488)] }),
        new TableRow({ children: [tableCell('3', 1872, true), tableCell('Widget "Brief del caso" + módulo Diagnóstico (líneas, preguntas, opciones)', 7488)] }),
        new TableRow({ children: [tableCell('4', 1872, true), tableCell('Migración de casos históricos + migración del diagnóstico anterior', 7488)] }),
        new TableRow({ children: [tableCell('5', 1872, true), tableCell('Portal cliente + plan de acción IA al cerrar diagnóstico + correos salientes', 7488)] }),
        new TableRow({ children: [tableCell('6', 1872, true), tableCell('Pulido + capacitación + paso a producción', 7488)] }),
    ],
}));

children.push(new Paragraph({ children: [new PageBreak()] }));
children.push(new Paragraph({
    spacing: { after: 200 },
    children: [new TextRun({ text: 'PARTE B — PREGUNTAS A REALIZARLE AL CLIENTE', font: 'Arial', size: 28, bold: true, color: '4F46E5' })],
}));
children.push(small('Estructuradas por bloques. Llevar a la reunión y marcar respuestas.'));

children.push(sectionTitle('Bloque 1 — Operación actual'));
children.push(questionItem('¿Cuántos abogados / consultores trabajan hoy en el despacho?'));
children.push(questionItem('¿Cuántos casos activos manejan en promedio?'));
children.push(questionItem('¿Cuántos casos nuevos entran al mes?'));
children.push(questionItem('¿Qué tipos de casos son los más frecuentes (tutela, derecho de petición, demanda laboral, conciliación, asesoría)?'));
children.push(questionItem('¿Cómo entran los casos hoy: correo, llamada, WhatsApp, presencial, formulario web?'));
children.push(questionItem('Cuando entra un caso por correo: ¿quién lo recibe primero? ¿quién lo asigna?'));
children.push(questionItem('¿Tienen un código o numeración interna por caso? ¿qué formato?'));

children.push(sectionTitle('Bloque 2 — Correo electrónico'));
children.push(questionItem('¿Tienen Google Workspace (Gmail empresarial pago) o Gmail personal?'));
children.push(questionItem('¿Cuál es la cuenta principal donde llegan los procesos?'));
children.push(questionItem('¿Quién es el administrador del correo del despacho?'));
children.push(questionItem('¿Cuántos correos con casos reciben al día aproximadamente?'));
children.push(questionItem('Cuando responden al cliente: ¿desde Gmail o desde la plataforma?'));
children.push(questionItem('¿Quieren notificaciones automáticas por correo (recordatorios, confirmaciones)?'));

children.push(sectionTitle('Bloque 3 — Documentos y archivos actuales'));
children.push(questionItem('¿Dónde guardan hoy los archivos: Drive, Dropbox, disco local?'));
children.push(questionItem('¿Cómo organizan las carpetas: por cliente, código, año, tipo?'));
children.push(questionItem('¿Cuántos casos cerrados quieren preservar? ¿Hasta qué año hacia atrás?'));
children.push(questionItem('¿Tienen plantillas de documentos legales que la IA debería usar como base?'));
children.push(questionItem('¿Hay documentos confidenciales que NO deben procesarse por IA?'));

children.push(sectionTitle('Bloque 4 — IA y borradores'));
children.push(questionItem('¿Aprueban que TODA respuesta IA quede como BORRADOR y un abogado revise antes? (sí/no)'));
children.push(questionItem('¿Aprueban que la IA use casos cerrados como contexto para nuevas respuestas? (sí/no)'));
children.push(questionItem('¿Quién aprueba los borradores antes de salir al cliente?'));
children.push(questionItem('¿Qué tipos de documento priorizan automatizar primero (1–3)?'));
children.push(questionItem('¿La IA imita el tono usado con cada cliente o tono uniforme del despacho?'));

children.push(sectionTitle('Bloque 5 — Plataforma anterior (diagnóstico)'));
children.push(questionItem('Las 4 líneas y 262 preguntas: ¿siguen siendo válidas o hay cambios normativos?'));
children.push(questionItem('¿Mantener el árbol de preguntas igual o reorganizarlo?'));
children.push(questionItem('¿Las explicaciones por nivel (25/50/75/100%) se mantienen o las reescribe la IA?'));
children.push(questionItem('¿El cliente externo responde el diagnóstico solo o lo aplica un consultor?'));
children.push(questionItem('¿Al cerrar el diagnóstico se genera automáticamente un plan de acción con IA?'));
children.push(questionItem('Las 60 empresas y 5.996 respuestas históricas: ¿migrar o arrancar limpios?'));
children.push(questionItem('¿Quién valida hoy las preguntas del diagnóstico (Carlos, Camila, otro)?'));

children.push(sectionTitle('Bloque 6 — Portal del cliente externo'));
children.push(questionItem('¿Cada empresa-cliente debe tener login propio?'));
children.push(questionItem('¿Qué información ve cada cliente?'));
children.push(subItem('Solo procesos abiertos'));
children.push(subItem('También documentos'));
children.push(subItem('También facturas / pagos'));
children.push(subItem('Su diagnóstico y plan de acción'));
children.push(subItem('Histórico cerrado'));
children.push(questionItem('¿El cliente puede subir documentos o solo recibir?'));
children.push(questionItem('¿El cliente puede comentar / responder por la plataforma?'));
children.push(questionItem('¿Hay flujo de firma electrónica deseado?'));

children.push(sectionTitle('Bloque 7 — Facturación y contabilidad'));
children.push(questionItem('¿Quién maneja hoy la facturación (Eliana sola, terceros)?'));
children.push(questionItem('¿Usan Siigo, Alegra, World Office u otro contable que haya que integrar?'));
children.push(questionItem('¿Las facturas las emite la plataforma o solo registra los pagos?'));
children.push(questionItem('¿Cómo cobran: anticipo, mensualidad, por etapa, mixto?'));
children.push(questionItem('¿Qué reporte espera el contador cada mes?'));

children.push(sectionTitle('Bloque 8 — Hosting y dominio'));
children.push(questionItem('¿Tienen ya el dominio proteccionlaboral.co contratado? ¿Quién lo administra?'));
children.push(questionItem('¿Tienen VPS / servidor actual o arrancamos de cero?'));
children.push(questionItem('¿Tienen presupuesto definido para hosting + IA mensual?'));
children.push(questionItem('¿Quién paga las suscripciones (despacho directo o refacturado)?'));

children.push(sectionTitle('Bloque 9 — Equipo interno y capacitación'));
children.push(questionItem('¿Quiénes serán los usuarios del sistema y con qué rol cada uno?'));
children.push(questionItem('¿Quién será el "súper administrador" del sistema?'));
children.push(questionItem('¿Cómo prefieren la capacitación: presencial, virtual, individual, grupal?'));
children.push(questionItem('¿Hay alguien técnicamente líder en el despacho que sea contraparte?'));

children.push(sectionTitle('Bloque 10 — Integraciones futuras (visión)'));
children.push(questionItem('¿WhatsApp Business para notificar a clientes? (fase 2)'));
children.push(questionItem('¿Calendario / agenda integrada con Google Calendar?'));
children.push(questionItem('¿Firma electrónica (DocuSign, HelloSign, Signio Colombia)?'));
children.push(questionItem('¿Pasarelas de pago (Wompi, ePayco, MercadoPago) para pagos en línea?'));
children.push(questionItem('¿Reportes a entidades (DIAN, Supersociedades)?'));

children.push(sectionTitle('Bloque 11 — Prioridades y plazos'));
children.push(questionItem('¿Cuál es el dolor más grande hoy?'));
children.push(questionItem('¿Tienen fecha objetivo para entrar en producción?'));
children.push(questionItem('¿Hay algún evento o cliente importante que marque la fecha?'));
children.push(questionItem('Si tuvieran que escoger 1 funcionalidad para empezar mañana, ¿cuál sería?'));

const doc = new Document({
    creator: 'Protección Laboral',
    title: 'Checklist + Preguntas — Sistema',
    styles: {
        default: { document: { run: { font: 'Arial', size: 22 } } },
        paragraphStyles: [
            { id: 'Heading1', name: 'Heading 1', basedOn: 'Normal', next: 'Normal', quickFormat: true,
                run: { size: 30, bold: true, font: 'Arial', color: '0F172A' },
                paragraph: { spacing: { before: 360, after: 200 }, outlineLevel: 0 } },
            { id: 'Heading2', name: 'Heading 2', basedOn: 'Normal', next: 'Normal', quickFormat: true,
                run: { size: 24, bold: true, font: 'Arial', color: '404857' },
                paragraph: { spacing: { before: 240, after: 120 }, outlineLevel: 1 } },
        ],
    },
    numbering: {
        config: [
            { reference: 'checks',
                levels: [{ level: 0, format: LevelFormat.BULLET, text: '☐', alignment: AlignmentType.LEFT,
                    style: { paragraph: { indent: { left: 540, hanging: 300 } } } }] },
            { reference: 'subchecks',
                levels: [{ level: 0, format: LevelFormat.BULLET, text: '○', alignment: AlignmentType.LEFT,
                    style: { paragraph: { indent: { left: 1080, hanging: 280 } } } }] },
            { reference: 'questions',
                levels: [{ level: 0, format: LevelFormat.DECIMAL, text: '%1.', alignment: AlignmentType.LEFT,
                    style: { paragraph: { indent: { left: 720, hanging: 360 } } } }] },
        ],
    },
    sections: [{
        properties: {
            page: { size: { width: 12240, height: 15840 }, margin: { top: 1440, right: 1440, bottom: 1440, left: 1440 } },
        },
        headers: {
            default: new Header({
                children: [new Paragraph({
                    alignment: AlignmentType.RIGHT,
                    children: [new TextRun({ text: 'Protección Laboral · Soluciones Legales SAS', font: 'Arial', size: 18, color: '647082' })],
                })],
            }),
        },
        footers: {
            default: new Footer({
                children: [new Paragraph({
                    alignment: AlignmentType.CENTER,
                    children: [
                        new TextRun({ text: 'Página ', font: 'Arial', size: 18, color: '647082' }),
                        new TextRun({ children: [PageNumber.CURRENT], font: 'Arial', size: 18, color: '647082' }),
                        new TextRun({ text: ' de ', font: 'Arial', size: 18, color: '647082' }),
                        new TextRun({ children: [PageNumber.TOTAL_PAGES], font: 'Arial', size: 18, color: '647082' }),
                    ],
                })],
            }),
        },
        children,
    }],
});

const outFile = path.join(__dirname, 'Checklist_y_Preguntas_Cliente.docx');
Packer.toBuffer(doc).then((buffer) => {
    fs.writeFileSync(outFile, buffer);
    console.log('OK -> ' + outFile);
});
