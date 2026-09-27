<?php
if (PHP_SAPI === 'cli') return;
if (!isset($_SERVER['REQUEST_URI'])) return;
$uri = $_SERVER['REQUEST_URI'];
if (preg_match('#/(admin|popup/)#', $uri)) return;
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
    // Версия в URL должна строиться по времени изменения самого скрипта.
    // При filemtime(__FILE__) любая правка JS оставляла бы URL прежним, и
    // браузер продолжал бы отдавать старую версию из своего кэша: у Apache
    // для этого файла нет Cache-Control, только Last-Modified + ETag.
    $jsFile = __DIR__ . '/../../templates/piper_tts/js/piper_tts.js';
    $jsVer = is_file($jsFile) ? (string)filemtime($jsFile) : (string)filemtime(__FILE__);
    $script = '<script>if(window.top===window.self){var s=document.createElement("script");s.src="/templates/piper_tts/js/piper_tts.js?' . $jsVer . '";document.body.appendChild(s)}</script>';
    if (($pos = stripos($buffer, '</body>')) !== false) {
        $buffer = substr_replace($buffer, $script . "\n</body>", $pos, 7);
    } else {
        $buffer .= "\n" . $script;
    }
    if ($isGzip) {
        $buffer = gzencode($buffer);
    }
    return $buffer;
});
