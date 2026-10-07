<?php
/**
 * Установщик движка sanoTTS — на чистом PHP, без bash, nohup, curl и git.
 * Одинаково работает на Linux, в Android/Termux и на Windows (XAMPP):
 *
 *   * sanotts_cli — готовая сборка для своей платформы из репозитория
 *     движка sanotts-engine (espeak-ng встроен); скачивается при установке
 *     в cms/sanotts/engine/<платформа>/ и оттуда ставится в bin/;
 *   * всё остальное тоже качается по HTTPS по списку native/data/sources.tsv:
 *     базовые данные espeak-ng, голос, словари языков, исходники русского
 *     словаря (+ список словоформ ru_listx, 23 МБ), словарь ё; у каждого
 *     файла проверяется sha256. С GitHub — с запасным зеркалом jsDelivr.
 *
 * Используется кнопкой «Установить движок» и из консоли (bin/install.php).
 * Помощники (платформа, скачивание, запуск программы) использует и модуль.
 */
class SanottsEngine
{
    public $native;
    public $base;
    public $voicesDir;
    public $voice;
    private $logFile;
    private $echo;

    function __construct($native, $base, $voicesDir, $voice, $logFile, $echo = false)
    {
        $this->native = self::trimDir($native);
        $this->base = self::trimDir($base);
        $this->voicesDir = self::trimDir($voicesDir);
        $this->voice = (string)$voice;
        $this->logFile = $logFile;
        $this->echo = $echo;
    }

    // ------------------------------------------------------------------
    // Платформа
    // ------------------------------------------------------------------

    public static function isWindows()
    {
        return DIRECTORY_SEPARATOR === '\\';
    }

    /** Android (в том числе Termux). */
    public static function isAndroid()
    {
        return !self::isWindows() &&
            (getenv('ANDROID_ROOT') !== false || is_file('/system/bin/linker64') || is_file('/system/bin/linker'));
    }

    /** Имя программы: sanotts_cli.exe на Windows, иначе sanotts_cli. */
    public static function cliName()
    {
        return self::isWindows() ? 'sanotts_cli.exe' : 'sanotts_cli';
    }

    /** Проигрыватель WAV для Android (AAudio / OpenSL ES): sanotts_play. */
    public static function playerName()
    {
        return 'sanotts_play';
    }

    /** Утилита огласовок для арабского: sanotts_tashkeel(.exe). */
    public static function tashkeelName()
    {
        return self::isWindows() ? 'sanotts_tashkeel.exe' : 'sanotts_tashkeel';
    }

    /** Веса модели огласовок (libtashkeel, как в Piper) в каталоге движка. */
    public static function tashkeelModel($base)
    {
        return self::trimDir($base) . '/share/tashkeel/tashkeel-q16.bin';
    }

    /**
     * Каталог программ движка. Обычно BASE/bin. Но на общей памяти Android
     * (/storage/emulated/0 — там сайты у KSWEB и подобных серверов) запускать
     * программы нельзя: файлы копируются, а запуск падает (rc=127). Тогда
     * установщик кладёт программы во внутренний каталог приложения веб-сервера
     * и запоминает его в BASE/.bindir; данные и голоса остаются в BASE.
     */
    public static function binDir($base)
    {
        $base = self::trimDir($base);
        $f = $base . '/.bindir';
        if (is_file($f)) {
            $d = self::trimDir(trim((string)@file_get_contents($f)));
            if ($d !== '' && is_dir($d)) return $d;
        }
        return $base . '/bin';
    }

    /** Запускается ли программа (sanotts_cli без аргументов печатает подсказку и выходит). */
    public static function canRun($bin)
    {
        return self::probe($bin) === null;
    }

    /** Как canRun(), но с причиной: null — запускается, иначе строка (код и вывод). */
    public static function probe($bin)
    {
        if (!is_file($bin)) return 'нет файла';
        list($launched, $rc, $out) = self::run(array($bin));
        if ($launched && $rc !== 126 && $rc !== 127 && stripos($out, 'sanotts_cli') !== false) return null;
        $out = trim(preg_replace('/\s+/', ' ', $out));
        return 'rc=' . $rc . ($out !== '' ? ', ' . self::cut($out, 160) : '');
    }

    /** Первые $n символов строки UTF-8 (без mbstring). */
    private static function cut($s, $n)
    {
        return preg_match('/^.{0,' . (int)$n . '}/su', $s, $m) ? $m[0] : substr($s, 0, $n);
    }

    /**
     * Системный загрузчик Android. Приложения, собранные под Android 10+
     * (targetSdk 29+), не могут запускать файлы из своего каталога напрямую
     * (execve), но загружать из него код могут — поэтому программа
     * запускается как «/system/bin/linker64 программа аргументы», как в Termux.
     */
    public static function androidLinker()
    {
        $l = (strpos(self::prebuiltName(), 'armv7') !== false) ? '/system/bin/linker' : '/system/bin/linker64';
        return is_file($l) ? $l : null;
    }

    /**
     * Окружение запускаемой программы. Нашим программам (sanotts_cli,
     * sanotts_tashkeel, sanotts_play) нужны только системные libc/libm/libdl,
     * поэтому LD_LIBRARY_PATH и LD_PRELOAD для них убираются целиком: веб-сервер
     * (KSWEB и т. п.) передаёт PHP каталог своих библиотек, и его libjpeg ломает
     * загрузку OpenSL ES в sanotts_play. Внешним программам (paplay, aplay…)
     * LD_LIBRARY_PATH оставляем, убирая только неабсолютные пути (KSWEB передаёт
     * буквальный «$LD_LIBRARY_PATH» — загрузчик Android ругается на него).
     * null — окружение наследуется как есть.
     */
    public static function childEnv(array $args = array())
    {
        if (self::isWindows()) return null;
        $own = false;
        foreach (array_slice($args, 0, 2) as $a) {
            if (in_array(basename((string)$a), array(self::cliName(), self::tashkeelName(), self::playerName()), true)) $own = true;
        }
        $ld = getenv('LD_LIBRARY_PATH');
        $pre = getenv('LD_PRELOAD');
        if ($own) {
            if ($ld === false && $pre === false) return null;
            $env = getenv();
            if (!is_array($env) || !$env) return null;
            unset($env['LD_LIBRARY_PATH'], $env['LD_PRELOAD']);
            return $env;
        }
        if ($ld === false) return null;
        $keep = array();
        foreach (explode(':', $ld) as $p) if ($p !== '' && $p[0] === '/') $keep[] = $p;
        if (implode(':', $keep) === $ld) return null;
        $env = getenv();
        if (!is_array($env) || !$env) return null;
        if ($keep) $env['LD_LIBRARY_PATH'] = implode(':', $keep); else unset($env['LD_LIBRARY_PATH']);
        return $env;
    }

    /**
     * Команда запуска: если каталог программы помечен файлом .linker (см.
     * installBinary), впереди ставится загрузчик Android.
     */
    public static function launchArgs(array $args)
    {
        if ($args && !self::isWindows()) {
            $mark = dirname((string)$args[0]) . '/.linker';
            if (is_file($mark)) {
                $l = trim((string)@file_get_contents($mark));
                if ($l !== '' && is_file($l)) array_unshift($args, $l);
            }
        }
        return $args;
    }

