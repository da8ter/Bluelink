/* Bluelink-Kachel – Symbole aus Font Awesome Light, das die Visualisierung unter /icons.js mitbringt
   (dort ist nur der Light-Schnitt vollständig). Übernommen aus der Türstations-Kachel:
   das SVG entsteht direkt aus den Icon-Daten statt über <i>-Ersetzung und steht sofort im DOM. */
(function () {
  'use strict';
  // Kachel-Name → Font-Awesome-Name
  const NAMES = {
    car: 'car', battery: 'battery-three-quarters', bolt: 'bolt', plug: 'plug', fuel: 'gas-pump', lock: 'lock',
    lockOpen: 'lock-open', climate: 'fan', defrost: 'snowflake', steering: 'steering-wheel', door: 'car-side',
    window: 'window-frame-open', trunk: 'car-rear', hood: 'car', battery12v: 'car-battery', odometer: 'gauge',
    position: 'location-dot', map: 'map-location-dot', refresh: 'arrows-rotate', wake: 'tower-broadcast',
    route: 'route', clock: 'clock', speed: 'gauge-high', chart: 'chart-column', leaf: 'leaf', minus: 'minus',
    plus: 'plus', down: 'chevron-down', right: 'chevron-right', warning: 'triangle-exclamation', online: 'cloud',
    offline: 'cloud-slash', ok: 'circle-check', close: 'xmark', overview: 'gauge-simple', log: 'list-timeline',
  };

  // [Breite, Höhe, Ligaturen, Unicode, Pfad] oder null
  function definition(name) {
    const iconName = NAMES[name] || name;
    const fa = window.FontAwesome;
    if (fa && typeof fa.findIconDefinition === 'function') {
      const found = fa.findIconDefinition({ prefix: 'fal', iconName });
      if (found && found.icon) return found.icon;
    }
    const ns = window.___FONT_AWESOME___;
    return (ns && ns.styles && ns.styles.fal && ns.styles.fal[iconName]) || null;
  }
  const pathOf = icon => (Array.isArray(icon[4]) ? icon[4].join(' ') : icon[4]);

  function svg(name, className) {
    const icon = definition(name);
    const cls = 'fa-icon' + (className ? ' ' + className : '');
    if (!icon) return '<svg class="' + cls + '" viewBox="0 0 512 512" aria-hidden="true"></svg>';
    return '<svg class="' + cls + '" viewBox="0 0 ' + icon[0] + ' ' + icon[1] + '" aria-hidden="true"><path fill="currentColor" d="' + pathOf(icon) + '"/></svg>';
  }

  // Als CSS-Bild (Maske oder Hintergrund) für Pseudoelemente, die kein SVG im DOM tragen können
  function url(name, color) {
    const icon = definition(name);
    if (!icon) return 'none';
    const markup = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + icon[0] + ' ' + icon[1] + '"><path fill="' + (color || '#000') + '" d="' + pathOf(icon) + '"/></svg>';
    return 'url("data:image/svg+xml,' + encodeURIComponent(markup) + '")';
  }

  window.BLIcons = { svg, url };
})();
