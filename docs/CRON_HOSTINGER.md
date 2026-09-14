# Cron de Hostinger — Ingesta de correos (Gmail)

Cómo configurar el cron en Hostinger (hosting compartido) para que el sistema
sondee Gmail y procese los correos entrantes automáticamente.

## Contexto

- El scheduler de Laravel (`routes/console.php`) corre `PollGmailInbox` cada 2 minutos.
- `PollGmailInbox` trae los no leídos (`is:unread`), los guarda en `email_ingestions`
  (dedup por `message_id` único) y despacha `ProcessInboundEmail` solo para los nuevos.
- `ProcessInboundEmail` descarga adjuntos, clasifica con IA, enruta al proceso y
  **marca el correo como leído** (para que el siguiente sondeo no lo vuelva a traer).
- En hosting compartido NO se pueden dejar procesos demonio (`schedule:work` /
  `queue:work` permanentes). Solo hay cron → se usa `php artisan schedule:run`.

---

## Opción A — Recomendada (comando directo `gmail:poll`, un solo cron)

El cron llama directamente al comando `gmail:poll`, que sondea Gmail y procesa los
correos inline (con `QUEUE_CONNECTION=sync`). **No depende del timing del scheduler**
(`everyTwoMinutes`): la frecuencia la define el propio cron, así que funciona con
cualquier intervalo que permita tu plan de Hostinger. Sin worker, sin segundo cron.

### 1. `.env` en Hostinger

```env
APP_ENV=production
APP_DEBUG=false
QUEUE_CONNECTION=sync
```

### 2. Cron en hPanel → Avanzado → Cron Jobs

```
*/2 * * * * cd /home/uXXXXXXXX/domains/TU-DOMINIO/public_html && /usr/bin/php8.2 artisan gmail:poll >> /dev/null 2>&1
```

> Ajusta el intervalo a gusto: `*/2` (cada 2 min), `*/5` (cada 5), etc. No hay que
> tocar nada en el código al cambiarlo. Opcional: `gmail:poll --max=100` para subir
> el tope de correos por corrida (default 50).

### Alternativa: vía scheduler (`schedule:run`)

Si prefieres dejar la frecuencia en `routes/console.php` (`everyTwoMinutes()`), el
cron debe correr **cada minuto** para que el scheduler la evalúe:

```
* * * * * cd /home/uXXXXXXXX/domains/TU-DOMINIO/public_html && /usr/bin/php8.2 artisan schedule:run >> /dev/null 2>&1
```

Requiere que el plan permita el intervalo `* * * * *`. El comando `gmail:poll` evita
esa restricción, por eso es el recomendado.

---

---

## Los borradores de IA TAMBIÉN dependen de la cola

Desde el 14-sep-2026 la generación de borradores **ya no es síncrona**, ni la de la
ficha del proceso (`AiGenerationController::store`) ni la respuesta a un correo
(`ProcessEmailController::draft`). Las dos crean la fila en `pendiente`, despachan
`GenerateAiDraft` y devuelven `202` con el id de la fila, y la pantalla sondea `GET /admin/processes/{p}/ai/generations/{id}` hasta que el
estado deja de ser `pendiente`.

**Sin worker, el botón se queda girando para siempre.** Antes al menos daba un error;
ahora no da ninguno, así que si alguien reporta «se queda generando y nunca termina»,
lo primero que hay que mirar es si el cron del worker está vivo:

```
SELECT count(*) FROM jobs;   -- si crece y no baja, no hay worker
```

### Por qué se hizo asíncrono

Las cuatro plantillas `draft_*` piden un escrito jurídico completo, así que el modelo
**agota siempre los 4.096 tokens de salida**. Medido contra producción el 14-sep-2026
sobre el proceso `PL-INBOX-20260827-BG0U`:

```
prompt 39.237 caracteres | 82,1 s | tokens_out=4.096 | stop_reason=max_tokens
```

Son ~80 segundos fijos, independientes del tamaño del expediente (el cuello es la
salida, no la entrada). El gateway de Hostinger cortaba muchísimo antes con un **504
seco**, y además mataba el proceso PHP: el `catch` del controller no llegaba a correr,
así que el intento **no dejaba ni rastro en `ai_generations`** — se pagaba la llamada
y no quedaba registro. El `set_time_limit(180)` del controller no servía de nada: sube
el límite de PHP, no el del proxy.

### Requisitos del worker