    /**
     * Куда ещё можно положить программы, если из BASE/bin они не запускаются:
     * внутренний каталог приложения, в котором работает PHP (/data/data/<пакет>
     * или /data/user/N/<пакет> — его видно по пути к PHP, php.ini, расширениям),
     * HOME и временный каталог, если они не на общей памяти.
     */
    public static function execDirCandidates()
    {
        $roots = array();
        $paths = array(defined('PHP_BINARY') ? PHP_BINARY : '', (string)php_ini_loaded_file(), (string)ini_get('extension_dir'),
            (string)getenv('HOME'), (string)getenv('TMPDIR'), (string)sys_get_temp_dir(), (string)getenv('PREFIX'));
        foreach ($paths as $p) {
            if ($p !== '' && preg_match('#^(/data/(?:data|user/\d+)/[^/]+)#', $p, $m)) $roots[$m[1]] = true;
        }
        $c = array();
        foreach (array_keys($roots) as $r) {
            $c[] = $r . '/files/sanotts/bin';
            $c[] = $r . '/sanotts/bin';
        }
        foreach (array(getenv('HOME'), getenv('TMPDIR'), sys_get_temp_dir()) as $d) {
            $d = (string)$d;
            if ($d !== '' && !preg_match('#^/(storage|sdcard|mnt/sdcard)#', $d)) $c[] = self::trimDir($d) . '/sanotts/bin';
        }
        return array_values(array_unique($c));
    }

    /** Каталог скачанных программ движка для этой платформы: BASE/engine/<платформа>. */
    public static function engineDir($base)
    {
        return self::trimDir($base) . '/engine/' . self::prebuiltName();
    }

    /** Строка sources.tsv с программой $name для этой платформы или null. */
    public static function engineSource($native, $name)
    {
        foreach (self::tsv(self::trimDir($native) . '/data/sources.tsv') as $c) {
            if (count($c) >= 6 && $c[0] === 'engine' && $c[1] === self::prebuiltName() && $c[2] === $name) return $c;
        }
        return null;
    }

    /** Платформы, для которых в sources.tsv есть движок. */
    public static function enginePlatforms($native)
    {
        $p = array();
        foreach (self::tsv(self::trimDir($native) . '/data/sources.tsv') as $c) {
            if (count($c) >= 6 && $c[0] === 'engine') $p[$c[1]] = true;
        }
        return array_keys($p);
    }

    /** Файл совпадает с записью sources.tsv (размер и sha256). */
    public static function matches($file, array $c)
    {
        return is_file($file) && filesize($file) == (int)$c[3] && hash_file('sha256', $file) === $c[4];
    }

    /**
     * Проверенная копия программы $name (sanotts_cli, sanotts_tashkeel) для этой
     * платформы в BASE/engine/<платформа>/: уже лежит там (скачана раньше или
     * положена вручную), или берётся из установленной, или скачивается.
     * Возвращает array(путь, null) или array(null, ошибка).
     */
    public static function obtainProgram($native, $base, $name, $log = null)
    {
        $c = self::engineSource($native, $name);
        if ($c === null) {
            return array(null, 'нет готового ' . $name . ' для ' . self::arch() . ' (' . self::platformTitle() . ', ' . self::prebuiltName() .
                '). Есть: ' . implode(' ', self::enginePlatforms($native)));
        }
        $dir = self::engineDir($base);
        $path = $dir . '/' . $name;
        if (self::matches($path, $c)) return array($path, null);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return array(null, 'нет прав на создание ' . $dir);
        $inst = self::binDir($base) . '/' . $name;
        if (self::matches($inst, $c) && @copy($inst, $path . '.part') && @rename($path . '.part', $path)) return array($path, null);
        if (is_file($path)) {
            if ($log) call_user_func($log, '  ' . $path . ': другая версия или файл повреждён — скачиваю заново');
        }
        if ($log) call_user_func($log, '  ' . $c[5]);
        $err = self::fetch($c[5], $path, $c[4], $log);
        return $err === null ? array($path, null) : array(null, $err);
    }

    /**
     * Кладёт программу $src в $binDir под именем $name.
     * Ошибка — строка, успех — null.
     */
    public static function installProgram($src, $binDir, $name)
    {
        if (!is_file($src)) return 'нет файла ' . $src;
        if (!is_dir($binDir) && !@mkdir($binDir, 0755, true)) return 'нет прав на создание ' . $binDir;
        $dst = $binDir . '/' . $name;
        $new = $dst . '.new';
        if (!@copy($src, $new)) return 'не удалось скопировать ' . $src . ' в ' . $new;
        @chmod($new, 0755);
        if (!@rename($new, $dst)) {
            // Windows не даёт заменить запущенный .exe — пробуем ещё раз чуть позже.
            usleep(500000);
            @unlink($dst);
            if (!@rename($new, $dst)) { @unlink($new); return 'не удалось заменить ' . $dst . ' (занят?)'; }
        }
        return null;
    }

    /**
     * Утилита огласовок для арабского: скачать (если нужно) и поставить рядом
     * с движком. Ошибка — строка, успех — null.
     */
    public static function ensureTashkeelProgram($native, $base, $log = null)
    {
        $c = self::engineSource($native, self::tashkeelName());
        $dst = self::binDir($base) . '/' . self::tashkeelName();
        if ($c !== null && self::matches($dst, $c)) return null;
        if ($c === null && is_file($dst)) return null;
        list($src, $err) = self::obtainProgram($native, $base, self::tashkeelName(), $log);
        if ($err !== null) return $err;
        return self::installProgram($src, self::binDir($base), self::tashkeelName());
    }

    /**
     * Проигрыватель sanotts_play (только Android) — рядом с движком.
     * Ошибка — строка, успех — null; для платформы без проигрывателя — null.
     */
    public static function ensurePlayerProgram($native, $base, $log = null)
    {
        $c = self::engineSource($native, self::playerName());
        if ($c === null) return null;
        $dst = self::binDir($base) . '/' . self::playerName();
        if (self::matches($dst, $c)) return null;
        list($src, $err) = self::obtainProgram($native, $base, self::playerName(), $log);
        if ($err !== null) return $err;
        return self::installProgram($src, self::binDir($base), self::playerName());
    }

