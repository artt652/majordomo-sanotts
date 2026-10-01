<?php

/**
 * sanoTTS — модуль синтеза речи для MajorDoMo на движке sanoTTS
 * (https://github.com/Ampixa/sanoTTS). Построен по образцу piper_tts.
 *
 * Движок — нативная утилита sanotts_cli (~0,5 МБ): тот же C-код, которым
 * голоса sanoTTS звучат в браузере, со встроенным espeak-ng; готовые сборки
 * для Linux, Android/Termux и Windows. Ни Python, ни демона: одна фраза —
 * один запуск, с загрузкой голоса около секунды на x86.
 */
require_once dirname(__FILE__) . '/bin/installer.php';
require_once dirname(__FILE__) . '/lib/rules.php';

class sanotts extends module
{
    const VERSION = '3.2.0';
    // Движок живёт внутри MajorDoMo (cms/sanotts, см. defaultBase()): туда
    // пишет пользователь веб-сервера на любой системе — Debian, Docker, Termux —
    // без root и sudo.
    // Сведения о последнем запуске установки: {"t": время, "run": каталог попытки}.
    // Если скрипт за это время не начал писать журнал — он не запустился.
    const INSTALL_START_TIMEOUT = 45;
    const INSTALL_MAX_TIME = 1800;
    // Установщик пишет в журнал хотя бы раз в 10 с (ход загрузки); если журнал
    // молчит дольше — процесс убит (тайм-аут веб-сервера, перезапуск и т. п.).
    const INSTALL_STALE_TIME = 300;

    /** Запись терминала, когда объект создан модулем «Терминалы» (тип TTS «sanotts»). */
    public $terminal = null;

    function __construct($terminal = null)
    {
        if (is_array($terminal)) $this->terminal = $terminal;
        $this->name = 'sanotts';
        $this->title = 'sanoTTS';
        $this->module_category = '<#LANG_SECTION_APPLICATIONS#>';
        $this->checkInstalled();
    }

    // Старый стиль конструктора ядро MajorDoMo тоже вызывает — оставляем.
    function sanotts()
    {
        $this->__construct();
    }

    function saveParams($data = 0)
    {
        $p = array();
        if (isset($this->id)) $p['id'] = $this->id;
        if (isset($this->view_mode)) $p['view_mode'] = $this->view_mode;
        if (isset($this->edit_mode)) $p['edit_mode'] = $this->edit_mode;
        if (isset($this->tab)) $p['tab'] = $this->tab;
        return parent::saveParams($p);
    }

    function getParams()
    {
        global $id, $mode, $view_mode, $edit_mode, $tab;
        if (isset($id)) $this->id = $id;
        if (isset($mode)) $this->mode = $mode;
        if (isset($view_mode)) $this->view_mode = $view_mode;
        if (isset($edit_mode)) $this->edit_mode = $edit_mode;
        if (isset($tab)) $this->tab = $tab;
    }

    /** Каталог движка по умолчанию: <корень MajorDoMo>/cms/sanotts. */
    public static function defaultBase()
    {
        $root = defined('ROOT') ? SanottsEngine::trimDir(ROOT) : dirname(dirname(dirname(__FILE__)));
        return $root . '/cms/sanotts';
    }

    /** Фраза для кнопки «Проверить» на языке голоса (ключ — словарь espeak). */
    public static function samplePhrases()
    {
        return SanottsEngine::samplePhrases();
    }

    /** Android (в том числе Termux). */
    public static function isAndroid()
    {
        return SanottsEngine::isAndroid();
    }

    /** Временный каталог: в Termux это $PREFIX/tmp, в XAMPP — C:\xampp\tmp. */
    public static function tmpDir()
    {
        return SanottsEngine::tmpDir();
    }

    private static function installStateFile()
    {
        return self::tmpDir() . '/sanotts-install.json';
    }

    public static function defaults()
    {
        return array(
            'BASE'             => self::defaultBase(),
            'CLI'              => SanottsEngine::binDir(self::defaultBase()) . '/' . SanottsEngine::cliName(),
            'VOICES_DIR'       => self::defaultBase() . '/voices',
            'VOICE'            => 'irina',
            'LENGTH_SCALE'     => '1.00',
            'PITCH'            => '0',
            'BASS'             => '0',
            'TREBLE'           => '0',
            'SENTENCE_SILENCE' => '0.15',
            'LEAD_SILENCE'     => '1.0',
            'NORMALIZE'        => '1',
            'LOUDNESS'         => '-16',
            'USE_CACHE'        => '1',
            'CACHE_DIR'        => dirname(self::defaultBase()) . '/cached/voice',
            'CACHE_CLEANUP'    => '1',
            'TASHKEEL'         => '1',
            'STREAM'           => '1',
            'RU_RULES'         => '1',
        );
    }

    function getConfig()
    {
        parent::getConfig();
        $dirty = false;

        foreach (self::defaults() as $k => $v) {
            if (!isset($this->config[$k]) || trim((string)$this->config[$k]) === '') {
                $this->config[$k] = $v;
            }
        }
        if (!isset($this->config['WS_PORT']) || !preg_match('/^\d+$/', (string)$this->config['WS_PORT'])) {
            $this->config['WS_PORT'] = defined('WEBSOCKETS_PORT') ? (string)WEBSOCKETS_PORT : '8001';
        }

        // Программы — во внутреннем каталоге приложения (Android, сайт на /storage,
        // см. SanottsEngine::binDir) — путь к sanotts_cli в настройках туда же.
        $base = SanottsEngine::trimDir($this->config['BASE']);
        $moved = SanottsEngine::binDir($base) . '/' . SanottsEngine::cliName();
        if ($this->config['CLI'] === $base . '/bin/' . SanottsEngine::cliName() && $moved !== $this->config['CLI'] && is_file($moved)) {
            $this->config['CLI'] = $moved;
            $dirty = true;
        }

        // Выбранного голоса нет, а другие установлены — берём первый, а не молчим.
        $dir = $this->voiceDir($this->config['VOICE']);
        if ($dir === null || !self::isVoiceDir($dir)) {
            $installed = $this->installedVoices();
            if ($installed) {
                $names = array_keys($installed);
                $this->config['VOICE'] = isset($installed['irina']) ? 'irina' : (isset($installed['russian']) ? 'russian' : $names[0]);
                $dirty = true;
            }
        }

        if ($dirty && method_exists($this, 'saveConfig')) {
            $this->saveConfig();
        }
    }

