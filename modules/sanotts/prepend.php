<?php
// Подключает клиентский плеер sanoTTS на страницы MajorDoMo.
// Загружается общим modules/prepend.php (он же обслуживает piper_tts и vosk).
if (PHP_SAPI === 'cli') return;
if (!isset($_SERVER['REQUEST_URI'])) return;
$uri = $_SERVER['REQUEST_URI'];
if (preg_match('#/(admin|popup/|objects/|api(\.php)?/|ajax/)#', $uri)) return;
if (isset($_GET['system_call']) || isset($_GET['ajax'])) return;
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') return;

ob_start(function ($buffer) {
    $ct = '';
    foreach (headers_list() as $h) {
        if (stripos($h, 'Content-Type:') === 0) {
            $ct = trim(substr($h, 13));
            break;
        }
    }
    if (strpos($ct, 'text/html') === false && strpos($ct, 'text/plain') === false) {
        return $buffer;
    }
    $isGzip = strlen($buffer) > 2 && ord($buffer[0]) === 0x1f && ord($buffer[1]) === 0x8b;
    if ($isGzip) {
        $buffer = gzdecode($buffer);
        if ($buffer === false) return false;
    }
    // Версия в URL — по mtime самого JS, чтобы правка скрипта сбрасывала кэш браузера.
    $jsFile = __DIR__ . '/../../templates/sanotts/js/sanotts.js';
    $jsVer = is_file($jsFile) ? (string)filemtime($jsFile) : (string)filemtime(__FILE__);
    // Порт WebSocket — тот же, что у сокета самого MajorDoMo (<#WEBSOCKETS_PORT#> в его шаблонах).
    $wsPort = defined('WEBSOCKETS_PORT') && (int)WEBSOCKETS_PORT > 0 ? (int)WEBSOCKETS_PORT : 8001;
    // Терминал этой вкладки — из сессии MajorDoMo (?terminal=… или вход с IP из поля
    // «Хост» терминала): фразы sayTo() на этот терминал играет только она.
    $term = '';
    global $session;
    if (is_object($session) && isset($session->data['TERMINAL'])) {
        $term = (string)$session->data['TERMINAL'];
    } elseif (isset($_SESSION['DATA']) && is_string($_SESSION['DATA'])) {
        $d = @unserialize($_SESSION['DATA'], array('allowed_classes' => false));
        if (is_array($d) && isset($d['TERMINAL'])) $term = (string)$d['TERMINAL'];
    }
    $termJs = $term !== '' ? 'window.SANOTTS_TERMINAL=' . json_encode($term, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . ';' : '';
    $script = '<script>' . $termJs . 'window.SANOTTS_WS_PORT=window.SANOTTS_WS_PORT||' . $wsPort . ';if(window.top===window.self){var s=document.createElement("script");s.src="/templates/sanotts/js/sanotts.js?' . $jsVer . '";document.body.appendChild(s)}</script>';
    // Только в настоящие страницы. Служебные ответы (запуск заданий и таймеров
    // /objects/?system_call=1&job=… отвечает «OK», AJAX, API) тоже бывают text/html
    // без </body> — дописанный к ним скрипт ломал проверку «OK»: ядро считало
    // задание неудачным и запускало его снова каждые 5 секунд.
    if (($pos = strripos($buffer, '</body>')) === false) {
        return $isGzip ? false : $buffer;
    }
    $buffer = substr_replace($buffer, $script . "\n</body>", $pos, 7);
    if ($isGzip) {
        $buffer = gzencode($buffer);
    }
    return $buffer;
});
