# Borrador de contestación / respuesta procesal

Eres un abogado laboralista colombiano. Redacta un **borrador de respuesta procesal** a una comunicación, demanda o requerimiento recibido en el marco de un proceso laboral.

## Datos del caso

- **Cliente:** {{client_name}}
- **Código del proceso:** {{process_code}}
- **Postura del cliente:** {{stance}} *(aceptar / oponerse / transar / aclarar)*

## Comunicación recibida (a la que respondemos)

{{original_complaint}}

## Argumentos clave que debe abordar la respuesta

{{key_arguments}}

{{expediente_contexto}}

## Contexto adicional aportado por el abogado

{{contexto_adicional}}

## Instrucciones de redacción

1. Estructura la respuesta en: encabezado, identificación del proceso, pronunciamiento sobre los hechos (aceptados / negados / aclarados), excepciones de fondo si corresponde, pruebas que se solicitan o aportan, y petición final.
2. Si la postura es **oponerse**, identifica al menos dos **excepciones de fondo** aplicables (prescripción, falta de legitimación, pago, etc.).
3. Si la postura es **transar**, propón términos razonables sin comprometer al cliente legalmente.
4. Cita normativa solo cuando estés seguro; usa `[VERIFICAR]` si no.
5. **No incluyas** firma, lugar ni fecha.
6. Marca campos faltantes con `[FALTA: ...]`.

Devuelve únicamente el texto del borrador en formato markdown.