    /** Скачивает веса модели огласовок (2,4 МБ), если их нет. Ошибка — строка, успех — null. */
    public static function ensureTashkeelModel($native, $base, $log = null)
    {
        $path = self::tashkeelModel($base);
        foreach (self::tsv(self::trimDir($native) . '/data/sources.tsv') as $c) {
            if (count($c) < 6 || $c[0] !== 'model' || $c[1] !== 'tashkeel') continue;
            if (is_file($path) && filesize($path) == (int)$c[3] && hash_file('sha256', $path) === $c[4]) return null;
            if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0755, true)) return 'нет прав на создание ' . dirname($path);
            if ($log) call_user_func($log, '  ' . $c[5]);
            return self::fetch($c[5], $path, $c[4], $log);
        }
        return 'модели огласовок нет в sources.tsv';
    }

    /**
     * Расставляет огласовки в арабском тексте утилитой sanotts_tashkeel.
     * Возвращает текст с огласовками или null (тогда $err — причина).
     */
    public static function diacritize($base, $text, &$err)
    {
        $err = '';
        $bin = self::binDir($base) . '/' . self::tashkeelName();
        $model = self::tashkeelModel($base);
        if (!is_file($bin)) { $err = 'нет ' . $bin; return null; }
        if (!is_file($model)) { $err = 'нет модели ' . $model; return null; }
        $out = self::tmpDir() . '/sanotts_tk_' . getmypid() . '_' . mt_rand() . '.txt';
        // Результат — в файл: run() смешивает stdout и stderr.
        list($launched, $rc, $msg) = self::run(array($bin, '-m', $model, '-o', $out), $text);
        $res = is_file($out) ? (string)file_get_contents($out) : '';
        @unlink($out);
        if (!$launched || $rc !== 0 || trim($res) === '') {
            $err = 'sanotts_tashkeel rc=' . $rc . ($msg !== '' ? ': ' . $msg : '');
            return null;
        }
        return trim($res);
    }

    public static function trimDir($dir)
    {
        $dir = (string)$dir;
        // «C:\» и «/» не укорачиваем до пустой строки.
        return preg_match('#^([A-Za-z]:)?[\\\\/]$#', $dir) ? $dir : rtrim($dir, '/\\');
    }

    public static function tmpDir()
    {
        return self::trimDir(sys_get_temp_dir());
    }

    /** Архитектура процессора: x86_64, aarch64, armv7l, x86 или как есть. */
    public static function arch()
    {
        if (self::isWindows()) {
            // У 32-битного PHP на 64-битной Windows настоящая архитектура — в *W6432.
            $pa = strtoupper((string)(getenv('PROCESSOR_ARCHITEW6432') ?: getenv('PROCESSOR_ARCHITECTURE')));
            if ($pa === 'AMD64' || $pa === 'EM64T') return 'x86_64';
            if ($pa === 'ARM64') return 'aarch64';
            if ($pa === 'X86') return 'x86';
        }
        $m = strtolower(php_uname('m'));
        if (in_array($m, array('x86_64', 'amd64', 'x64'), true)) return 'x86_64';
        if (in_array($m, array('aarch64', 'arm64', 'armv8b', 'aarch64_be'), true)) return 'aarch64';
        if (in_array($m, array('armv7l', 'armv8l', 'armv7', 'arm', 'armhf'), true)) return 'armv7l';
        if (preg_match('/^i[3-6]86$/', $m)) return 'x86';
        return $m;
    }

    /** Каталог готовой сборки: x86_64, android-aarch64, windows-x86_64, … */
    public static function prebuiltName()
    {
        $arch = self::arch();
        if (self::isWindows()) {
            // 32-битная Windows — своя сборка. Windows на ARM исполняет x64-программы
            // в эмуляции (XAMPP и сам только x64), отдельной ARM64-сборки нет.
            return $arch === 'x86' ? 'windows-x86' : 'windows-x86_64';
        }
        if (self::isAndroid()) return 'android-' . $arch;
        return $arch;
    }

    public static function platformTitle()
    {
        if (self::isWindows()) return 'Windows';
        if (self::isAndroid()) return 'Android, bionic PIE';
        return 'Linux, static';
    }

    public static function functionEnabled($name)
    {
        if (!function_exists($name)) return false;
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        return !in_array($name, $disabled, true);
    }

    // ------------------------------------------------------------------
    // Запуск sanotts_cli
    // ------------------------------------------------------------------

    /**
     * Запуск программы без оболочки: proc_open с массивом аргументов (PHP 7.4+)
     * не зависит ни от /bin/sh, ни от правил кавычек cmd.exe. Вывод — во
     * временные файлы, чтобы большой stderr не подвесил процесс.
     * Возвращает array(запустилась, код, stdout+stderr).
     */
    public static function run(array $args, $stdin = '')
    {
        $args = self::launchArgs($args);
        $tmp = self::tmpDir();
        $errFile = @tempnam($tmp, 'snt');
        if ($errFile === false) return array(false, -1, 'cannot create temp file in ' . $tmp);

        if (self::functionEnabled('proc_open')) {
            $spec = array(0 => array('pipe', 'r'), 1 => array('file', $errFile, 'a'), 2 => array('file', $errFile, 'a'));
            if (PHP_VERSION_ID >= 70400) {
                $cmd = $args;
            } else {
                $cmd = implode(' ', array_map('escapeshellarg', $args));
            }
            $proc = @proc_open($cmd, $spec, $pipes, null, self::childEnv($args), array('bypass_shell' => true));
            if (is_resource($proc)) {
                if ($stdin !== '') fwrite($pipes[0], $stdin);
                fclose($pipes[0]);
                $rc = proc_close($proc);
                $out = (string)@file_get_contents($errFile);
                @unlink($errFile);
                return array(true, $rc, trim($out));
            }
        }

        // proc_open запрещён — exec/shell_exec, текст — через временный файл.
        foreach (array('exec', 'shell_exec') as $fn) {
            if (!self::functionEnabled($fn)) continue;
            $in = @tempnam($tmp, 'snt');
            if ($in === false || @file_put_contents($in, $stdin) === false) break;
            $cmd = implode(' ', array_map('escapeshellarg', $args)) . ' < ' . escapeshellarg($in) .
                ' > ' . escapeshellarg($errFile) . ' 2>&1';
            // cmd.exe снимает внешние кавычки у строки, начинающейся с кавычки.
            if (self::isWindows()) $cmd = '"' . $cmd . '"';
            $rc = -1;
            if ($fn === 'exec') {
                $o = array();
                exec($cmd, $o, $rc);
            } else {
                shell_exec($cmd);
                $rc = 0; // кода нет — о результате судим по выходному файлу
            }
            @unlink($in);
            $out = (string)@file_get_contents($errFile);
            @unlink($errFile);
            return array(true, $rc, trim($out));
        }
        @unlink($errFile);
        return array(false, -1, 'proc_open, exec и shell_exec запрещены в disable_functions PHP');
    }

    /**
     * Запуск с чтением stdout построчно по мере вывода (для потокового режима
     * sanotts_cli -O): $onLine(строка) вызывается сразу, как строка готова.
     * Нужен proc_open; без него возвращает null (вызывающий идёт обходным путём).
     * Иначе array(запустилась, код, stderr).
     */
    public static function runStream(array $args, $stdin, $onLine)
    {
        $args = self::launchArgs($args);
        if (!self::functionEnabled('proc_open')) return null;
        $errFile = @tempnam(self::tmpDir(), 'snt');
        if ($errFile === false) return null;
        $spec = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('file', $errFile, 'a'));
        $cmd = PHP_VERSION_ID >= 70400 ? $args : implode(' ', array_map('escapeshellarg', $args));
        $proc = @proc_open($cmd, $spec, $pipes, null, self::childEnv($args), array('bypass_shell' => true));
        if (!is_resource($proc)) { @unlink($errFile); return null; }
        if ($stdin !== '') fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        while (($line = fgets($pipes[1])) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line !== '') call_user_func($onLine, $line);
        }
        fclose($pipes[1]);
        $rc = proc_close($proc);
        $err = trim((string)@file_get_contents($errFile));
        @unlink($errFile);
        return array(true, $rc, $err);
    }

    // ------------------------------------------------------------------
    // Скачивание с проверкой sha256
    // ------------------------------------------------------------------

    /**
     * Скачивает $url в $dest и сверяет sha256. Ошибка — строка, успех — null.
     * Пробует php-curl, потом потоки PHP (allow_url_fopen), потом curl/wget.
     * Если не удаётся проверить сертификат сервера (частая беда XAMPP: не
     * задан curl.cainfo), повторяет без проверки — подлинность файла всё
     * равно гарантирует sha256 из модуля.
     */
    public static function fetch($url, $dest, $sha, $log = null)
    {
        $errs = array();
        $urls = self::mirrors($url);
        foreach ($urls as $i => $u) {
            if ($i > 0 && $log) call_user_func($log, '  зеркало: ' . $u);
            $err = self::fetchOne($u, $dest, $sha, $log);
            if ($err === null) return null;
            $errs[] = $err;
        }
        return implode("\n", $errs);
    }

    /**
     * Адреса файла: сам $url и, для файлов с GitHub, зеркало jsDelivr — у
     * части провайдеров raw.githubusercontent.com недоступен. Подлинность
     * файла с любого адреса проверяет sha256. jsDelivr не отдаёт файлы
     * больше 20 МБ — для них зеркала нет.
     */
    public static function mirrors($url)
    {
        $u = array($url);
        if (preg_match('#^https://raw\.githubusercontent\.com/([^/]+)/([^/]+)/([^/]+)/(.+)$#', $url, $m)) {
            $u[] = 'https://cdn.jsdelivr.net/gh/' . $m[1] . '/' . $m[2] . '@' . $m[3] . '/' . $m[4];
        }
        return $u;
    }

    /** Скачивание с одного адреса (см. fetch). */
    private static function fetchOne($url, $dest, $sha, $log = null)
    {
        static $noVerify = array();   // способы, у которых уже не прошла проверка сертификата
        $part = $dest . '.part';
        $errs = array();
        foreach (array('curl', 'stream', 'tool') as $how) {
            @unlink($part);
            $err = self::fetchWith($how, $url, $part, empty($noVerify[$how]), $log);
            if ($err !== null && $err !== false && empty($noVerify[$how]) &&
                preg_match('/SSL|certificate|issuer|CA file|crypto/i', $err)) {
                if ($log) call_user_func($log, "  ($err) — дальше без проверки сертификата: каждый файл сверяется по sha256");
                $noVerify[$how] = true;
                @unlink($part);
                $err = self::fetchWith($how, $url, $part, false, $log);
            }
            if ($err === false) continue;            // способ недоступен
            if ($err !== null) { $errs[] = "$how: $err"; continue; }
            $got = @hash_file('sha256', $part);
            if ($got !== $sha) {
                @unlink($part);
                return basename($dest) . ': неверная контрольная сумма (получено ' . $got . ')';
            }
            @unlink($dest);
            return @rename($part, $dest) ? null : 'cannot rename ' . $part;
        }
        @unlink($part);
        return $errs ? "$url: " . implode('; ', $errs)
            : 'нечем скачивать: нужен php-curl, allow_url_fopen с openssl, curl или wget';
    }

    /** null — успех, false — способ недоступен, строка — ошибка. */
    private static function fetchWith($how, $url, $part, $verify, $log = null)
    {
        // Раз в 10 секунд — строка о ходе загрузки: по ней модуль видит, что
        // установка жива, а не зависла.
        $last = time();
        $progress = function ($got, $total) use (&$last, $log) {
            if (!$log || time() - $last < 10) return;
            $last = time();
            call_user_func($log, $total > 0
                ? sprintf('  %d%% (%.1f / %.1f MB)', $got * 100 / $total, $got / 1048576, $total / 1048576)
                : sprintf('  %.1f MB', $got / 1048576));
        };
        if ($how === 'curl') {
            if (!function_exists('curl_init')) return false;
            $fp = @fopen($part, 'wb');
            if (!$fp) return 'cannot write ' . $part;
            $ch = curl_init($url);
            curl_setopt_array($ch, array(CURLOPT_FILE => $fp, CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_TIMEOUT => 900, CURLOPT_FAILONERROR => true,
                CURLOPT_SSL_VERIFYPEER => $verify, CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
                CURLOPT_NOPROGRESS => false,
                CURLOPT_PROGRESSFUNCTION => function ($ch, $total, $got) use ($progress) { $progress($got, $total); return 0; }));
            $ok = curl_exec($ch);
            $err = curl_error($ch);
            curl_close($ch);
            fclose($fp);
            return $ok ? null : ($err !== '' ? $err : 'download failed');
        }
        if ($how === 'stream') {
            if (!ini_get('allow_url_fopen') || !in_array('https', stream_get_wrappers(), true)) return false;
            $ctx = stream_context_create(array(
                'http' => array('timeout' => 60, 'follow_location' => 1, 'ignore_errors' => false),
                'ssl'  => array('verify_peer' => $verify, 'verify_peer_name' => $verify),
            ));
            $last = null;
            set_error_handler(function ($no, $str) use (&$last) { $last = $str; return true; });
            $in = fopen($url, 'rb', false, $ctx);
            $out = $in ? fopen($part, 'wb') : false;
            $ok = $in && $out;
            if ($ok) {
                $total = 0;
                $meta = stream_get_meta_data($in);
                foreach ((array)(isset($meta['wrapper_data']) ? $meta['wrapper_data'] : array()) as $h) {
                    if (preg_match('/^Content-Length:\s*(\d+)/i', (string)$h, $m)) $total = (int)$m[1];
                }
                $got = 0;
                while (!feof($in)) {
                    $buf = fread($in, 65536);
                    if ($buf === false) { $ok = false; break; }
                    if ($buf !== '' && fwrite($out, $buf) === false) { $ok = false; break; }
                    $got += strlen($buf);
                    $progress($got, $total);
                }
                if ($total > 0 && $got !== $total) $ok = false;
            }
            restore_error_handler();
            if ($in) fclose($in);
            if ($out) fclose($out);
            return $ok ? null : ($last !== null ? $last : 'download failed');
        }
        // Внешние программы: curl есть и в Windows 10+ (System32\curl.exe).
        foreach (array('curl', 'wget') as $tool) {
            if (!self::haveTool($tool)) continue;
            $args = $tool === 'curl'
                ? array('curl', '-fsSL', '--retry', '3', '--connect-timeout', '20', '-o', $part, $url)
                : array('wget', '-q', '-t', '3', '-T', '60', '-O', $part, $url);
            if (!$verify) array_splice($args, 1, 0, $tool === 'curl' ? '-k' : '--no-check-certificate');
            list($launched, $rc, $out) = self::run($args);
            if ($launched && $rc === 0 && is_file($part)) return null;
            return "$tool rc=$rc " . $out;
        }
        return false;
    }

    public static function haveTool($name)
    {
        $path = (string)getenv('PATH');
        $exts = self::isWindows() ? array('.exe', '.cmd', '.bat') : array('');
        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
            if ($dir === '') continue;
            foreach ($exts as $e) {
                $f = self::trimDir($dir) . DIRECTORY_SEPARATOR . $name . $e;
                if (@is_file($f) && (self::isWindows() || @is_executable($f))) return true;
            }
        }
        return false;
    }

    /**
     * PHP для запуска установщика отдельным процессом. PHP_BINARY у веб-сервера —
     * это php-fpm, php-cgi или httpd.exe, они не подходят: ищем именно php(.exe).
     */
    public static function phpCli()
    {
        $exe = self::isWindows() ? 'php.exe' : 'php';
        $c = array();
        if (defined('PHP_BINARY') && PHP_BINARY !== '') $c[] = PHP_BINARY;
        if (defined('PHP_BINDIR')) $c[] = PHP_BINDIR . DIRECTORY_SEPARATOR . $exe;
        foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $dir) {
            if ($dir !== '') $c[] = self::trimDir($dir) . DIRECTORY_SEPARATOR . $exe;
        }
        if (getenv('PREFIX')) $c[] = getenv('PREFIX') . '/bin/php';   // Termux
        $c[] = '/usr/bin/php';
        $c[] = '/usr/local/bin/php';
        foreach ($c as $p) {
            if (preg_match('/^php[0-9.]*(\.exe)?$/i', basename($p)) && @is_file($p)) return $p;
        }
        return null;
    }

    /** Фраза на языке голоса: кнопка «Проверить» и самопроверка (ключ — словарь espeak). */
    public static function samplePhrases()
    {
        return array(
            'ru'  => 'Проверка связи. Температура на улице 23°C, влажность 45%.',
            'en'  => 'Hello! The front door is open, and it is 23 degrees outside.',
            'de'  => 'Hallo! Die Haustür ist offen, draußen sind es 23 Grad.',
            'fr'  => 'Bonjour ! La porte d\'entrée est ouverte, il fait 23 degrés dehors.',
            'es'  => '¡Hola! La puerta de entrada está abierta y fuera hace 23 grados.',
            'it'  => 'Ciao! La porta d\'ingresso è aperta e fuori ci sono 23 gradi.',
            'pt'  => 'Olá! A porta da frente está aberta e lá fora faz 23 graus.',
            'cs'  => 'Ahoj! Vchodové dveře jsou otevřené a venku je 23 stupňů.',
            'ro'  => 'Salut! Ușa de la intrare este deschisă, iar afară sunt 23 de grade.',
            'tr'  => 'Merhaba! Ön kapı açık, dışarıda hava 23 derece.',
            'pl'  => 'Cześć! Drzwi wejściowe są otwarte, a na zewnątrz są 23 stopnie.',
            'ar'  => 'مرحبا! الباب الأمامي مفتوح.',
            'vi'  => 'Xin chào! Cửa trước đang mở, bên ngoài trời 23 độ.',
            'id'  => 'Halo! Pintu depan terbuka, suhu di luar 23 derajat.',
            'hi'  => 'नमस्ते! सामने का दरवाज़ा खुला है, बाहर तापमान 23 डिग्री है।',
            'ne'  => 'नमस्ते! अगाडिको ढोका खुला छ, बाहिर तापक्रम 23 डिग्री छ।',
            'cmn' => '你好！前门开着，外面温度二十三度。',
        );
    }

    // ------------------------------------------------------------------
    // Файлы
    // ------------------------------------------------------------------

    public static function copyTree($src, $dst)
    {
        if (!is_dir($dst) && !@mkdir($dst, 0755, true)) return false;
        foreach ((array)@scandir($src) as $f) {
            if ($f === '.' || $f === '..' || $f === false) continue;
            $s = $src . '/' . $f;
            $d = $dst . '/' . $f;
            if (is_dir($s)) {
                if (!self::copyTree($s, $d)) return false;
            } elseif (!@copy($s, $d)) {
                return false;
            }
        }
        return true;
    }

    public static function removeTree($dir)
    {
        if (!file_exists($dir) && !is_link($dir)) return true;
        if (!is_dir($dir) || is_link($dir)) return @unlink($dir);
        foreach ((array)@scandir($dir) as $f) {
            if ($f === '.' || $f === '..' || $f === false) continue;
            self::removeTree($dir . '/' . $f);
        }
        return @rmdir($dir);
    }

    /** Строки sources.tsv вида $kind (и $name, если задано). */
    public static function sourceRows($native, $kind, $name = null)
    {
        $out = array();
        foreach (self::tsv(self::trimDir($native) . '/data/sources.tsv') as $c) {
            if (count($c) >= 6 && $c[0] === $kind && ($name === null || $c[1] === $name)) $out[] = $c;
        }
        return $out;
    }

    /**
     * Метка набора файлов из sources.tsv (пути и sha256): по ней видно, что
     * после обновления модуля данные в cms/sanotts нужно обновить.
     */
    public static function rowsStamp(array $rows)
    {
        $p = array();
        foreach ($rows as $c) $p[] = $c[2] . ':' . $c[4];
        sort($p);
        return hash('sha256', implode("\n", $p));
    }

    /** Метка базовых данных espeak (фонемные таблицы и дерево lang/). */
    public static function baseStamp($native)
    {
        return self::rowsStamp(self::sourceRows($native, 'base', 'espeak'));
    }

    /**
     * Скачивает в $dir файлы sources.tsv вида $kind/$name (путь — третий столбец,
     * может быть с подкаталогами), которых нет или которые не совпадают по sha256.
     * Ошибка — строка, успех — null.
     */
    public static function ensureFiles($native, $kind, $name, $dir, $log = null)
    {
        $rows = self::sourceRows($native, $kind, $name);
        if (!$rows) return "нет $kind/$name в sources.tsv";
        foreach ($rows as $c) {
            if (strpos($c[2], '..') !== false) return 'недопустимый путь ' . $c[2];
            $path = self::trimDir($dir) . '/' . $c[2];
            if (self::matches($path, $c)) continue;
            if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0755, true)) return 'нет прав на создание ' . dirname($path);
            if ($log) call_user_func($log, '  ' . $c[5]);
            $err = self::fetch($c[5], $path, $c[4], $log);
            if ($err !== null) return $err;
        }
        return null;
    }

    /** Копия базовых данных espeak без словарей (*_dict) и служебных файлов — для сборки словаря. */
    public static function copyBase($native, $src, $dst)
    {
        foreach (self::sourceRows($native, 'base', 'espeak') as $c) {
            $d = $dst . '/' . $c[2];
            if (!is_dir(dirname($d)) && !@mkdir(dirname($d), 0755, true)) return false;
            if (!@copy($src . '/' . $c[2], $d)) return false;
        }
        return true;
    }

    /** Строки TSV-файла из native/data (без комментариев). */
    public static function tsv($file)
    {
        $rows = array();
        foreach ((array)@file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = rtrim((string)$line, "\r");
            if ($line === '' || $line[0] === '#') continue;
            $rows[] = explode("\t", $line);
        }
        return $rows;
    }

    // ------------------------------------------------------------------
    // Установка
    // ------------------------------------------------------------------

    /** Дублировать журнал в вывод (консоль или открытый HTTP-ответ). */
    public function setEcho($on)
    {
        $this->echo = (bool)$on;
    }

    public function say($msg)
    {
        $line = '[sanoTTS] ' . $msg . "\n";
        if ($this->logFile) @file_put_contents($this->logFile, $line, FILE_APPEND);
        if ($this->echo) { echo $line; @flush(); }
    }

    /** Полная установка/обновление. true — успех; журнал кончается «Done» или «FAILED». */
    public function install()
    {
        try {
            $this->steps();
            $this->say('Done');
            return true;
        } catch (Exception $e) {
            $this->say('ERROR: ' . $e->getMessage());
            $this->say('FAILED');
            return false;
        }
    }

    private function fail($msg)
    {
        throw new Exception($msg);
    }

    private function bin()
    {
        return self::binDir($this->base) . '/' . self::cliName();
    }

    private function data()
    {
        return $this->base . '/share/espeak-ng-data';
    }

    private function source($kind, $name, $file)
    {
        foreach (self::tsv($this->native . '/data/sources.tsv') as $c) {
            if (count($c) >= 6 && $c[0] === $kind && $c[1] === $name && $c[2] === $file) return $c;
        }
        return null;
    }

    private function download($kind, $name, $file, $dest)
    {
        $c = $this->source($kind, $name, $file);
        if ($c === null) $this->fail("$kind/$name/$file нет в sources.tsv");
        $this->say('  ' . $c[5]);
        $self = $this;
        $err = self::fetch($c[5], $dest, $c[4], function ($m) use ($self) { $self->say($m); });
        if ($err !== null) $this->fail('не удалось скачать: ' . $err);
    }

    private function steps()
    {
        $user = function_exists('posix_geteuid') && function_exists('posix_getpwuid') && ($pw = @posix_getpwuid(posix_geteuid()))
            ? $pw['name'] : (string)(getenv('USERNAME') ?: getenv('USER') ?: '?');
        $this->say('arch=' . self::arch() . ' os=' . PHP_OS . ' php=' . PHP_VERSION . ' user=' . $user . ' base=' . $this->base);
        @set_time_limit(0);

        foreach (array($this->base . '/bin', $this->voicesDir) as $d) {
            if (!is_dir($d) && !@mkdir($d, 0755, true)) $this->fail('нет прав на создание ' . $d);
        }
        // Каталог внутри веб-корня (cms/sanotts) не должен раздаваться по HTTP.
        @file_put_contents($this->base . '/.htaccess', "Require all denied\n");
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->say('WARNING: запуск от root — файлы будут принадлежать root, и модуль');
            $this->say('         (пользователь веб-сервера) не сможет ставить голоса.');
        }

        $this->installBinary();
        $this->installBaseData();
        $this->installVoice();

        $need = array();
        foreach ((array)glob($this->voicesDir . '/*/meta.json') as $m) {
            $d = $this->voiceDict(basename(dirname($m)));
            if ($d !== '') $need[$d] = true;
        }
        $this->say('Dictionaries needed: ' . implode(' ', array_keys($need)));
        // Русский голос читает оставшуюся латиницу (café) по английским правилам espeak.
        if (isset($need['ru'])) $need['en'] = true;
        foreach (array_keys($need) as $d) $this->ensureDict($d);
        if (isset($need['ru'])) self::buildYoIndex($this->native, $this->base);
        if (isset($need['ar'])) {
            $this->say('Arabic diacritizer (sanotts_tashkeel + libtashkeel model, 2.4 MB)...');
            $self = $this;
            $log = function ($m) use ($self) { $self->say($m); };
            $err = self::ensureTashkeelProgram($this->native, $this->base, $log);
            if ($err === null) $err = self::ensureTashkeelModel($this->native, $this->base, $log);
            // Без огласовок арабский голос всё равно работает — не ошибка установки.
            if ($err !== null) $this->say('WARNING: огласовки не установлены: ' . $err);
        }

        $this->say('Self-test...');
        $wav = self::tmpDir() . '/sanotts_selftest_' . getmypid() . '.wav';
        @unlink($wav);
        // Пример на языке голоса целиком (несколько секунд речи): по нему же меряем
        // скорость синтеза на этом устройстве — с одним коротким словом время
        // ушло бы в основном на запуск движка.
        $samples = self::samplePhrases();
        $lang = $this->voiceDict($this->voice);
        $text = isset($samples[$lang]) ? $samples[$lang] : 'Test.';
        if ($lang === 'ar') {
            $d = self::diacritize($this->base, $text, $err);
            if ($d !== null) {
                $this->say('  tashkeel: ' . $d);
                $text = $d;
            } else {
                $this->say('  WARNING: огласовки не расставлены: ' . $err);
            }
        }
        $t0 = microtime(true);
        list($launched, $rc, $out) = self::run(array($this->bin(), '-d', $this->data(),
            '-v', $this->voicesDir . '/' . $this->voice, '-o', $wav), $text);
        $took = microtime(true) - $t0;
        if ($out !== '') $this->say('  ' . str_replace("\n", "\n  ", $out));
        $ok = $launched && is_file($wav) && filesize($wav) > 44;
        if ($ok) $this->saveSpeed($wav, $took);
        @unlink($wav);
        if (!$ok) $this->fail('самопроверка не прошла' . ($launched ? " (rc=$rc)" : ': ' . $out));
    }

    /**
     * Скорость синтеза на этом устройстве — по самопроверке: во сколько раз
     * синтез быстрее воспроизведения получившегося звука, пересчитанная на голос
     * Ирина (irina) по каталогу голосов, если проверяли другим голосом.
     * Пишется в BASE/speed.json; модуль показывает её во вкладке «Голоса».
     */
    private function saveSpeed($wav, $took)
    {
        $sec = self::wavSeconds($wav);
        if ($sec <= 0 || $took <= 0) return;
        $factor = $sec / $took;
        $cat = self::voiceCatalogRows($this->native);
        $sv = isset($cat[$this->voice]) ? (float)$cat[$this->voice] : 0.0;
        $si = isset($cat['irina']) ? (float)$cat['irina'] : 0.0;
        if ($this->voice !== 'irina') {
            if ($sv <= 0 || $si <= 0) return;
            $factor = $factor * $si / $sv;
        }
        @file_put_contents($this->base . '/speed.json', json_encode(array(
            'irina' => round($factor, 3), 'voice' => $this->voice,
            'audio' => round($sec, 2), 'synth' => round($took, 2), 'time' => date('c'))));
        $this->say(sprintf('  speed: %.2f s of audio in %.2f s (%s)', $sec, $took, $this->voice));
    }

    /** Длительность WAV по заголовку (секунды) или 0. */
    public static function wavSeconds($wav)
    {
        $f = @fopen($wav, 'rb');
        if (!$f) return 0.0;
        $h = fread($f, 4096);
        fclose($f);
        if (strlen($h) < 44 || substr($h, 0, 4) !== 'RIFF' || substr($h, 8, 4) !== 'WAVE') return 0.0;
        $byteRate = 0;
        $p = 12;
        while ($p + 8 <= strlen($h)) {
            $id = substr($h, $p, 4);
            $len = unpack('V', substr($h, $p + 4, 4))[1];
            if ($id === 'fmt ' && $p + 20 <= strlen($h)) $byteRate = unpack('V', substr($h, $p + 16, 4))[1];
            if ($id === 'data') {
                $size = min($len, max(0, filesize($wav) - $p - 8));
                return $byteRate > 0 ? $size / $byteRate : 0.0;
            }
            $p += 8 + $len + ($len & 1);
        }
        return 0.0;
    }

    /** voice => скорость по каталогу (колонка speed в voices.tsv). */
    private static function voiceCatalogRows($native)
    {
        $out = array();
        foreach ((array)@file($native . '/data/voices.tsv', FILE_IGNORE_NEW_LINES) as $line) {
            if ($line === '' || $line[0] === '#') continue;
            $c = explode("\t", $line);
            if (count($c) > 12 && $c[12] !== '') $out[$c[0]] = $c[12];
        }
        return $out;
    }

    private function installBinary()
    {
        $pre = self::prebuiltName();
        $this->say("Installing sanotts_cli ($pre — " . self::platformTitle() . ')');
        $self = $this;
        list($src, $err) = self::obtainProgram($this->native, $this->base, self::cliName(), function ($m) use ($self) { $self->say($m); });
        if ($err !== null) {
            $c = self::engineSource($this->native, self::cliName());
            $this->fail('движок не получен: ' . $err . ($c === null ? '' :
                "\nБез доступа к GitHub: скачайте " . $c[5] . "\nи положите в " . self::engineDir($this->base) . '/ — установщик проверит sha256 и возьмёт его.'));
        }
        $dir = $this->base . '/bin';
        $err = self::installProgram($src, $dir, self::cliName());
        if ($err !== null) $this->fail($err);
        @unlink($dir . '/.linker');
        $why = self::isWindows() ? null : self::probe($dir . '/' . self::cliName());
        if ($why === null) {
            @unlink($this->base . '/.bindir');
        } else {
            $this->say("  $dir: $why");
            // Android: сайт на общей памяти (/storage/emulated/0, KSWEB и т. п.) —
            // оттуда программы не запускаются. Ищем внутренний каталог приложения.
            $this->say("WARNING: из $dir программы не запускаются (память без права запуска — так на /storage в Android)");
            $found = null;
            $linker = self::isAndroid() ? self::androidLinker() : null;
            foreach (self::execDirCandidates() as $cand) {
                @unlink($cand . '/.linker');
                $err = self::installProgram($src, $cand, self::cliName());
                if ($err !== null) { $this->say("  не подходит: $cand ($err)"); continue; }
                $why = self::probe($cand . '/' . self::cliName());
                if ($why === null) { $found = $cand; break; }
                if ($linker !== null && @file_put_contents($cand . '/.linker', $linker . "\n") !== false) {
                    // Android 10+: запуск напрямую запрещён — через системный загрузчик.
                    $why2 = self::probe($cand . '/' . self::cliName());
                    if ($why2 === null) {
                        $this->say("  $cand: напрямую не запускается ($why), запуск через $linker");
                        $found = $cand;
                        break;
                    }
                    @unlink($cand . '/.linker');
                    $why .= "; через $linker: $why2";
                }
                $this->say("  не подходит: $cand ($why)");
            }
            if ($found === null) {
                $this->fail("нет каталога, откуда веб-сервер может запускать программы.\n" .
                    "Каталог MajorDoMo ($this->base) — на памяти, где запуск программ запрещён. Перенесите MajorDoMo во внутреннюю\n" .
                    "память приложения веб-сервера или используйте Termux (там всё работает из его каталога).");
            }
            if (@file_put_contents($this->base . '/.bindir', $found . "\n") === false) $this->fail('не удалось записать ' . $this->base . '/.bindir');
            $dir = $found;
            $this->say("  программы установлены в $dir");
        }
        // Проигрыватель для динамика сервера (Android): ошибка не мешает синтезу.
        if (self::isAndroid()) {
            $err = self::ensurePlayerProgram($this->native, $this->base, function ($m) use ($self) { $self->say($m); });
            if ($err !== null) $this->say('WARNING: sanotts_play: ' . $err);
        }
        // Утилита огласовок (арабский) обновляется вместе с движком, если уже стоит;
        // впервые ставится вместе с арабским голосом.
        if (is_file($dir . '/' . self::tashkeelName())) {
            $err = self::ensureTashkeelProgram($this->native, $this->base, function ($m) use ($self) { $self->say($m); });
            if ($err !== null) $this->say('WARNING: sanotts_tashkeel: ' . $err);
        }
    }

    private function installBaseData()
    {
        // База (фонемные таблицы и дерево lang/) — по sources.tsv; словари языков
        // не трогаем — они скачиваются/собираются по мере установки голосов.
        $this->say('espeak-ng base data...');
        $dst = $this->data();
        if (!is_dir($dst) && !@mkdir($dst, 0755, true)) $this->fail('нет прав на создание ' . $dst);
        $self = $this;
        $err = self::ensureFiles($this->native, 'base', 'espeak', $dst, function ($m) use ($self) { $self->say($m); });
        if ($err !== null) $this->fail('данные espeak-ng не получены: ' . $err);
        @file_put_contents($dst . '/.base_version', self::baseStamp($this->native));
    }

    /** Голос → словарь: из каталога, иначе по его meta.json. */
    private function voiceDict($voice)
    {
        foreach (self::tsv($this->native . '/data/voices.tsv') as $c) {
            if (count($c) >= 3 && $c[0] === $voice) return $c[2];
        }
        $meta = @json_decode((string)@file_get_contents($this->voicesDir . '/' . $voice . '/meta.json'), true);
        $ev = is_array($meta) && isset($meta['espeak_voice']) ? strtolower((string)$meta['espeak_voice']) : '';
        if ($ev === '') return '';
        if (in_array($ev, array('en', 'en-us', 'en-gb'), true)) return 'en';
        if (in_array($ev, array('pt', 'pt-br'), true)) return 'pt';
        return preg_replace('/[^a-z]/', '', $ev);
    }

    private function installVoice()
    {
        $dir = $this->voicesDir . '/' . $this->voice;
        if (is_file($dir . '/meta.json')) {
            $this->say("Voice '{$this->voice}' already installed");
            return;
        }
        $files = array();
        foreach (self::tsv($this->native . '/data/sources.tsv') as $c) {
            if (count($c) >= 6 && $c[0] === 'voice' && $c[1] === $this->voice) $files[] = $c[2];
        }
        if (!$files) {
            if ($this->voice === 'irina') $this->fail("голоса 'irina' нет в sources.tsv");
            $this->say("WARNING: голоса '{$this->voice}' нет в каталоге, ставлю irina");
            $this->voice = 'irina';
            $dir = $this->voicesDir . '/irina';
            if (is_file($dir . '/meta.json')) return;
            return $this->installVoice();
        }
        $this->say("Downloading voice '{$this->voice}'...");
        $tmp = $this->voicesDir . '/.' . $this->voice . '.new';
        self::removeTree($tmp);
        if (!@mkdir($tmp, 0755, true)) $this->fail('нет прав на создание ' . $tmp);
        try {
            foreach ($files as $f) $this->download('voice', $this->voice, $f, $tmp . '/' . $f);
        } catch (Exception $e) {
            self::removeTree($tmp);
            throw $e;
        }
        self::removeTree($dir);
        if (!@rename($tmp, $dir)) { self::removeTree($tmp); $this->fail('не удалось переименовать ' . $tmp); }
    }

    /**
     * Словарь языка вне полной установки (модуль ставит голос с вкладки «Голоса»):
     * для русского — скачать список словоформ и собрать. Ошибка — строка, успех — null.
     */
    public function dictOnly($d)
    {
        try {
            $this->ensureDict($d);
            return null;
        } catch (Exception $e) {
            return $e->getMessage();
        }
    }

    private function ensureDict($d)
    {
        if ($d === 'ru') {
            // Словарь ё нужен русским правилам; без него ё просто не расставляется.
            $self = $this;
            $err = self::ensureYoDict($this->native, $this->base, function ($m) use ($self) { $self->say($m); });
            if ($err !== null) $this->say('WARNING: словарь ё не скачался: ' . $err);
            return $this->buildRuDict();
        }
        $c = null;
        foreach (self::tsv($this->native . '/data/sources.tsv') as $row) {
            if (count($row) >= 6 && $row[0] === 'dict' && $row[1] === $d) { $c = $row; break; }
        }
        if ($c === null) { $this->say("WARNING: словаря '$d' нет в каталоге"); return; }
        $path = $this->data() . '/' . $d . '_dict';
        if (is_file($path) && hash_file('sha256', $path) === $c[4]) return;
        $this->say("Downloading espeak dictionary '$d'...");
        $this->download('dict', $d, $c[2], $path);
    }

    /** Исходники русского словаря, которые нужны для пересборки (список словоформ 23 МБ). */
    public static function ruSourceDir($base)
    {
        return self::trimDir($base) . '/share/ru_dictsource';
    }

    /** Ударения из текстов (за́мок, зам+ок): строки для ru_extra, копятся здесь. */
    public static function ruUserExtraFile($base)
    {
        return self::ruSourceDir($base) . '/ru_extra.user';
    }

    /** Словарь ё (eyo-kernel, safe.txt): скачивается рядом с исходниками русского словаря. */
    public static function yoFile($base)
    {
        return self::ruSourceDir($base) . '/yo_safe.txt';
    }

    /** Скачивает словарь ё (0,9 МБ), если его нет. Ошибка — строка, успех — null. */
    public static function ensureYoDict($native, $base, $log = null)
    {
        $path = self::yoFile($base);
        foreach (self::tsv(self::trimDir($native) . '/data/sources.tsv') as $c) {
            if (count($c) < 6 || $c[0] !== 'data' || $c[1] !== 'yo') continue;
            if (self::matches($path, $c)) return null;
            if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0755, true)) return 'нет прав на создание ' . dirname($path);
            if ($log) call_user_func($log, '  ' . $c[5]);
            return self::fetch($c[5], $path, $c[4], $log);
        }
        return 'словаря ё нет в sources.tsv';
    }

    /** Индекс словаря ё (100 тыс. форм) — заранее, чтобы первая фраза не ждала. */
    public static function buildYoIndex($native, $base)
    {
        $lib = dirname($native) . '/lib/ru_frontend.php';
        $dir = self::ruSourceDir($base);
        if (!is_file($lib) || !is_file(self::yoFile($base))) return;
        require_once $lib;
        SanottsRuText::$yoSource = self::yoFile($base);
        SanottsRuText::$yoIndexDir = $dir;
        SanottsRuText::restoreYo('еще');
    }

    /**
     * Метка собранного ru_dict: исходники espeak-ng из sources.tsv + дополнения
     * модуля (словоформы и ударения) + ударения из текстов.
     */
    public static function ruDictStamp($native, $base)
    {
        $native = self::trimDir($native);
        $h = self::rowsStamp(self::sourceRows($native, 'dictsrc', 'ru')) . ':' .
            hash_file('sha256', $native . '/data/dictsource/ru_listx.extra') . ':' .
            hash_file('sha256', $native . '/data/dictsource/ru_extra');
        $u = self::ruUserExtraFile($base);
        if (is_file($u)) $h .= ':' . hash_file('sha256', $u);
        return hash('sha256', $h);
    }

    private function buildRuDict()
    {
        $ds = self::ruSourceDir($this->base);
        $stamp = self::ruDictStamp($this->native, $this->base);
        $data = $this->data();
        if (is_file($data . '/ru_dict') && filesize($data . '/ru_dict') > 0 &&
            trim((string)@file_get_contents($data . '/.ru_dict.sha')) === $stamp) {
            $this->say('Russian dictionary already built');
            return;
        }
        $src = self::ruSourceDir($this->base);
        if (!is_dir($src) && !@mkdir($src, 0755, true)) $this->fail('нет прав на создание ' . $src);
        // Исходники словаря espeak-ng 1.52.0 — по sources.tsv; дополнения модуля
        // (словоформы, ударения омографов) — в модуле, native/data/dictsource.
        $self = $this;
        $err = self::ensureFiles($this->native, 'dictsrc', 'ru', $src, function ($m) use ($self) { $self->say($m); });
        if ($err !== null) $this->fail('исходники русского словаря не получены: ' . $err);
        // Список словоформ espeak-ng храним: словарь пересобирается, когда в текстах
        // появляются новые ударения (за́мок) — без повторной загрузки 23 МБ.
        $listx = $src . '/ru_listx.base';
        $want = null;
        foreach (self::tsv($this->native . '/data/sources.tsv') as $row) {
            if (count($row) >= 6 && $row[0] === 'dict' && $row[1] === 'ru') { $want = (int)$row[3]; break; }
        }
        if (!is_file($listx) || ($want && filesize($listx) !== $want)) {
            $this->say('Downloading Russian word list (espeak-ng 1.52.0, 23 MB)...');
            @unlink($listx);
            $this->download('dict', 'ru', 'ru_listx', $listx . '.part');
            if (!@rename($listx . '.part', $listx)) $this->fail('не удалось записать ' . $listx);
        }
        $work = self::tmpDir() . '/sanotts_dict_' . getmypid() . '_' . mt_rand();
        $build = $data . '.build.' . getmypid();
        $cleanup = function () use ($work, $build) {
            SanottsEngine::removeTree($work);
            SanottsEngine::removeTree($build);
        };
        try {
            if (!@mkdir($work, 0755, true)) $this->fail('нет прав на создание ' . $work);
            foreach (array('ru_rules', 'ru_list', 'ru_emoji') as $f) {
                if (!@copy($ds . '/' . $f, $work . '/' . $f)) $this->fail('не удалось скопировать ' . $f);
            }
            // + словоформы модуля с ударениями, которых нет в списке 1.52.0.
            $out = fopen($work . '/ru_listx', 'wb');
            foreach (array($listx, $this->native . '/data/dictsource/ru_listx.extra') as $f) {
                $in = fopen($f, 'rb');
                stream_copy_to_stream($in, $out);
                fclose($in);
            }
            fclose($out);
            // ru_extra модуля (ударения омографов: замокъъ = замо́к) +
            // ударения, встреченные в текстах.
            $extra = (string)file_get_contents($this->native . '/data/dictsource/ru_extra');
            $user = self::ruUserExtraFile($this->base);
            if (is_file($user)) $extra .= "\n" . (string)file_get_contents($user) . "\n";
            if (@file_put_contents($work . '/ru_extra', $extra) === false) $this->fail('не удалось записать ru_extra');

            $this->say('Building Russian dictionary (811k words)...');
            // Собираем во временную копию данных, чтобы рабочий словарь не пропал при сбое.
            self::removeTree($build);
            if (!self::copyBase($this->native, $data, $build)) $this->fail('не удалось подготовить ' . $build);
            list($launched, $rc, $log) = self::run(array($this->bin(), '-d', $build, '--compile-dict', $work . '/', 'ru'));
            if (!$launched || !is_file($build . '/ru_dict') || filesize($build . '/ru_dict') == 0) {
                $this->fail("словарь не собрался (rc=$rc)\n" . $log);
            }
            // rename поверх: читающий процесс видит либо старый, либо новый файл.
            if (!@rename($build . '/ru_dict', $data . '/ru_dict')) {
                @unlink($data . '/ru_dict');
                if (!@rename($build . '/ru_dict', $data . '/ru_dict')) $this->fail('не удалось записать ' . $data . '/ru_dict');
            }
            @file_put_contents($data . '/.ru_dict.sha', $stamp);
            $this->say('ru_dict: ' . round(filesize($data . '/ru_dict') / 1024) . ' KB');
        } catch (Exception $e) {
            $cleanup();
            throw $e;
        }
        $cleanup();
    }
}
