@php
    $g = $content['general'];
    $active = fn ($items) => collect($items)->where('is_active', true);
    $loginUrl = route('app').'#/login';
    $registerUrl = route('app').'#/crear-cuenta';
    $dashboardUrl = route('app').'#/app/dashboard';
@endphp
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $g['meta_title'] }}</title>
    <meta name="description" content="{{ $g['meta_description'] }}">
    <meta name="theme-color" content="#2166f3">
    <link rel="icon" type="image/png" href="/images/pwa/ecuapos-favicon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/landing.css') }}">
    <script>try{if(localStorage.getItem('auth_token'))document.documentElement.classList.add('lp-has-session')}catch(e){}</script>
</head>
<body data-app-url="{{ route('app') }}" data-register-url="{{ $registerUrl }}">
    <div class="lp-announcement"><span></span>{{ $g['announcement'] }} <a href="{{ $registerUrl }}" data-auth-cta data-auth-label="Ir al dashboard →">Comenzar ahora →</a></div>
    <header class="lp-header" id="inicio">
        <nav class="lp-nav lp-container">
            <a class="lp-brand" href="#inicio"><img src="/images/ecua-pos-logo.png" alt="EcuaPOS"><strong>{{ $g['brand_name'] }}</strong></a>
            <button class="lp-menu" type="button" aria-label="Abrir menú" aria-expanded="false"><i></i><i></i><i></i></button>
            <div class="lp-nav-links">
                <a href="#funcionalidades">Funcionalidades</a><a href="#beneficios">Beneficios</a><a href="#planes">Planes</a><a href="#preguntas">Preguntas</a>
            </div>
            <div class="lp-nav-actions"><a class="lp-link" href="{{ $loginUrl }}" data-auth-guest>Iniciar sesión</a><a class="lp-btn lp-btn--small" href="{{ $registerUrl }}" data-auth-guest>Prueba gratis</a><a class="lp-btn lp-btn--small" href="{{ $dashboardUrl }}" data-auth-user>Ir al dashboard →</a></div>
        </nav>
    </header>

    <main>
        <section class="lp-hero lp-container">
            <div class="lp-hero-copy lp-reveal">
                <span class="lp-kicker"><i></i>{{ $g['eyebrow'] }}</span>
                <h1>{{ $g['hero_title'] }}</h1>
                <p>{{ $g['hero_description'] }}</p>
                <div class="lp-hero-actions"><a class="lp-btn" href="{{ $registerUrl }}" data-auth-cta data-auth-label="Ir al dashboard →">{{ $g['hero_primary_label'] }} <b>→</b></a><a class="lp-btn lp-btn--ghost" href="#funcionalidades">{{ $g['hero_secondary_label'] }}</a></div>
                <div class="lp-trust"><span>✓ Sin tarjeta de crédito</span><span>✓ Configuración guiada</span><span>✓ Soporte local</span></div>
            </div>
            <div class="lp-hero-visual lp-reveal">
                @if($g['hero_image'])
                    <img class="lp-hero-upload" src="{{ $g['hero_image'] }}" alt="Vista de EcuaPOS">
                @else
                    <div class="lp-product-shot">
                        <div class="lp-shot-sidebar"><img src="/images/ecua-pos-logo.png" alt=""><i></i><i></i><i></i><i></i><i></i></div>
                        <div class="lp-shot-main"><div class="lp-shot-top"><span></span><span></span></div><small>VENTAS DE HOY</small><strong>$ 2.470,80</strong><div class="lp-shot-chart"><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div><div class="lp-shot-cards"><span></span><span></span><span></span></div></div>
                    </div>
                @endif
                <div class="lp-float-card lp-float-card--sales"><b>↗ 18,4%</b><span>Ventas este mes</span></div>
                <div class="lp-float-card lp-float-card--online"><i></i><b>Operación en línea</b></div>
            </div>
        </section>

        <section class="lp-proof"><div class="lp-container lp-proof-grid"><div><strong>+99,9%</strong><span>Disponibilidad</span></div><div><strong>14 días</strong><span>Prueba gratuita</span></div><div><strong>Todo en uno</strong><span>Una sola plataforma</span></div><div><strong>Ecuador</strong><span>Soporte cercano</span></div></div></section>

        @if($active($content['partners'])->isNotEmpty())
        <section class="lp-partners lp-container"><span>NEGOCIOS QUE CONFÍAN EN ECUAPOS</span><div>@foreach($active($content['partners']) as $partner)<figure>@if($partner['image'] ?? null)<img src="{{ $partner['image'] }}" alt="{{ $partner['name'] }}">@else<strong>{{ $partner['name'] }}</strong>@endif</figure>@endforeach</div></section>
        @endif

        <section class="lp-section lp-container" id="funcionalidades">
            <div class="lp-section-head lp-reveal"><span class="lp-kicker">FUNCIONALIDADES CONECTADAS</span><h2>{{ $g['services_title'] }}</h2><p>{{ $g['services_description'] }}</p></div>
            <div class="lp-feature-grid">
                @foreach($active($content['services']) as $index => $service)
                <article class="lp-feature lp-reveal"><span class="lp-feature-icon">{{ ['⌁','▦','▤','◇','↗','◫'][$index % 6] }}</span><div><h3>{{ $service['title'] }}</h3><p>{{ $service['description'] }}</p></div><a href="{{ $registerUrl }}" aria-label="Probar {{ $service['title'] }}" data-auth-cta>→</a></article>
                @endforeach
            </div>
        </section>

        <section class="lp-section lp-section--tint" id="beneficios"><div class="lp-container lp-benefit-layout">
            <div class="lp-benefit-copy lp-reveal"><span class="lp-kicker">HECHO PARA TRABAJAR CONTIGO</span><h2>{{ $g['benefits_title'] }}</h2><p>EcuaPOS convierte la operación diaria en información útil, sin agregar complejidad a tu equipo.</p><a class="lp-text-link" href="{{ $registerUrl }}" data-auth-cta data-auth-label="Ir al dashboard →">Probar EcuaPOS gratis →</a></div>
            <div class="lp-benefit-grid">@foreach($active($content['benefits']) as $index => $benefit)<article class="lp-reveal"><span>0{{ $index + 1 }}</span><h3>{{ $benefit['title'] }}</h3><p>{{ $benefit['description'] }}</p></article>@endforeach</div>
        </div></section>

        <section class="lp-section lp-container" id="pasos">
            <div class="lp-section-head lp-reveal"><span class="lp-kicker">IMPLEMENTACIÓN SIMPLE</span><h2>{{ $g['steps_title'] }}</h2></div>
            <div class="lp-steps">@foreach($active($content['steps']) as $step)<article class="lp-reveal"><b>{{ $step['number'] }}</b><span></span><h3>{{ $step['title'] }}</h3><p>{{ $step['description'] }}</p></article>@endforeach</div>
        </section>

        <section class="lp-section lp-section--dark" id="planes"><div class="lp-container">
            <div class="lp-section-head lp-section-head--light lp-reveal"><span class="lp-kicker">PLANES TRANSPARENTES</span><h2>{{ $g['plans_title'] }}</h2><p>Empieza sin riesgo y cambia de plan a medida que crece tu operación.</p></div>
            <div class="lp-plans">@forelse($plans as $index => $plan)<article class="lp-plan lp-reveal {{ $index === 1 ? 'featured' : '' }}">@if($index === 1)<span class="lp-plan-badge">MÁS COMPLETO</span>@endif<h3>{{ $plan->name }}</h3><p>{{ $plan->description }}</p><div><strong>${{ number_format((float)$plan->price, 2, ',', '.') }}</strong><span>/ {{ $plan->billing_interval === 'yearly' ? 'año' : ($plan->billing_interval === 'weekly' ? 'semana' : 'mes') }}</span></div><ul><li>✓ {{ $plan->max_users ?: 'Usuarios ilimitados' }}{{ $plan->max_users ? ' usuarios' : '' }}</li><li>✓ {{ $plan->max_stores ?: 'Tiendas ilimitadas' }}{{ $plan->max_stores ? ' tiendas' : '' }}</li><li>✓ {{ $plan->max_warehouses ?: 'Almacenes ilimitados' }}{{ $plan->max_warehouses ? ' almacenes' : '' }}</li><li>✓ {{ $plan->max_electronic_documents ?: 'Documentos ilimitados' }}{{ $plan->max_electronic_documents ? ' documentos electrónicos' : '' }}</li></ul><a class="lp-btn {{ $index !== 1 ? 'lp-btn--ghost-light' : '' }}" href="{{ $registerUrl }}">Elegir {{ $plan->name }}</a></article>@empty<article class="lp-plan"><h3>Plan EcuaPOS</h3><p>Contáctanos para conocer las opciones disponibles.</p><a class="lp-btn" href="mailto:{{ $g['contact_email'] }}">Solicitar información</a></article>@endforelse</div>
        </div></section>

        @if($active($content['testimonials'])->isNotEmpty())
        <section class="lp-section lp-container"><div class="lp-section-head lp-reveal"><span class="lp-kicker">EXPERIENCIAS REALES</span><h2>{{ $g['testimonials_title'] }}</h2></div><div class="lp-testimonials">@foreach($active($content['testimonials']) as $testimonial)<article class="lp-reveal"><div class="lp-stars">★★★★★</div><blockquote>“{{ $testimonial['quote'] }}”</blockquote><footer>@if($testimonial['avatar'] ?? null)<img src="{{ $testimonial['avatar'] }}" alt="">@else<span>{{ mb_substr($testimonial['name'], 0, 1) }}</span>@endif<div><strong>{{ $testimonial['name'] }}</strong><small>{{ $testimonial['role'] ?? '' }}</small></div></footer></article>@endforeach</div></section>
        @endif

        <section class="lp-section lp-section--tint" id="preguntas"><div class="lp-container lp-faq-layout"><div class="lp-reveal"><span class="lp-kicker">RESOLVEMOS TUS DUDAS</span><h2>{{ $g['faq_title'] }}</h2><p>Si necesitas una respuesta más específica, nuestro equipo está listo para ayudarte.</p><a class="lp-text-link" href="mailto:{{ $g['contact_email'] }}">Hablar con soporte →</a></div><div class="lp-faqs">@foreach($active($content['faqs']) as $index => $faq)<details class="lp-reveal" {{ $index === 0 ? 'open' : '' }}><summary>{{ $faq['question'] }}<span>+</span></summary><p>{{ $faq['answer'] }}</p></details>@endforeach</div></div></section>

        <section class="lp-final lp-container lp-reveal"><div><span class="lp-kicker">TU NEGOCIO, MEJOR ORGANIZADO</span><h2>{{ $g['contact_title'] }}</h2><p>{{ $g['contact_description'] }}</p></div><div><a class="lp-btn lp-btn--white" href="{{ $registerUrl }}" data-auth-cta data-auth-label="Ir al dashboard →">Comenzar prueba gratis →</a><a href="https://wa.me/{{ preg_replace('/\D/', '', $g['whatsapp']) }}" target="_blank" rel="noopener">Conversar por WhatsApp</a></div></section>
    </main>

    <footer class="lp-footer"><div class="lp-container"><div class="lp-footer-brand"><a class="lp-brand" href="#inicio"><img src="/images/ecua-pos-logo.png" alt=""><strong>{{ $g['brand_name'] }}</strong></a><p>La plataforma que conecta ventas, inventario, caja y facturación electrónica.</p></div><div><strong>Producto</strong><a href="#funcionalidades">Funcionalidades</a><a href="#planes">Planes</a><a href="{{ $loginUrl }}" data-auth-guest>Iniciar sesión</a><a href="{{ $dashboardUrl }}" data-auth-user>Ir al dashboard</a></div><div><strong>Contacto</strong><a href="mailto:{{ $g['contact_email'] }}">{{ $g['contact_email'] }}</a><span>{{ $g['location'] }}</span></div><div><strong>Legal</strong><button data-legal="terms">Términos y condiciones</button><button data-legal="privacy">Privacidad</button><button data-legal="refunds">Reembolsos</button></div></div><div class="lp-container lp-copyright"><span>© {{ date('Y') }} EcuaPOS. Todos los derechos reservados.</span><span>Hecho en Ecuador 🇪🇨</span></div></footer>

    @foreach(['terms' => 'Términos y condiciones', 'privacy' => 'Política de privacidad', 'refunds' => 'Política de reembolsos'] as $key => $label)
    <dialog class="lp-legal" data-legal-dialog="{{ $key }}"><button class="lp-legal-close" aria-label="Cerrar">×</button><span class="lp-kicker">INFORMACIÓN LEGAL</span><h2>{{ $label }}</h2><div>{!! nl2br(e($content['legal'][$key])) !!}</div></dialog>
    @endforeach
    <script src="{{ asset('js/landing.js') }}" defer></script>
</body>
</html>
