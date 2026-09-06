document.addEventListener('DOMContentLoaded', function () {
    var sessionKeys = ['auth_token', 'user', 'first_name', 'last_name', 'image', 'user_image_url', 'get_permissions', 'current_store_id', 'current_organization_id', 'role_name', 'is_super_admin', 'loginUserArray'];
    var authToken = null;
    try { authToken = localStorage.getItem('auth_token'); } catch (_) {}

    function dashboardUrl() {
        var base = document.body.dataset.appUrl || '/sistema';
        var superAdmin = false;
        try { superAdmin = localStorage.getItem('is_super_admin') === 'true'; } catch (_) {}
        return base + (superAdmin ? '#/app/super-admin/dashboard' : '#/app/dashboard');
    }

    function authLinks() {
        var registerUrl = document.body.dataset.registerUrl;
        var selector = '[data-auth-cta]' + (registerUrl ? ',a[href="' + registerUrl + '"]' : '');
        return document.querySelectorAll(selector);
    }

    function renderSessionState(authenticated) {
        document.documentElement.classList.toggle('lp-has-session', authenticated);
        authLinks().forEach(function (link) {
            if (!link.dataset.guestHref) link.dataset.guestHref = link.getAttribute('href');
            if (!link.dataset.guestHtml) link.dataset.guestHtml = link.innerHTML;
            link.setAttribute('href', authenticated ? dashboardUrl() : link.dataset.guestHref);
            var signedInLabel = link.dataset.authLabel || (link.closest('.lp-plan') ? 'Ir al dashboard' : null);
            if (signedInLabel) link.innerHTML = authenticated ? signedInLabel : link.dataset.guestHtml;
        });
        document.querySelectorAll('[data-auth-user]').forEach(function (link) {
            link.setAttribute('href', dashboardUrl());
        });
    }

    function clearInvalidSession() {
        try { sessionKeys.forEach(function (key) { localStorage.removeItem(key); }); } catch (_) {}
    }

    renderSessionState(Boolean(authToken));
    if (authToken) {
        fetch('/api/validate-auth-token', {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'Authorization': 'Bearer ' + authToken, 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {
            if ([401, 403, 419].includes(response.status)) return { success: false };
            return response.ok ? response.json() : Promise.reject();
        })
            .then(function (payload) {
                var valid = payload && payload.success === true;
                if (!valid) clearInvalidSession();
                renderSessionState(valid);
            })
            .catch(function () {
                // Ante una caída de red conservamos la sesión visible. Al entrar,
                // el POS también valida el token y su concesión de trabajo offline.
                renderSessionState(true);
            });
    }

    var menu = document.querySelector('.lp-menu');
    var header = document.querySelector('.lp-header');
    if (menu) menu.addEventListener('click', function () {
        var open = header.classList.toggle('menu-open');
        menu.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    document.querySelectorAll('.lp-nav-links a').forEach(function (link) {
        link.addEventListener('click', function () { header.classList.remove('menu-open'); });
    });
    document.querySelectorAll('[data-legal]').forEach(function (button) {
        button.addEventListener('click', function () {
            var dialog = document.querySelector('[data-legal-dialog="' + button.dataset.legal + '"]');
            if (dialog) dialog.showModal();
        });
    });
    document.querySelectorAll('.lp-legal').forEach(function (dialog) {
        dialog.querySelector('.lp-legal-close').addEventListener('click', function () { dialog.close(); });
        dialog.addEventListener('click', function (event) { if (event.target === dialog) dialog.close(); });
    });
    if ('IntersectionObserver' in window) {
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) { if (entry.isIntersecting) { entry.target.classList.add('visible'); observer.unobserve(entry.target); } });
        }, { threshold: .08 });
        document.querySelectorAll('.lp-reveal').forEach(function (element) { observer.observe(element); });
    } else document.querySelectorAll('.lp-reveal').forEach(function (element) { element.classList.add('visible'); });
});
