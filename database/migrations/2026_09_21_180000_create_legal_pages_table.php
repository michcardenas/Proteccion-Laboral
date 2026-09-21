<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Las páginas legales del despacho, editables sin desplegar.
 *
 * La plataforma no tenía ninguna: ni política de privacidad, ni términos, ni
 * aviso de cookies. Tres motivos para que existan, por orden de urgencia:
 *
 * 1. GOOGLE. La app pide `gmail.readonly`, `gmail.modify` y `drive.readonly`,
 *    que son scopes RESTRINGIDOS. Para sacar el cliente OAuth del modo
 *    «Testing» —donde el refresh token caduca cada 7 días y la ingesta se
 *    muere sola— hay que pasar verificación, y la verificación exige una URL
 *    pública de política de privacidad en el mismo dominio.
 * 2. LEY 1581 DE 2012 y Decreto 1377 de 2013. El despacho es responsable del
 *    tratamiento y aquí vive lo más delicado que maneja: trabajadores
 *    identificados, pleitos laborales e incapacidades médicas, que son datos
 *    sensibles.
 * 3. El contenido de los correos y los documentos sale hacia terceros
 *    (Anthropic para clasificar y redactar, Google para leer el buzón). Eso se
 *    cuenta, no se esconde.
 *
 * El texto vive en la BASE y no en un Blade a propósito: lo tiene que escribir
 * Carlos, que es el abogado, y cada matiz suyo no puede costar un despliegue.
 *
 * Nacen SIN PUBLICAR y con marcadores `[[COMPLETAR: ...]]` bien visibles. Una
 * página sin publicar sale con un cartel de borrador y en `noindex`: es
 * preferible que se vea a medio hacer a que parezca un compromiso legal que
 * nadie ha revisado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('titulo');
            $table->string('resumen')->nullable();
            $table->longText('contenido');

            // Sin publicar = borrador a la vista de todos, con su cartel.
            $table->boolean('publicado')->default(false);
            $table->date('vigente_desde')->nullable();

            // Quién tocó el texto por última vez: en un documento legal importa
            // tanto como la fecha.
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });

        $ahora = now();

        DB::table('legal_pages')->insert([
            [
                'slug' => 'politica-de-privacidad',
                'titulo' => 'Política de tratamiento de datos personales',
                'resumen' => 'Cómo recogemos, usamos y protegemos la información de nuestros clientes y de las personas cuyos casos gestionamos.',
                'contenido' => $this->borradorPrivacidad(),
                'publicado' => false,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ],
            [
                'slug' => 'terminos-y-condiciones',
                'titulo' => 'Términos y condiciones de uso',
                'resumen' => 'Condiciones de acceso y uso de la plataforma.',
                'contenido' => $this->borradorTerminos(),
                'publicado' => false,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_pages');
    }

    /**
     * Esqueleto de la política, con la estructura que pide la Ley 1581 de 2012.
     *
     * NO es texto jurídico terminado ni pretende serlo: los apartados están
     * para que el despacho los complete o los reescriba enteros. Lo que sí
     * intenta es no dejarse fuera nada de lo que la plataforma hace de verdad
     * —incluido el paso de correos y documentos por un tercero de IA—, porque
     * eso solo lo sabe quien conoce el código.
     */
    private function borradorPrivacidad(): string
    {
        return <<<'HTML'
<h2>1. Responsable del tratamiento</h2>
<p>[[COMPLETAR: razón social]], identificada con NIT [[COMPLETAR: NIT]] y domicilio en [[COMPLETAR: dirección]], [[COMPLETAR: ciudad]] (Colombia), es responsable del tratamiento de los datos personales descritos en esta política.</p>
<p>Canal de atención al titular: [[COMPLETAR: correo de contacto]] · [[COMPLETAR: teléfono]].</p>

<h2>2. Qué datos tratamos</h2>
<ul>
  <li><strong>Datos de identificación y contacto</strong> de nuestros clientes y de las personas vinculadas a cada proceso: nombre, documento de identidad, cargo, teléfono, correo electrónico y dirección.</li>
  <li><strong>Datos laborales y del proceso</strong>: contratos, salarios, liquidaciones, comunicaciones con la contraparte, actuaciones judiciales y administrativas, y los documentos que las soportan.</li>
  <li><strong>Datos sensibles</strong>: información de salud, incapacidades médicas y calificaciones de origen laboral, cuando son necesarias para el caso. Su tratamiento es facultativo para el titular y exige autorización expresa.</li>
  <li><strong>Correo electrónico</strong> recibido en las cuentas del despacho destinadas a la gestión de casos, junto con sus archivos adjuntos.</li>
</ul>

<h2>3. Para qué los usamos</h2>
<ul>
  <li>Prestar los servicios jurídicos contratados y gestionar cada proceso hasta su cierre.</li>
  <li>Clasificar el correo entrante, asociarlo al proceso que corresponde y conservar sus documentos en el expediente.</li>
  <li>Elaborar borradores de escritos y comunicaciones con apoyo de herramientas de inteligencia artificial, que siempre revisa un abogado antes de usarse.</li>
  <li>Cumplir las obligaciones legales, contables y contractuales del despacho.</li>
</ul>

<h2>4. Con quién se comparten</h2>
<p>No vendemos ni cedemos datos personales con fines comerciales. Para operar la plataforma intervienen los siguientes encargados del tratamiento, obligados a la misma confidencialidad:</p>
<ul>
  <li><strong>Google LLC</strong> — acceso al buzón de correo del despacho y a los documentos de su unidad compartida, únicamente para la ingesta de casos.</li>
  <li><strong>Anthropic PBC</strong> — procesamiento del contenido de correos y documentos para clasificarlos y redactar borradores. [[COMPLETAR: confirmar con el proveedor la política de retención aplicable]].</li>
  <li><strong>[[COMPLETAR: proveedor de alojamiento]]</strong> — servidores donde residen la aplicación y los archivos.</li>
</ul>
<p>Además, se comparten con autoridades judiciales y administrativas cuando el ejercicio de la representación o la ley lo exigen.</p>

<h2>5. Cuánto tiempo los conservamos</h2>
<p>[[COMPLETAR: plazo de conservación del expediente tras el cierre del caso y su fundamento —deber profesional, términos de prescripción, obligaciones contables—.]]</p>

<h2>6. Derechos del titular</h2>
<p>Conforme al artículo 8 de la Ley 1581 de 2012, usted puede en cualquier momento:</p>
<ul>
  <li>Conocer, actualizar y rectificar sus datos personales.</li>
  <li>Solicitar prueba de la autorización otorgada, salvo cuando la ley no la exija.</li>
  <li>Ser informado sobre el uso que se ha dado a sus datos.</li>
  <li>Presentar quejas ante la Superintendencia de Industria y Comercio.</li>
  <li>Revocar la autorización y solicitar la supresión de sus datos, cuando no lo impida un deber legal o contractual.</li>
  <li>Acceder de forma gratuita a sus datos personales.</li>
</ul>
<p>Las consultas se atienden en un plazo máximo de diez (10) días hábiles y los reclamos en quince (15) días hábiles, prorrogables en los términos de la ley.</p>

<h2>7. Seguridad</h2>
<p>El acceso a la plataforma es nominal y por roles, de modo que cada persona ve únicamente lo que su función exige. [[COMPLETAR: describir las demás medidas técnicas y administrativas adoptadas —copias de seguridad, cifrado, registro de actividad—.]]</p>

<h2>8. Vigencia y cambios</h2>
<p>Esta política rige desde [[COMPLETAR: fecha de entrada en vigencia]]. Cualquier cambio sustancial se comunicará por los canales habituales de atención.</p>
HTML;
    }

    private function borradorTerminos(): string
    {
        return <<<'HTML'
<h2>1. Objeto</h2>
<p>Esta plataforma es la herramienta interna de [[COMPLETAR: razón social]] para la gestión de procesos laborales, y el canal por el que sus clientes consultan el estado de sus casos.</p>

<h2>2. Quién puede usarla</h2>
<p>El acceso es nominal y se otorga a los integrantes del despacho según su rol, y a los clientes respecto de sus propios procesos. Las credenciales son personales e intransferibles.</p>

<h2>3. Uso aceptable</h2>
<p>La información contenida en la plataforma está amparada por el secreto profesional. Quien accede se obliga a no divulgarla ni utilizarla para fines distintos de la prestación o el seguimiento del servicio.</p>

<h2>4. Contenidos generados con inteligencia artificial</h2>
<p>La plataforma redacta borradores con apoyo de inteligencia artificial. Son eso: borradores. Ningún documento producido por esa vía tiene valor jurídico mientras no lo revise y lo apruebe un abogado del despacho.</p>

<h2>5. Disponibilidad</h2>
<p>[[COMPLETAR: compromisos de disponibilidad y ventanas de mantenimiento, si se quieren asumir.]]</p>

<h2>6. Tratamiento de datos</h2>
<p>El tratamiento de los datos personales se rige por la <a href="/politica-de-privacidad">política de tratamiento de datos personales</a>.</p>

<h2>7. Contacto</h2>
<p>[[COMPLETAR: correo y teléfono de contacto del despacho.]]</p>
HTML;
    }
};