    /**
     * Что модуль видит на диске — для вкладки «Голоса». Когда голосов нет,
     * здесь сразу видно почему: не собран движок, нет каталога, нет прав,
     * open_basedir, голос не в том формате.
     */
    private function diagnostics()
    {
        $rows = array();
        $add = function ($name, $ok, $value) use (&$rows) {
            $rows[] = array('NAME' => htmlspecialchars((string)$name, ENT_QUOTES, 'UTF-8'), 'OK' => $ok ? '1' : '0', 'VALUE' => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'));
        };
        $user = function_exists('posix_geteuid') && function_exists('posix_getpwuid')
            ? (($pw = @posix_getpwuid(posix_geteuid())) ? $pw['name'] : posix_geteuid()) : (string)(getenv('USERNAME') ?: '?');
        $add('PHP user', true, $user . ' — PHP ' . PHP_VERSION . ', ' . PHP_OS . ' ' . SanottsEngine::arch());
        $obd = str_replace('\\', '/', (string)ini_get('open_basedir'));
        $baseN = str_replace('\\', '/', SanottsEngine::trimDir($this->config['BASE']));
        $rootN = defined('ROOT') ? str_replace('\\', '/', SanottsEngine::trimDir(ROOT)) : '/var/www';
        $add('open_basedir', $obd === '' || stripos($obd, $baseN) !== false || stripos($baseN, $rootN) === 0, $obd === '' ? '—' : $obd);

        $cli = $this->config['CLI'];
        $add('sanotts_cli', $this->cliReady(), $cli . (is_file($cli) ? (is_executable($cli) ? '' : ' (не исполняемый)') : ' (нет файла)'));
        if (is_file($cli)) {
            // Android запускает программы из каталога приложения через системный
            // загрузчик, а тот берёт только PIE (ET_DYN); Linux-сборка там — ET_EXEC.
            $hdr = (string)@file_get_contents($cli, false, null, 0, 18);
            if (SanottsEngine::isWindows()) {
                $okType = substr($hdr, 0, 2) === 'MZ';
                $what = 'Windows, ' . ($okType ? 'PE' : 'не .exe');
            } else {
                $etype = strlen($hdr) >= 18 ? ord($hdr[16]) | (ord($hdr[17]) << 8) : 0;
                $android = self::isAndroid();
                $okType = substr($hdr, 0, 4) === "\x7fELF" && ($android ? $etype === 3 : ($etype === 2 || $etype === 3));
                $what = ($android ? 'Android' : 'Linux') . ', ' . (substr($hdr, 0, 4) === "\x7fELF"
                    ? 'ELF ' . ($etype === 3 ? 'PIE' : ($etype === 2 ? 'static EXEC' : '?')) : 'не ELF');
            }
            $add('build', $okType, $what . ' (' . SanottsEngine::prebuiltName() . ')' .
                ($okType ? '' : ' — сборка не для этой системы, нажмите «Обновить движок»'));
        }

        $vd = $this->config['VOICES_DIR'];
        $add('voices dir', is_dir($vd) && is_readable($vd), $vd . (is_dir($vd) ? (is_readable($vd) ? '' : ' (нет прав на чтение)') : ' (нет каталога)'));
        foreach ((array)@scandir($vd) as $f) {
            if ($f === '.' || $f === '..' || $f === false || !is_dir($vd . '/' . $f)) continue;
            $d = $vd . '/' . $f;
            if (self::isVoiceDir($d)) {
                $vi = self::voiceInfo($d);
                $dictOk = $vi['dict'] === '' || is_file($this->dataDir() . '/' . $vi['dict'] . '_dict');
                $add('  ' . $f, $dictOk, $vi['language'] . ($dictOk ? ' — OK' : ' — нет словаря ' . $vi['dict'] . '_dict (нажмите «Обновить движок»)'));
            } else {
                $add('  ' . $f, false, 'нет meta.json / front_f*.bin / dec_f*.bin');
            }
        }

        $ed = rtrim($this->config['BASE'], '/') . '/share/espeak-ng-data';
        $add('espeak data', is_file($ed . '/ru_dict'), $ed . (is_file($ed . '/ru_dict') ? ' (ru_dict ' . round(filesize($ed . '/ru_dict') / 1048576, 1) . ' MB)' : ' (нет ru_dict)'));
        $hasAr = false;
        foreach ($this->installedVoices() as $vi) if ($vi['dict'] === 'ar') $hasAr = true;
        if ($hasAr) {
            $base = SanottsEngine::trimDir($this->config['BASE']);
            $tb = SanottsEngine::binDir($base) . '/' . SanottsEngine::tashkeelName();
            $tm = SanottsEngine::tashkeelModel($base);
            $ok = is_file($tb) && is_file($tm);
            $val = !(int)$this->config['TASHKEEL'] ? 'выключено в настройках'
                : ($ok ? 'OK' : (!is_file($tb) ? 'нет ' . $tb : 'нет модели ' . $tm) . ' — скачается при первом синтезе');
            if ($ok && (int)$this->config['TASHKEEL']) {
                $t0 = microtime(true);
                $d = SanottsEngine::diacritize($base, 'مرحبا بكم', $err);
                $val = $d !== null ? sprintf('OK, %.2f s: %s', microtime(true) - $t0, $d) : $err;
                $ok = $d !== null;
            }
            $add('tashkeel (ar)', $ok || !(int)$this->config['TASHKEEL'], $val);
        }
        $add('voice catalog', (bool)self::voiceCatalog(), implode(', ', array_keys(self::voiceCatalog())) . ' (скачиваются с GitHub)');
        $dl = array();
        if (function_exists('curl_init')) $dl[] = 'php-curl';
        if (ini_get('allow_url_fopen') && in_array('https', stream_get_wrappers(), true)) $dl[] = 'php https';
        foreach (array('curl', 'wget') as $t) if (SanottsEngine::haveTool($t)) $dl[] = $t;
        $add('downloader', (bool)$dl, $dl ? implode(', ', $dl) : 'нечем скачивать: включите php-curl или openssl (allow_url_fopen)');
        $php = SanottsEngine::phpCli();
        $add('php cli', true, $php !== null && !SanottsEngine::isWindows() ? $php . ' (установка — фоновым процессом)'
            : ($php !== null ? $php . '; ' : '') . 'установка идёт внутри запроса веб-сервера');
        $fns = array();
        foreach (array('proc_open', 'exec', 'shell_exec') as $fn) $fns[] = $fn . (self::functionEnabled($fn) ? ' ✔' : ' ✘');
        $add('php exec', self::functionEnabled('proc_open') || self::functionEnabled('exec') || self::functionEnabled('shell_exec'), implode(', ', $fns));
        $tmp = self::tmpDir() . '/sanotts_diag_' . getmypid() . '.wav';
        $t0 = microtime(true);
        $samples = self::samplePhrases();
        $lang = $this->currentLang();
        // Первое предложение примера («Проверка связи.») — проверить движок хватит, а ждать меньше.
        $sample = isset($samples[$lang]) ? $samples[$lang] : 'Test.';
        $parts = preg_split('/(?<=[.!?\x{3002}\x{FF01}])\s*/u', $sample, 2);
        $ok = $this->engineSynthesize(self::cleanText($parts[0], $lang), $tmp);
        $add('test synth', $ok, $ok ? sprintf('OK, %.2f s', microtime(true) - $t0) : $this->lastError);
        @unlink($tmp);
        $add('selected voice', ($d = $this->voiceDir($this->config['VOICE'])) !== null && self::isVoiceDir($d), $this->config['VOICE']);

        return $rows;
    }

    // ------------------------------------------------------------------
    // Помощники
    // ------------------------------------------------------------------

    private function log($msg)
    {
        if (function_exists('DebMes')) DebMes('sanotts: ' . $msg, 'sanotts');
    }

    private function e($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Ядро само подключает languages/<модуль>_<язык>.php, но не во всех
     * контекстах (cron, CLI, вызов из аддона). Без словаря PHP 8 падает на
     * первой же неопределённой константе LANG_*, поэтому подстраховываемся.
     */
    public static function loadLanguage()
    {
        if (defined('LANG_SANOTTS_TITLE')) return;
        $lang = defined('SETTINGS_SITE_LANGUAGE') ? SETTINGS_SITE_LANGUAGE : 'ru';
        $root = defined('ROOT') ? rtrim(ROOT, '/') : dirname(dirname(dirname(__FILE__)));
        foreach (array($lang, 'en', 'ru') as $l) {
            $file = $root . '/languages/sanotts_' . preg_replace('/[^a-z]/', '', $l) . '.php';
            if (is_file($file)) {
                include $file;
                return;
            }
        }
    }

    private function getWsPort()
    {
        if (defined('WEBSOCKETS_PORT')) return (int)WEBSOCKETS_PORT;
        $port = (int)$this->config['WS_PORT'];
        return $port > 0 ? $port : 8001;
    }

    private function isWsAlive()
    {
        $fp = @fsockopen('127.0.0.1', $this->getWsPort(), $errno, $errstr, 0.5);
        if ($fp === false) return false;
        fclose($fp);
        return true;
    }

    private function fatal($message)
    {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: text/html; charset=utf-8');
        echo '<html><body style="font-family:sans-serif;padding:40px">';
        echo '<p style="color:red">' . $this->e($message) . '</p>';
        echo '<script>setTimeout(function(){window.location.href="?action=sanotts&tab=models"},2000);</script>';
        echo '</body></html>';
        exit;
    }

    /**
     * Имя голоса — это имя каталога, оно уходит в путь, в rm и в командную
     * строку. Пропускаем только безопасный алфавит без точек в начале.
     */
    public static function sanitizeVoice($voice)
    {
        $voice = trim((string)$voice);
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $voice) ? $voice : null;
    }

    private function cliReady()
    {
        $cli = $this->config['CLI'];
        if ($cli === '' || !is_file($cli)) return false;
        // Запуск через загрузчик Android (.linker рядом): флаг «исполняемый» не нужен.
        return is_executable($cli) || is_file(dirname($cli) . '/.linker');
    }

    private static function isVoiceDir($dir)
    {
        return is_file($dir . '/meta.json') &&
            (glob($dir . '/front_f*.bin') ?: array()) && (glob($dir . '/dec_f*.bin') ?: array());
    }

    private static function voiceInfo($dir)
    {
        $meta = @json_decode((string)@file_get_contents($dir . '/meta.json'), true);
        $size = 0;
        foreach ((array)glob($dir . '/*.bin') as $f) $size += (int)@filesize($f);
        $ev = is_array($meta) && isset($meta['espeak_voice']) ? (string)$meta['espeak_voice'] : '';
        $params = 0;
        foreach (array('front_dims', 'dec_dims') as $k) {
            if (is_array($meta) && isset($meta[$k]['weight_floats'])) $params += (int)$meta[$k]['weight_floats'];
        }
        return array(
            'espeak'   => $ev,
            'dict'     => self::dictOf($ev),
            'language' => self::languageName(self::dictOf($ev)),
            'rate'     => is_array($meta) && isset($meta['sample_rate']) ? (int)$meta['sample_rate'] : 22050,
            'slot'     => is_array($meta) && isset($meta['g2p_voice_slot']) ? (int)$meta['g2p_voice_slot'] : 10,
            'size'     => $size,
            'params'   => $params,
            'gender'   => '', 'teacher' => '', 'wer' => '', 'scoreq' => '', 'speed' => '',
            'note'     => '',
        );
    }

    /** Установленные голоса: имя => info. */
    private function installedVoices()
    {
        $out = array();
        foreach ((array)glob(rtrim($this->config['VOICES_DIR'], '/') . '/*', GLOB_ONLYDIR) as $dir) {
            $name = basename($dir);
            if (self::sanitizeVoice($name) !== null && self::isVoiceDir($dir)) {
                $out[$name] = self::voiceInfo($dir);
            }
        }
        ksort($out);
        return $out;
    }

    /** Имя словаря espeak для голоса espeak (en-us → en, pt-br → pt). */
    public static function dictOf($espeakVoice)
    {
        $v = strtolower((string)$espeakVoice);
        if ($v === '') return '';
        if (in_array($v, array('en', 'en-us', 'en-gb'), true)) return 'en';
        if (in_array($v, array('pt', 'pt-br'), true)) return 'pt';
        return preg_replace('/[^a-z]/', '', $v);
    }

    /** Название языка по словарю — из native/data/voices.tsv. */
    public static function languageName($dict)
    {
        static $names = null;
        if ($names === null) {
            $names = array();
            $ru = !defined('SETTINGS_SITE_LANGUAGE') || SETTINGS_SITE_LANGUAGE == 'ru';
            foreach (self::tsv('voices.tsv') as $c) {
                if (count($c) >= 5) $names[$c[2]] = $ru ? $c[3] : $c[4];
            }
        }
        return isset($names[$dict]) ? $names[$dict] : $dict;
    }

    private static function tsv($name)
    {
        return SanottsEngine::tsv(dirname(__FILE__) . '/native/data/' . $name);
    }

    /**
     * Каталог голосов для скачивания: native/data/voices.tsv (язык, словарь,
     * параметры модели, пол, голос-учитель, оценки качества, скорость,
     * примечание) + native/data/sources.tsv (файлы, sha256, URL) — те же файлы
     * читает установщик.
     */
    private static function voiceCatalog()
    {
        $ru = !defined('SETTINGS_SITE_LANGUAGE') || SETTINGS_SITE_LANGUAGE == 'ru';
        $out = array();
        foreach (self::tsv('voices.tsv') as $c) {
            if (count($c) < 6 || self::sanitizeVoice($c[0]) === null) continue;
            $c = array_pad($c, 15, '');
            $out[$c[0]] = array('espeak' => $c[1], 'dict' => $c[2], 'language' => self::languageName($c[2]),
                'size' => (int)$c[5], 'params' => (int)$c[6], 'rate' => (int)$c[7], 'gender' => $c[8],
                'teacher' => $c[9], 'wer' => $c[10], 'scoreq' => $c[11], 'speed' => $c[12],
                'note' => $ru ? $c[13] : ($c[14] !== '' ? $c[14] : $c[13]), 'files' => array());
        }
        foreach (self::tsv('sources.tsv') as $c) {
            if (count($c) < 6 || $c[0] !== 'voice' || !isset($out[$c[1]])) continue;
            $out[$c[1]]['files'][$c[2]] = array($c[4], $c[5]);
        }
        return $out;
    }

    /** Число в местном формате: 1,57 по-русски, 1.57 по-английски. */
    private static function num($x, $dec)
    {
        $ru = !defined('SETTINGS_SITE_LANGUAGE') || SETTINGS_SITE_LANGUAGE == 'ru';
        return number_format((float)$x, $dec, $ru ? ',' : '.', $ru ? "\xc2\xa0" : ',');
    }

    /** «1,57 млн» / «512 тыс.» параметров. */
    private static function paramsText($n)
    {
        if ($n <= 0) return '';
        return $n >= 1000000 ? self::num($n / 1000000, 2) . ' ' . LANG_SANOTTS_PARAMS_M
            : self::num(round($n / 1000), 0) . ' ' . LANG_SANOTTS_PARAMS_K;
    }

    /**
     * Скорость голоса — относительно основного голоса russian: сама скорость
     * синтеза зависит от процессора, а соотношение между голосами — нет.
     */
    private static function speedText($speed)
    {
        static $base = null;
        if ($base === null) {
            $cat = self::voiceCatalog();
            $base = isset($cat['russian']) && $cat['russian']['speed'] !== '' ? (float)$cat['russian']['speed'] : 0.0;
        }
        if ($base <= 0 || $speed <= 0) return '';
        $r = $speed / $base;
        if ($r >= 0.9 && $r <= 1.1) return abs($r - 1) < 0.001 ? LANG_SANOTTS_SPEED_BASE : LANG_SANOTTS_SPEED_SAME;
        return $r > 1 ? sprintf(LANG_SANOTTS_SPEED_FASTER, self::num($r, 1)) : sprintf(LANG_SANOTTS_SPEED_SLOWER, self::num(1 / $r, 1));
    }

    /**
     * Описание голоса для списка: пол · размер модели · частота · учитель;
     * вторая строка — оценки качества и скорость. array(desc, quality).
     */
    private static function voiceDescription($info)
    {
        $d = array();
        if ($info['gender'] === 'f') $d[] = LANG_SANOTTS_GENDER_F;
        if ($info['gender'] === 'm') $d[] = LANG_SANOTTS_GENDER_M;
        if ($info['params'] > 0) $d[] = self::paramsText($info['params']);
        if ($info['rate'] > 0) $d[] = self::num($info['rate'] / 1000, $info['rate'] % 1000 ? 2 : 0) . ' ' . LANG_SANOTTS_KHZ;
        if ($info['teacher'] !== '') $d[] = LANG_SANOTTS_TEACHER . ' Piper ' . $info['teacher'];
        $q = array();
        if ($info['wer'] !== '') {
            // «cer:0.468» — для китайского: ошибки по иероглифам, не по словам.
            $metric = strpos($info['wer'], 'cer:') === 0 ? 'CER' : 'WER';
            $v = (float)preg_replace('/^cer:/', '', $info['wer']) * 100;
            $q[] = $metric . ' ' . self::num($v, $v < 10 ? 1 : 0) . '%';
        }
        if ($info['scoreq'] !== '') $q[] = 'SCOREQ ' . self::num($info['scoreq'], 2) . '/5';
        if ($info['speed'] !== '') $q[] = self::speedText((float)$info['speed']);
        if ($info['wer'] === '' && $info['scoreq'] === '' && $info['teacher'] !== '') array_unshift($q, LANG_SANOTTS_NOT_MEASURED);
        return array(implode(' · ', $d), implode(' · ', $q));
    }

    /** Словари для скачивания: имя => array(file, sha256, url). */
    private static function dictCatalog()
    {
        $out = array();
        foreach (self::tsv('sources.tsv') as $c) {
            if (count($c) >= 6 && $c[0] === 'dict' && $c[1] !== 'ru') $out[$c[1]] = array($c[2], $c[4], $c[5]);
        }
        return $out;
    }

    private function dataDir()
    {
        return SanottsEngine::trimDir($this->config['BASE']) . '/share/espeak-ng-data';
    }

    /**
     * Базовые данные espeak (фонемные таблицы и дерево lang/ с голосами всех
     * языков) приходят с модулем. После обновления модуля их сверяем по метке
     * и докладываем сами — без повторной установки движка.
     * Словари (*_dict) не трогаем.
     */
    private function syncBaseData()
    {
        $dst = $this->dataDir();
        $native = dirname(__FILE__) . '/native';
        $stamp = $dst . '/.base_version';
        $want = SanottsEngine::baseStamp($native);
        if (is_file($stamp) && trim((string)@file_get_contents($stamp)) === $want) return null;
        if (!is_dir($dst)) return 'нет данных espeak (' . $dst . ') — нажмите «Установить движок»';
        if (!is_writable($dst)) return 'нет прав на запись в ' . $dst . ' — нажмите «Обновить движок»';
        // Модуль обновился с новыми данными — докачиваем. Без интернета работаем на
        // прежних, повтор — не чаще раза в час.
        $try = $dst . '/.base_version.try';
        if (is_file($try) && time() - (int)@filemtime($try) < 3600) return null;
        @touch($try);
        $err = SanottsEngine::ensureFiles($native, 'base', 'espeak', $dst);
        if ($err !== null) { $this->log('espeak base data: ' . $err . ' — работают прежние'); return null; }
        @file_put_contents($stamp, $want);
        @unlink($try);
        $this->log('espeak base data updated in ' . $dst);
        return null;
    }

    /**
     * После обновления модуля установленный sanotts_cli может остаться старым, а
     * модуль — звать его с новыми параметрами. Поэтому программа сверяется с
     * sha256 из native/data/sources.tsv и при расхождении сама обновляется:
     * скачивается из репозитория движка (если настройки указывают на неё, а не
     * на свою сборку). Неудачная загрузка повторяется не чаще раза в час, чтобы
     * без интернета каждая фраза не ждала тайм-аута; работает прежняя версия.
     */
    private function syncBinary()
    {
        $base = SanottsEngine::trimDir($this->config['BASE']);
        $binDir = SanottsEngine::binDir($base);
        $native = dirname(__FILE__) . '/native';
        $cli = $this->config['CLI'];
        $progs = array();
        if (str_replace('\\', '/', $cli) === str_replace('\\', '/', $binDir . '/' . SanottsEngine::cliName()) && is_file($cli)) {
            $progs[] = SanottsEngine::cliName();
        }
        // Утилита огласовок нужна только арабскому голосу; обновляется, если уже стоит.
        if (is_file($binDir . '/' . SanottsEngine::tashkeelName())) $progs[] = SanottsEngine::tashkeelName();
        foreach ($progs as $name) {
            $c = SanottsEngine::engineSource($native, $name);
            if ($c === null) continue;
            $key = preg_replace('/\.exe$/', '', $name);
            $stamp = $binDir . '/.' . $key . '.sha256';
            if (trim((string)@file_get_contents($stamp)) === $c[4]) continue;
            $dst = $binDir . '/' . $name;
            if (SanottsEngine::matches($dst, $c)) { @file_put_contents($stamp, $c[4]); continue; }
            $try = $binDir . '/.' . $key . '.try';
            if (is_file($try) && time() - (int)@filemtime($try) < 3600) continue;
            @touch($try);
            list($src, $err) = SanottsEngine::obtainProgram($native, $base, $name);
            if ($err === null) $err = SanottsEngine::installProgram($src, $binDir, $name);
            if ($err !== null) { $this->log($name . ': новая версия не установлена (' . $err . '), работает прежняя'); continue; }
            @file_put_contents($stamp, $c[4]);
            @unlink($try);
            $this->log($name . ' updated: ' . $dst);
        }
    }

    /**
     * Огласовки для арабского: утилита sanotts_tashkeel и веса модели
     * (скачиваются). Ошибка — строка, успех — null.
     */
    private function ensureTashkeel()
    {
        $base = SanottsEngine::trimDir($this->config['BASE']);
        $native = dirname(__FILE__) . '/native';
        if (!is_file(SanottsEngine::binDir($base) . '/' . SanottsEngine::tashkeelName())) {
            $err = SanottsEngine::ensureTashkeelProgram($native, $base);
            if ($err !== null) return $err;
        }
        return SanottsEngine::ensureTashkeelModel($native, $base);
    }

    private static function download($url, $dest, $sha)
    {
        return SanottsEngine::fetch($url, $dest, $sha);
    }

    /**
     * Словарь espeak для языка голоса. Русский собирает установщик движка
     * (811 тыс. словоформ), остальные — готовые файлы, их качаем сами.
     */
    private function ensureDict($dict)
    {
        if ($dict === '') return null;
        $path = $this->dataDir() . '/' . $dict . '_dict';
        if ($dict === 'ru') {
            if (is_file($path)) return null;
            // Движок ставили с другим голосом — собираем русский словарь сами
            // (23 МБ списка словоформ + несколько секунд сборки), как установщик.
            if (!$this->cliReady() || !is_dir($this->dataDir())) return 'нет русского словаря — нажмите «Обновить движок»';
            @set_time_limit(0);
            $inst = new SanottsEngine(dirname(__FILE__) . '/native', $this->config['BASE'], $this->config['VOICES_DIR'], 'russian', '');
            $err = $inst->dictOnly('ru');
            $this->log('ru_dict build: ' . ($err === null ? 'ok' : $err));
            if ($err === null && !is_file($this->dataDir() . '/en_dict')) {
                $e2 = $this->ensureDict('en');
                if ($e2 !== null) $this->log('en_dict: ' . $e2);
            }
            return $err;
        }
        $cat = self::dictCatalog();
        if (!isset($cat[$dict])) return is_file($path) ? null : "словаря '$dict' нет в каталоге";
        list(, $sha, $url) = $cat[$dict];
        if (is_file($path) && hash_file('sha256', $path) === $sha) return null;
        if (!is_dir($this->dataDir())) return 'нет данных espeak — нажмите «Установить движок»';
        return self::download($url, $path, $sha);
    }

    /** Скачивание голоса (и словаря его языка) с проверкой sha256; ошибка — строка, успех — null. */
    private function downloadVoice($voice, $dir)
    {
        $cat = self::voiceCatalog();
        if (!isset($cat[$voice])) return 'unknown voice';
        $err = $this->syncBaseData();
        if ($err !== null) return $err;
        $err = $this->ensureDict($cat[$voice]['dict']);
        if ($err !== null) return $err;
        if ($cat[$voice]['dict'] === 'ar') {
            $err = $this->ensureTashkeel();
            // Голос работает и без огласовок — только пишем в журнал.
            if ($err !== null) $this->log('tashkeel: ' . $err);
        }
        $tmp = dirname($dir) . '/.' . $voice . '.new';
        self::removeTree($tmp);
        if (!@mkdir($tmp, 0755, true)) return 'cannot create ' . $tmp;
        foreach ($cat[$voice]['files'] as $file => $info) {
            list($sha, $url) = $info;
            $err = self::download($url, $tmp . '/' . $file, $sha);
            if ($err !== null) { self::removeTree($tmp); return $err; }
        }
        self::removeTree($dir);
        if (!@rename($tmp, $dir)) { self::removeTree($tmp); return 'cannot rename ' . $tmp; }
        return null;
    }

    /** Язык (словарь espeak) выбранного голоса: 'ru', 'de', 'en', … */
    private function currentLang()
    {
        $dir = $this->voiceDir($this->config['VOICE']);
        if ($dir === null || !is_file($dir . '/meta.json')) return 'ru';
        $info = self::voiceInfo($dir);
        return $info['dict'] !== '' ? $info['dict'] : 'ru';
    }

    private static function removeTree($dir)
    {
        return SanottsEngine::removeTree($dir);
    }

    /** Каталог голоса внутри VOICES_DIR — вторая линия защиты после sanitizeVoice. */
    private function voiceDir($voice)
    {
        $base = realpath($this->config['VOICES_DIR']);
        if ($base === false || self::sanitizeVoice($voice) === null) return null;
        return $base . '/' . $voice;
    }

    // ------------------------------------------------------------------
    // Синтез
    // ------------------------------------------------------------------

    /** Тишина в начале для текущего куска (у второго и далее предложений — пауза между ними). */
    private $leadOverride = null;

    private function getLeadSilence()
    {
        if ($this->leadOverride !== null) return $this->leadOverride;
        if (isset($this->config['LEAD_SILENCE']) && trim((string)$this->config['LEAD_SILENCE']) !== '') {
            return max(0.0, min(5.0, (float)$this->config['LEAD_SILENCE']));
        }
        return 1.0;
    }

    /**
     * Выравнивание громкости делает сам sanotts_cli: громкость речи по
     * BS.1770 (паузы и вступительная тишина не считаются), усиление до цели
     * и лимитер с упреждением — короткие и длинные фразы звучат одинаково.
     * «off» — только пик на −2 dBFS, как без выравнивания.
     */
    private function loudnessArg()
    {
        if (!(int)$this->config['NORMALIZE']) return 'off';
        return (string)max(-30, min(-10, (int)$this->config['LOUDNESS']));
    }

    /** Сырой синтез в WAV через sanotts_cli; текст идёт через stdin. */
    /** Текст последней ошибки синтеза — показывается кнопкой «Проверить» и в диагностике. */
    private $lastError = '';

    private static function functionEnabled($name)
    {
        return SanottsEngine::functionEnabled($name);
    }

    /** Сырой синтез в WAV через sanotts_cli; текст идёт через stdin. */
    /**
     * Проверки перед синтезом: программа, голос, данные espeak, словарь языка.
     * Возвращает array(каталог голоса, сведения о голосе) или null ($lastError — причина).
     */
    private function prepareSynthesis()
    {
        $this->lastError = '';
        if (!$this->cliReady()) {
            $this->lastError = 'sanotts_cli не найден или не исполняемый: ' . $this->config['CLI'];
            $this->log($this->lastError);
            return null;
        }
        $dir = $this->voiceDir($this->config['VOICE']);
        if ($dir === null || !self::isVoiceDir($dir)) {
            $this->lastError = 'голос не установлен: ' . $this->config['VOICE'] . ' в ' . $this->config['VOICES_DIR'];
            $this->log($this->lastError);
            return null;
        }
        $this->syncBinary();
        $err = $this->syncBaseData();
        if ($err !== null) {
            $this->lastError = $err;
            $this->log($err);
            return null;
        }
        $vi = self::voiceInfo($dir);
        if ($vi['dict'] === 'ru' && is_file($this->dataDir() . '/ru_dict')) $this->ensureRuSupport();
        if ($vi['dict'] !== '' && !is_file($this->dataDir() . '/' . $vi['dict'] . '_dict')) {
            // Голос поставлен руками или словарь удалён — докачиваем его.
            $err = $this->ensureDict($vi['dict']);
            if ($err !== null) {
                $this->lastError = 'нет словаря ' . $vi['dict'] . '_dict: ' . $err;
                $this->log($this->lastError);
                return null;
            }
        }
        return array($dir, $vi);
    }

    /** Огласовки для арабского голоса (если включены); иначе текст как есть. */
    private function withTashkeel($text, $vi)
    {
        // Арабский: огласовки расставляет отдельная утилита (модель libtashkeel,
        // как в Piper) — голос учился на тексте с ними. Не вышло — синтезируем как есть.
        if ($vi['dict'] === 'ar' && (int)$this->config['TASHKEEL']) {
            $err = $this->ensureTashkeel();
            $d = $err === null ? SanottsEngine::diacritize($this->config['BASE'], $text, $err) : null;
            if ($d !== null) $text = $d;
            else $this->log('tashkeel skipped: ' . $err);
        }
        return $text;
    }

    // ------------------------------------------------------------------
    // Русские правила (lib/ru_frontend.php)
    // ------------------------------------------------------------------

    /**
     * Русские правила включены и применимы: голос русский,
     * галочка в настройках. $vi — сведения о голосе (иначе — текущий голос).
     */
    private function ruActive($vi = null)
    {
        if (!(int)$this->config['RU_RULES']) return false;
        $lang = $vi !== null ? $vi['dict'] : $this->currentLang();
        if ($lang !== 'ru') return false;
        if (!self::ruFrontend()) return false;
        SanottsRuText::$yoSource = SanottsEngine::yoFile($this->config['BASE']);
        return true;
    }

    /** Подключение фронтенда; словарь ё и его индекс — рядом с исходниками словаря (cms/sanotts/share/ru_dictsource). */
    private static function ruFrontend()
    {
        static $ok = null;
        if ($ok !== null) return $ok;
        $file = dirname(__FILE__) . '/lib/ru_frontend.php';
        $ok = is_file($file);
        if ($ok) require_once $file;
        return $ok;
    }

    private function ruIndexDir()
    {
        $d = SanottsEngine::ruSourceDir($this->config['BASE']);
        if (!is_dir($d)) @mkdir($d, 0755, true);
        return is_dir($d) && is_writable($d) ? $d : self::tmpDir();
    }

    /**
     * Текст сообщения перед синтезом. Русский с правилами — как есть: числа,
     * валюты, сокращения, скобки и кавычки разбирает фронтенд (ему нужны
     * $ € № « » и переводы строк); иначе — прежняя очистка cleanText().
     */
    public function textForSynthesis($message)
    {
        $message = $this->applyUserRules((string)$message);
        if ($this->ruActive()) return trim(SanottsRuText::moduleRules($message));
        return self::cleanText($message, $this->currentLang());
    }

    // ------------------------------------------------------------------
    // Свои правила произношения (таблица sanotts_rules, lib/rules.php)
    // ------------------------------------------------------------------

    /** Версия схемы таблиц: при расхождении с настройками — dbInstall(). */
    const DB_VERSION = '1';

    /** Языки, для которых есть вкладка правил. Остальные — позже (поле LANG уже есть). */
    public static function rulesLanguages()
    {
        return array('ru');
    }

    function dbInstall($data = '')
    {
        $data = <<<EOD
 sanotts_rules: ID int(10) unsigned NOT NULL auto_increment
 sanotts_rules: LANG varchar(16) NOT NULL DEFAULT 'ru'
 sanotts_rules: MATCH_TYPE varchar(10) NOT NULL DEFAULT 'word'
 sanotts_rules: PATTERN varchar(255) NOT NULL DEFAULT ''
 sanotts_rules: REPLACEMENT varchar(255) NOT NULL DEFAULT ''
 sanotts_rules: CASE_SENSITIVE int(3) NOT NULL DEFAULT 0
 sanotts_rules: ACTIVE int(3) NOT NULL DEFAULT 1
 sanotts_rules: PRIORITY int(10) NOT NULL DEFAULT 0
 sanotts_rules: NOTE varchar(255) NOT NULL DEFAULT ''
 sanotts_rules: UPDATED datetime
 sanotts_rules: INDEX (LANG)
EOD;
        parent::dbInstall($data);
    }

    /**
     * Таблица правил: модуль мог обновиться без повторной установки (Маркет
     * распаковывает архив поверх) — тогда создаём/дополняем её здесь, один раз.
     */
    private function ensureRulesTable()
    {
        static $done = false;
        if ($done) return true;
        if (!function_exists('SQLSelect')) return false;
        if (!isset($this->config['DB_VERSION']) || $this->config['DB_VERSION'] !== self::DB_VERSION) {
            try {
                $this->dbInstall('');
            } catch (Throwable $e) {
                $this->log('dbInstall: ' . $e->getMessage());
                return false;
            }
            $this->config['DB_VERSION'] = self::DB_VERSION;
            if (method_exists($this, 'saveConfig')) $this->saveConfig();
        }
        $done = true;
        return true;
    }

    /** Все правила (для вкладки) или только включённые для языка $lang — по порядку применения. */
    private function loadRules($lang = null, $activeOnly = false)
    {
        if (!$this->ensureRulesTable()) return array();
        $w = array();
        if ($lang !== null) $w[] = "(LANG='" . DBSafe($lang) . "' OR LANG='')";
        if ($activeOnly) $w[] = 'ACTIVE=1';
        try {
            $rows = SQLSelect('SELECT * FROM sanotts_rules' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY PRIORITY, ID');
        } catch (Throwable $e) {
            $this->log('rules: ' . $e->getMessage());
            return array();
        }
        return is_array($rows) ? $rows : array();
    }

    /** Включённые правила для языка текущего голоса (на время запроса — из памяти). */
    private $rulesCache = array();

    private function activeRules()
    {
        $lang = $this->currentLang();
        if (!in_array($lang, self::rulesLanguages(), true)) return array();
        if (!isset($this->rulesCache[$lang])) $this->rulesCache[$lang] = $this->loadRules($lang, true);
        return $this->rulesCache[$lang];
    }

    /** Свои правила — до встроенных: ими можно перекрыть и встроенное чтение (Wi-Fi и т. п.). */
    private function applyUserRules($text, &$hits = null)
    {
        $rules = $this->activeRules();
        $hits = array();
        return $rules ? SanottsRules::apply($text, $rules, $hits) : $text;
    }

    /** Ударения, которые модуль дописал в словарь (ru_extra.user): строка => «по́ртфель». */
    private function learnedStresses()
    {
        $f = SanottsEngine::ruUserExtraFile($this->config['BASE']);
        $out = array();
        if (!is_file($f)) return $out;
        foreach (preg_split('/\r?\n/', (string)@file_get_contents($f)) as $l) {
            $l = trim($l);
            if ($l === '' || strpos($l, '//') === 0) continue;
            $out[$l] = self::stressDisplay($l);
        }
        return $out;
    }

    /**
     * «портфельъ $1» → «по́ртфель», «замокъъ $2» → «замо́к»: метки ъ убираются,
     * знак ударения — после гласной с номером из $N.
     */
    public static function stressDisplay($line)
    {
        $w = preg_split('/\s+/', trim($line));
        $word = preg_replace('/ъ+$/u', '', $w[0]);
        $n = isset($w[1]) && preg_match('/^\$(\d+)$/', $w[1], $m) ? (int)$m[1] : 0;
        if ($n < 1) return $word;
        $i = 0;
        return preg_replace_callback('/[аеёиоуыэюяАЕЁИОУЫЭЮЯ]/u', function ($v) use (&$i, $n) {
            $i++;
            return $i === $n ? $v[0] . "\xCC\x81" : $v[0];
        }, $word);
    }

    /** Удаление ударений из ru_extra.user (все — $lines = null) и пересборка словаря. */
    private function removeLearnedStresses($lines)
    {
        $f = SanottsEngine::ruUserExtraFile($this->config['BASE']);
        if (!is_file($f)) return null;
        $dir = dirname($f);
        $lock = @fopen($dir . '/.lock', 'c');
        if ($lock) flock($lock, LOCK_EX);
        $keep = array();
        if ($lines !== null) {
            $drop = array_flip(array_map('trim', $lines));
            foreach (preg_split('/\r?\n/', (string)@file_get_contents($f)) as $l) {
                if (trim($l) !== '' && !isset($drop[trim($l)])) $keep[] = trim($l);
            }
        }
        $ok = $keep ? @file_put_contents($f, implode("\n", $keep) . "\n") !== false : @unlink($f);
        $err = null;
        if ($ok && is_file($this->dataDir() . '/ru_dict') && $this->cliReady()) {
            @set_time_limit(0);
            $inst = new SanottsEngine(dirname(__FILE__) . '/native', $this->config['BASE'], $this->config['VOICES_DIR'], 'irina', '');
            $err = $inst->dictOnly('ru');
            $this->log('ru_dict rebuilt after stress removal: ' . ($err === null ? 'ok' : $err));
        }
        if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
        return $ok ? $err : 'не удалось записать ' . $f;
    }

    /**
     * Разбор фразы для окна «Проверка»: что сделали свои правила и встроенные
     * правила языка, какие ударения получились.
     */
    private function explainText($text)
    {
        $lang = $this->currentLang();
        $hits = array();
        $after = $this->applyUserRules($text, $hits);
        $byId = array();
        foreach ($this->activeRules() as $r) $byId[(int)$r['ID']] = $r;
        $fired = array();
        foreach ($hits as $id => $n) {
            if (!isset($byId[$id])) continue;
            $fired[] = array('id' => $id, 'pattern' => $byId[$id]['PATTERN'], 'replacement' => $byId[$id]['REPLACEMENT'],
                'type' => $byId[$id]['MATCH_TYPE'], 'count' => $n);
        }
        $res = array('lang' => $lang, 'voice' => $this->config['VOICE'], 'original' => $text, 'user' => $after, 'rules' => $fired,
            'ru' => false, 'chunks' => array());
        if ($this->ruActive()) {
            SanottsRuText::$yoIndexDir = $this->ruIndexDir();
            $norm = trim(SanottsRuText::moduleRules($after));
            $res['ru'] = true;
            foreach (SanottsRuText::splitChunks($norm) as $c) {
                $p = SanottsRuText::prepare($c);
                $res['chunks'][] = array('text' => self::readableMarked($p['final']), 'stress' => array_map(array('sanotts', 'stressDisplay'), $p['extra']));
            }
        } else {
            $clean = self::cleanText($after, $lang);
            foreach (self::splitSentences($clean) as $c) $res['chunks'][] = array('text' => $c, 'stress' => array());
        }
        return $res;
    }

    /** Текст с метками ударений фронтенда («замокъъ») — как читается: «замо́к». */
    public static function readableMarked($text)
    {
        return preg_replace_callback('/([\p{L}]+?)(ъ+)(?![\p{L}])/u', function ($m) {
            return sanotts::stressDisplay($m[1] . ' $' . strlen($m[2]) / 2);
        }, $text);
    }

    /** Нарезка для синтеза по частям: у русского — по фразам русских правил. */
    private function splitForStream($clean)
    {
        return $this->ruActive() ? SanottsRuText::splitChunks($clean) : self::splitSentences($clean);
    }

    /**
     * Фразы -> строки кодов фонем для sanotts_cli -I (по строке на фразу),
     * по русским правилам: ударения по контексту, ё, числа, мягкий знак и т. д.
     * null — ошибка ($lastError).
     */
    private function ruToIds(array $chunks, $vi)
    {
        SanottsRuText::$yoIndexDir = $this->ruIndexDir();
        $preps = array();
        $extra = array();
        $req = array();
        foreach ($chunks as $k => $c) {
            $p = SanottsRuText::prepare($c);
            $preps[$k] = $p;
            foreach ($p['extra'] as $e) $extra[$e] = true;
            foreach (SanottsRuText::g2pRequests($p['final']) as $q) $req[$q] = true;
        }
        if ($extra) $this->ensureRuExtras(array_keys($extra));
        $map = $this->g2pBatch(array_map('strval', array_keys($req)), $vi);
        if ($map === null) return null;
        $self = $this;
        $missing = array();
        $g2p = function ($s) use (&$map, &$missing) {
            if (isset($map[$s])) return $map[$s];
            $missing[$s] = true;
            return array();
        };
        $out = array();
        foreach ($chunks as $k => $c) $out[$k] = SanottsRuText::idsFromFinal($preps[$k]['final'], $g2p);
        if ($missing) {
            // Запросы, которых не предвидел g2pRequests(), — дозапрашиваем и считаем заново.
            $more = $this->g2pBatch(array_map('strval', array_keys($missing)), $vi);
            if ($more === null) return null;
            $map = $map + $more;
            foreach ($chunks as $k => $c) $out[$k] = SanottsRuText::idsFromFinal($preps[$k]['final'], $g2p);
        }
        $lines = array();
        foreach ($chunks as $k => $c) $lines[] = implode(' ', $out[$k]);
        return $lines;
    }

    /** Фонемы для списка строк одним запуском sanotts_cli --g2p: строка => коды. */
    private function g2pBatch(array $texts, $vi)
    {
        if (!$texts) return array();
        $in = array();
        foreach ($texts as $t) $in[] = str_replace(array("\r", "\n"), ' ', $t);
        $args = array($this->config['CLI'], '-d', $this->dataDir(), '--g2p', $vi['espeak'] !== '' ? $vi['espeak'] : 'ru', (string)$vi['slot']);
        $got = array();
        $collect = function ($line) use (&$got) {
            if (preg_match('/^=(\d+)((?: -?\d+)*)$/', $line, $m)) $got[(int)$m[1]] = trim($m[2]);
        };
        $res = SanottsEngine::runStream($args, implode("\n", $in) . "\n", $collect);
        if ($res === null) {
            // Без proc_open: вывод вместе с журналом одним текстом.
            list($launched, $rc, $all) = SanottsEngine::run($args, implode("\n", $in) . "\n");
            foreach (preg_split('/\r?\n/', $all) as $line) $collect(rtrim($line));
        }
        $map = array();
        foreach ($texts as $k => $t) {
            if (!isset($got[$k])) {
                $this->lastError = 'sanotts_cli --g2p: нет ответа для строки ' . $k;
                $this->log($this->lastError);
                return null;
            }
            $map[$t] = $got[$k] === '' ? array() : array_map('intval', explode(' ', $got[$k]));
        }
        return $map;
    }

    /**
     * Ударения, которых нет в словаре (за́мок, зам+ок в тексте), — в
     * ru_extra.user и пересборка ru_dict (около секунды). Без этого метка
     * ударения молча не сработает — синтез не прерываем.
     */
    private function ensureRuExtras(array $lines)
    {
        $known = array();
        $static = dirname(__FILE__) . '/native/data/dictsource/ru_extra';
        $user = SanottsEngine::ruUserExtraFile($this->config['BASE']);
        $read = function ($f) use (&$known) {
            if (!is_file($f)) return;
            foreach (preg_split('/\r?\n/', (string)@file_get_contents($f)) as $l) {
                $w = preg_split('/\s+/', trim($l));
                if ($w[0] !== '') $known[$w[0]] = true;
            }
        };
        $read($static);
        $read($user);
        $new = array();
        foreach ($lines as $l) {
            $w = preg_split('/\s+/', trim($l));
            if ($w[0] !== '' && !isset($known[$w[0]])) $new[] = $l;
        }
        if (!$new) return;
        $dir = dirname($user);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) { $this->log('ru_extra.user: нет каталога ' . $dir); return; }
        $lock = @fopen($dir . '/.lock', 'c');
        if ($lock) flock($lock, LOCK_EX);
        $known = array();
        $read($user);
        $add = array();
        foreach ($new as $l) {
            $w = preg_split('/\s+/', trim($l));
            if (!isset($known[$w[0]])) $add[] = $l;
        }
        if ($add && @file_put_contents($user, implode("\n", $add) . "\n", FILE_APPEND) !== false) {
            @set_time_limit(0);
            $t0 = microtime(true);
            $inst = new SanottsEngine(dirname(__FILE__) . '/native', $this->config['BASE'], $this->config['VOICES_DIR'], 'russian', '');
            $err = $inst->dictOnly('ru');
            $this->log(sprintf('ru_dict rebuilt for %d stress mark(s) in %.1f s: %s', count($add), microtime(true) - $t0, $err === null ? 'ok' : $err));
        }
        if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    }

    /**
     * Русский словарь после обновления модуля: ru_extra модуля (омографы,
     * служебные слова) входит в ru_dict — пересобираем по метке. И английский
     * словарь: остатки латиницы (café) espeak читает по-английски.
     * Неудача не мешает синтезу; повтор — не чаще раза в час.
     */
    private function ensureRuSupport()
    {
        $data = $this->dataDir();
        $native = dirname(__FILE__) . '/native';
        $stamp = SanottsEngine::ruDictStamp($native, $this->config['BASE']);
        $needDict = trim((string)@file_get_contents($data . '/.ru_dict.sha')) !== $stamp;
        $needEn = !is_file($data . '/en_dict');
        $needYo = !is_file(SanottsEngine::yoFile($this->config['BASE']));
        if (!$needDict && !$needEn && !$needYo) return;
        $retry = $data . '/.ru_support.retry';
        if (is_file($retry) && time() - (int)@filemtime($retry) < 3600) return;
        @set_time_limit(0);
        if ($needEn) {
            $err = $this->ensureDict('en');
            if ($err !== null) $this->log('en_dict: ' . $err);
        }
        $err = null;
        if ($needYo && !$needDict) {
            $err = SanottsEngine::ensureYoDict($native, $this->config['BASE']);
            $this->log('yo_safe.txt: ' . ($err === null ? 'ok' : $err));
            if ($err === null) SanottsEngine::buildYoIndex($native, $this->config['BASE']);
        }
        if ($needDict) {
            $dir = SanottsEngine::ruSourceDir($this->config['BASE']);
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $lock = @fopen($dir . '/.lock', 'c');
            if ($lock) flock($lock, LOCK_EX);
            if (trim((string)@file_get_contents($data . '/.ru_dict.sha')) !== $stamp) {
                $t0 = microtime(true);
                $inst = new SanottsEngine($native, $this->config['BASE'], $this->config['VOICES_DIR'], 'russian', '');
                $err = $inst->dictOnly('ru');
                $this->log(sprintf('ru_dict update in %.1f s: %s', microtime(true) - $t0, $err === null ? 'ok' : $err));
                if ($err === null) SanottsEngine::buildYoIndex($native, $this->config['BASE']);
            }
            if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
        }
        if ($err !== null || ($needEn && !is_file($data . '/en_dict'))) @touch($retry); else @unlink($retry);
    }

    /** Число для командной строки: всегда с точкой, без лишних нулей. */
    private static function fmtNum($v)
    {
        $s = rtrim(rtrim(sprintf('%.2f', (float)$v), '0'), '.');
        return $s === '-0' || $s === '' ? '0' : $s;
    }

    /** Высота (полутоны) и тембр (дБ) в допустимых пределах. */
    private function applyVoiceShape($pitch, $bass, $treble)
    {
        if ($pitch !== null && is_numeric($pitch)) $this->config['PITCH'] = self::fmtNum(max(-8, min(8, round((float)$pitch * 2) / 2)));
        if ($bass !== null && is_numeric($bass)) $this->config['BASS'] = self::fmtNum(max(-12, min(12, round((float)$bass))));
        if ($treble !== null && is_numeric($treble)) $this->config['TREBLE'] = self::fmtNum(max(-12, min(12, round((float)$treble))));
    }

    /** Общие параметры sanotts_cli (без вывода и тишины в начале). */
    private function cliArgs($dir)
    {
        return array($this->config['CLI'], '-d', $this->dataDir(), '-v', $dir,
            '-s', sprintf('%.2f', (float)$this->config['LENGTH_SCALE']),
            '-P', self::fmtNum($this->config['PITCH']),
            '-B', self::fmtNum($this->config['BASS']),
            '-T', self::fmtNum($this->config['TREBLE']),
            '-p', sprintf('%.2f', (float)$this->config['SENTENCE_SILENCE']),
            '-L', $this->loudnessArg());
    }

    private function engineSynthesize($text, $path)
    {
        $prep = $this->prepareSynthesis();
        if ($prep === null) return false;
        list($dir, $vi) = $prep;
        $text = $this->withTashkeel($text, $vi);
        @unlink($path);
        $idsMode = false;
        if ($this->ruActive($vi)) {
            // Русский: фразы и фонемы готовят русские правила, движку — коды фонем.
            $lines = $this->ruToIds(SanottsRuText::splitChunks($text), $vi);
            if ($lines === null) return false;
            $text = implode("\n", $lines);
            $idsMode = true;
        }
        // Путь к данным espeak — параметром -d: на Android/Termux программу
        // запускает системный загрузчик, и сама она своё расположение узнать
        // не может. Запуск — без оболочки (ни sh, ни cmd.exe), текст — в stdin.
        $args = array_merge($this->cliArgs($dir), array('-o', $path, '-l', sprintf('%.2f', $this->getLeadSilence())));
        if ($idsMode) $args[] = '-I';
        list($launched, $rc, $err) = SanottsEngine::run($args, $text);
        // Судим по результату, а не по коду: proc_close при установленном
        // обработчике SIGCHLD возвращает -1 даже при успешном запуске.
        if ($launched && is_file($path) && filesize($path) > 44) return true;
        $this->lastError = ($launched ? "sanotts_cli rc=$rc: " : '') . ($err !== '' ? $err : 'нет вывода');
        $this->log($this->lastError);
        return false;
    }

    // ------------------------------------------------------------------
    // Админка
    // ------------------------------------------------------------------

    function run()
    {
        $out = array();
        if ($this->action == 'admin') {
            $this->admin($out);
        } else {
            $this->usual($out);
        }
        if (isset($this->owner->action)) $out['PARENT_ACTION'] = $this->owner->action;
        if (isset($this->owner->name)) $out['PARENT_NAME'] = $this->owner->name;
        $out['VIEW_MODE'] = $this->view_mode;
        $out['EDIT_MODE'] = $this->edit_mode;
        $out['MODE'] = $this->mode;
        $out['ACTION'] = $this->action;
        $this->data = $out;
        $p = new parser(DIR_TEMPLATES . $this->name . '/' . $this->name . '.html', $this->data, $this);
        $this->result = $p->result;
    }

    /**
     * Куда ставить движок: в каталог из настроек, если туда можно писать
     * (или создать его), иначе — в cms/sanotts.
     */
    private function chooseBase()
    {
        $base = SanottsEngine::trimDir($this->config['BASE']);
        $writable = is_dir($base) ? is_writable($base) : is_writable(dirname($base));
        return $writable ? $base : self::defaultBase();
    }

    /** Команда для ручного запуска установки из консоли сервера. */
    public static function manualInstallCommand()
    {
        $script = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'install.php';
        $php = SanottsEngine::phpCli();
        if (SanottsEngine::isWindows()) {
            if ($php === null) $php = 'php.exe';
            return '"' . str_replace('/', '\\', $php) . '" "' . str_replace('/', '\\', $script) . '"';
        }
        if ($php === null) $php = 'php';
        $user = function_exists('posix_geteuid') && function_exists('posix_getpwuid') && ($pw = @posix_getpwuid(posix_geteuid()))
            ? $pw['name'] : 'www-data';
        // От того же пользователя, что и веб-сервер, иначе файлы окажутся чужими.
        return (function_exists('posix_geteuid') && posix_geteuid() !== 0 && !self::isAndroid() && SanottsEngine::haveTool('sudo'))
            ? 'sudo -u ' . $user . ' ' . $php . ' ' . $script
            : $php . ' ' . $script;
    }

    /** После успешной установки в другой каталог — переводим настройки на него. */
    private function adoptBase($base)
    {
        $base = SanottsEngine::trimDir($base);
        $old = SanottsEngine::trimDir($this->config['BASE']);
        $cli = SanottsEngine::binDir($base) . '/' . SanottsEngine::cliName();
        if ($base === '' || !is_file($cli)) return;
        if ($base === $old && $this->cliReady() && $this->config['CLI'] === $cli) return;
        $this->config['BASE'] = $base;
        $oldCli = $old . '/bin/' . SanottsEngine::cliName();
        if ($this->config['CLI'] === $oldCli || $this->config['CLI'] === SanottsEngine::binDir($old) . '/' . SanottsEngine::cliName() || !$this->cliReady()) {
            $this->config['CLI'] = $cli;
        }
        if ($this->config['VOICES_DIR'] === $old . '/voices' || !is_dir($this->config['VOICES_DIR'])) {
            $this->config['VOICES_DIR'] = $base . '/voices';
        }
        if (method_exists($this, 'saveConfig')) $this->saveConfig();
        $this->log("engine base switched to $base");
    }

    /**
     * Состояние последней попытки установки. Считается по журналу попытки,
     * а не по маркеру: маркер, оставшийся от незапустившегося скрипта,
     * раньше навсегда держал статус «Установка» и прятал кнопку.
     * Возвращает array(state, log_text), state: none|installing|done|failed.
     */
    private function installAttempt()
    {
        $info = @json_decode((string)@file_get_contents(self::installStateFile()), true);
        if (!is_array($info) || empty($info['run']) || empty($info['t'])) return array('none', '');
        $run = (string)$info['run'];
        $age = time() - (int)$info['t'];
        $logFile = $run . '/install.log';
        $log = (string)@file_get_contents($logFile);
        $launch = trim((string)@file_get_contents($run . '/launch.log'));

        if (strpos($log, '[sanoTTS] Done') !== false) {
            if (!empty($info['base'])) $this->adoptBase($info['base']);
            return array('done', $log);
        }
        if (strpos($log, '[sanoTTS] FAILED') !== false) return array('failed', $log);
        $manual = "\n\nЗапустите установку в консоли сервера:\n\n    " . self::manualInstallCommand() . "\n";
        if (trim($log) === '') {
            if ($age < self::INSTALL_START_TIMEOUT && $launch === '') return array('installing', '');
            $text = $launch !== ''
                ? "Установщик не стартовал:\n" . $launch
                : 'Установщик не стартовал и ничего не вывел.';
            return array('failed', $text . $manual);
        }
        $idle = time() - (int)@filemtime($logFile);
        if ($idle > self::INSTALL_STALE_TIME || $age > self::INSTALL_MAX_TIME) {
            return array('failed', rtrim($log) . "\n\n[прервано: установщик остановился без завершения — " .
                "вероятно, его прервал веб-сервер по тайм-ауту]" . ($launch !== '' ? "\n" . $launch : '') . $manual);
        }
        return array('installing', $log);
    }

    private function statusArray()
    {
        list($attempt, ) = $this->installAttempt();
        if ($attempt == 'installing') {
            $install = 'installing';
        } elseif ($this->cliReady()) {
            $install = 'installed';
        } elseif ($attempt == 'failed') {
            $install = 'failed';
        } else {
            $install = 'not_installed';
        }
        $dir = $this->voiceDir($this->config['VOICE']);
        return array(
            'install' => $install,
            'voice'   => ($dir !== null && self::isVoiceDir($dir)) ? 'ok' : 'missing',
            'ws'      => $this->isWsAlive() ? 'connected' : 'not_connected',
            'ws_port' => $this->getWsPort(),
        );
    }

    function admin(&$out)
    {
        $this->getConfig();
        self::loadLanguage();
        $cmd = gr('cmd');

        if ($cmd == 'check_status') {
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode($this->statusArray());
            exit;
        }

        if ($cmd == 'install_engine') {
            $this->log('install_engine called');
            $inst = $this->runEngineInstall();
            if ($inst === null) {
                while (ob_get_level()) ob_end_clean();
                header('Content-Type: application/json');
                echo json_encode(array('ok' => true, 'inline' => false));
                exit;
            }
            // Установка в этом запросе. Браузер за ответом не следит — он читает
            // журнал; от закрытия вкладки и лимита времени — защита.
            ignore_user_abort(true);
            @set_time_limit(0);
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            @ini_set('zlib.output_compression', '0');
            while (ob_get_level()) ob_end_clean();
            // mod_php (XAMPP), PHP-FPM и LiteSpeed умеют работать после отправки
            // ответа — отдаём его сразу. CGI/FastCGI (lighttpd, IIS, mod_fcgid)
            // убивают PHP, как только ответ получен, — там держим запрос открытым
            // и пишем в него журнал: строки о ходе загрузки не дают сработать
            // тайм-ауту простоя.
            $early = in_array(PHP_SAPI, array('apache2handler', 'fpm-fcgi', 'litespeed'), true);
            if ($early) {
                $body = json_encode(array('ok' => true, 'inline' => true));
                header('Content-Type: application/json');
                header('Connection: close');
                header('Content-Length: ' . strlen($body));
                echo $body;
                flush();
                if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
                elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
            } else {
                header('Content-Type: text/plain; charset=utf-8');
                header('X-Accel-Buffering: no');
                echo "[sanoTTS] installing (" . PHP_SAPI . ")\n";
                flush();
                $inst->setEcho(true);
            }
            $ok = $inst->install();
            $this->log('inline install ' . ($ok ? 'done' : 'FAILED'));
            if ($ok) $this->installAttempt();   // переводит настройки на каталог установки
            exit;
        }

        if ($cmd == 'install_log') {
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: text/plain; charset=utf-8');
            list($attempt, $text) = $this->installAttempt();
            echo $attempt == 'none' ? '' : $text;
            exit;
        }

        if ($cmd == 'diag') {
            while (ob_get_level()) ob_end_clean();
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            header('Content-Type: application/json');
            header('Cache-Control: no-cache');
            // Значения уже экранированы для HTML (как для шаблона).
            echo json_encode(array('rows' => $this->diagnostics()));
            exit;
        }

        if ($cmd == 'test_poll') {
            // Готовые предложения задания начиная с from; ждёт до 15 с, пока
            // появится хоть одно (или задание закончится).
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: application/json');
            header('Cache-Control: no-cache');
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            $job = (string)gr('job');
            if (!preg_match('/^[a-f0-9]{16,40}$/', $job)) { echo json_encode(array('error' => 'bad job')); exit; }
            $dir = self::testJobDir($job);
            $i = max(0, (int)gr('from'));
            $deadline = microtime(true) + 15;
            $items = array();
            $n = 0;
            $done = false;
            do {
                clearstatcache();
                $n = is_file($dir . '/n') ? (int)file_get_contents($dir . '/n') : 0;
                while (true) {
                    $name = $dir . '/' . sprintf('%03d', $i);
                    if (is_file($name . '.wav')) {
                        $items[] = array('i' => $i, 'server' => (float)@file_get_contents($name . '.t'),
                            'audio' => 'data:audio/wav;base64,' . base64_encode((string)file_get_contents($name . '.wav')));
                    } elseif (is_file($name . '.skip')) {
                        $items[] = array('i' => $i, 'skip' => true);
                    } else {
                        break;
                    }
                    $i++;
                }
                $done = is_file($dir . '/done');
                if ($items || $done) break;
                usleep(50000);
            } while (microtime(true) < $deadline);
            $error = $done ? trim((string)@file_get_contents($dir . '/done')) : '';
            $finished = $done && $n > 0 && $i >= $n;
            if ($finished) self::removeTree($dir);
            echo json_encode(array('items' => $items, 'next' => $i, 'n' => $n, 'done' => $finished || ($done && !$items), 'error' => $error));
            exit;
        }

        if ($cmd == 'test_put') {
            // Часть длинного текста для «Проверить»/«Скачать» (base64url; адрес
            // запроса у веб-серверов короткий, текст приходит частями).
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: application/json');
            $key = (string)gr('tk');
            $i = (int)gr('i');
            $d = (string)gr('d');
            if (!preg_match('/^[a-f0-9]{16,40}$/', $key) || $i < 0 || $i > 2000 || !preg_match('/^[A-Za-z0-9_-]{0,8000}$/', $d)) {
                echo json_encode(array('ok' => false, 'error' => 'bad part'));
                exit;
            }
            self::cleanupTestJobs();
            $dir = self::testTextDir($key);
            if (!is_dir($dir)) @mkdir($dir, 0777, true);
            $ok = is_dir($dir) && @file_put_contents($dir . '/' . sprintf('%04d', $i), $d) !== false;
            echo json_encode($ok ? array('ok' => true) : array('ok' => false, 'error' => 'cannot write ' . $dir));
            exit;
        }

        if ($cmd == 'test_download') {
            // Текст из окна проверки — одним WAV-файлом на скачивание.
            while (ob_get_level()) ob_end_clean();
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            @set_time_limit(0);
            $text = $this->testText();
            $tmp = self::tmpDir() . '/sanotts_dl_' . md5(uniqid('', true)) . '.wav';
            if (!$this->engineSynthesize($this->textForSynthesis($text), $tmp)) {
                @unlink($tmp);
                header('Content-Type: application/json');
                echo json_encode(array('ok' => false, 'error' => $this->lastError));
                exit;
            }
            $name = 'sanotts_' . $this->config['VOICE'] . '_' . date('Ymd_His') . '.wav';
            header('Content-Type: audio/wav');
            header('Content-Disposition: attachment; filename="' . $name . '"');
            header('Content-Length: ' . filesize($tmp));
            header('Cache-Control: no-cache');
            readfile($tmp);
            @unlink($tmp);
            exit;
        }

        if ($cmd == 'test_say') {
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: application/json');
            $text = $this->testText();
            $t0 = microtime(true);
            $clean = $this->textForSynthesis($text);
            // job=…: синтез в каталог задания, предложение за предложением (один
            // запуск движка); кнопка забирает готовые отдельными короткими
            // запросами test_poll. Так звук начинается сразу, даже если веб-сервер
            // или прокси придерживают потоковый ответ до конца (nginx, сжатие).
            $job = (string)gr('job');
            if (preg_match('/^[a-f0-9]{16,40}$/', $job)) {
                if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
                ignore_user_abort(true);
                @set_time_limit(0);
                self::cleanupTestJobs();
                $dir = self::testJobDir($job);
                @mkdir($dir, 0777, true);
                $sentences = (int)$this->config['STREAM'] ? $this->splitForStream($clean) : array();
                if (!$sentences) $sentences = array($clean);
                $n = count($sentences);
                file_put_contents($dir . '/n', (string)$n);
                $put = function ($i, $wav) use ($dir, $t0) {
                    $name = $dir . '/' . sprintf('%03d', $i);
                    // Когда предложение было готово на сервере — для строки состояния кнопки.
                    @file_put_contents($name . '.t', sprintf('%.2f', microtime(true) - $t0));
                    if ($wav !== null && @copy($wav, $name . '.part')) {
                        @rename($name . '.part', $name . '.wav');
                    } else {
                        @touch($name . '.skip');
                    }
                };
                if ($n > 1) {
                    if ($this->streamSentences($sentences, $put) === false) {
                        foreach ($sentences as $i => $sentence) $put($i, $this->renderSentence($sentence, $i == 0));
                    }
                } else {
                    $tmp = $dir . '/whole.tmp.wav';
                    $put(0, $this->engineSynthesize($clean, $tmp) ? $tmp : null);
                    @unlink($tmp);
                }
                file_put_contents($dir . '/done', (string)$this->lastError);
                echo json_encode(array('done' => true, 'n' => $n, 'error' => $this->lastError));
                exit;
            }
            $tmp = self::tmpDir() . '/sanotts_test_' . md5(uniqid('', true)) . '.wav';
            $ok = $this->engineSynthesize($clean, $tmp);
            $res = array('ok' => $ok, 'seconds' => round(microtime(true) - $t0, 2));
            if (!$ok) $res['error'] = $this->lastError;
            if ($ok) $res['audio'] = 'data:audio/wav;base64,' . base64_encode(file_get_contents($tmp));
            @unlink($tmp);
            echo json_encode($res);
            exit;
        }

        // --- Свои правила произношения (вкладка «Произношение») ---
        if (in_array($cmd, array('rules_list', 'rule_save', 'rule_delete', 'rule_toggle', 'rule_move', 'stress_delete', 'test_explain'), true)) {
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-cache');
            echo json_encode($this->rulesCommand($cmd), JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($cmd == 'rules_export') {
            while (ob_get_level()) ob_end_clean();
            $body = SanottsRules::export($this->loadRules());
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="sanotts_rules_' . date('Ymd') . '.txt"');
            header('Content-Length: ' . strlen($body));
            echo $body;
            exit;
        }

        if (isset($this->view_mode) && $this->view_mode == 'rules_import') {
            $this->redirect('?action=sanotts&tab=rules&' . $this->importRules());
        }

        if ($cmd == 'install_model' || $cmd == 'delete_model') {
            $voice = self::sanitizeVoice(gr('voice'));
            $dir = $voice === null ? null : $this->voiceDir($voice);
            if ($dir === null) $this->fatal(LANG_SANOTTS_INVALID_MODEL);
            if ($cmd == 'delete_model') {
                $ok = self::removeTree($dir);
                $this->log("delete $voice: " . ($ok ? 'ok' : 'failed'));
            } else {
                $cat = self::voiceCatalog();
                if (!isset($cat[$voice])) $this->fatal(LANG_SANOTTS_INVALID_MODEL);
                set_time_limit(0);
                $err = $this->downloadVoice($voice, $dir);
                if ($err !== null) {
                    $this->log("install $voice FAILED: $err");
                    $this->fatal(LANG_SANOTTS_DOWNLOAD_ERROR . ': ' . $err);
                }
                $this->log("install $voice: ok");
            }
            $this->redirect('?action=sanotts&tab=models');
            exit;
        }

        if (isset($this->view_mode) && $this->view_mode == 'update_settings') {
            $cli = trim((string)gr('cli', $this->config['CLI']));
            $this->config['CLI'] = $cli !== '' ? $cli : SanottsEngine::binDir($this->config['BASE']) . '/' . SanottsEngine::cliName();
            $dir = trim((string)gr('voices_dir', $this->config['VOICES_DIR']));
            $this->config['VOICES_DIR'] = $dir !== '' ? $dir : self::defaults()['VOICES_DIR'];
            $voice = self::sanitizeVoice(gr('voice', $this->config['VOICE']));
            if ($voice !== null) $this->config['VOICE'] = $voice;
            $this->config['LENGTH_SCALE'] = sprintf('%.2f', max(0.5, min(2.0, (float)gr('length_scale', $this->config['LENGTH_SCALE']))));
            $this->applyVoiceShape(gr('pitch', $this->config['PITCH']), gr('bass', $this->config['BASS']), gr('treble', $this->config['TREBLE']));
            $this->config['SENTENCE_SILENCE'] = sprintf('%.2f', max(0.0, min(1.0, (float)gr('sentence_silence', $this->config['SENTENCE_SILENCE']))));
            $this->config['LEAD_SILENCE'] = sprintf('%.1f', max(0.0, min(5.0, (float)gr('lead_silence', $this->config['LEAD_SILENCE']))));
            $this->config['NORMALIZE'] = gr('normalize', 0) ? '1' : '0';
            $this->config['LOUDNESS'] = (string)max(-30, min(-10, (int)round((float)gr('loudness', $this->config['LOUDNESS']))));
            $this->config['USE_CACHE'] = gr('use_cache', 0) ? '1' : '0';
            $cacheDir = trim((string)gr('cache_dir', $this->config['CACHE_DIR']));
            $this->config['CACHE_DIR'] = $cacheDir !== '' ? $cacheDir : self::defaults()['CACHE_DIR'];
            $this->config['CACHE_CLEANUP'] = gr('cache_cleanup', 0) ? '1' : '0';
            // Галочка есть на странице, только когда стоит арабский голос.
            if (gr('tashkeel_shown', 0)) $this->config['TASHKEEL'] = gr('tashkeel', 0) ? '1' : '0';
            $this->config['STREAM'] = gr('stream', 0) ? '1' : '0';
            // Галочка есть на странице, только когда стоит русский голос.
            if (gr('ru_rules_shown', 0)) $this->config['RU_RULES'] = gr('ru_rules', 0) ? '1' : '0';
            $this->config['WS_PORT'] = gr('ws_port', $this->config['WS_PORT']);
            $this->saveConfig();
            $this->redirect('?action=sanotts&tab=settings');
        }

        $out['CLI'] = $this->e($this->config['CLI']);
        $out['VOICES_DIR'] = $this->e($this->config['VOICES_DIR']);
        $out['LENGTH_SCALE'] = $this->e($this->config['LENGTH_SCALE']);
        $out['PITCH'] = $this->e(self::fmtNum($this->config['PITCH']));
        $out['BASS'] = $this->e(self::fmtNum($this->config['BASS']));
        $out['TREBLE'] = $this->e(self::fmtNum($this->config['TREBLE']));
        $out['SENTENCE_SILENCE'] = $this->e($this->config['SENTENCE_SILENCE']);
        $out['LEAD_SILENCE'] = $this->e($this->config['LEAD_SILENCE']);
        $out['NORMALIZE'] = $this->config['NORMALIZE'] ? 'checked' : '';
        $out['LOUDNESS'] = $this->e($this->config['LOUDNESS']);
        $out['USE_CACHE'] = $this->config['USE_CACHE'] ? 'checked' : '';
        $out['CACHE_DIR'] = $this->e($this->config['CACHE_DIR']);
        $out['CACHE_CLEANUP'] = $this->config['CACHE_CLEANUP'] ? 'checked' : '';
        $out['TASHKEEL'] = (int)$this->config['TASHKEEL'] ? 'checked' : '';
        $out['STREAM'] = (int)$this->config['STREAM'] ? 'checked' : '';
        $out['RU_RULES'] = (int)$this->config['RU_RULES'] ? 'checked' : '';
        $out['WS_PORT'] = $this->e($this->getWsPort());
        $out['VERSION'] = self::VERSION;

        $st = $this->statusArray();
        $codes = array('installed' => '1', 'installing' => '2', 'failed' => '3');
        $out['INSTALL_STATUS'] = isset($codes[$st['install']]) ? $codes[$st['install']] : '0';
        $out['MANUAL_INSTALL'] = $this->e(self::manualInstallCommand());
        $out['VOICE_OK'] = $st['voice'] == 'ok' ? '1' : '';
        $out['WS_ALIVE'] = $st['ws'] == 'connected' ? '1' : '';

        $tab = gr('tab');
        if (!in_array($tab, array('settings', 'models', 'rules', 'help'), true)) $tab = 'settings';
        $out['TAB'] = $tab;
        $out['TAB_SETTINGS'] = $tab == 'settings' ? '1' : '0';
        $out['TAB_MODELS'] = $tab == 'models' ? '1' : '0';
        $out['TAB_RULES'] = $tab == 'rules' ? '1' : '0';
        if ($tab == 'rules') {
            // Итог импорта (после перехода назад на вкладку).
            $out['IMPORT_DONE'] = gr('imported') !== '' ? '1' : '';
            $out['IMPORT_ADDED'] = (int)gr('imported');
            $out['IMPORT_SKIPPED'] = (int)gr('skipped');
            $out['IMPORT_ERRORS'] = $this->e(base64_decode(strtr((string)gr('ierr'), '-_', '+/')) ?: '');
            $out['IMPORT_FAILED'] = $this->e(base64_decode(strtr((string)gr('ifail'), '-_', '+/')) ?: '');
        }
        $out['TAB_HELP'] = $tab == 'help' ? '1' : '0';

        $installed = $this->installedVoices();
        $bundled = self::voiceCatalog();
        $current = $this->config['VOICE'];
        $out['VOICES'] = array();
        uasort($installed, function ($a, $b) { return strcmp($a['language'], $b['language']); });
        foreach ($installed as $name => $info) {
            // «Русский — russian (жен., 1,57 млн)»
            $g = isset($bundled[$name]) ? $bundled[$name]['gender'] : '';
            $extra = array();
            if ($g === 'f') $extra[] = LANG_SANOTTS_GENDER_F_SHORT;
            if ($g === 'm') $extra[] = LANG_SANOTTS_GENDER_M_SHORT;
            if ($info['params'] > 0) $extra[] = self::paramsText($info['params']);
            $out['VOICES'][] = array(
                'VALUE' => $this->e($name),
                'TITLE' => $this->e(($info['language'] !== '' ? $info['language'] . ' — ' : '') . $name .
                    ($extra ? ' (' . implode(', ', $extra) . ')' : '')),
                'SELECTED' => $name === $current ? 'selected' : '',
                'LANG' => $this->e($info['dict']),
            );
        }
        if (!$installed) {
            $out['VOICES'][] = array('VALUE' => $this->e($current), 'TITLE' => $this->e($current), 'SELECTED' => 'selected', 'LANG' => 'ru');
        }
        $out['HAS_ARABIC'] = '';
        foreach ($installed as $info) if ($info['dict'] === 'ar') $out['HAS_ARABIC'] = '1';
        $out['HAS_RUSSIAN'] = '';
        foreach ($installed as $info) if ($info['dict'] === 'ru') $out['HAS_RUSSIAN'] = '1';
        $out['SAMPLES_JSON'] = json_encode(self::samplePhrases(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        // Для голосов из каталога — его сведения (пол, учитель, оценки), для своих — из meta.json.
        $all = array();
        foreach ($installed as $name => $info) $all[$name] = isset($bundled[$name]) ? $bundled[$name] : $info;
        $all += $bundled;
        // Русский первым, дальше по названию языка, внутри языка — по имени.
        uksort($all, function ($a, $b) use ($all) {
            $ra = $all[$a]['dict'] === 'ru' ? 0 : 1; $rb = $all[$b]['dict'] === 'ru' ? 0 : 1;
            if ($ra != $rb) return $ra - $rb;
            $c = strcmp($all[$a]['language'], $all[$b]['language']);
            return $c ?: strcmp($a, $b);
        });
        $rows = array();
        foreach ($all as $name => $info) {
            list($desc, $quality) = self::voiceDescription($info);
            $size = isset($installed[$name]) ? $installed[$name]['size'] : $info['size'];
            $rows[] = array(
                'VOICE' => $this->e($name),
                'LANGUAGE' => $this->e($info['language']),
                'DESC' => $this->e($desc),
                'QUALITY' => $this->e($quality),
                'NOTE' => $this->e($info['note']),
                'SIZE' => $this->e(self::num($size / 1048576, 1) . ' ' . LANG_SANOTTS_MB),
                'INSTALLED' => isset($installed[$name]) ? '1' : '0',
                'CUSTOM' => isset($bundled[$name]) ? '0' : '1',
            );
        }
        $out['AVAILABLE_MODELS'] = $rows;
        $out['NO_VOICES'] = $rows ? '' : '1';
        // Диагностику (в ней пробный синтез) страница подгружает отдельным
        // запросом cmd=diag — список голосов открывается сразу.
    }

    /** Строка из base64url-параметра запроса (текст правил идёт так же, как текст проверки). */
    private static function grText($name)
    {
        $v = (string)gr($name);
        if ($v === '') return '';
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $v)) return '';
        $d = base64_decode(strtr($v, '-_', '+/'), true);
        return ($d !== false && preg_match('//u', $d)) ? $d : '';
    }

    private function ruleRow(array $r)
    {
        return array('id' => (int)$r['ID'], 'pattern' => (string)$r['PATTERN'], 'replacement' => (string)$r['REPLACEMENT'],
            'type' => (string)$r['MATCH_TYPE'], 'cs' => (int)$r['CASE_SENSITIVE'], 'active' => (int)$r['ACTIVE'],
            'lang' => (string)$r['LANG'], 'note' => (string)$r['NOTE'],
            'valid' => SanottsRules::validate($r['MATCH_TYPE'], $r['PATTERN'], $r['REPLACEMENT']) === null);
    }

    /** Команды вкладки «Произношение» (AJAX, JSON). */
    private function rulesCommand($cmd)
    {
        if ($cmd == 'test_explain') {
            $text = $this->testText();
            return $this->explainText($text);
        }
        if (!$this->ensureRulesTable()) return array('ok' => false, 'error' => 'нет таблицы sanotts_rules');
        $id = (int)gr('id');
        $rec = $id > 0 ? SQLSelectOne('SELECT * FROM sanotts_rules WHERE ID=' . $id) : array();
        if ($id > 0 && !$rec && $cmd != 'rules_list' && $cmd != 'rule_save') return array('ok' => false, 'error' => 'правило не найдено');
        switch ($cmd) {
            case 'rule_save':
                $type = (string)gr('type');
                list($pattern, $replacement) = SanottsRules::normalize($type, self::grText('p'), self::grText('r'));
                $err = SanottsRules::validate($type, $pattern, $replacement);
                if ($err !== null) return array('ok' => false, 'error' => $err);
                $lang = (string)gr('lang');
                if (!in_array($lang, self::rulesLanguages(), true)) $lang = 'ru';
                $dup = SQLSelectOne("SELECT ID FROM sanotts_rules WHERE LANG='" . DBSafe($lang) . "' AND MATCH_TYPE='" . DBSafe($type) .
                    "' AND BINARY PATTERN='" . DBSafe($pattern) . "' AND ID<>" . $id);
                if ($dup) return array('ok' => false, 'error' => 'такое правило уже есть');
                $r = $rec ? $rec : array();
                $r['LANG'] = $lang;
                $r['MATCH_TYPE'] = $type;
                $r['PATTERN'] = $pattern;
                $r['REPLACEMENT'] = $replacement;
                $r['CASE_SENSITIVE'] = gr('cs') ? 1 : 0;
                $r['NOTE'] = SanottsRules::clean(self::grText('n'));
                $r['UPDATED'] = date('Y-m-d H:i:s');
                if ($rec) {
                    SQLUpdate('sanotts_rules', $r);
                } else {
                    $max = SQLSelectOne('SELECT MAX(PRIORITY) AS M FROM sanotts_rules');
                    $r['PRIORITY'] = (isset($max['M']) ? (int)$max['M'] : 0) + 10;
                    $r['ACTIVE'] = 1;
                    $r['ID'] = SQLInsert('sanotts_rules', $r);
                }
                break;
            case 'rule_delete':
                SQLExec('DELETE FROM sanotts_rules WHERE ID=' . $id);
                break;
            case 'rule_toggle':
                $rec['ACTIVE'] = gr('active') ? 1 : 0;
                SQLUpdate('sanotts_rules', $rec);
                break;
            case 'rule_move':
                // Порядок применения: меняемся местами с соседом сверху/снизу.
                $all = $this->loadRules();
                foreach ($all as $k => $r) {
                    $all[$k]['PRIORITY'] = ($k + 1) * 10;
                }
                foreach ($all as $k => $r) {
                    if ((int)$r['ID'] !== $id) continue;
                    $j = gr('dir') === 'up' ? $k - 1 : $k + 1;
                    if (isset($all[$j])) {
                        $t = $all[$k]['PRIORITY'];
                        $all[$k]['PRIORITY'] = $all[$j]['PRIORITY'];
                        $all[$j]['PRIORITY'] = $t;
                    }
                    break;
                }
                foreach ($all as $r) SQLExec('UPDATE sanotts_rules SET PRIORITY=' . (int)$r['PRIORITY'] . ' WHERE ID=' . (int)$r['ID']);
                break;
            case 'stress_delete':
                $line = self::grText('line');
                $err = $this->removeLearnedStresses(gr('all') ? null : array($line));
                if ($err !== null) return array('ok' => false, 'error' => $err);
                break;
        }
        $rules = array();
        foreach ($this->loadRules() as $r) $rules[] = $this->ruleRow($r);
        $stress = array();
        foreach ($this->learnedStresses() as $line => $word) $stress[] = array('line' => $line, 'word' => $word);
        return array('ok' => true, 'rules' => $rules, 'stress' => $stress);
    }

    /** Импорт файла правил (форма вкладки «Произношение»): строка параметров для возврата на вкладку. */
    private function importRules()
    {
        $b64 = function ($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); };
        $f = isset($_FILES['rules_file']) ? $_FILES['rules_file'] : null;
        if (!$f || !empty($f['error']) || !is_uploaded_file($f['tmp_name'])) {
            return 'imported=0&ifail=' . $b64('файл не получен');
        }
        if ($f['size'] > 2 * 1048576) return 'imported=0&ifail=' . $b64('файл больше 2 МБ');
        if (!$this->ensureRulesTable()) return 'imported=0&ifail=' . $b64('нет таблицы sanotts_rules');
        list($rules, $errors) = SanottsRules::parse((string)file_get_contents($f['tmp_name']));
        if (gr('import_mode') === 'replace') {
            if (!$rules) return 'imported=0&ifail=' . $b64('в файле нет правил — замена отменена');
            SQLExec('DELETE FROM sanotts_rules');
        }
        $have = array();
        foreach ($this->loadRules() as $r) $have[$r['LANG'] . "\x1F" . $r['MATCH_TYPE'] . "\x1F" . $r['PATTERN']] = true;
        $max = SQLSelectOne('SELECT MAX(PRIORITY) AS M FROM sanotts_rules');
        $prio = isset($max['M']) ? (int)$max['M'] : 0;
        $added = 0;
        $skipped = 0;
        foreach ($rules as $r) {
            $k = $r['LANG'] . "\x1F" . $r['MATCH_TYPE'] . "\x1F" . $r['PATTERN'];
            if (isset($have[$k])) { $skipped++; continue; }
            $have[$k] = true;
            $prio += 10;
            $r['PRIORITY'] = $prio;
            $r['UPDATED'] = date('Y-m-d H:i:s');
            SQLInsert('sanotts_rules', $r);
            $added++;
        }
        $this->log("rules import: added $added, skipped $skipped, errors " . count($errors));
        return 'imported=' . $added . '&skipped=' . $skipped . ($errors ? '&ierr=' . $b64(implode("\n", array_slice($errors, 0, 20))) : '');
    }

    /**
     * На сайте (не в панели управления) модулю показывать нечего. Команды
     * админки отсюда не вызываются: иначе их мог бы выполнить любой
     * посетитель (/?action=sanotts&app_action=1&cmd=…).
     */
    function usual(&$out)
    {
    }

    // ------------------------------------------------------------------
    // Нормализация текста (общая с piper_tts)
    // ------------------------------------------------------------------

    public static function pluralize($n, $forms)
    {
        $n = abs((int)$n) % 100;
        $n1 = $n % 10;
        if ($n > 10 && $n < 20) return $forms[2];
        if ($n1 > 1 && $n1 < 5) return $forms[1];
        if ($n1 == 1) return $forms[0];
        return $forms[2];
    }

    public static function numberToText($n)
    {
        $n = (int)$n;
        if ($n === 0) return 'ноль';
        $units = array('', 'один', 'два', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять');
        $unitsFem = array('', 'одна', 'две', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять');
        $teens = array('десять', 'одиннадцать', 'двенадцать', 'тринадцать', 'четырнадцать', 'пятнадцать', 'шестнадцать', 'семнадцать', 'восемнадцать', 'девятнадцать');
        $tens = array('', '', 'двадцать', 'тридцать', 'сорок', 'пятьдесят', 'шестьдесят', 'семьдесят', 'восемьдесят', 'девяносто');
        $hundreds = array('', 'сто', 'двести', 'триста', 'четыреста', 'пятьсот', 'шестьсот', 'семьсот', 'восемьсот', 'девятьсот');
        $result = '';
        if ($n >= 1000) {
            $th = (int)($n / 1000);
            $n %= 1000;
            $thText = $th < 10 ? $unitsFem[$th] : self::numberToText($th);
            $result .= $thText . ' ' . self::pluralize($th, array('тысяча', 'тысячи', 'тысяч')) . ' ';
        }
        if ($n >= 100) {
            $result .= $hundreds[(int)($n / 100)] . ' ';
            $n %= 100;
        }
        if ($n >= 20) {
            $result .= $tens[(int)($n / 10)] . ' ';
            $n %= 10;
        } elseif ($n >= 10) {
            $result .= $teens[$n - 10] . ' ';
            $n = 0;
        }
        if ($n > 0) $result .= $units[$n] . ' ';
        return trim($result);
    }

    public static function expandDecimal($str)
    {
        if (!preg_match('/^(\d+)[.,](\d+)$/', $str, $m)) return $str;
        $whole = (int)$m[1];
        $frac = $m[2];
        $fracInt = (int)$frac;
        if ($fracInt === 0) return self::numberToText($whole);
        $wholeText = self::numberToText($whole);
        if (strlen($frac) === 1 && $fracInt === 5) return $wholeText . ' с половиной';
        if (strlen($frac) === 1) {
            return $wholeText . ' целых ' . self::numberToText($fracInt) . ' ' .
                self::pluralize($fracInt, array('десятая', 'десятых', 'десятых'));
        }
        return $wholeText . ' целых ' . self::numberToText($fracInt);
    }

    private static function unitCallback($forms, $suffix = '')
    {
        return function ($m) use ($forms, $suffix) {
            $num = str_replace(',', '.', $m[1]);
            $whole = (int)$num;
            $spoken = strpos($num, '.') !== false ? self::expandDecimal($num) : $m[1];
            $form = strpos($num, '.') !== false ? $forms[1] : self::pluralize($whole, $forms);
            return $spoken . ' ' . $form . $suffix;
        };
    }

    public static function preprocessText($text)
    {
        // Скорость с числом согласуем («3 метра в секунду»), без числа — общий падеж.
        $text = preg_replace_callback('/(\d+(?:[.,]\d+)?)\s*км\s*\/\s*ч/ui', self::unitCallback(array('километр', 'километра', 'километров'), ' в час'), $text);
        $text = preg_replace_callback('/(\d+(?:[.,]\d+)?)\s*м\s*\/\s*с/ui', self::unitCallback(array('метр', 'метра', 'метров'), ' в секунду'), $text);
        $text = preg_replace('/км\s*\/\s*ч/ui', 'километров в час', $text);
        $text = preg_replace('/м\s*\/\s*с/ui', 'метров в секунду', $text);

        // Знак минуса перед числом: espeak иначе читает дефис как паузу.
        $text = preg_replace('/(^|[\s(])[-\x{2212}](\d)/u', '$1минус $2', $text);

        $text = preg_replace_callback('/(\d+(?:[.,]\d+)?)\s*°\s*C/ui', self::unitCallback(array('градус', 'градуса', 'градусов')), $text);
        $text = preg_replace_callback('/(\d+(?:[.,]\d+)?)\s*%/u', self::unitCallback(array('процент', 'процента', 'процентов')), $text);
        $text = preg_replace_callback('/(\d+(?:[.,]\d+)?)\s*мм\s+рт\.?\s*ст\.?/ui', self::unitCallback(array('миллиметр', 'миллиметра', 'миллиметров'), ' ртутного столба'), $text);
        $text = preg_replace_callback('/(\d+(?:[.,]\d+)?)\s*мм\b/u', self::unitCallback(array('миллиметр', 'миллиметра', 'миллиметров')), $text);

        // Время 07:05 — «семь часов пять минут», а не «семь двоеточие ноль пять».
        $text = preg_replace_callback('/\b([01]?\d|2[0-3]):([0-5]\d)\b/u', function ($m) {
            $h = (int)$m[1];
            $min = (int)$m[2];
            $res = self::numberToText($h) . ' ' . self::pluralize($h, array('час', 'часа', 'часов'));
            if ($min > 0) {
                $minText = self::numberToText($min);
                $minText = preg_replace(array('/\bодин$/u', '/\bдва$/u'), array('одна', 'две'), $minText);
                $res .= ' ' . $minText . ' ' . self::pluralize($min, array('минута', 'минуты', 'минут'));
            }
            return $res;
        }, $text);

        $text = preg_replace_callback('/\b(\d+)[.,](\d+)\b/u', function ($m) {
            return self::expandDecimal($m[1] . '.' . $m[2]);
        }, $text);

        $tzPos = array(
            0 => 'гринвичу', 1 => 'центральноевропейскому', 2 => 'калининграду',
            3 => 'москве', 4 => 'самаре', 5 => 'екатеринбургу',
            6 => 'омску', 7 => 'красноярску', 8 => 'иркутску',
            9 => 'якутску', 10 => 'владивостоку', 11 => 'магадану', 12 => 'камчатке',
        );
        $tzNeg = array(
            1 => 'азорам', 2 => 'бразилии', 3 => 'аргентине', 4 => 'нью-йорку', 5 => 'чикаго',
            6 => 'денверу', 7 => 'лос-анджелесу', 8 => 'анкориджу', 9 => 'гавайям', 10 => 'острову пасхи',
        );
        $text = preg_replace_callback('/\bUTC([+-]\d{1,2})\b/u', function ($m) use ($tzPos, $tzNeg) {
            $offset = (int)$m[1];
            if ($offset >= 0 && isset($tzPos[$offset])) return 'по ' . $tzPos[$offset];
            if ($offset < 0 && isset($tzNeg[-$offset])) return 'по ' . $tzNeg[-$offset];
            return 'UTC' . $m[1];
        }, $text);
        $tz = array(
            'UTC(?![-+])' => 'гринвичу', 'GMT' => 'гринвичу', 'MSK' => 'москве',
            'EDT' => 'нью-йорку', 'EST' => 'нью-йорку', 'PDT' => 'лос-анджелесу', 'PST' => 'лос-анджелесу',
            'CDT' => 'чикаго', 'CST' => 'чикаго', 'MDT' => 'денверу', 'MST' => 'денверу',
            'CET' => 'центральноевропейскому', 'CEST' => 'центральноевропейскому',
            'EET' => 'восточноевропейскому', 'EEST' => 'восточноевропейскому',
            'BST' => 'лондону', 'JST' => 'токио', 'KST' => 'сеулу',
        );
        foreach ($tz as $abbr => $city) {
            $text = preg_replace('/\b' . $abbr . '\b/u', 'по ' . $city, $text);
        }
        return $text;
    }

    /**
     * Очистка перед синтезом. Пунктуацию оставляем: по ней модель делает
     * паузы и интонацию, а sanotts_cli режет текст на предложения по . ! ? …
     */
    public static function cleanText($message, $lang = 'ru')
    {
        // Числа, единицы и часовые пояса прописью — только по-русски; для других
        // языков цифры читает espeak на языке голоса.
        $clean = $lang === 'ru' ? self::preprocessText((string)$message) : (string)$message;
        $clean = str_replace(array("\r\n", "\r", "\n", "\t"), ' ', $clean);
        // \p{M} — огласовки и знаки деванагари/арабицы: без них хинди и арабский
        // превращаются в бессмыслицу. Плюс знаки препинания других письменностей.
        $clean = preg_replace('/[^\p{L}\p{M}\p{N}\s.,!?;:\x{2013}\x{2014}\x{2019}\x{2026}()\x{00A1}\x{00BF}\x{00AB}\x{00BB}\x{060C}\x{061B}\x{061F}\x{0964}\x{0965}\x{3001}\x{3002}\x{FF01}\x{FF0C}\x{FF1F}\x{0027}\x{00B0}%-]/u', ' ', $clean);
        $clean = preg_replace('/\s+/u', ' ', $clean);
        return trim($clean);
    }

    // ------------------------------------------------------------------
    // Синтез в кэш и доставка
    // ------------------------------------------------------------------

    private function synthesizeToFile($message, $path)
    {
        $clean = $this->textForSynthesis($message);
        if (trim($clean) === '') return;

        CreateDir(dirname($path));
        $raw = $path . '.raw.wav';
        if (!$this->engineSynthesize($clean, $raw)) {
            @unlink($raw);
            $this->log("synthesis failed for: $path");
            return;
        }

        rename($raw, $path);
    }

    /**
     * Нарезка на предложения — та же, что у sanotts_cli (он синтезирует каждое
     * предложение отдельно): после . ! ? … । ॥ ؟ перед пробелом, после 。！？ сразу.
     * Звучание от нарезки не меняется — меняется только то, когда звук готов.
     */
    public static function splitSentences($text)
    {
        $parts = preg_split('/(?<=[.!?\x{2026}\x{0964}\x{0965}\x{061F}])\s+|(?<=[\x{3002}\x{FF01}\x{FF1F}])\s*/u', trim((string)$text));
        $out = array();
        foreach ((array)$parts as $p) {
            $p = trim($p);
            // Кусок без букв и цифр («...», «!») отдельно не озвучить — приклеиваем к предыдущему.
            if ($p === '') continue;
            if ($out && !preg_match('/[\p{L}\p{N}]/u', $p)) { $out[count($out) - 1] .= ' ' . $p; continue; }
            $out[] = $p;
        }
        // Обрывки вроде «Т.» из «т. е.» — к следующему куску: движок сам разрежет их
        // так же, звук не изменится, а лишнего запуска программы не будет.
        $merged = array();
        $carry = '';
        foreach ($out as $p) {
            $p = $carry !== '' ? $carry . ' ' . $p : $p;
            $carry = '';
            if (preg_match_all('/./su', $p) <= 5) { $carry = $p; continue; }
            $merged[] = $p;
        }
        if ($carry !== '') {
            if ($merged) $merged[count($merged) - 1] .= ' ' . $carry; else $merged[] = $carry;
        }
        return $merged;
    }

    /**
     * Одно предложение (уже очищенный текст) в файл кэша. $first — первое во
     * фразе: перед ним «тишина в начале», перед остальными — пауза между предложениями.
     */
    private function renderSentence($clean, $first)
    {
        $this->leadOverride = $first ? null : max(0.0, min(1.0, (float)$this->config['SENTENCE_SILENCE']));
        $cacheDir = $this->config['CACHE_DIR'];
        $wavFile = $cacheDir . '/sanotts_' . md5('sentence|' . $this->getCacheKey($clean)) . '.wav';
        CreateDir($cacheDir);
        if ((int)$this->config['USE_CACHE'] !== 1 || !file_exists($wavFile)) {
            $raw = $wavFile . '.raw.wav';
            if ($this->engineSynthesize($clean, $raw)) {
                rename($raw, $wavFile);
            } else {
                @unlink($raw);
            }
        } elseif ((int)$this->config['CACHE_CLEANUP'] === 1) {
            @touch($wavFile);
        }
        $this->leadOverride = null;
        return file_exists($wavFile) ? $wavFile : null;
    }

    /** Файл кэша для предложения ($first — первое во фразе: другая тишина в начале). */
    private function sentenceCacheFile($clean, $first)
    {
        $this->leadOverride = $first ? null : max(0.0, min(1.0, (float)$this->config['SENTENCE_SILENCE']));
        $f = $this->config['CACHE_DIR'] . '/sanotts_' . md5('sentence|' . $this->getCacheKey($clean)) . '.wav';
        $this->leadOverride = null;
        return $f;
    }

    /**
     * Предложения — в WAV одним запуском sanotts_cli (-O): голос и словарь
     * загружаются один раз, каждое предложение готово сразу после синтеза.
     * $onReady(номер, путь) вызывается строго по порядку предложений, как
     * только очередное готово (из кэша — сразу). Пропущенные (нечего
     * произнести, ошибка) — $onReady(номер, null).
     * false — потоковый режим недоступен (нет proc_open): вызывающий идёт
     * по предложениям отдельными запусками.
     */
    private function streamSentences(array $sentences, $onReady)
    {
        $n = count($sentences);
        $files = array();
        $ready = array();
        $todo = array();
        $useCache = (int)$this->config['USE_CACHE'] === 1;
        CreateDir($this->config['CACHE_DIR']);
        foreach ($sentences as $i => $snt) {
            $files[$i] = $this->sentenceCacheFile($snt, $i == 0);
            if ($useCache && is_file($files[$i])) {
                $ready[$i] = $files[$i];
                if ((int)$this->config['CACHE_CLEANUP'] === 1) @touch($files[$i]);
            } else {
                $todo[] = $i;
            }
        }
        $next = 0;
        $flush = function () use (&$next, &$ready, $n, $onReady) {
            while ($next < $n && array_key_exists($next, $ready)) {
                call_user_func($onReady, $next, $ready[$next]);
                $next++;
            }
        };
        $flush();
        if (!$todo) return true;

        $prep = $this->prepareSynthesis();
        if ($prep === null) {
            foreach ($todo as $i) $ready[$i] = null;
            $flush();
            return true;
        }
        list($dir, $vi) = $prep;
        $lines = array();
        $idsMode = $this->ruActive($vi);
        if ($idsMode) {
            // Русский: каждая фраза (splitChunks) — строка кодов фонем.
            $chunks = array();
            foreach ($todo as $i) $chunks[] = $sentences[$i];
            $lines = $this->ruToIds($chunks, $vi);
            if ($lines === null) {
                foreach ($todo as $i) $ready[$i] = null;
                $flush();
                return true;
            }
            $text = implode("\n", $lines);
        } else {
            foreach ($todo as $i) $lines[] = str_replace(array("\r", "\n"), ' ', $sentences[$i]);
            // Огласовки — одним запуском на все предложения; перевод строки утилита не трогает.
            $text = $this->withTashkeel(implode("\n", $lines), $vi);
            if (count(explode("\n", $text)) !== count($lines)) $text = implode("\n", $lines);
        }

        $prefix = $this->config['CACHE_DIR'] . '/sanotts_part_' . getmypid() . '_' . mt_rand() . '_';
        // Первая строка получает -l: «тишина в начале», если это первое предложение
        // фразы, иначе — пауза между предложениями (как у остальных строк).
        $lead = $todo[0] == 0 ? $this->getLeadSilence() : max(0.0, min(1.0, (float)$this->config['SENTENCE_SILENCE']));
        $args = array_merge($this->cliArgs($dir), array('-O', $prefix, '-l', sprintf('%.2f', $lead)));
        if ($idsMode) $args[] = '-I';
        $res = SanottsEngine::runStream($args, $text, function ($line) use ($todo, $files, &$ready, $flush) {
            if (!preg_match('/^(\d+) (.+)$/', $line, $m) || !isset($todo[(int)$m[1]])) return;
            $i = $todo[(int)$m[1]];
            $ready[$i] = null;
            if ($m[2] !== '-' && is_file($m[2])) {
                @unlink($files[$i]);
                $ready[$i] = @rename($m[2], $files[$i]) ? $files[$i] : null;
            }
            $flush();
        });
        if ($res === null) return false;
        list(, $rc, $err) = $res;
        foreach ($todo as $i) {
            if (!array_key_exists($i, $ready)) $ready[$i] = null;
        }
        foreach ((array)glob($prefix . '*') as $f) @unlink($f);
        if ($rc !== 0 && $err !== '') {
            $this->lastError = 'sanotts_cli rc=' . $rc . ': ' . $err;
            $this->log($this->lastError);
        }
        $flush();
        return true;
    }

    /** Для замыканий (PHP 5.x не пускает их к private-методам). */
    public function logPublic($msg) { $this->log($msg); }
    public function webUrlPublic($wavFile) { return $this->webUrl($wavFile); }

    /**
     * Текст и голос для кнопок «Проверить» и «Скачать»: голос из списка на
     * странице (можно не сохранять настройки), текст — t64 (base64url) или
     * пример на языке голоса.
     */
    private function testText()
    {
        $v = self::sanitizeVoice(gr('voice'));
        if ($v !== null && ($vd = $this->voiceDir($v)) !== null && self::isVoiceDir($vd)) {
            $this->config['VOICE'] = $v;
        }
        // Темп, высота и тембр — как выставлены на странице (сохранять не обязательно).
        $ls = gr('ls', '');
        if ($ls !== '' && is_numeric($ls)) $this->config['LENGTH_SCALE'] = sprintf('%.2f', max(0.5, min(2.0, (float)$ls)));
        $this->applyVoiceShape(gr('pitch', null), gr('bass', null), gr('treble', null));
        $text = trim((string)gr('text'));
        $t64 = (string)gr('t64');
        $key = (string)gr('tk');
        if ($t64 === '' && preg_match('/^[a-f0-9]{16,40}$/', $key) && is_dir(self::testTextDir($key))) {
            // Длинный текст, присланный частями (test_put).
            $parts = (array)glob(self::testTextDir($key) . '/[0-9][0-9][0-9][0-9]');
            sort($parts, SORT_STRING);
            foreach ($parts as $f) $t64 .= (string)@file_get_contents($f);
            self::removeTree(self::testTextDir($key));
        }
        if ($t64 !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $t64)) {
            $dec = base64_decode(strtr($t64, '-_', '+/'), true);
            if ($dec !== false && preg_match('//u', $dec)) $text = trim($dec);
        }
        if ($text === '') {
            $samples = self::samplePhrases();
            $lang = $this->currentLang();
            $text = isset($samples[$lang]) ? $samples[$lang] : $samples['ru'];
        }
        return $text;
    }

    /** Каталог задания кнопки «Проверить». */
    private static function testJobDir($job)
    {
        return self::tmpDir() . '/sanotts_job_' . $job;
    }

    /** Части длинного текста кнопок «Проверить»/«Скачать». */
    private static function testTextDir($key)
    {
        return self::tmpDir() . '/sanotts_txt_' . $key;
    }

    /** Брошенные задания (вкладку закрыли до конца) — старше 10 минут. */
    private static function cleanupTestJobs()
    {
        foreach (array_merge((array)glob(self::tmpDir() . '/sanotts_job_*', GLOB_ONLYDIR), (array)glob(self::tmpDir() . '/sanotts_txt_*', GLOB_ONLYDIR)) as $d) {
            if (time() - (int)@filemtime($d) > 600) self::removeTree($d);
        }
    }

    /** URL файла кэша для браузера. */
    private function webUrl($wavFile)
    {
        // Пути сравниваем с прямыми слешами: на Windows в них бывают и «\\», и «/».
        $wavN = str_replace('\\', '/', $wavFile);
        $root = defined('ROOT') ? str_replace('\\', '/', SanottsEngine::trimDir(ROOT)) : '';
        if ($root !== '' && stripos($wavN, $root . '/') === 0) {
            $webPath = substr($wavN, strlen($root));
        } else {
            $docRoot = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] !== ''
                ? str_replace('\\', '/', SanottsEngine::trimDir($_SERVER['DOCUMENT_ROOT'])) : '/var/www/html';
            $webPath = str_ireplace($docRoot, '', $wavN);
        }
        return '/' . ltrim(str_replace('\\', '/', $webPath), '/');
    }

    private function cacheKeyPart($value)
    {
        $value = (string)$value;
        return strlen($value) . ':' . $value;
    }

    private function getCacheKey($message)
    {
        // Ключ зависит от всех параметров синтеза, иначе после смены голоса
        // или темпа повтор фразы отдаст старый WAV.
        return 'sanotts3|' . $this->cacheKeyPart($message)
            . $this->cacheKeyPart($this->config['VOICE'])
            . $this->cacheKeyPart($this->config['LENGTH_SCALE'])
            . ((float)$this->config['PITCH'] || (float)$this->config['BASS'] || (float)$this->config['TREBLE']
                ? $this->cacheKeyPart('shape=' . self::fmtNum($this->config['PITCH']) . '/' . self::fmtNum($this->config['BASS']) . '/' . self::fmtNum($this->config['TREBLE'])) : '')
            . $this->cacheKeyPart($this->config['SENTENCE_SILENCE'])
            . $this->cacheKeyPart($this->getLeadSilence())
            . $this->cacheKeyPart($this->loudnessArg())
            . ($this->currentLang() === 'ar' ? $this->cacheKeyPart('tashkeel=' . (int)$this->config['TASHKEEL']) : '')
            . ($this->ruActive() ? $this->cacheKeyPart('ru=' . SanottsRuText::VERSION . '/' . self::VERSION) : '')
            . (($st = SanottsRules::stamp($this->activeRules())) !== '' ? $this->cacheKeyPart('rules=' . $st) : '');
    }

    /**
     * Синтез фразы в файл кэша (или взятие готового). Используется и событием
     * SAY, и аддоном terminals. Возвращает путь к WAV или null.
     */
    public function renderToCache($message)
    {
        if (empty($this->config)) $this->getConfig();
        $cacheDir = $this->config['CACHE_DIR'];
        $useCache = (int)$this->config['USE_CACHE'] === 1;
        $wavFile = $cacheDir . '/sanotts_' . md5($this->getCacheKey($message)) . '.wav';

        CreateDir($cacheDir);

        if (!$useCache || !file_exists($wavFile)) {
            $this->synthesizeToFile($message, $wavFile);
        } elseif ((int)$this->config['CACHE_CLEANUP'] === 1) {
            @touch($wavFile);
        }
        if ((int)$this->config['CACHE_CLEANUP'] === 1) $this->cleanupCache();

        return file_exists($wavFile) ? $wavFile : null;
    }

    // ------------------------------------------------------------------
    // TTS для модуля «Терминалы» (modules/terminals/tts/sanotts.addon.php):
    // те же методы, что у tts_addon — say(), ask(), sayCached()
    // ------------------------------------------------------------------

    public function say($phrase, $level = 0)
    {
        return $this->terminalPlayPhrase($phrase);
    }

    public function ask($phrase, $level = 0)
    {
        return $this->say($phrase, $level);
    }

    public function sayCached($phrase, $level = 0, $cached_file = '')
    {
        if ($cached_file !== '' && file_exists($cached_file) && $this->terminalPlayFile($cached_file)) return true;
        return $this->terminalPlayPhrase($phrase);
    }

    private function terminalPlayPhrase($phrase)
    {
        if (empty($this->config)) $this->getConfig();
        $file = $this->renderToCache($phrase);
        if ($file === null) {
            $this->log('terminal: synthesis failed');
            return false;
        }
        return $this->terminalPlayFile($file);
    }

    /** Проигрывание WAV на сервере; программы запускаются без оболочки. */
    private function terminalPlayFile($file)
    {
        if (SanottsEngine::isWindows()) {
            $ps = "(New-Object System.Media.SoundPlayer '" . str_replace("'", "''", $file) . "').PlaySync()";
            list($ok, $rc, ) = SanottsEngine::run(array('powershell', '-NoProfile', '-NonInteractive', '-Command', $ps));
            return $ok && $rc === 0;
        }
        static $have = array();
        $players = array(
            'paplay'     => array('paplay', $file),
            'aplay'      => array('aplay', '-q', $file),
            'ffplay'     => array('ffplay', '-nodisp', '-autoexit', '-loglevel', 'quiet', $file),
            'play-audio' => array('play-audio', $file),   // Termux: pkg install play-audio
        );
        foreach ($players as $cmd => $args) {
            if (!isset($have[$cmd])) $have[$cmd] = SanottsEngine::haveTool($cmd);
            if (!$have[$cmd]) continue;
            SanottsEngine::run($args);
            return true;
        }
        $this->log('terminal: no audio player (paplay, aplay, ffplay, play-audio)');
        return false;
    }

    function processSubscription($event, &$details)
    {
        $this->getConfig();

        $message = '';
        if (isset($details['MESSAGE'])) {
            $message = $details['MESSAGE'];
        } elseif (isset($details['message'])) {
            $message = $details['message'];
        }
        if (empty($message)) return;

        $destination = isset($details['destination']) ? $details['destination'] : '';

        // В браузер — по предложениям: первое звучит, пока синтезируются следующие
        // (плеер sanotts.js ставит их в очередь). Терминалам (SAYTO) нужен один файл.
        if (!($event == 'SAYTO' && $destination !== '') && (int)$this->config['STREAM']) {
            $sentences = $this->splitForStream($this->textForSynthesis($message));
            if (count($sentences) > 1) {
                $t0 = microtime(true);
                $self = $this;
                $post = function ($i, $wav) use ($self, $t0, $sentences) {
                    if ($wav === null) { $self->logPublic("sentence $i skipped: " . $sentences[$i]); return; }
                    $url = $self->webUrlPublic($wav);
                    $result = postToWebSocket('SANOTTS', array('COMMAND' => 'PlayAudio', 'URL' => $url), 'PostEvent');
                    if ($i == 0) $self->logPublic(sprintf('first sentence ready in %.2f s', microtime(true) - $t0));
                    if ($result === false) $self->logPublic("postToWebSocket failed url=$url");
                };
                // Один запуск движка на всё сообщение; без proc_open — по предложению за запуск.
                if ($this->streamSentences($sentences, $post) === false) {
                    foreach ($sentences as $i => $sentence) $post($i, $this->renderSentence($sentence, $i == 0));
                }
                if ((int)$this->config['CACHE_CLEANUP'] === 1) $this->cleanupCache();
                return;
            }
        }

        $wavFile = $this->renderToCache($message);
        if ($wavFile === null) {
            $this->log('wav not found after synthesis');
            return;
        }
        $url = $this->webUrl($wavFile);

        if ($event == 'SAYTO' && $destination !== '') {
            $level = isset($details['level']) ? $details['level'] : (isset($details['IMPORTANCE']) ? $details['IMPORTANCE'] : 0);
            processSubscriptionsSafe('SAY_CACHED_READY', array(
                'level'       => $level,
                'filename'    => $wavFile,
                'event'       => 'SAYTO',
                'destination' => $destination,
                'message'     => $message,
            ));
            $this->log("SAY_CACHED_READY dest=$destination url=$url");
            return;
        }

        $result = postToWebSocket('SANOTTS', array('COMMAND' => 'PlayAudio', 'URL' => $url), 'PostEvent');
        $this->log('postToWebSocket result=' . ($result === false ? 'false' : 'ok') . " url=$url");
    }

    private function cleanupCache()
    {
        $dir = $this->config['CACHE_DIR'];
        if (!is_dir($dir)) return;
        $now = time();
        foreach ((array)glob($dir . '/sanotts_*.wav') as $f) {
            if ($now - @filemtime($f) > 864000) @unlink($f); // 10 дней
        }
    }

    // ------------------------------------------------------------------
    // Установка движка
    // ------------------------------------------------------------------

    /**
     * Запуск установщика (bin/installer.php). Если есть консольный PHP —
     * отдельным фоновым процессом: ему не страшны тайм-ауты веб-сервера.
     * Иначе (Windows/XAMPP, запрещённый exec, open_basedir) возвращает
     * установщик, и admin() выполняет его прямо в этом запросе, отдав
     * браузеру ответ заранее. Возвращает null, если установка уже идёт
     * или запущена в фоне.
     */
    private function runEngineInstall()
    {
        list($attempt, ) = $this->installAttempt();
        if ($attempt == 'installing') return null;

        // Каждая попытка — в своём каталоге. Файлы с фиксированными именами в /tmp
        // могли остаться от прошлых запусков с владельцем root, и тогда запись
        // в них из-под www-data молча не удавалась.
        $run = self::tmpDir() . '/sanotts_install_' . date('Ymd_His') . '_' . getmypid();
        if (!@mkdir($run, 0777)) {
            $this->log("cannot create $run");
            return null;
        }
        @chmod($run, 0777);
        $voice = self::sanitizeVoice($this->config['VOICE']);
        if ($voice === null) $voice = 'irina';
        $base = $this->chooseBase();
        $voicesDir = SanottsEngine::trimDir($this->config['BASE']) === $base ? $this->config['VOICES_DIR'] : $base . '/voices';
        $job = array('base' => $base, 'voices_dir' => $voicesDir, 'voice' => $voice, 'log' => $run . '/install.log');
        file_put_contents($run . '/job.json', json_encode($job));
        file_put_contents(self::installStateFile(), json_encode(array('t' => time(), 'run' => $run, 'base' => $base)));

        $php = SanottsEngine::phpCli();
        if ($php !== null && !SanottsEngine::isWindows() && self::functionEnabled('exec')) {
            $script = dirname(__FILE__) . '/bin/install.php';
            exec(escapeshellarg($php) . ' ' . escapeshellarg($script) . ' --job=' . escapeshellarg($run . '/job.json') .
                ' < /dev/null > ' . escapeshellarg($run . '/launch.log') . ' 2>&1 &');
            // Убеждаемся, что процесс стартовал; нет — ставим прямо здесь.
            for ($i = 0; $i < 30; $i++) {
                clearstatcache();
                if (@filesize($run . '/install.log') > 0) {
                    $this->log("install started in background: $run (base=$base, php=$php)");
                    return null;
                }
                usleep(100000);
            }
            $this->log("background install did not start (" . trim((string)@file_get_contents($run . '/launch.log')) . "), running inline");
            @file_put_contents($run . '/launch.log', '');
        }
        $this->log("install inline: $run (base=$base)");
        return new SanottsEngine(dirname(__FILE__) . '/native', $base, $voicesDir, $voice, $run . '/install.log');
    }

    // ------------------------------------------------------------------
    // Установка / удаление модуля
    // ------------------------------------------------------------------

    function install($data = '')
    {
        subscribeToEvent($this->name, 'SAY', '', 110);
        subscribeToEvent($this->name, 'SAYTO', '', 110);
        subscribeToEvent($this->name, 'SAYREPLY', '', 110);

        // Общий загрузчик modules/prepend.php — тот же, что у piper_tts и vosk:
        // он подключает prepend.php каждого активного модуля.
        $loaderPath = ROOT . 'modules/prepend.php';
        $htaccessPath = ROOT . '.htaccess';

        if (!file_exists($loaderPath)) {
            $loaderContent = <<<'PHP'
<?php
if (PHP_SAPI === 'cli') return;

$configFile = __DIR__ . '/../config.php';
if (!file_exists($configFile)) return;
include_once $configFile;

$link = null;
try {
    $link = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    if (!$link) return;

    $result = mysqli_query($link, "SELECT NAME FROM project_modules WHERE HIDDEN=0");
    if (!$result) return;

    while ($row = mysqli_fetch_assoc($result)) {
        $prepend = __DIR__ . '/' . $row['NAME'] . '/prepend.php';
        if (file_exists($prepend)) {
            include_once $prepend;
        }
    }
} catch (Throwable $e) {
    return;
} finally {
    if ($link instanceof mysqli) {
        @mysqli_close($link);
    }
}
PHP;
            file_put_contents($loaderPath, $loaderContent);
            $this->log('install: created modules/prepend.php');
        }

        if (file_exists($htaccessPath)) {
            $htContent = file_get_contents($htaccessPath);
            if (strpos($htContent, 'modules/prepend.php') === false) {
                $htContent = 'php_value auto_prepend_file ' . $loaderPath . "\n" . $htContent;
                file_put_contents($htaccessPath, $htContent);
                $this->log('install: added auto_prepend_file to .htaccess');
            }
        }

        parent::install();
    }

    function uninstall()
    {
        unsubscribeFromEvent($this->name, 'SAY');
        unsubscribeFromEvent($this->name, 'SAYTO');
        unsubscribeFromEvent($this->name, 'SAYREPLY');

        $loaderPath = ROOT . 'modules/prepend.php';
        $htaccessPath = ROOT . '.htaccess';

        // Загрузчик общий: снимаем его, только если им больше никто не пользуется.
        $othersInstalled = false;
        try {
            $res = SQLSelect("SELECT ID FROM project_modules WHERE NAME IN ('vosk','piper_tts') AND HIDDEN=0");
            $othersInstalled = is_array($res) && count($res) > 0;
        } catch (Throwable $e) {
            $othersInstalled = true;
        }

        if (!$othersInstalled) {
            if (file_exists($htaccessPath)) {
                $line = 'php_value auto_prepend_file ' . $loaderPath;
                $htContent = file_get_contents($htaccessPath);
                $htContent = str_replace(array($line . "\r\n", $line . "\n", $line), '', $htContent);
                file_put_contents($htaccessPath, preg_replace('/\n{3,}/', "\n\n", $htContent));
            }
            if (file_exists($loaderPath)) @unlink($loaderPath);
        }

        // Свои правила произношения — вместе с модулем (перед удалением их можно
        // выгрузить кнопкой «Экспорт»).
        if (function_exists('SQLDropTable')) @SQLDropTable('sanotts_rules');

        // Каталог движка (cms/sanotts) модуль не удаляет: голоса и словари могут
        // пригодиться при переустановке. Удалить вручную — см. README.
        parent::uninstall();
    }
}
