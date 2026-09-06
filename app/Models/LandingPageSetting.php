<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandingPageSetting extends BaseModel
{
    protected $fillable = ['content', 'is_published', 'updated_by'];

    protected $casts = [
        'content' => 'array',
        'is_published' => 'boolean',
    ];

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public static function defaults(): array
    {
        return [
            'general' => [
                'brand_name' => 'EcuaPOS',
                'announcement' => 'Prueba EcuaPOS gratis durante 14 días',
                'eyebrow' => 'EL POS CREADO PARA NEGOCIOS ECUATORIANOS',
                'hero_title' => 'Vende, factura y controla tu negocio desde un solo lugar.',
                'hero_description' => 'Punto de venta, inventario, caja y facturación electrónica en una plataforma rápida, clara y preparada para crecer contigo.',
                'hero_primary_label' => 'Comenzar prueba gratis',
                'hero_secondary_label' => 'Conocer funcionalidades',
                'hero_image' => '',
                'services_title' => 'Todo lo que necesitas para operar mejor',
                'services_description' => 'Herramientas conectadas para vender con agilidad y tomar decisiones con información real.',
                'benefits_title' => 'Menos tareas repetitivas. Más control.',
                'steps_title' => 'Empieza a vender en pocos pasos',
                'testimonials_title' => 'Negocios que ya trabajan con EcuaPOS',
                'faq_title' => 'Preguntas frecuentes',
                'plans_title' => 'Un plan para cada etapa de tu negocio',
                'contact_title' => '¿Listo para modernizar tu negocio?',
                'contact_description' => 'Comienza tu prueba gratis o conversa con nosotros para elegir el plan adecuado.',
                'contact_email' => 'support@ecua-pos.com',
                'whatsapp' => '593999999999',
                'location' => 'Manabí, Ecuador',
                'meta_title' => 'EcuaPOS | Punto de venta y facturación electrónica',
                'meta_description' => 'POS, inventario, caja, compras, reportes y facturación electrónica para negocios en Ecuador.',
            ],
            'services' => [
                ['id' => 'pos', 'title' => 'Ventas ágiles', 'description' => 'Cobra rápidamente, administra presentaciones y mantén la operación incluso ante una conexión inestable.', 'icon' => 'cart', 'is_active' => true],
                ['id' => 'inventory', 'title' => 'Inventario real', 'description' => 'Controla existencias por almacén, movimientos, conteos y alertas de stock desde una sola vista.', 'icon' => 'boxes', 'is_active' => true],
                ['id' => 'invoice', 'title' => 'Facturación electrónica', 'description' => 'Emite documentos electrónicos y gestiona secuenciales de forma integrada con tu flujo de venta.', 'icon' => 'invoice', 'is_active' => true],
                ['id' => 'cash', 'title' => 'Control de caja', 'description' => 'Supervisa aperturas, movimientos, cierres y diferencias con trazabilidad por cajero.', 'icon' => 'wallet', 'is_active' => true],
                ['id' => 'reports', 'title' => 'Reportes claros', 'description' => 'Conoce ventas, rentabilidad, cartera e inventario para decidir con confianza.', 'icon' => 'chart', 'is_active' => true],
                ['id' => 'catalog', 'title' => 'Catálogo virtual', 'description' => 'Muestra tus productos, recibe pedidos y mantén informados a tus clientes.', 'icon' => 'store', 'is_active' => true],
            ],
            'benefits' => [
                ['id' => 'one', 'title' => 'Diseñado para Ecuador', 'description' => 'Flujos adaptados a comercios locales y facturación electrónica.', 'icon' => 'ecuador', 'is_active' => true],
                ['id' => 'two', 'title' => 'Información centralizada', 'description' => 'Ventas, compras, caja e inventario comparten la misma información.', 'icon' => 'sync', 'is_active' => true],
                ['id' => 'three', 'title' => 'Crece sin perder control', 'description' => 'Administra usuarios, tiendas y almacenes según el plan contratado.', 'icon' => 'growth', 'is_active' => true],
                ['id' => 'four', 'title' => 'Soporte cercano', 'description' => 'Acompañamiento para configurar y aprovechar mejor EcuaPOS.', 'icon' => 'support', 'is_active' => true],
            ],
            'steps' => [
                ['id' => 'step-1', 'number' => '01', 'title' => 'Crea tu cuenta', 'description' => 'Registra tu negocio y activa tu prueba gratuita.', 'is_active' => true],
                ['id' => 'step-2', 'number' => '02', 'title' => 'Configura tu operación', 'description' => 'Agrega productos, almacenes, impuestos y datos de facturación.', 'is_active' => true],
                ['id' => 'step-3', 'number' => '03', 'title' => 'Empieza a vender', 'description' => 'Abre caja y administra tu negocio con información en tiempo real.', 'is_active' => true],
            ],
            'testimonials' => [],
            'faqs' => [
                ['id' => 'faq-1', 'question' => '¿Necesito instalar algún programa?', 'answer' => 'No. EcuaPOS funciona desde el navegador y también puede instalarse como aplicación web en dispositivos compatibles.', 'is_active' => true],
                ['id' => 'faq-2', 'question' => '¿Puedo probarlo antes de contratar?', 'answer' => 'Sí. La prueba gratuita dura 14 días e incluye acceso a los módulos principales con límites definidos.', 'is_active' => true],
                ['id' => 'faq-3', 'question' => '¿Mis datos permanecen seguros si vence el plan?', 'answer' => 'Sí. La información no se elimina; el acceso se restablece al renovar la suscripción.', 'is_active' => true],
                ['id' => 'faq-4', 'question' => '¿Puedo usar más de una tienda o almacén?', 'answer' => 'Sí. La capacidad depende del plan seleccionado y puede ampliarse cuando tu negocio lo necesite.', 'is_active' => true],
            ],
            'partners' => [],
            'legal' => [
                'terms' => "Términos y condiciones\n\nEl uso de EcuaPOS está sujeto al plan contratado, sus límites y las condiciones comerciales aceptadas por la organización.",
                'privacy' => "Política de privacidad\n\nEcuaPOS utiliza la información necesaria para prestar el servicio y mantiene controles para proteger los datos de cada organización.",
                'refunds' => "Política de reembolsos\n\nLas solicitudes de revisión de pagos deberán enviarse a soporte con el comprobante y los datos de la organización.",
            ],
        ];
    }

    public static function mergeWithDefaults(?array $saved): array
    {
        $content = self::defaults();
        foreach ($saved ?? [] as $section => $value) {
            if (in_array($section, ['general', 'legal'], true) && is_array($value)) {
                $content[$section] = array_replace($content[$section], $value);
            } elseif (array_key_exists($section, $content)) {
                // Las colecciones se sustituyen completas para que eliminar
                // un elemento desde el CMS no lo reviva desde los defaults.
                $content[$section] = $value;
            }
        }

        return $content;
    }
}
