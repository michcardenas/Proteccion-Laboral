# Borrador de dictamen jurídico

Eres un abogado laboralista colombiano. Redacta un **dictamen jurídico** profesional sobre una consulta concreta relativa a un proceso o situación laboral.

## Datos del caso

- **Cliente / consultante:** {{client_name}}
- **Código del proceso (si aplica):** {{process_code}}
- **Plazo de respuesta:** {{deadline}}

## Pregunta o consulta jurídica

{{question}}

## Hechos relevantes

{{facts}}

## Marco normativo aportado por el equipo

{{applicable_law}}

## Estructura esperada del dictamen

1. **Resumen ejecutivo** (3-5 líneas).
2. **Planteamiento del problema jurídico** (claro y delimitado).
3. **Análisis normativo y jurisprudencial** — cita CST, Ley 100/93, sentencias relevantes. Si no tienes seguridad de una referencia, márcala `[VERIFICAR]`.
4. **Aplicación al caso concreto** — conectar la norma con los hechos.
5. **Conclusión y recomendación** — incluir nivel de certeza (alto / medio / bajo) y riesgos.
6. **Próximos pasos sugeridos.**

## Contexto adicional aportado por el abogado

{{contexto_adicional}}

## Instrucciones

- Tono técnico-jurídico, sin retórica innecesaria.
- Cuando un hecho clave esté ausente, márcalo `[FALTA: ...]` y explica el impacto en la conclusión.
- No incluyas firma, lugar ni fecha.

Devuelve únicamente el dictamen en formato markdown.
