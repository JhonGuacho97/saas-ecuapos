/* ── Estilos ── */
export const headerStyles = `
  @import url('https://fonts.googleapis.com/css2?family=Nunito:wght@600;700;800&family=Poppins:wght@400;500;600&display=swap');

  /* ── Navbar ── */
  .hdr-navbar {
    background: #ffffff !important;
    border-bottom: 1px solid #f0f0f8 !important;
    box-shadow: 0 2px 16px rgba(47, 111, 237,0.07) !important;
    padding: 0 24px !important;
    min-height: 64px;
    position: sticky;
    top: 0;
    z-index: 100;
    font-family: 'Poppins', sans-serif;
  }

  /* En mobile: los 24px de padding a cada lado + los 6-12px de margin
     entre cada píldora se comen buena parte de un viewport angosto --
     sumado a que este <Navbar> (react-bootstrap) nunca tuvo
     Navbar.Toggle/Navbar.Collapse (por eso expand='lg' no hace nada
     acá), sus hijos solo fluyen con flexbox y envuelven en cuanto no
     entran. Se fuerza una sola fila (nowrap) y se recorta el padding y
     los márgenes entre píldoras -- ya vienen reducidas a solo ícono
     acá abajo, así que con esto alcanza para que quepan todas juntas.
     Sin el nowrap, un envoltorio parcial hacía que el header creciera
     de golpe (o, peor, que el contenido de la fila de más se
     encimara con la página de abajo). */
  @media (max-width: 767.98px) {
    .hdr-navbar {
      padding: 0 10px !important;
      flex-wrap: nowrap !important;
    }
    .hdr-store-btn,
    .hdr-search-trigger,
    .hdr-icon-btn {
      margin: 0 3px;
    }
    .hdr-user-trigger {
      margin-left: 4px;
    }
    /* Pantalla completa no es una acción confiable en navegadores
       móviles (iOS Safari ni siquiera implementa la Fullscreen API
       fuera de <video>) -- ocultarla en mobile libera ~44px que hacían
       falta para que la tira de tabs de reportes (abajo) tuviera dónde
       existir sin desbordar la página. */
    .hdr-icon-btn {
      display: none !important;
    }
  }

  /* ── Botón POS ── */
  .hdr-pos-btn {
    font-family: 'Nunito', sans-serif;
    font-size: 12.5px;
    font-weight: 800;
    letter-spacing: 0.6px;
    text-transform: uppercase;
    color: #2F6FED !important;
    background: #f0f0ff !important;
    border: none;
    border-radius: 10px;
    padding: 8px 18px !important;
    text-decoration: none !important;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: background 0.18s, transform 0.15s, box-shadow 0.15s;
    box-shadow: none;
    cursor: pointer;
  }
  .hdr-pos-btn:hover {
    background: linear-gradient(135deg, #2F6FED, #2F6FED) !important;
    color: white !important;
    transform: translateY(-1px);
    box-shadow: 0 6px 18px rgba(47, 111, 237,0.3);
  }

  .hdr-trial-badge {
    height: 34px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-right: 8px;
    padding: 0 11px;
    border: 1px solid #bfdbfe;
    border-radius: 999px;
    background: #eff6ff;
    color: #2563eb;
    font-family: 'Poppins', sans-serif;
    font-size: 11.5px;
    font-weight: 600;
    white-space: nowrap;
  }
  .hdr-trial-badge b {
    padding-left: 6px;
    border-left: 1px solid #bfdbfe;
    font-family: 'Nunito', sans-serif;
    font-size: 12px;
    font-weight: 800;
  }
  .hdr-trial-badge--expired {
    border-color: #fecaca;
    background: #fff1f2;
    color: #dc2626;
  }
  @media (max-width: 575.98px) {
    .hdr-trial-badge {
      width: 38px;
      padding: 0;
      margin-right: 4px;
      justify-content: center;
    }
    .hdr-trial-badge b { border-left: 0; padding-left: 0; }
    .hdr-trial-badge svg { display: none; }
  }

  /* ── Selector de tienda / idioma (una sola línea: ícono + valor +
     flecha -- mismo componente visual para ambos) ── */
  .hdr-store-btn {
    display: flex;
    align-items: center;
    gap: 8px;
    height: 38px;
    padding: 0 12px;
    border-radius: 10px;
    background: #f8f7ff;
    border: 1.5px solid #E2E8F0;
    cursor: pointer;
    transition: background 0.18s, border-color 0.18s;
    color: #4b5563;
    margin: 0 6px;
    max-width: 180px;
  }
  .hdr-store-btn:hover {
    background: #E2E8F0;
    border-color: #94A3B8;
  }
  .hdr-store-icon {
    color: #2F6FED;
    font-size: 14px;
    flex-shrink: 0;
  }
  .hdr-store-name {
    font-family: 'Poppins', sans-serif;
    font-size: 13px;
    font-weight: 500;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .hdr-store-chevron {
    color: #94A3B8 !important;
    font-size: 10px;
    flex-shrink: 0;
  }

  /* ── Buscador global (trigger en la barra + modal Cmd/Ctrl+K) ── */
  .hdr-search-trigger {
    display: flex;
    align-items: center;
    gap: 10px;
    height: 38px;
    padding: 0 12px;
    border-radius: 10px;
    background: #f8f7ff;
    border: 1.5px solid #E2E8F0;
    cursor: pointer;
    flex: 1 1 auto;
    max-width: 420px;
    min-width: 0;
    margin: 0 12px;
    transition: background 0.18s, border-color 0.18s;
  }
  .hdr-search-trigger:hover {
    background: #E2E8F0;
    border-color: #94A3B8;
  }
  .hdr-search-icon {
    color: #9ca3af;
    font-size: 13px;
    flex-shrink: 0;
  }
  .hdr-search-placeholder {
    font-family: 'Poppins', sans-serif;
    font-size: 13px;
    color: #9ca3af;
    flex: 1;
    text-align: left;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .hdr-search-kbd {
    font-family: 'Poppins', sans-serif;
    font-size: 11px;
    font-weight: 600;
    color: #9ca3af;
    background: #fff;
    border: 1px solid #E2E8F0;
    border-radius: 6px;
    padding: 2px 6px;
    flex-shrink: 0;
  }

  /* En mobile el trigger se reduce a solo el ícono (mismo criterio que
     el resto de los botones de la barra, que ya ocultan su texto con
     d-none d-sm-block) -- el placeholder completo no entra junto con
     tienda/idioma/pantalla-completa/usuario sin que el navbar se
     desborde a varias filas. */
  @media (max-width: 575.98px) {
    .hdr-search-trigger {
      flex: 0 0 auto;
      width: 38px;
      max-width: 38px;
      padding: 0;
      justify-content: center;
      margin: 0 4px;
    }
    .hdr-search-placeholder,
    .hdr-search-kbd {
      display: none;
    }
  }

  .hdr-search-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 15, 25, 0.45);
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
    z-index: 1050;
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding-top: 12vh;
    animation: hdr-search-overlay-in 0.18s ease-out;
  }
  .hdr-search-modal {
    width: 100%;
    max-width: 640px;
    max-height: 70vh;
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 24px 70px rgba(0,0,0,0.3);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    margin: 0 16px;
    animation: hdr-search-modal-in 0.22s cubic-bezier(0.16, 1, 0.3, 1);
  }
  @keyframes hdr-search-overlay-in {
    from { opacity: 0; }
    to { opacity: 1; }
  }
  @keyframes hdr-search-modal-in {
    from { opacity: 0; transform: translateY(-12px) scale(0.97); }
    to { opacity: 1; transform: translateY(0) scale(1); }
  }
  @media (prefers-reduced-motion: reduce) {
    .hdr-search-overlay,
    .hdr-search-modal {
      animation: none;
    }
  }
  .hdr-search-modal-input-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 16px 18px;
    border-bottom: 1px solid #f0f0f8;
    flex-shrink: 0;
  }
  .hdr-search-modal-icon {
    color: #9ca3af;
    font-size: 15px;
  }
  .hdr-search-modal-input {
    flex: 1;
    border: none;
    outline: none;
    font-family: 'Poppins', sans-serif;
    font-size: 15px;
    color: #1e1b4b;
  }
  .hdr-search-modal-close {
    border: none;
    background: #f3f4f6;
    color: #9ca3af;
    width: 26px;
    height: 26px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    flex-shrink: 0;
  }
  .hdr-search-modal-results {
    overflow-y: auto;
    padding: 8px;
  }
  .hdr-search-modal-empty {
    padding: 24px;
    text-align: center;
    color: #9ca3af;
    font-family: 'Poppins', sans-serif;
    font-size: 13px;
  }
  .hdr-search-modal-group {
    margin-bottom: 6px;
  }
  .hdr-search-modal-group-label {
    font-family: 'Poppins', sans-serif;
    font-size: 10.5px;
    font-weight: 700;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    color: #9ca3af;
    padding: 8px 10px 4px;
  }
  .hdr-search-modal-item {
    display: flex;
    align-items: center;
    padding: 10px 12px;
    border-radius: 10px;
    font-family: 'Poppins', sans-serif;
    font-size: 13.5px;
    color: #374151;
    cursor: pointer;
  }
  .hdr-search-modal-item-active {
    background: #f0f0ff;
    color: #2F6FED;
  }
  .hdr-search-modal-footer {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 10px 18px;
    border-top: 1px solid #f0f0f8;
    font-family: 'Poppins', sans-serif;
    font-size: 11.5px;
    color: #9ca3af;
    flex-shrink: 0;
  }
  .hdr-search-modal-footer kbd {
    background: #f3f4f6;
    border: 1px solid #e5e7eb;
    border-radius: 5px;
    padding: 1px 5px;
    font-size: 10.5px;
    margin-right: 3px;
    color: #6b7280;
  }

  /* ── Botón ícono (fullscreen) ── */
  .hdr-icon-btn {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    background: #f8f7ff;
    border: 1.5px solid #E2E8F0;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: background 0.18s, border-color 0.18s, transform 0.15s;
    color: #9ca3af;
    margin: 0 6px;
  }
  .hdr-icon-btn:hover {
    background: #E2E8F0;
    border-color: #94A3B8;
    color: #2F6FED;
    transform: scale(1.05);
  }

  /* ── Trigger del usuario ── */
  .hdr-user-trigger {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 6px 12px 6px 6px;
    border-radius: 50px;
    background: #eaf1ff;
    border: 1.5px solid #E2E8F0;
    cursor: pointer;
    transition: background 0.18s, border-color 0.18s, box-shadow 0.18s;
    margin-left: 8px;
  }
  .hdr-user-trigger:hover {
    background: #2F6FED;
    border-color: #94A3B8;
        color: white !important;
    box-shadow: 0 4px 14px rgba(47, 111, 237,0.12);
  }

  .hdr-avatar-img {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #E2E8F0;
  }

  .hdr-avatar-initials {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: linear-gradient(135deg, #2F6FED, #2F6FED);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: 'Nunito', sans-serif;
    font-size: 12px;
    font-weight: 800;
    flex-shrink: 0;
  }

  .hdr-user-name {
    flex-direction: column;
    justify-content: center;
    gap: 2px;
    line-height: 1.15;
    font-family: 'Poppins', sans-serif;
    font-size: 13px;
    font-weight: 500;
    max-width: 130px;
    overflow: hidden;
  }
  /* !important -- d-sm-block (necesaria para ocultar este bloque en
     mobile con d-none) trae display:block !important de Bootstrap y le
     ganaba al flex-direction:column de acá: el nombre y el rol
     terminaban en la misma línea, pegados sin espacio ("Jhon
     GuachoBOSS"). Se limita el !important al mismo breakpoint (576px)
     en el que d-sm-block ya se activa, para no pisar el d-none de abajo
     de ese punto y así no romper el ocultamiento en mobile (eso causaba
     que el nombre completo se siguiera mostrando en mobile y desbordara
     el header). */
  @media (min-width: 576px) {
    .hdr-user-name {
      display: flex !important;
    }
  }
  .hdr-user-name-value {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .hdr-user-role {
    align-self: flex-start;
    font-family: 'Poppins', sans-serif;
    font-size: 9.5px;
    font-weight: 700;
    letter-spacing: 0.4px;
    text-transform: uppercase;
    color: #2F6FED;
    background: rgba(47, 111, 237, 0.1);
    border-radius: 4px;
    padding: 1px 6px;
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    transition: background 0.18s, color 0.18s;
  }
  .hdr-user-trigger:hover .hdr-user-role {
    color: #ffffff;
    background: rgba(255, 255, 255, 0.22);
  }

  .hdr-chevron {
    color: #94A3B8 !important;
    font-size: 11px;
    transition: transform 0.2s;
  }

  /* ── Dropdown menu ── */
  .hdr-dropdown-menu {
    border: none !important;
    border-radius: 18px !important;
    box-shadow: 0 20px 60px rgba(47, 111, 237,0.15), 0 4px 16px rgba(0,0,0,0.06) !important;
    padding: 8px !important;
    min-width: 240px !important;
    margin-top: 10px !important;
    background: white !important;
    overflow: hidden;
    border: 1px solid #f0f0f8 !important;
  }

  /* Menú de acceso rápido (+) -- es un <div> propio portalado a
     document.body (ver comentario en asideTopSubMenuItem.js), no un
     Dropdown.Menu de react-bootstrap, así que no hereda su
     position:absolute -- se posiciona fijo con las coordenadas
     calculadas a mano desde el botón. Mismo z-index que el overlay del
     buscador (ambos son los únicos elementos portalados a body). */
  .hdr-quick-add-menu {
    position: fixed;
    z-index: 1050;
    margin-top: 0 !important;
  }

  /* Header del dropdown */
  .hdr-dropdown-header {
    padding: 16px 16px 20px;
    text-align: center;
    border-bottom: 1px solid #f3f4f6;
    margin-bottom: 8px;
  }

  .hdr-dropdown-avatar {
    width: 56px;
    height: 56px;
    border-radius: 50%;
    object-fit: cover;
    margin: 0 auto 10px;
    display: block;
    border: 3px solid #f0f2ff;
  }

  .hdr-dropdown-initials {
    width: 56px;
    height: 56px;
    border-radius: 50%;
    background: linear-gradient(135deg, #2F6FED, #2F6FED);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: 'Nunito', sans-serif;
    font-size: 18px;
    font-weight: 800;
    margin: 0 auto 10px;
  }

  .hdr-dropdown-name {
    font-family: 'Nunito', sans-serif;
    font-size: 15px;
    font-weight: 700;
    color: #1e1b4b;
    margin-bottom: 2px;
  }

  .hdr-dropdown-email {
    font-family: 'Poppins', sans-serif;
    font-size: 12px;
    color: #9ca3af;
    font-weight: 400;
  }

  /* Items del dropdown */
  .hdr-dropdown-item {
    display: flex !important;
    align-items: center;
    gap: 12px;
    padding: 10px 14px !important;
    border-radius: 12px !important;
    font-family: 'Poppins', sans-serif !important;
    font-size: 13.5px !important;
    color: #6b7280 !important;
    font-weight: 500 !important;
    transition: background 0.15s, color 0.15s !important;
    margin-bottom: 2px;
    background: transparent !important;
  }
  .hdr-dropdown-item:hover {
    background: #F1F5F9 !important;
    color: #2F6FED !important;
  }
  .hdr-dropdown-item:hover .hdr-item-icon {
    background: #E2E8F0 !important;
    color: #2F6FED !important;
  }

  .hdr-item-icon {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    background: #f3f4f6;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    color: #9ca3af;
    flex-shrink: 0;
    transition: background 0.15s, color 0.15s;
  }

  /* Tienda desactivada -- visible en el selector pero bloqueada */
  .hdr-dropdown-item--disabled {
    cursor: not-allowed !important;
    color: #c4c8d4 !important;
    opacity: 0.7;
  }
  .hdr-dropdown-item--disabled:hover {
    background: transparent !important;
    color: #c4c8d4 !important;
  }
  .hdr-dropdown-item--disabled:hover .hdr-item-icon {
    background: #f3f4f6 !important;
    color: #9ca3af !important;
  }
  .hdr-store-inactive-badge {
    margin-left: auto;
    font-family: 'Poppins', sans-serif;
    font-size: 10.5px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    color: #b45309;
    background: #fff7ed;
    border: 1px solid #fed7aa;
    border-radius: 20px;
    padding: 2px 8px;
    white-space: nowrap;
  }

  /* Logout */
  .hdr-dropdown-item--logout { color: #ef4444 !important; }
  .hdr-dropdown-item--logout .hdr-item-icon { color: #ef4444; background: #fff5f5; }
  .hdr-dropdown-item--logout:hover {
    background: #fff5f5 !important;
    color: #ef4444 !important;
  }
  .hdr-dropdown-item--logout:hover .hdr-item-icon {
    background: #fee2e2 !important;
    color: #ef4444 !important;
  }

  .hdr-divider {
    height: 1px;
    background: #f3f4f6;
    margin: 6px 8px;
  }
`;
