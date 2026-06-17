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
