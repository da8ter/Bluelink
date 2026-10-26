/* Bluelink-Kachel – Fahrzeug-Dashboard für Hyundai Bluelink und Kia Connect. Der Zustand kommt als
   {type:'state'} über handleMessage (Startzustand eingebettet), Befehle gehen über requestAction an die
   Fahrzeug-Instanz. Welche Bereiche erscheinen, bestimmt state.caps (Elektro, Tank, PIN), damit dieselbe
   Kachel für Elektroautos, Plug-in-Hybride und Verbrenner passt. In einer IIFE, weil die Visu
   translate/requestAction/openObject global einspeist. */
(function () {
  'use strict';
  const cfg = window.BL_CONFIG || {};
  const T = (key, ...args) => {
    let s = (cfg.texts && cfg.texts[key]) || key;
    args.forEach(v => { s = s.replace(/%d|%s/, v); });
    return s;
  };
  const I = (name, cls) => (window.BLIcons ? window.BLIcons.svg(name, cls) : '');
  const $ = id => document.getElementById(id);
  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const num = (v, d) => Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: d || 0, maximumFractionDigits: d || 0 });
  const SEND_MS = 1200;   // Sollwerte erst nach einer Pause senden: jeder Wert ist ein Befehl ans Auto
  const CONFIRM_MS = 4000;

  let state = null;
  let offset = 0;                 // Serverzeit - Browserzeit (s)
  let tab = 'overview';
  let metric = 'dist';
  let selDay = null;              // gewählter Tag in der Statistik
  const openDays = new Set();     // aufgeklappte Tage im Fahrtenbuch
  let openInit = false;
  const confirm = {};             // Aktion -> Ende der Rückfrage (ms)
  const pending = {};             // Sollwert -> {value, timer, until}
  let busyUntil = 0;              // nach einem Tipp, bis der Befehl im Zustand steht
  let refreshing = null;          // laufende Abfrage {updated, timer}: Symbol dreht sich nur bis zur Antwort
  const older = {};               // ältere Tage des Fahrtenbuchs, monatsweise nachgeladen
  const loadedMonths = new Set();
  let olderRequest = null;        // {rid, month, timer}: Antwort nur für dieses Fenster

  function action(ident, value) {
    try { requestAction(ident, value); } catch (e) { /* außerhalb von Symcon */ }
  }

  // Ränder der Visu (Kachelname, Vollbild-Symbol) aus der iframe-Adresse
  const params = new URLSearchParams(location.search);
  ['margintop', 'marginside', 'marginbottom'].forEach(name => {
    const v = parseInt(params.get(name), 10);
    if (!isNaN(v)) document.documentElement.style.setProperty('--' + name, v + 'px');
  });

  function layout() {
    const w = window.innerWidth, h = window.innerHeight, root = document.documentElement.classList;
    const mini = w < 230 || h < 170;
    root.toggle('mini', mini);
    // Klein nur bei wenig Höhe: eine schmale, hohe Kachel (Smartphone) bekommt die volle Ansicht
    root.toggle('compact', !mini && (h < 380 || (w < 300 && h < 560)));
    root.toggle('wide', w >= 720 && h >= 380);
  }
  const mode = () => (document.documentElement.classList.contains('mini') ? 'mini'
    : document.documentElement.classList.contains('compact') ? 'compact' : 'full');

  const now = () => Date.now() / 1000 + offset;
  function ago(ts) {
    if (!ts) return T('Never');
    const d = Math.max(0, now() - ts);
    if (d < 60) return T('just now');
    if (d < 3600) return T('%d min ago', Math.floor(d / 60));
    if (d < 6 * 3600) return T('%d h ago', Math.floor(d / 3600));
    const date = new Date(ts * 1000);
    return date.toLocaleDateString(undefined, { day: '2-digit', month: '2-digit' }) + ' '
      + date.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
  }
  function dur(min) {
    min = Math.max(0, Math.round(min || 0));
    const h = Math.floor(min / 60), m = min % 60;
    return h ? T('%d h', h) + (m ? ' ' + T('%d min', m) : '') : T('%d min', m);
  }
  const ymd = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  const dateOf = key => { const p = key.split('-'); return new Date(+p[0], +p[1] - 1, +p[2]); };
  function dayLabel(key) {
    const today = new Date(now() * 1000), d = dateOf(key);
    if (key === ymd(today)) return T('Today');
    const y = new Date(today); y.setDate(today.getDate() - 1);
    if (key === ymd(y)) return T('Yesterday');
    return d.toLocaleDateString(undefined, { weekday: 'short', day: '2-digit', month: '2-digit' });
  }
  const levelColor = p => (p < 20 ? 'var(--red)' : p < 40 ? 'var(--orange)' : 'var(--green)');
  // Die Cloud liefert den zuletzt gemeldeten Zustand. Ist die Meldung des Autos älter, können
  // Klima und Laden inzwischen anders sein (ein geparktes Auto meldet sich nicht von selbst).
  const STALE_S = 3 * 3600;
  const stale = s => s.carTime > 0 && now() - s.carTime > STALE_S;

  // ── Bausteine ──────────────────────────────────────────────────────

  function ring(pct, color, big, small) {
    const r = 52, c = 2 * Math.PI * r, p = Math.max(0, Math.min(100, pct || 0));
    return '<div class="ring"><svg viewBox="0 0 120 120" aria-hidden="true">'
      + '<circle cx="60" cy="60" r="' + r + '" class="ring-track"/>'
      + '<circle cx="60" cy="60" r="' + r + '" class="ring-bar" style="stroke:' + color + ';stroke-dasharray:' + (c * p / 100).toFixed(1) + ' ' + c.toFixed(1) + '"/>'
      + '</svg><div class="ring-text"><b>' + big + '</b><span>' + small + '</span></div></div>';
  }

  function chargingLine(s) {
    if (!s.caps.ev) return '';
    if (s.charging === 2) {
      const parts = [T('Charging now') + (stale(s) ? ' (' + T('last reported') + ')' : '')];
      if (s.power > 0) parts.push(num(s.power, 1) + ' kW');
      if (s.remaining > 0) parts.push(T('%s left', dur(s.remaining)));
      return '<div class="line accent">' + I('bolt') + '<span>' + esc(parts.join(' · ')) + '</span></div>';
    }
    if (s.plugged || s.charging === 1) return '<div class="line">' + I('plug') + '<span>' + esc(T('Plugged in')) + '</span></div>';
    return '<div class="line muted">' + I('plug') + '<span>' + esc(T('Not plugged in')) + '</span></div>';
  }

  function hero(s) {
    const range = s.range > 0 ? num(s.range) + ' km' : '';
    let main;
    if (s.caps.ev) {
      main = ring(s.soc, levelColor(s.soc), num(s.soc) + ' %', esc(range || T('Battery')));
    } else {
      main = ring(s.fuel, levelColor(s.fuel), num(s.fuel) + ' %', esc(range || T('Fuel')));
    }
    let side = chargingLine(s);
    if (s.caps.ev && s.caps.fuel) {
      side += '<div class="line">' + I('fuel') + '<span>' + esc(T('Fuel')) + '</span><span class="bar"><i style="width:' + Math.max(0, Math.min(100, s.fuel)) + '%;background:' + levelColor(s.fuel) + '"></i></span><b>' + num(s.fuel) + ' %</b></div>';
    }
    side += limits(s);
    if (!side) {
      // Verbrenner: ohne Laden und Ladelimits die wichtigsten Werte neben den Ring
      if (s.odometer > 0) side += '<div class="line">' + I('odometer') + '<span>' + esc(num(s.odometer) + ' km') + '</span></div>';
      if (s.battery12v > 0) side += '<div class="line">' + I('battery12v') + '<span>' + esc(T('12V battery') + ' ' + num(s.battery12v) + ' %') + '</span></div>';
    }
    return '<section class="card hero">' + main + '<div class="hero-side">' + side + '</div></section>';
  }

  function pendingValue(key, current) {
    const p = pending[key];
    return p && Date.now() < p.until ? p.value : current;
  }

  function limits(s) {
    if (!s.caps.ev) return '';
    const row = (key, label) => {
      const v = pendingValue(key, s['limit' + key]);
      if (!v) return '';
      const controls = s.caps.pin
        ? '<button class="step" data-act="limit" data-k="' + key + '" data-d="-10" title="-10 %"' + (v <= 50 ? ' disabled' : '') + '>' + I('minus') + '</button>'
          + '<b>' + num(v) + ' %</b>'
          + '<button class="step" data-act="limit" data-k="' + key + '" data-d="10" title="+10 %"' + (v >= 100 ? ' disabled' : '') + '>' + I('plus') + '</button>'
        : '<b>' + num(v) + ' %</b>';
      return '<div class="limit"><span>' + esc(T('Charge limit') + ' ' + label) + '</span><span class="stepper">' + controls + '</span></div>';
    };
    return row('AC', T('AC')) + row('DC', T('DC'));
  }

  function busy(s) { return !!s.command || Date.now() < busyUntil; }

  function actions(s, small) {
    const dis = !s.caps.pin || busy(s) ? ' disabled' : '';
    const unlockAsk = Date.now() < (confirm.unlock || 0);
    const lockBtn = s.locked
      ? '<button class="act ok' + (unlockAsk ? ' ask' : '') + '" data-act="lock"' + dis + '>' + I(unlockAsk ? 'lockOpen' : 'lock') + '<span>' + esc(unlockAsk ? T('Tap again to unlock') : T('Locked')) + '</span></button>'
      : '<button class="act warn" data-act="lock"' + dis + '>' + I('lockOpen') + '<span>' + esc(T('Unlocked')) + '</span><small>' + esc(T('Lock')) + '</small></button>';
    const temp = pendingValue('temp', s.target || 22);
    const old = stale(s);
    const climateBtn = '<button class="act' + (s.climate ? (old ? ' was-on' : ' on') : '') + '" data-act="climate"' + dis + '>' + I('climate')
      + '<span>' + esc(T('Climate')) + '</span><small>' + esc(s.climate ? (old ? T('last on') : T('On')) : T('Off')) + '</small></button>';
    if (small) return '<div class="act-row">' + lockBtn + climateBtn + '</div>';
    const climate = '<div class="act-group">' + climateBtn
      + '<span class="stepper">'
      + '<button class="step" data-act="temp" data-d="-0.5"' + (dis || (temp <= 14 ? ' disabled' : '')) + '>' + I('minus') + '</button>'
      + '<b>' + num(temp, 1) + ' °C</b>'
      + '<button class="step" data-act="temp" data-d="0.5"' + (dis || (temp >= 30 ? ' disabled' : '')) + '>' + I('plus') + '</button></span></div>';
    let charge = '';
    if (s.caps.ev && (s.plugged || s.charging > 0)) {
      const charging = s.charging === 2;
      charge = '<button class="act' + (charging ? ' on' : '') + '" data-act="charge"' + dis + '>' + I('bolt')
        + '<span>' + esc(charging ? T('Stop charging') : T('Start charging')) + '</span></button>';
    }
    let hint = '';
    if (old && (s.climate || s.charging === 2)) hint += '<div class="hint">' + esc(T('Last report from the car: %s. Climate and charging may have changed since then.', ago(s.carTime))) + '</div>';
    if (!s.caps.pin) hint += '<div class="hint">' + esc(T('Remote actions need the PIN in the account settings')) + '</div>';
    else if (busy(s)) hint += '<div class="hint">' + esc(T('Command running…')) + '</div>';
    return '<section class="card actions"><div class="act-row">' + lockBtn + climate + charge + '</div>' + hint + '</section>';
  }

  function openList(names) { return names.filter(n => n[1]).map(n => n[0]); }

  // Zeilen der Karte „Fahrzeug“: Reihenfolge und Auswahl kommen aus dem Formular (state.rows).
  // Jede Zeile liefert [Symbol, Beschriftung, Wert, Warnung] oder null, wenn das Auto den Wert nicht hat.
  const DEFAULT_ROWS = ['doors', 'windows', 'trunk', 'hood', 'battery12v', 'odometer'];
  const onOff = v => (v ? T('On') : T('Off'));
  const ROWS = {
    doors: s => { const o = openList([[T('Driver'), s.doors[0]], [T('Passenger'), s.doors[1]], [T('Rear left'), s.doors[2]], [T('Rear right'), s.doors[3]]]);
      return ['door', T('Doors'), o.length ? T('Open') + ': ' + o.join(', ') : T('All closed'), o.length > 0]; },
    windows: s => { const o = openList([[T('Driver'), s.windows[0]], [T('Passenger'), s.windows[1]], [T('Rear left'), s.windows[2]], [T('Rear right'), s.windows[3]]]);
      return ['window', T('Windows'), o.length ? T('Open') + ': ' + o.join(', ') : T('All closed'), o.length > 0]; },
    trunk: s => ['trunk', T('Trunk'), s.trunk ? T('Open') : T('Closed'), s.trunk],
    hood: s => ['hood', T('Hood'), s.hood ? T('Open') : T('Closed'), s.hood],
    locked: s => [s.locked ? 'lock' : 'lockOpen', T('Doors Locked'), s.locked ? T('Locked') : T('Unlocked'), !s.locked],
    battery12v: s => (s.battery12v > 0 ? ['battery12v', T('12V battery'), num(s.battery12v) + ' %', s.battery12v < 50] : null),
    odometer: s => (s.odometer > 0 ? ['odometer', T('Odometer'), num(s.odometer) + ' km', false] : null),
    soc: s => (s.caps.ev ? ['battery', T('Battery SOC'), num(s.soc) + ' %', s.soc < 20] : null),
    range: s => (s.range > 0 ? ['route', T('Range'), num(s.range) + ' km', false] : null),
    plugged: s => (s.caps.ev ? ['plug', T('Plugged In'), s.plugged ? T('Plugged in') : T('Not plugged in'), false] : null),
    charging: s => (s.caps.ev ? ['bolt', T('Charging State'), s.charging === 2 ? T('Charging now') : s.charging === 1 ? T('Plugged in') : T('Not plugged in'), false] : null),
    power: s => (s.caps.ev ? ['bolt', T('Charging Power'), num(s.power, 1) + ' kW', false] : null),
    remaining: s => (s.caps.ev ? ['clock', T('Remaining Charge Time'), s.remaining > 0 ? dur(s.remaining) : '–', false] : null),
    limitAC: s => (s.caps.ev && s.limitAC > 0 ? ['battery', T('Charge Limit AC'), num(s.limitAC) + ' %', false] : null),
    limitDC: s => (s.caps.ev && s.limitDC > 0 ? ['battery', T('Charge Limit DC'), num(s.limitDC) + ' %', false] : null),
    fuel: s => (s.caps.fuel ? ['fuel', T('Fuel Level'), num(s.fuel) + ' %', s.fuel < 15] : null),
    climate: s => ['climate', T('Climate'), onOff(s.climate), false],
    target: s => ['climate', T('Target Temperature'), num(s.target, 1) + ' °C', false],
    defrost: s => ['defrost', T('Defrost'), onOff(s.defrost), false],
    steering: s => ['steering', T('Steering Wheel Heating'), onOff(s.steering), false],
    position: s => (s.pos ? ['position', T('Position'), num(s.pos.lat, 5) + ', ' + num(s.pos.lon, 5), false] : null),
    carTime: s => (s.carTime > 0 ? ['clock', T('Vehicle Data From'), ago(s.carTime), stale(s)] : null),
    updated: s => ['clock', T('Last Update'), ago(s.updated), false],
    online: s => [s.online ? 'online' : 'offline', T('API Online'), s.online ? T('Online') : T('Offline'), !s.online],
    lastCommand: s => ['clock', T('Last Command'), ago(s.lastCommand), false],
    error: s => ['warning', T('Last Error'), s.error || '–', !!s.error],
    cloudCount: s => ['refresh', T('Cloud Refresh Counter'), num(s.cloudCount), false],
    vehicleCount: s => ['wake', T('Vehicle Refresh Counter'), num(s.vehicleCount), false],
  };

  function vehicle(s) {
    const keys = Array.isArray(s.rows) ? s.rows : DEFAULT_ROWS;
    // Zusatzwerte (Reifendruck, Warnungen, Abfahrtszeiten …) bringt der Server fertig mit: [Symbol, Beschriftung, Wert, Warnung]
    const html = keys.map(k => (ROWS[k] ? ROWS[k](s) : (s.extras && s.extras[k]) || null)).filter(Boolean)
      .map(([icon, label, value, warn]) => '<div class="row' + (warn ? ' warn' : '') + '">' + I(icon) + '<span>' + esc(label) + '</span><b>' + esc(value) + '</b></div>').join('');
    return html ? '<section class="card list"><h3>' + esc(T('Vehicle')) + '</h3>' + html + '</section>' : '';
  }

  function position(s) {
    if (!s.pos) return '';
    return '<section class="card list"><h3>' + esc(T('Position')) + '</h3>'
      + '<div class="row">' + I('position') + '<span>' + esc(num(s.pos.lat, 5) + ', ' + num(s.pos.lon, 5)) + '</span><b>' + esc(ago(s.pos.ts)) + '</b></div>'
      + '<button class="wide-btn" data-act="map">' + I('map') + '<span>' + esc(T('Open map')) + '</span></button></section>';
  }

  // Kachel der Zusammenfassung: Zahl oben, Einheit in der Beschriftung (sonst wird schmal abgeschnitten)
  const box = (value, label) => '<div><b>' + esc(value) + '</b><span>' + esc(label) + '</span></div>';
  const hm = min => { min = Math.round(min || 0); return Math.floor(min / 60) + ':' + String(min % 60).padStart(2, '0'); };

  const tripDays = s => Object.keys(s.trips || {}).sort().reverse();
  const statDays = s => Object.keys(s.days || {}).sort().reverse();

  function lastTrip(s) {
    const days = tripDays(s);
    if (!days.length) return '';
    const day = days[0], entry = s.trips[day], trip = entry.trips[entry.trips.length - 1];
    if (!trip) return '';
    return '<section class="card list"><h3>' + esc(T('Last trip')) + '</h3>'
      + '<div class="row">' + I('route') + '<span>' + esc(dayLabel(day) + ' ' + trip.t) + '</span><b>' + esc(num(trip.dist) + ' km · ' + dur(trip.drive)) + '</b></div>'
      + '<button class="wide-btn" data-act="tab" data-v="log">' + I('log') + '<span>' + esc(T('Trip log')) + '</span></button></section>';
  }

  function overview(s) {
    return '<div class="ov"><div class="col">' + hero(s) + actions(s) + lastTrip(s) + '</div><div class="col">'
      + vehicle(s) + position(s) + '</div></div>';
  }

  // Klein (2x1, 2x2): Ring, Laden und die wichtigsten Knöpfe in einer Karte
  function compact(s) {
    const pct = s.caps.ev ? s.soc : s.fuel;
    return '<section class="card hero">' + ring(pct, levelColor(pct), num(pct) + ' %', esc(s.range > 0 ? num(s.range) + ' km' : ''))
      + '<div class="hero-side">' + chargingLine(s) + actions(s, true) + '</div></section>';
  }

  // Energie eines Tags aus der Fahrstatistik (falls vorhanden): "4,9 kWh · 11,1 kWh/100 km"
  function energyText(s, key, km) {
    const d = s.days && s.days[key];
    if (!d || !d.Consumption) return '';
    const dist = d.Distance || km;
    return num(d.Consumption, 1) + ' kWh' + (dist > 0 ? ' · ' + num(d.Consumption / dist * 100, 1) + ' ' + T('kWh/100 km') : '');
  }

  // Ältere Monate: der Zustand trägt nur die letzten 31 Tage, der Rest kommt auf Anfrage
  const monthOf = key => key.slice(0, 7);
  function prevMonth(m) {
    const p = m.split('-').map(Number), d = new Date(p[0], p[1] - 2, 1);
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
  }
  function monthLabel(m) {
    const p = m.split('-').map(Number);
    return new Date(p[0], p[1] - 1, 1).toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
  }
  function windowStart() {
    const d = new Date(now() * 1000);
    d.setDate(d.getDate() - 30);
    return ymd(d);
  }
  function nextOlderMonth(s) {
    if (!s.tripOldest) return null;
    const start = windowStart();
    let m = start.slice(8) === '01' ? prevMonth(monthOf(start)) : monthOf(start);
    while (loadedMonths.has(m)) m = prevMonth(m);
    return m >= monthOf(s.tripOldest) ? m : null;
  }
  function loadOlder(month) {
    const rid = Math.random().toString(36).slice(2, 12);
    olderRequest = { rid, month, timer: setTimeout(() => { olderRequest = null; render(); }, 15000) };
    action('TileTrips', JSON.stringify({ month, rid }));
    render();
  }
  const allTripDays = s => Object.keys(Object.assign({}, older, s.trips || {})).sort().reverse();
  const tripEntry = (s, key) => (s.trips && s.trips[key]) || older[key];

  function tripLog(s) {
    const days = tripDays(s), all = allTripDays(s), next = nextOlderMonth(s);
    const olderBtn = next ? '<button class="wide-btn" data-act="older" data-v="' + esc(next) + '"' + (olderRequest ? ' disabled' : '') + '>' + I('clock')
      + '<span>' + esc(olderRequest ? T('Loading…') : T('Older trips') + ' · ' + monthLabel(next)) + '</span></button>' : '';
    if (!all.length) return '<div class="empty">' + I('route') + '<span>' + esc(T('No trips yet')) + '</span></div>' + olderBtn;
    if (!openInit) { openDays.add(all[0]); openInit = true; }
    let trips = 0, km = 0, minutes = 0;
    days.forEach(k => { const e = s.trips[k]; trips += e.count; km += e.dist; minutes += e.drive; });
    let html = '<div class="summary">' + box(num(trips), T('Trips')) + box(num(km), T('km distance')) + box(hm(minutes), T('h driving time')) + '</div>'
      + '<div class="caption">' + esc(T('Last 30 days')) + '</div>';
    const start = windowStart();
    let month = null;
    all.forEach(key => {
      if (key < start && monthOf(key) !== month) {
        month = monthOf(key);
        html += '<div class="caption month">' + esc(monthLabel(month)) + '</div>';
      }
      const e = tripEntry(s, key), isOpen = openDays.has(key), energy = energyText(s, key, e.dist);
      html += '<section class="card day' + (isOpen ? ' open' : '') + '"><button class="day-head" data-act="day" data-v="' + esc(key) + '">'
        + '<span class="day-title"><b>' + esc(dayLabel(key)) + '</b><small>' + esc((e.count === 1 ? T('%d trip', e.count) : T('%d trips', e.count)) + ' · ' + dur(e.drive) + (energy ? ' · ' + energy : '')) + '</small></span>'
        + '<b class="day-km">' + num(e.dist) + ' km</b>' + I(isOpen ? 'down' : 'right', 'chev') + '</button>';
      if (isOpen) {
        html += '<div class="trips">' + e.trips.slice().reverse().map(t => '<div class="trip"><b>' + esc(t.t) + '</b>'
          + '<span>' + esc(num(t.dist) + ' km · ' + dur(t.drive)) + (t.idle > 0 ? '<small>' + esc(T('%s idle', dur(t.idle))) + '</small>' : '') + '</span>'
          + '<span class="muted">' + esc('Ø ' + num(t.avg) + ' · max ' + num(t.max) + ' km/h') + '</span></div>').join('') + '</div>';
      }
      html += '</section>';
    });
    return html + olderBtn;
  }

  const PARTS = [['Drive', 'var(--accent)'], ['Climate', 'var(--orange)'], ['Electronics', '#8e7cf0'], ['BatteryCare', 'var(--muted)']];
  const PART_LABEL = { Drive: 'Drive', Climate: 'Climate', Electronics: 'Electronics', BatteryCare: 'Battery care' };

  function statistics(s) {
    const hasEnergy = statDays(s).length > 0;
    if (!hasEnergy && !tripDays(s).length) return '<div class="empty">' + I('chart') + '<span>' + esc(T('No data yet')) + '</span></div>';
    if (!hasEnergy) metric = 'dist';
    const today = new Date(now() * 1000), keys = [];
    for (let i = 29; i >= 0; i--) { const d = new Date(today); d.setDate(today.getDate() - i); keys.push(ymd(d)); }
    const km = k => (s.days && s.days[k] ? s.days[k].Distance : 0) || (s.trips && s.trips[k] ? s.trips[k].dist : 0);
    const kwh = k => (s.days && s.days[k] ? s.days[k].Consumption : 0);
    const value = k => metric === 'kwh' ? kwh(k) : metric === 'eff' ? (km(k) > 0 && kwh(k) > 0 ? kwh(k) / km(k) * 100 : 0) : km(k);
    const unit = metric === 'kwh' ? ' kWh' : metric === 'eff' ? ' ' + T('kWh/100 km') : ' km';
    const digits = metric === 'dist' ? 0 : 1;
    const max = Math.max(1, ...keys.map(value));
    if (!selDay || !keys.includes(selDay)) selDay = keys.slice().reverse().find(k => value(k) > 0) || keys[keys.length - 1];

    let html = '';
    if (hasEnergy) {
      html += '<div class="chips-row">' + [['dist', T('Distance')], ['kwh', T('Consumption')], ['eff', T('kWh/100 km')]]
        .map(m => '<button class="pill' + (metric === m[0] ? ' on' : '') + '" data-act="metric" data-v="' + m[0] + '">' + esc(m[1]) + '</button>').join('') + '</div>';
    }
    html += '<section class="card chart"><div class="bars">' + keys.map(k => {
      const v = value(k);
      return '<button class="bar-col' + (k === selDay ? ' sel' : '') + '" data-act="bar" data-v="' + esc(k) + '" title="' + esc(dayLabel(k) + ': ' + num(v, digits) + unit) + '">'
        + '<i style="height:' + (v > 0 ? Math.max(3, v / max * 100) : 0) + '%"></i></button>';
    }).join('') + '</div><div class="axis"><span>' + esc(dayLabel(keys[0])) + '</span><span>' + esc(dayLabel(keys[keys.length - 1])) + '</span></div>';

    // Gewählter Tag
    const d = s.days && s.days[selDay];
    html += '<div class="sel-day"><b>' + esc(dayLabel(selDay)) + '</b><span>' + esc(num(km(selDay)) + ' km' + (kwh(selDay) ? ' · ' + num(kwh(selDay), 1) + ' kWh' : '')) + '</span></div>';
    if (d && d.Consumption > 0) html += breakdown(d, d.Consumption);
    html += '</section>';

    if (hasEnergy) {
      const sum = { Consumption: 0, Distance: 0, Regeneration: 0 };
      PARTS.forEach(p => { sum[p[0]] = 0; });
      let drivingDays = 0;
      statDays(s).forEach(k => {
        const day = s.days[k];
        Object.keys(sum).forEach(key => { sum[key] += day[key] || 0; });
        if (day.Distance > 0) drivingDays++;
      });
      html += '<section class="card list"><h3>' + esc(T('Last 30 days')) + '</h3>'
        + '<div class="summary">' + box(num(sum.Consumption, 1), T('kWh consumption'))
        + box(sum.Distance > 0 ? num(sum.Consumption / sum.Distance * 100, 1) : '–', T('kWh/100 km'))
        + box(drivingDays ? num(sum.Distance / drivingDays) : '–', T('km per driving day')) + '</div>'
        + breakdown(sum, sum.Consumption)
        + regeneration(sum) + '</section>';
    } else {
      // Ohne Energiedaten (Verbrenner): Zusammenfassung aus dem Fahrtenbuch
      let km = 0, trips = 0, minutes = 0, drivingDays = 0;
      tripDays(s).forEach(k => { const e = s.trips[k]; km += e.dist; trips += e.count; minutes += e.drive; if (e.dist > 0) drivingDays++; });
      html += '<section class="card list"><h3>' + esc(T('Last 30 days')) + '</h3>'
        + '<div class="summary">' + box(num(km), T('km distance')) + box(drivingDays ? num(km / drivingDays) : '–', T('km per driving day'))
        + box(minutes > 0 ? num(km / minutes * 60) : '–', T('km/h average speed')) + '</div></section>';
    }
    return html;
  }

  // „Antrieb“ ist schon nach Abzug der Rekuperation gerechnet; der Anteil bezieht sich deshalb auf
  // die gesamte Antriebsenergie (Antrieb + Rekuperation), sonst wirkt der Wert zu hoch.
  function regeneration(sum) {
    const gross = (sum.Drive || 0) + (sum.Regeneration || 0);
    const share = gross > 0 ? '<small>' + esc(T('%s of the drive energy recovered', num(sum.Regeneration / gross * 100) + ' %')) + '</small>' : '';
    return '<div class="row">' + I('leaf') + '<span>' + esc(T('Regeneration')) + share + '</span><b>' + esc(num(sum.Regeneration, 1) + ' kWh') + '</b></div>';
  }

  function breakdown(d, total) {
    const parts = PARTS.filter(p => d[p[0]] > 0);
    if (!parts.length || total <= 0) return '';
    return '<div class="stack">' + parts.map(p => '<i style="width:' + (d[p[0]] / total * 100).toFixed(1) + '%;background:' + p[1] + '"></i>').join('') + '</div>'
      + '<div class="legend">' + parts.map(p => '<span><i style="background:' + p[1] + '"></i>' + esc(T(PART_LABEL[p[0]])) + ' <b>' + num(d[p[0]], 1) + ' kWh</b></span>').join('') + '</div>';
  }

  function mini(s) {
    const pct = s.caps.ev ? s.soc : s.fuel;
    return '<div class="mini-view">' + ring(pct, levelColor(pct), num(pct) + ' %', esc(s.range > 0 ? num(s.range) + ' km' : ''))
      + '<span class="mini-lock ' + (s.locked ? 'ok' : 'warn') + '">' + I(s.locked ? 'lock' : 'lockOpen') + '</span>'
      + (s.caps.ev && s.charging === 2 ? '<span class="mini-charge">' + I('bolt') + '</span>' : '') + '</div>';
  }

  // ── Zeichnen ───────────────────────────────────────────────────────

  function render() {
    if (!state) return;
    const s = state, m = mode();
    const title = [s.vehicle.model, s.vehicle.year].filter(Boolean).join(' · ');
    $('model').textContent = title || T('Vehicle');
    // Stand des Autos, nicht der Cloud-Abfrage; die steht im Tooltip
    $('sub').textContent = s.carTime > 0 ? T('Vehicle data: %s', ago(s.carTime)) : T('Updated %s', ago(s.updated));
    $('sub').title = T('Cloud queried %s', ago(s.updated));
    $('chips').innerHTML = '<span class="chip ' + (s.online ? 'ok' : 'bad') + '">' + esc(s.online ? T('Online') : T('Offline')) + '</span>';
    $('btnRefresh').innerHTML = I('refresh');
    $('btnRefresh').title = T('Refresh');
    $('btnRefresh').classList.toggle('spin', !!refreshing);
    $('btnRefresh').disabled = !!refreshing;
    const wakeAsk = Date.now() < (confirm.wake || 0);
    $('btnWake').hidden = !s.caps.vehicleRefresh;
    $('btnWake').innerHTML = I('wake');
    $('btnWake').classList.toggle('ask', wakeAsk);
    $('btnWake').title = wakeAsk ? T('Tap again to wake the vehicle') : T('Wake vehicle (drains the 12V battery)');
    $('notice').hidden = !s.error;
    $('notice').innerHTML = s.error ? I('warning') + '<span>' + esc(s.error) + '</span>' : '';

    const tabs = [['overview', T('Overview')]];
    if (tripDays(s).length) tabs.push(['log', T('Trip log')]);
    if (statDays(s).length || tripDays(s).length) tabs.push(['stats', T('Statistics')]);
    if (!tabs.some(t => t[0] === tab)) tab = 'overview';
    $('tabs').hidden = m !== 'full' || tabs.length < 2;
    $('tabs').innerHTML = tabs.map(t => '<button class="tab' + (t[0] === tab ? ' on' : '') + '" data-act="tab" data-v="' + t[0] + '">' + esc(t[1]) + '</button>').join('');

    const body = $('body'), top = body.scrollTop;
    if (m === 'mini') body.innerHTML = mini(s);
    else if (m === 'compact') body.innerHTML = compact(s);
    else body.innerHTML = tab === 'log' ? tripLog(s) : tab === 'stats' ? statistics(s) : overview(s);
    body.scrollTop = top;
  }

  // ── Bedienung ──────────────────────────────────────────────────────

  function setpoint(key, value, ident) {
    const p = pending[key] || (pending[key] = {});
    p.value = value;
    p.until = Date.now() + SEND_MS + 8000; // bis der Zustand den Wert zurückmeldet
    clearTimeout(p.timer);
    p.timer = setTimeout(() => { action(ident, value); busyUntil = Date.now() + 5000; render(); }, SEND_MS);
    render();
  }

  // Karte als Ebene in der Kachel: window.open verlässt in der Symcon-App die Kachel ohne Rückweg.
  // Die eingebettete OpenStreetMap lädt erst beim Öffnen und wird beim Schließen wieder entladen.
  function openMap(pos) {
    if (!pos) return;
    const lat = Number(pos.lat), lon = Number(pos.lon), dLat = 0.004, dLon = 0.007;
    if (!isFinite(lat) || !isFinite(lon)) return;
    $('mapFrame').title = T('Position');
    $('mapFrame').src = 'https://www.openstreetmap.org/export/embed.html?bbox=' + [lon - dLon, lat - dLat, lon + dLon, lat + dLat].join(',')
      + '&layer=mapnik&marker=' + lat + ',' + lon;
    $('mapClose').innerHTML = I('close');
    $('mapClose').title = T('Close');
    $('mapLayer').hidden = false;
    document.querySelector('.app').classList.add('map-open');
  }
  function closeMap() {
    $('mapLayer').hidden = true;
    document.querySelector('.app').classList.remove('map-open');
    $('mapFrame').src = 'about:blank';
  }
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && !$('mapLayer').hidden) closeMap(); });

  function stopRefresh() {
    if (!refreshing) return;
    clearTimeout(refreshing.timer);
    refreshing = null;
    render();
  }

  function ask(key) {
    if (Date.now() < (confirm[key] || 0)) { confirm[key] = 0; return true; }
    confirm[key] = Date.now() + CONFIRM_MS;
    setTimeout(render, CONFIRM_MS + 50);
    render();
    return false;
  }

  document.addEventListener('click', e => {
    const el = e.target.closest('[data-act]');
    if (!el || el.disabled || !state) return;
    const s = state, act = el.dataset.act, v = el.dataset.v;
    switch (act) {
      case 'tab': tab = v; $('body').scrollTop = 0; render(); break;
      case 'day': if (openDays.has(v)) openDays.delete(v); else openDays.add(v); render(); break;
      case 'metric': metric = v; render(); break;
      case 'bar': selDay = v; render(); break;
      case 'older': if (!olderRequest) loadOlder(v); break;
      case 'refresh':
        refreshing = { updated: s.updated, error: s.error, timer: setTimeout(stopRefresh, 30000) };
        action('TileRefresh', true);
        render();
        break;
      case 'wake': if (ask('wake')) action('TileVehicleRefresh', true); break;
      case 'lock':
        if (s.locked && !ask('unlock')) break;
        busyUntil = Date.now() + 5000;
        action('DoorsLocked', !s.locked);
        render();
        break;
      case 'climate': busyUntil = Date.now() + 5000; action('ClimateOn', !s.climate); render(); break;
      case 'charge': busyUntil = Date.now() + 5000; action('ChargeAction', s.charging !== 2); render(); break;
      case 'temp': {
        const t = Math.max(14, Math.min(30, pendingValue('temp', s.target || 22) + parseFloat(el.dataset.d)));
        setpoint('temp', t, 'TargetTempC');
        break;
      }
      case 'limit': {
        const key = el.dataset.k;
        const l = Math.max(50, Math.min(100, pendingValue(key, s['limit' + key]) + parseInt(el.dataset.d, 10)));
        setpoint(key, l, 'ChargeLimit' + key);
        break;
      }
      case 'map': openMap(s.pos); break;
      case 'mapclose': closeMap(); break;
    }
  });

  function receive(data) {
    let msg;
    try { msg = typeof data === 'string' ? JSON.parse(data) : data; } catch (e) { return; }
    if (msg && msg.type === 'trips') {
      // Nur die eigene Anfrage, und nur einmal (jede Nachricht kommt doppelt)
      if (olderRequest && msg.rid === olderRequest.rid) {
        clearTimeout(olderRequest.timer);
        Object.assign(older, msg.days || {});
        loadedMonths.add(msg.month);
        olderRequest = null;
        render();
      }
      return;
    }
    if (!msg || msg.type !== 'state') return;
    state = msg;
    if (typeof msg.now === 'number') offset = msg.now - Date.now() / 1000;
    // Gemeldete Sollwerte beenden die lokale Vorschau
    [['temp', msg.target], ['AC', msg.limitAC], ['DC', msg.limitDC]].forEach(([key, value]) => {
      if (pending[key] && pending[key].value === value) pending[key].until = 0;
    });
    if (msg.command) busyUntil = 0;
    // Neuer Abruf oder Fehler beendet die Drehung
    if (refreshing && (msg.updated !== refreshing.updated || msg.error !== refreshing.error)) { clearTimeout(refreshing.timer); refreshing = null; }
    render();
  }
  window.handleMessage = receive;
  window.addEventListener('message', ev => {
    if (ev.source !== window && ev.source !== window.parent) return;
    receive(ev.data);
  });
  window.addEventListener('resize', () => { layout(); render(); });

  layout();
  receive(cfg.state);
  setInterval(render, 60000); // relative Zeiten nachführen (keine Animation)
})();