- **`QUEUE_CONNECTION=database`** en el `.env` (ya está en producción).
- **El cron del worker** de la Opción B de más abajo. Un solo worker basta: el mismo
  que procesa `ProcessInboundEmail` procesa los borradores.
- **`DB_QUEUE_RETRY_AFTER` debe ser mayor que el `$timeout` del job** (300 s). El
  default de Laravel eran 90 s, y con un job de 82 s cualquier pico hacía que el worker
  lo diera por colgado y lo relanzara — **pagando la misma llamada dos veces**. Por eso
  `config/queue.php` ahora trae 300.
- El `--timeout` del worker NO hay que tocarlo: `GenerateAiDraft::$timeout = 300` manda
  sobre la opción del worker. Lo mismo con `--tries`: el job fuerza `$tries = 1` porque
  un reintento automático vuelve a pagar la generación entera.

### En local

Con `QUEUE_CONNECTION=sync` (el default del `.env` de desarrollo) el `dispatch` corre
inline: la ruta tarda los ~80 s en responder el `202` y luego el sondeo encuentra el
borrador ya hecho. Funciona, pero no reproduce el comportamiento real. Para probarlo de
verdad, `QUEUE_CONNECTION=database` y un `php artisan queue:work` a mano.

---

## Opción B — Cola `database` (desacoplada, más resiliente)

Mejor si la clasificación con IA llega a tardar (reintentos 429/529) y no quieres
que el sondeo se trabe. El scheduler solo encola; un worker efímero procesa la cola.

### 1. `.env`

```env
APP_ENV=production
APP_DEBUG=false
QUEUE_CONNECTION=database
```

(La tabla `jobs` ya existe por las migraciones.)

### 2. Dos crons

```
# Scheduler: encola PollGmailInbox cada 2 min
* * * * * cd /home/uXXXXXXXX/domains/TU-DOMINIO/public_html && /usr/bin/php artisan schedule:run >> /dev/null 2>&1

# Worker efímero: procesa la cola y sale (no es demonio)
* * * * * cd /home/uXXXXXXXX/domains/TU-DOMINIO/public_html && /usr/bin/php artisan queue:work --stop-when-empty --max-time=55 >> storage/logs/worker.log 2>&1
```

---

## Cómo obtener los valores reales

- **Ruta del proyecto:** en hPanel → Administrador de archivos. Suele ser
  `/home/uXXXXXXXX/domains/tudominio.com/public_html`. Si Laravel está en una
  subcarpeta, apunta ahí (donde está `artisan`).
- **Binario de PHP:** por SSH `which php`, o usa la ruta del alt-php de tu versión,
  p. ej. `/opt/alt/php82/usr/bin/php`. Asegúrate de que sea PHP 8.2+.
- **`APP_URL`** debe ser el dominio real (https). De ahí sale el
  `GMAIL_REDIRECT_URI` (`${APP_URL}/admin/integrations/gmail/callback`), que debe
  coincidir EXACTO con el configurado en Google Cloud Console.

## Verificación

1. Conecta la cuenta de Gmail en la app: **Integraciones → Gmail** (rol director).
2. Ejecuta el sondeo a mano una vez por SSH para validar:
   ```
   php8.2 artisan gmail:poll
   ```
3. Revisa que aparezcan filas en `email_ingestions` y, lo no enrutado, en la
   bandeja **Revisión de correos** (sidebar, director/coordinador).
4. Logs del scheduler/worker: `storage/logs/laravel.log`.

## Notas

- **Dedup:** garantizada por `message_id` único + `firstOrCreate` + `wasRecentlyCreated`.
  No depende de marcar leídos; marcar leídos solo evita re-listar y drena el buzón.
- **Tope por sondeo:** `PollGmailInbox` trae hasta 50 (`maxResults`). Con el marcado
  como leído ya activo, el buzón se drena y no se acumulan no leídos.
- **Timing:** `everyTwoMinutes()` calza con un cron por minuto. Si tu plan no permite
  `* * * * *`, cambia en `routes/console.php` a la frecuencia que sí permita el cron
  (p. ej. `everyFiveMinutes()` con un cron `*/5 * * * *`) para que coincidan.
- **Reintento de fallidos:** un correo en `failed` no se reintenta solo (la fila ya
  existe). Si hace falta, se puede reprocesar manualmente despachando
  `ProcessInboundEmail` con su id.
