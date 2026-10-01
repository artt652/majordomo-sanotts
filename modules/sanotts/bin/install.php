<?php
/**
 * Установка движка sanoTTS из консоли — Linux, Termux и Windows одинаково:
 *
 *   php modules/sanotts/bin/install.php [--voice=irina] [--base=DIR] [--voices-dir=DIR]
 *
 * Запускайте от того же пользователя, что и веб-сервер (Linux:
 * sudo -u www-data php …), иначе модуль не сможет потом ставить голоса.
 * По умолчанию движок ставится в <корень MajorDoMo>/cms/sanotts.
 *
 * Кнопка «Установить движок» запускает этот же файл в фоне с --job=<файл>.
 */
if (PHP_SAPI !== 'cli') {
    header('HTTP/1.0 403 Forbidden');
    exit;
}
require dirname(__FILE__) . '/installer.php';

$opt = getopt('', array('voice:', 'base:', 'voices-dir:', 'job:', 'help'));
if (isset($opt['help'])) {
    echo "php install.php [--voice=irina] [--base=DIR] [--voices-dir=DIR]\n";
    exit(0);
}

$native = dirname(dirname(__FILE__)) . '/native';
if (isset($opt['job'])) {
    // Фоновый запуск из модуля: параметры и журнал — в каталоге попытки.
    $job = @json_decode((string)@file_get_contents($opt['job']), true);
    if (!is_array($job) || empty($job['base']) || empty($job['log'])) {
        fwrite(STDERR, "bad job file: {$opt['job']}\n");
        exit(2);
    }
    $inst = new SanottsEngine($native, $job['base'], $job['voices_dir'], $job['voice'], $job['log'], false);
} else {
    $root = dirname(dirname(dirname(dirname(__FILE__))));   // modules/sanotts/bin → корень
    $base = isset($opt['base']) ? $opt['base'] : $root . '/cms/sanotts';
    $voices = isset($opt['voices-dir']) ? $opt['voices-dir'] : SanottsEngine::trimDir($base) . '/voices';
    $voice = isset($opt['voice']) ? $opt['voice'] : 'irina';
    $log = SanottsEngine::tmpDir() . '/sanotts_install_manual.log';
    @unlink($log);
    $inst = new SanottsEngine($native, $base, $voices, $voice, $log, true);
}
exit($inst->install() ? 0 : 1);
