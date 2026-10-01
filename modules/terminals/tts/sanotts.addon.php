<?php

/**
 * TTS для модуля «Терминалы»: тип TTS «sanotts» — речь sanoTTS из динамика
 * сервера (paplay / aplay / ffplay / play-audio в Termux, в Windows — плеер
 * PowerShell).
 *
 * «Терминалы» подключают этот файл и создают объект класса с именем типа —
 * new sanotts($terminal). Класс sanotts — это сам модуль, поэтому здесь нового
 * класса нет (второй класс с тем же именем в одном процессе обработки SAY дал
 * бы «Cannot redeclare class»): модуль сам умеет say(), ask() и sayCached().
 * Синтез и кэш — общие с браузером.
 */
if (!class_exists('sanotts', false)) include_once DIR_MODULES . 'sanotts/sanotts.class.php';
