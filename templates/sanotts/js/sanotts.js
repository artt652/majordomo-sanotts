(function () {
  if (window.__sanottsPlayerLoaded) return;
  window.__sanottsPlayerLoaded = true;

  var ws = null;
  var host = window.location.hostname;
  // Порт задаёт prepend.php (WEBSOCKETS_PORT из config.php). 8001 — порт ядра по умолчанию,
  // для страниц вне MajorDoMo, куда скрипт вставлен вручную без window.SANOTTS_WS_PORT.
  var port = window.SANOTTS_WS_PORT || 8001;
  var queue = [];
  var playing = false;

  function warn(msg, err) {
    if (window.console) window.console.warn('sanotts: ' + msg, err || '');
  }

  // Основной путь — Web Audio: каждый файл скачивается и декодируется сразу,
  // как пришёл, и ставится в расписание точно встык к предыдущему. Между
  // предложениями нет паузы на загрузку, и не нужно ждать событий <audio>
  // (мобильные браузеры и Safari без жеста пользователя их не присылают —
  // прежний плеер ждал тогда по 3 секунды перед каждым файлом).
  var AC = window.AudioContext || window.webkitAudioContext;
  var ctx = null, chain = null, playEnd = 0, warnedLocked = false;

  function audioCtx() {
    if (!AC) return null;
    if (!ctx) { try { ctx = new AC(); } catch (e) { ctx = null; AC = null; } }
    return ctx;
  }

  // Браузер разрешает звук только после жеста пользователя — первый же клик,
  // касание или клавиша на странице «разблокирует» воспроизведение.
  ['click', 'touchstart', 'keydown'].forEach(function (ev) {
    document.addEventListener(ev, function () {
      var c = audioCtx();
      if (c && c.state === 'suspended') c.resume();
    }, { passive: true, capture: true });
  });

  function decode(c, data) {
    return new Promise(function (resolve, reject) {
      var p = c.decodeAudioData(data, resolve, reject);   // Safari: только колбэки
      if (p && p.catch) p.catch(reject);
    });
  }

  function schedule(c, buf) {
    if (c.state === 'suspended') {
      c.resume();
      if (!warnedLocked) { warnedLocked = true; warn('звук заблокирован браузером до первого клика по странице'); }
    }
    var src = c.createBufferSource();
    src.buffer = buf;
    src.connect(c.destination);
    var at = Math.max(c.currentTime + 0.03, playEnd);
    src.start(at);
    playEnd = at + buf.duration;
  }

  function playSound(url) {
    var c = audioCtx();
    if (!c || !window.fetch || !window.Promise) { htmlPlay(url); return; }
    // Загрузка и декодирование — параллельно, в расписание — строго по порядку прихода.
    var ready = fetch(url, { cache: 'force-cache' })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.arrayBuffer(); })
      .then(function (data) { return decode(c, data); });
    chain = (chain || Promise.resolve()).then(function () {
      return ready.then(function (buf) { schedule(c, buf); },
                        function (err) { warn('не удалось загрузить ' + url, err); htmlPlay(url); });
    });
  }

  // Запасной путь — элемент <audio> (нет Web Audio или файл не декодировался).
  // Каждый файл начинает загружаться сразу, как пришёл.
  function htmlPlay(url) {
    var audio = new Audio();
    audio.preload = 'auto';
    audio.src = url;
    try { audio.load(); } catch (e) {}
    queue.push({ url: url, audio: audio });
    next();
  }

  function next() {
    if (playing || !queue.length) return;
    playing = true;
    var item = queue.shift();
    var audio = item.audio;
    var finished = false;
    function done() { if (finished) return; finished = true; playing = false; next(); }
    audio.addEventListener('ended', done, { once: true });
    audio.addEventListener('error', function () { warn('ошибка загрузки ' + item.url); done(); }, { once: true });
    // Сразу play(): браузер сам дождётся данных; ждать canplaythrough нельзя —
    // без жеста пользователя часть браузеров его не присылает.
    var p = audio.play();
    if (p && p.catch) {
      p.catch(function (err) { warn('не удалось воспроизвести', err); done(); });
    }
  }

  // Для отладки из консоли браузера: sanottsPlay('/cms/cached/voice/….wav')
  window.sanottsPlay = playSound;

  function connect() {
    if (ws && (ws.readyState === WebSocket.OPEN || ws.readyState === WebSocket.CONNECTING)) return;
    var sock = new WebSocket((location.protocol === 'https:' ? 'wss://' : 'ws://') + host + ':' + port + '/majordomo');
    ws = sock;

    sock.onopen = function () {
      if (sock.readyState !== WebSocket.OPEN) return;
      try {
        sock.send(JSON.stringify({ action: 'subscribe', data: { TYPE: 'events', EVENTS: 'SANOTTS' } }));
      } catch (e) {}
    };

    sock.onmessage = function (evt) {
      try {
        var msg = JSON.parse(evt.data);
        if (msg.action !== 'events' || !msg.data) return;
        var payload = typeof msg.data === 'string' ? JSON.parse(msg.data) : msg.data;
        if (!payload.EVENT_DATA || payload.EVENT_DATA.NAME !== 'SANOTTS') return;
        var data = payload.EVENT_DATA.VALUE;
        if (!data || data.COMMAND !== 'PlayAudio' || !data.URL) return;
        // Вкладка терминала играет только фразы своего терминала (их присылает модуль
        // «Терминалы» с учётом порога уровня терминала); вкладка без терминала — общие.
        var mine = String(window.SANOTTS_TERMINAL || '').toUpperCase();
        if (String(data.TERMINAL || '').toUpperCase() !== mine) return;
        playSound(data.URL);
      } catch (e) {}
    };

    sock.onclose = function () {
      if (ws === sock) ws = null;
      if (!document.hidden) setTimeout(connect, 5000);
    };

    sock.onerror = function () {
      try { sock.close(); } catch (e) {}
    };
  }

  function disconnect() {
    if (ws) {
      ws.onclose = null;
      ws.close();
      ws = null;
    }
  }

  // Звук получает только активная вкладка, чтобы фраза не звучала из всех окон сразу.
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) disconnect(); else connect();
  });

  if (!document.hidden && window.WebSocket) connect();
})();
