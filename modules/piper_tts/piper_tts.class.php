<?php

class piper_tts extends module
{
    function piper_tts()
    {
        $this->name = 'piper_tts';
        $this->title = 'Piper TTS';
        $this->module_category = '<#LANG_SECTION_APPLICATIONS#>';
        $this->checkInstalled();
    }

    function saveParams($data = 0)
    {
        $p = array();
        if (isset($this->id)) {
            $p['id'] = $this->id;
        }
        if (isset($this->view_mode)) {
            $p['view_mode'] = $this->view_mode;
        }
        if (isset($this->edit_mode)) {
            $p['edit_mode'] = $this->edit_mode;
        }
        if (isset($this->tab)) {
            $p['tab'] = $this->tab;
        }
        return parent::saveParams($p);
    }

    function getParams()
    {
        global $id, $mode, $view_mode, $edit_mode, $tab;
        if (isset($id)) {
            $this->id = $id;
        }
        if (isset($mode)) {
            $this->mode = $mode;
        }
        if (isset($view_mode)) {
            $this->view_mode = $view_mode;
        }
        if (isset($edit_mode)) {
            $this->edit_mode = $edit_mode;
        }
        if (isset($tab)) {
            $this->tab = $tab;
        }
    }

    function getConfig()
    {
        parent::getConfig();
        if (!isset($this->config['PIPER_BIN']) || trim($this->config['PIPER_BIN']) === '') {
            $this->config['PIPER_BIN'] = '/usr/local/bin/piper';
        }
        if (!isset($this->config['MODELS_DIR']) || trim($this->config['MODELS_DIR']) === '') {
            $this->config['MODELS_DIR'] = '/opt/piper/voices';
        }
        if (!isset($this->config['MODEL'])) {
            $this->config['MODEL'] = $this->isRemoteMode()
                ? 'ru_RU-irina-medium'
                : '/opt/piper/voices/ru_RU-irina-medium/ru_RU-irina-medium.onnx';
        }
        if (!isset($this->config['LENGTH_SCALE'])) {
            $this->config['LENGTH_SCALE'] = '0.95';
        }
        if (!isset($this->config['SENTENCE_SILENCE'])) {
            $this->config['SENTENCE_SILENCE'] = '0.15';
        }
        if (!isset($this->config['LEAD_SILENCE'])) {
            $this->config['LEAD_SILENCE'] = '1.0';
        }
        if (!isset($this->config['USE_CACHE'])) {
            $this->config['USE_CACHE'] = '1';
        }
        if (!isset($this->config['CACHE_DIR'])) {
            $this->config['CACHE_DIR'] = '/var/www/html/cms/cached/voice';
        }
        if (!isset($this->config['CACHE_CLEANUP'])) {
            $this->config['CACHE_CLEANUP'] = '0';
        }
        if (!isset($this->config['WS_PORT']) || !preg_match('/^\d+$/', (string)$this->config['WS_PORT'])) {
            $this->config['WS_PORT'] = defined('WEBSOCKETS_PORT') ? (string)WEBSOCKETS_PORT : '8001';
        }
    }

    /**
     * Порт, на котором реально слушает WebSocket-сервер ядра.
     * Источник истины — константа WEBSOCKETS_PORT: сервер занимает именно её,
     * и postToWebSocket() ходит на неё же. Настройка WS_PORT модуля является
     * лишь запасным вариантом на случай, если константа не определена.
     */
    private function getWsPort()
    {
        if (defined('WEBSOCKETS_PORT')) {
            return (int)WEBSOCKETS_PORT;
        }
        $port = (int)$this->config['WS_PORT'];
        return $port > 0 ? $port : 8001;
    }

    private function isWsAlive()
    {
        $errno = 0;
        $errstr = '';
        $fp = @fsockopen('127.0.0.1', $this->getWsPort(), $errno, $errstr, 0.5);
        if ($fp === false) {
            return false;
        }
        fclose($fp);
        return true;
    }

    private function getArch()
    {
        static $arch = null;
        if ($arch === null) {
            $arch = trim((string)shell_exec('uname -m'));
        }
        return $arch;
    }

    private function isArch64()
    {
        return in_array($this->getArch(), array('x86_64', 'amd64', 'aarch64'));
    }

    private function e($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

    private function fatal($message)
    {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: text/html; charset=utf-8');
        echo '<html><body style="font-family:sans-serif;padding:40px">';
        echo '<p style="color:red">' . $this->e($message) . '</p>';
        echo '<script>setTimeout(function(){window.location.href="' . $this->e('?action=piper_tts') . '"},1500);</script>';
        echo '</body></html>';
        exit;
    }

    /**
     * Белый список голоса и качества.
     *
     * Значения приходят из GET и подставляются в пути, которые уходят в mkdir/chown/rm,
     * поэтому произвольная строка недопустима: escapeshellarg защищает только от
     * инъекции в команду, но не от выхода за пределы MODELS_DIR через "..".
     */
    private function sanitizeVoice($voice)
    {
        $allowed = array('irina', 'denis', 'dmitri', 'ruslan', 'luka');
        return in_array($voice, $allowed, true) ? $voice : null;
    }

    private function sanitizeQuality($quality)
    {
        $allowed = array('x_low', 'low', 'medium', 'high', 'x_high');
        return in_array($quality, $allowed, true) ? $quality : null;
    }

    /**
     * Каталог модели внутри MODELS_DIR — вторая линия защиты после белого списка.
     */
    private function resolveModelDir($voice, $quality)
    {
        $base = realpath($this->config['MODELS_DIR']);
        if ($base === false) {
            return null;
        }
        $modelDir = $base . '/ru_RU-' . $voice . '-' . $quality;
        $real = realpath($modelDir);
        if ($real === false) {
            // Каталога ещё нет — проверяем нормализованный путь префиксом.
            if (strpos($modelDir, $base . '/') !== 0) {
                return null;
            }
            return $modelDir;
        }
        return (strpos($real, $base . '/') === 0) ? $real : null;
    }

    function run()
    {
        $out = array();
        if ($this->action == 'admin') {
            $this->admin($out);
        } else {
            $this->usual($out);
        }
        if (isset($this->owner->action)) {
            $out['PARENT_ACTION'] = $this->owner->action;
        }
        if (isset($this->owner->name)) {
            $out['PARENT_NAME'] = $this->owner->name;
        }
        $out['VIEW_MODE'] = $this->view_mode;
        $out['EDIT_MODE'] = $this->edit_mode;
        $out['MODE'] = $this->mode;
        $out['ACTION'] = $this->action;
        $this->data = $out;
        $p = new parser(DIR_TEMPLATES . $this->name . '/' . $this->name . '.html', $this->data, $this);
        $this->result = $p->result;
    }

    private function isRemoteMode()
    {
        $val = trim($this->config['PIPER_BIN']);
        if ($val === '') return false;
        if ($val[0] === '/' || $val[0] === '.' || strpos($val, '\\') !== false) return false;
        if (!preg_match('/[.:\d]/', $val)) return false;
        return true;
    }

    private function getRemoteAddr()
    {
        $addr = $this->config['PIPER_BIN'];
        if (strpos($addr, ':') === false) {
            $addr .= ':5000';
        }
        return $addr;
    }

    private function fetchRemoteVoices()
    {
        $addr = $this->getRemoteAddr();
        $url = "http://$addr/voices";
        $json = @file_get_contents($url);
        if (!$json) return array();
        $data = json_decode($json, true);
        if (!$data) return array();
        $models = array();
        $current = $this->config['MODEL'];
        foreach ($data as $name => $info) {
            $models[] = array(
                'VALUE' => $this->e($name),
                'TITLE' => $this->e($name),
                'SELECTED' => $name === $current ? 'selected' : '',
            );
        }
        return $models;
    }

    private function scanModels($dir)
    {
        $models = array();
        if (!is_dir($dir)) return $models;
        $files = glob($dir . '/*/*.onnx');
        if (!$files) $files = glob($dir . '/*.onnx');
        sort($files);
        $current = $this->config['MODEL'];
        foreach ($files as $f) {
            $name = basename(dirname($f)) . '/' . basename($f);
            $models[] = array(
                'VALUE' => $this->e($f),
                'TITLE' => $this->e($name),
                'SELECTED' => $f === $current ? 'selected' : '',
            );
        }
        return $models;
    }

    private function getAvailableModels()
    {
        $dir = $this->config['MODELS_DIR'];
        $available = array();

        $voices = array(
            'irina'  => array('quality' => 'medium', 'repo' => 'rhasspy'),
            'denis'  => array('quality' => 'medium', 'repo' => 'rhasspy'),
            'dmitri' => array('quality' => 'medium', 'repo' => 'rhasspy'),
            'ruslan' => array('quality' => 'medium', 'repo' => 'rhasspy'),
            'luka'   => array('quality' => 'medium', 'repo' => 'luka'),
        );

        foreach ($voices as $voice => $info) {
            $quality = $info['quality'];
            $modelDir = $dir . '/ru_RU-' . $voice . '-' . $quality;
            $modelFile = $modelDir . '/ru_RU-' . $voice . '-' . $quality . '.onnx';
            $configFile = $modelDir . '/ru_RU-' . $voice . '-' . $quality . '.onnx.json';
            $installed = file_exists($modelFile) && file_exists($configFile);
            $available[] = array(
                'VOICE' => $voice,
                'QUALITY' => $quality,
                'DIR' => $modelDir,
                'INSTALLED' => $installed ? '1' : '0',
            );
        }
        return $available;
    }

    private function getModelBaseUrl($voice, $quality)
    {
        if ($voice === 'luka') {
            return 'https://huggingface.co/superkeka/piper-tts-luka/resolve/main/ru/ru_RU/luka/' . $quality;
        }
        return 'https://huggingface.co/rhasspy/piper-voices/resolve/main/ru/ru_RU/' . $voice . '/' . $quality;
    }

    function admin(&$out)
    {
        if (function_exists('DebMes')) DebMes("piper_tts: admin() called, action=" . $this->action . ", view_mode=" . $this->view_mode . ", GET_cmd=" . gr('cmd') . ", GET_voice=" . gr('voice'), 'piper_tts');
        $this->getConfig();

        if (gr('cmd') == 'check_piper_status') {
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: application/json');
            $arch64 = $this->isArch64();
            if ($this->isRemoteMode()) {
                $addr = $this->getRemoteAddr();
                $ch = curl_init("http://$addr/voices");
                curl_setopt_array($ch, array(
                    CURLOPT_TIMEOUT => 3,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => false,
                ));
                curl_exec($ch);
                $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                $connect = ($http >= 200 && $http < 300) ? 'connected' : 'not_connected';
            } else {
                $connect = is_file('/usr/local/bin/piper') ? 'connected' : 'not_connected';
            }
            $marker = '/tmp/piper-tts-installing';
            if (is_file($marker)) {
                $install = 'installing';
            } elseif (is_file('/usr/local/bin/piper')) {
                $install = 'installed';
            } else {
                $install = 'not_installed';
            }
            // Синтез и канал доставки — разные вещи: доступность Piper ничего
            // не говорит о том, дойдёт ли WAV до вкладки браузера.
            echo json_encode(array(
                'connect' => $connect,
                'install' => $install,
                'arch64' => $arch64,
                'ws' => $this->isWsAlive() ? 'connected' : 'not_connected',
                'ws_port' => $this->getWsPort(),
            ));
            exit;
        }

        if (gr('cmd') == 'install_piper') {
            if (function_exists('DebMes')) DebMes("piper_tts: install_piper called", 'piper_tts');
            $this->runPiperInstall();
            header('Content-Type: application/json');
            echo json_encode(array('ok' => true));
            exit;
        }

        if (gr('cmd') == 'install_model') {
            if (function_exists('DebMes')) DebMes("piper_tts: install_model START", 'piper_tts');
            // Вкладка «Модели» скрыта в удалённом режиме, но команда оставалась
            // доступна напрямую из адресной строки.
            if ($this->isRemoteMode()) {
                $this->fatal(LANG_PIPER_TTS_REMOTE_MODE_ERROR);
            }
            $voice = $this->sanitizeVoice(gr('voice'));
            $quality = $this->sanitizeQuality(gr('quality'));
            if ($voice === null || $quality === null) {
                $this->fatal(LANG_PIPER_TTS_INVALID_MODEL);
            }
            $modelDir = $this->resolveModelDir($voice, $quality);
            if ($modelDir === null) {
                $this->fatal(LANG_PIPER_TTS_INVALID_MODEL);
            }
            $parentDir = dirname($modelDir);
            exec('chown -R www-data:www-data ' . escapeshellarg($parentDir) . ' 2>/dev/null');
            if (!is_dir($modelDir)) mkdir($modelDir, 0755, true);
            $baseUrl = $this->getModelBaseUrl($voice, $quality);
            $files = array(
                'ru_RU-' . $voice . '-' . $quality . '.onnx',
                'ru_RU-' . $voice . '-' . $quality . '.onnx.json',
            );
            set_time_limit(0);
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: text/html; charset=utf-8');
            echo '<html><body style="font-family:sans-serif;padding:40px;text-align:center">';
            echo '<h2>' . sprintf(LANG_PIPER_TTS_DOWNLOADING_MODEL, htmlspecialchars($voice), htmlspecialchars($quality)) . '</h2>';
            echo '<div style="width:100%;background:#e9ecef;border-radius:4px;overflow:hidden;height:30px;margin:20px 0">';
            echo '<div id="b" style="width:0%;height:30px;background:#007bff;color:#fff;line-height:30px;font-size:14px">0%</div></div>';
            echo '<p id="s">' . LANG_PIPER_TTS_STARTING . '</p>';
            flush();

            $fileSizes = array_fill(0, count($files), 0);
            foreach ($files as $i => $f) {
                $ch = curl_init($baseUrl . '/' . $f);
                curl_setopt_array($ch, array(
                    CURLOPT_NOBODY => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_HEADERFUNCTION => function($curl, $header) use (&$fileSizes, $i) {
                        if (stripos($header, 'content-length:') === 0) {
                            $fileSizes[$i] = (int)trim(substr($header, 15));
                        }
                        return strlen($header);
                    },
                ));
                curl_exec($ch);
                curl_close($ch);
            }
            $totalBytes = array_sum($fileSizes);
            if ($totalBytes <= 0) $totalBytes = 1;

            $ok = true;
            $cumBytes = 0;
            $lastPct = -1;
            for ($i = 0; $i < count($files); $i++) {
                $url = $baseUrl . '/' . $files[$i];
                $dest = $modelDir . '/' . $files[$i];
                $fp = @fopen($dest, 'w');
                if (!$fp) { $ok = false; break; }
                $ch = curl_init($url);
                curl_setopt_array($ch, array(
                    CURLOPT_FILE => $fp,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT => 300,
                    CURLOPT_NOPROGRESS => false,
                    CURLOPT_PROGRESSFUNCTION => function($r, $dlSize, $dlNow, $ulSize, $ulNow) use ($totalBytes, $fileSizes, $i, &$cumBytes, &$lastPct) {
                        $fileBytes = $fileSizes[$i] > 0 ? $fileSizes[$i] : $dlSize;
                        $done = $cumBytes + ($fileBytes > 0 && $dlNow <= $fileBytes ? $dlNow : 0);
                        $overall = $totalBytes > 0 ? round($done / $totalBytes * 100) : 0;
                        if ($overall != $lastPct) {
                            $lastPct = $overall;
                            $msg = sprintf(LANG_PIPER_TTS_FILE_PROGRESS, $i+1, count($fileSizes), $overall);
                            echo '<script>document.getElementById("b").style.width="' . $overall . '%";document.getElementById("b").textContent="' . $overall . '%";document.getElementById("s").textContent="' . $msg . '";</script>';
                            flush();
                        }
                    },
                ));
                $res = curl_exec($ch);
                $err = curl_error($ch);
                curl_close($ch);
                fclose($fp);
                if ($res === false || !file_exists($dest) || filesize($dest) == 0) {
                    if (function_exists('DebMes')) DebMes("piper_tts: curl failed: $err", 'piper_tts');
                    $ok = false; break;
                }
                $cumBytes += filesize($dest);
            }
            exec('chown -R www-data:www-data ' . escapeshellarg($modelDir) . ' 2>/dev/null');
            if (!$ok) {
                exec('rm -rf ' . escapeshellarg($modelDir));
                echo '<p style="color:red">' . LANG_PIPER_TTS_DOWNLOAD_ERROR . '</p>';
                if (function_exists('DebMes')) DebMes("piper_tts: install FAILED", 'piper_tts');
            } else {
                echo '<p style="color:green;font-weight:bold">' . LANG_PIPER_TTS_DONE . '</p>';
                if (function_exists('DebMes')) DebMes("piper_tts: install OK", 'piper_tts');
            }
            echo '<script>setTimeout(function(){window.location.href="' . htmlspecialchars('?action=piper_tts') . '"},1000);</script>';
            echo '</body></html>';
            exit;
        }

        if (gr('cmd') == 'delete_model') {
            if (function_exists('DebMes')) DebMes("piper_tts: delete_model START", 'piper_tts');
            if ($this->isRemoteMode()) {
                $this->fatal(LANG_PIPER_TTS_REMOTE_MODE_ERROR);
            }
            $voice = $this->sanitizeVoice(gr('voice'));
            $quality = $this->sanitizeQuality(gr('quality'));
            if ($voice === null || $quality === null) {
                $this->fatal(LANG_PIPER_TTS_INVALID_MODEL);
            }
            $modelDir = $this->resolveModelDir($voice, $quality);
            if ($modelDir === null) {
                $this->fatal(LANG_PIPER_TTS_INVALID_MODEL);
            }
            if (function_exists('DebMes')) DebMes("piper_tts: deleting $modelDir", 'piper_tts');
            if (is_dir($modelDir)) {
                exec('rm -rf ' . escapeshellarg($modelDir) . ' 2>&1', $rmOut, $rmRet);
                if ($rmRet !== 0) {
                    if (function_exists('DebMes')) DebMes("piper_tts: rm failed ($rmRet): " . implode("\n", $rmOut), 'piper_tts');
                } else {
                    if (function_exists('DebMes')) DebMes("piper_tts: deleted $modelDir", 'piper_tts');
                }
            }
            if ($this->config['MODEL'] === $modelDir . '/ru_RU-' . $voice . '-' . $quality . '.onnx') {
                $this->config['MODEL'] = $this->config['MODELS_DIR'] . '/ru_RU-irina-medium/ru_RU-irina-medium.onnx';
                $this->saveConfig();
                if (function_exists('DebMes')) DebMes("piper_tts: reset active model to irina", 'piper_tts');
            }
            if (function_exists('DebMes')) DebMes("piper_tts: redirect after delete", 'piper_tts');
            $this->redirect('?action=piper_tts');
            exit;
        }

        $isRemote = $this->isRemoteMode();
        $out['IS_REMOTE'] = $isRemote ? '1' : '';
        $out['PIPER_BIN'] = $this->e($this->config['PIPER_BIN']);

        if ($isRemote) {
            $model = $this->config['MODEL'];
            if (strpos($model, '/') !== false) {
                $base = basename($model, '.onnx');
                $this->config['MODEL'] = $base;
                $this->saveConfig();
            }
            $out['REMOTE_MODELS'] = $this->fetchRemoteVoices();
        } else {
            $out['MODELS_DIR'] = $this->e($this->config['MODELS_DIR']);
            $out['MODELS'] = $this->scanModels($this->config['MODELS_DIR']);
            $out['AVAILABLE_MODELS'] = $this->getAvailableModels();
        }

        $out['LENGTH_SCALE'] = $this->e($this->config['LENGTH_SCALE']);
        $out['SENTENCE_SILENCE'] = $this->e($this->config['SENTENCE_SILENCE']);
        $out['USE_CACHE'] = $this->config['USE_CACHE'] ? 'checked' : '';
        $out['CACHE_DIR'] = $this->e($this->config['CACHE_DIR']);
        $out['CACHE_CLEANUP'] = $this->config['CACHE_CLEANUP'] ? 'checked' : '';
        $out['WS_PORT'] = $this->e($this->getWsPort());

        $out['ARCH_64'] = $this->isArch64() ? '1' : '';
        $out['WS_ALIVE'] = $this->isWsAlive() ? '1' : '';

        if ($isRemote) {
            $addr = $this->getRemoteAddr();
            $ch = curl_init("http://$addr/voices");
            curl_setopt_array($ch, array(
                CURLOPT_TIMEOUT => 3,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
            ));
            curl_exec($ch);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $out['CONNECT_STATUS'] = ($http >= 200 && $http < 300) ? '1' : '0';
        } else {
            $out['CONNECT_STATUS'] = is_file('/usr/local/bin/piper') ? '1' : '0';
        }

        $marker = '/tmp/piper-tts-installing';
        if (is_file($marker)) {
            $out['INSTALL_STATUS'] = '2';
        } elseif (is_file('/usr/local/bin/piper')) {
            $out['INSTALL_STATUS'] = '1';
        } else {
            $out['INSTALL_STATUS'] = '0';
        }

        $tab = gr('tab');
        if (!$tab) $tab = 'settings';
        $out['TAB'] = $tab;
        $out['TAB_SETTINGS'] = ($tab == 'settings') ? '1' : '0';
        $out['TAB_MODELS'] = ($tab == 'models') ? '1' : '0';
        $out['TAB_HELP'] = ($tab == 'help') ? '1' : '0';
        $out['VERSION'] = '1.1.0';

        if ($this->view_mode == 'update_settings') {
            $piperBin = gr('piper_bin', $this->config['PIPER_BIN']);
            $this->config['PIPER_BIN'] = trim($piperBin) !== '' ? $piperBin : '/usr/local/bin/piper';
            $isRemote = $this->isRemoteMode();
            if (!$isRemote) {
                $this->config['MODELS_DIR'] = gr('models_dir', $this->config['MODELS_DIR']);
                if (trim($this->config['MODELS_DIR']) === '') {
                    $this->config['MODELS_DIR'] = '/opt/piper/voices';
                }
            }
            $this->config['MODEL'] = gr('model', $this->config['MODEL']);
            $this->config['LENGTH_SCALE'] = gr('length_scale', $this->config['LENGTH_SCALE']);
            $this->config['SENTENCE_SILENCE'] = gr('sentence_silence', $this->config['SENTENCE_SILENCE']);
            $this->config['USE_CACHE'] = gr('use_cache', 0) ? 1 : 0;
            $this->config['CACHE_DIR'] = gr('cache_dir', $this->config['CACHE_DIR']);
            $this->config['CACHE_CLEANUP'] = gr('cache_cleanup', 0) ? 1 : 0;
            $this->config['WS_PORT'] = gr('ws_port', $this->config['WS_PORT']);
            $this->saveConfig();
            $this->redirect('?action=piper_tts&tab=' . $tab);
        }
    }

    function usual(&$out)
    {
        $this->admin($out);
    }

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
            $result .= $unitsFem[$th] . ' тысяча ';
            $n %= 1000;
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
        if ($n > 0) {
            $result .= $units[$n] . ' ';
        }
        return trim($result);
    }

    public static function expandDecimal($str)
    {
        if (!preg_match('/^(\d+)[.,](\d+)$/', $str, $m)) {
            return $str;
        }
        $whole = (int)$m[1];
        $frac = $m[2];
        $fracInt = (int)$frac;
        if ($fracInt === 0) {
            return self::numberToText($whole);
        }
        $wholeText = self::numberToText($whole);
        if (strlen($frac) === 1 && $fracInt === 5) {
            return $wholeText . ' с половиной';
        }
        if (strlen($frac) === 1) {
            $fracText = self::numberToText($fracInt);
            $fracWord = self::pluralize($fracInt, array('десятая', 'десятых', 'десятых'));
            return $wholeText . ' целых ' . $fracText . ' ' . $fracWord;
        }
        return $wholeText . ' целых ' . self::numberToText($fracInt);
    }

    public static function preprocessText($text)
    {
        // Составные единицы: без замены «м/с» превращается в «м с».
        $text = preg_replace('/км\s*\/\s*ч/ui', 'километров в час', $text);
        $text = preg_replace('/м\s*\/\s*с/ui', 'метров в секунду', $text);

        $text = preg_replace_callback('/(\d+(?:[.,]\d+)?)\s*°\s*C/ui', function($m) {
            $num = str_replace(',', '.', $m[1]);
            if (strpos($num, '.') !== false) {
                $whole = (int)$num;
                return self::expandDecimal($num) . ' ' . self::pluralize($whole, array('градус', 'градуса', 'градусов'));
            }
            return $m[1] . ' ' . self::pluralize((int)$num, array('градус', 'градуса', 'градусов'));
        }, $text);

        $text = preg_replace_callback('/(\d+(?:[.,]\d+)?)\s*%/u', function($m) {
            $num = str_replace(',', '.', $m[1]);
            if (strpos($num, '.') !== false) {
                $whole = (int)$num;
                return self::expandDecimal($num) . ' ' . self::pluralize($whole, array('процент', 'процента', 'процентов'));
            }
            return $m[1] . ' ' . self::pluralize((int)$num, array('процент', 'процента', 'процентов'));
        }, $text);

        $text = preg_replace_callback('/(\d+(?:[.,]\d+)?)\s*мм\s+рт\.?\s*ст\.?/ui', function($m) {
            $num = str_replace(',', '.', $m[1]);
            if (strpos($num, '.') !== false) {
                $whole = (int)$num;
                return self::expandDecimal($num) . ' ' . self::pluralize($whole, array('миллиметр', 'миллиметра', 'миллиметров')) . ' ртутного столба';
            }
            return $m[1] . ' ' . self::pluralize((int)$num, array('миллиметр', 'миллиметра', 'миллиметров')) . ' ртутного столба';
        }, $text);

        $text = preg_replace_callback('/(\d+(?:[.,]\d+)?)\s*мм\b/u', function($m) {
            $num = str_replace(',', '.', $m[1]);
            if (strpos($num, '.') !== false) {
                $whole = (int)$num;
                return self::expandDecimal($num) . ' ' . self::pluralize($whole, array('миллиметр', 'миллиметра', 'миллиметров'));
            }
            return $m[1] . ' ' . self::pluralize((int)$num, array('миллиметр', 'миллиметра', 'миллиметров'));
        }, $text);

        $text = preg_replace_callback('/\b(\d+)[.,](\d+)\b/u', function($m) {
            return self::expandDecimal($m[1] . '.' . $m[2]);
        }, $text);

        $tzPos = array(
            0 => 'гринвичу', 1 => 'центральноевропейскому', 2 => 'калининграду',
            3 => 'москве', 4 => 'самаре', 5 => 'екатеринбургу',
            6 => 'омску', 7 => 'красноярску', 8 => 'иркутску',
            9 => 'якутску', 10 => 'владивостоку', 11 => 'магадану', 12 => 'камчатке',
        );
        $tzNeg = array(
            1 => 'азорам', 2 => 'бразилии', 3 => 'аргентине',
            4 => 'нью-йорку', 5 => 'чикаго', 6 => 'денверу',
            7 => 'лос-анджелесу', 8 => 'анкориджу', 9 => 'гавайям',
            10 => 'острову пасхи',
        );
        $text = preg_replace_callback('/\bUTC([+-]\d{1,2})\b/u', function($m) use ($tzPos, $tzNeg) {
            $offset = (int)$m[1];
            if ($offset >= 0 && isset($tzPos[$offset])) return 'по ' . $tzPos[$offset];
            if ($offset < 0 && isset($tzNeg[-$offset])) return 'по ' . $tzNeg[-$offset];
            return 'UTC' . $m[1];
        }, $text);
        $text = preg_replace('/\bUTC(?![-+])/u', 'по гринвичу', $text);
        $text = preg_replace('/\bGMT\b/u', 'по гринвичу', $text);
        $text = preg_replace('/\bMSK\b/u', 'по москве', $text);
        $text = preg_replace('/\bEDT\b/u', 'по нью-йорку', $text);
        $text = preg_replace('/\bEST\b/u', 'по нью-йорку', $text);
        $text = preg_replace('/\bPDT\b/u', 'по лос-анджелесу', $text);
        $text = preg_replace('/\bPST\b/u', 'по лос-анджелесу', $text);
        $text = preg_replace('/\bCDT\b/u', 'по чикаго', $text);
        $text = preg_replace('/\bCST\b/u', 'по чикаго', $text);
        $text = preg_replace('/\bMDT\b/u', 'по денверу', $text);
        $text = preg_replace('/\bMST\b/u', 'по денверу', $text);
        $text = preg_replace('/\bAKDT\b/u', 'по анкориджу', $text);
        $text = preg_replace('/\bAKST\b/u', 'по анкориджу', $text);
        $text = preg_replace('/\bHADT\b/u', 'по гавайям', $text);
        $text = preg_replace('/\bHAST\b/u', 'по гавайям', $text);
        $text = preg_replace('/\bCET\b/u', 'по центральноевропейскому', $text);
        $text = preg_replace('/\bCEST\b/u', 'по центральноевропейскому', $text);
        $text = preg_replace('/\bEET\b/u', 'по восточноевропейскому', $text);
        $text = preg_replace('/\bEEST\b/u', 'по восточноевропейскому', $text);
        $text = preg_replace('/\bBST\b/u', 'по лондону', $text);
        $text = preg_replace('/\bIST\b/u', 'по индии', $text);
        $text = preg_replace('/\bJST\b/u', 'по токио', $text);
        $text = preg_replace('/\bKST\b/u', 'по сеулу', $text);
        $text = preg_replace('/\bAWST\b/u', 'по перту', $text);
        $text = preg_replace('/\bACST\b/u', 'по дарвину', $text);
        $text = preg_replace('/\bAEST\b/u', 'по сиднею', $text);

        return $text;
    }

    /**
     * Очистка текста перед синтезом.
     *
     * Piper строит просодию по пунктуации, поэтому знаки препинания сохраняются:
     * прежняя фильтрация [^\p{L}\p{N}\s] выбрасывала всё, и речь становилась
     * монотонной — без пауз между предложениями и без вопросительной интонации.
     * Удаляются только символы, которые несут для движка никакой информации
     * (кавычки, скобки, служебные знаки, управляющие коды).
     */
    public static function cleanText($message)
    {
        $clean = self::preprocessText($message);
        $clean = str_replace(array("\r\n", "\r", "\n", "\t"), ' ', $clean);
        $clean = preg_replace('/[^\p{L}\p{N}\s.,!?;:\x{2013}\x{2014}\x{2019}\x{201C}\x{201D}\x{2026}()-]/u', ' ', $clean);
        $clean = preg_replace('/\s+/u', ' ', $clean);
        return trim($clean);
    }

    /**
     * Разбивает текст на предложения по знакам конца.
     * Используется только в удалённом режиме, где сервер не умеет sentence_silence.
     */
    private function splitSentences($text)
    {
        $parts = preg_split('/(?<=[.!?…])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($parts)) {
            return array($text);
        }
        $parts = array_values(array_filter(array_map('trim', $parts), function($p) {
            return $p !== '';
        }));
        return count($parts) > 0 ? $parts : array($text);
    }

    private function requestRemoteSynthesis($text, $path)
    {
        $addr = $this->getRemoteAddr();
        // Сервер piper1-gpl принимает length_w_scale, а не noise_w: имя noise_w
        // он молча игнорирует, и ширина фонем оставалась на значении по умолчанию.
        $payload = json_encode(array(
            'text' => $text,
            'voice' => $this->config['MODEL'],
            'length_scale' => (float)$this->config['LENGTH_SCALE'],
            'noise_scale' => 0.667,
            'length_w_scale' => 0.8,
        ));
        CreateDir(dirname($path));
        $fp = @fopen($path, 'w');
        if ($fp === false) {
            if (function_exists('DebMes')) DebMes("piper_tts: cannot open $path for writing", 'piper_tts');
            return false;
        }
        $ch = curl_init("http://$addr/");
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FILE => $fp,
        ));
        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);
        if ($res === false && function_exists('DebMes')) {
            DebMes("piper_tts: remote synthesis failed: $err", 'piper_tts');
        }
        return $res !== false && file_exists($path) && filesize($path) > 0;
    }

    /**
     * Удалённый сервер не поддерживает паузу между предложениями (это аргумент
     * его запуска, а не поле JSON-запроса), поэтому пауза собирается локально:
     * предложения синтезируются по отдельности и склеиваются тишиной нужной
     * длины. Наивная отправка sentence_silence в payload не давала ничего.
     */
    private function synthesizeRemoteWithSilence($text, $path)
    {
        $silence = (float)$this->config['SENTENCE_SILENCE'];
        $sentences = $this->splitSentences($text);

        if ($silence <= 0 || count($sentences) < 2) {
            return $this->requestRemoteSynthesis($text, $path);
        }

        $workDir = $path . '.parts';
        CreateDir($workDir);
        $parts = array();
        $ok = true;
        foreach ($sentences as $i => $sentence) {
            $partFile = $workDir . '/part' . $i . '.wav';
            if (!$this->requestRemoteSynthesis($sentence, $partFile)) {
                $ok = false;
                break;
            }
            $parts[] = $partFile;
        }

        if (!$ok || count($parts) < 2) {
            $this->removeDir($workDir);
            return $this->requestRemoteSynthesis($text, $path);
        }

        $gap = $workDir . '/gap.wav';
        $samples = (int)round(22050 * $silence);
        exec('ffmpeg -y -f lavfi -i anullsrc=r=22050:cl=mono -t ' .
            escapeshellarg((string)$silence) . ' -c:a pcm_s16le ' . escapeshellarg($gap) . ' 2>/dev/null');
        if (!file_exists($gap)) {
            $this->removeDir($workDir);
            return $this->requestRemoteSynthesis($text, $path);
        }

        $listFile = $workDir . '/concat.txt';
        $lines = '';
        foreach ($parts as $partFile) {
            $lines .= "file '" . str_replace("'", "'\\''", $partFile) . "'\n";
        }
        // Пауза добавляется между предложениями, но не после последнего.
        for ($i = 0; $i < count($parts) - 1; $i++) {
            $lines .= "file '" . str_replace("'", "'\\''", $gap) . "'\n";
        }
        file_put_contents($listFile, $lines);

        exec('ffmpeg -y -f concat -safe 0 -i ' . escapeshellarg($listFile) .
            ' -c copy ' . escapeshellarg($path) . ' 2>/dev/null');
        $this->removeDir($workDir);

        if (file_exists($path) && filesize($path) > 0) {
            return true;
        }
        return $this->requestRemoteSynthesis($text, $path);
    }

    private function removeDir($dir)
    {
        if (!is_dir($dir)) return;
        foreach (glob($dir . '/*') as $f) {
            if (is_file($f)) @unlink($f);
        }
        @rmdir($dir);
    }

    /**
     * Пауза перед началом фразы. Без неё первые миллисекунды WAV теряются на
     * стороне проигрывателя: файл стартует, но начало уже проигнорировано.
     * Настраивается строкой LEAD_SILENCE в таблице settings, 0 отключает.
     */
    private function getLeadSilence()
    {
        if (isset($this->config['LEAD_SILENCE']) && trim((string)$this->config['LEAD_SILENCE']) !== '') {
            return max(0.0, (float)$this->config['LEAD_SILENCE']);
        }
        return 1.0;
    }

    private function synthesizeToFile($message, $path)
    {
        $clean = self::cleanText($message);
        if ($clean === '') {
            return;
        }

        if ($this->isRemoteMode()) {
            $ok = $this->synthesizeRemoteWithSilence($clean, $path);
        } else {
            $bin = $this->config['PIPER_BIN'];
            $model = $this->config['MODEL'];
            $ls = $this->config['LENGTH_SCALE'];
            $ss = $this->config['SENTENCE_SILENCE'];
            $cmd = 'printf %s ' . escapeshellarg($clean) . ' | ' .
                escapeshellarg($bin) .
                ' --model ' . escapeshellarg($model) .
                ' --length-scale ' . escapeshellarg($ls) .
                ' --sentence-silence ' . escapeshellarg($ss) .
                ' --noise-scale 0.667 --noise-w 0.8' .
                ' --output-file ' . escapeshellarg($path);
            exec($cmd . ' 2>&1', $out, $ret);
            $ok = ($ret === 0 && file_exists($path));
        }

        if (!$ok) {
            if (function_exists('DebMes')) DebMes("piper_tts: synthesis failed for: $path", 'piper_tts');
            return;
        }

        // Расширение .tmp ffmpeg не распознаёт: «Error initializing the muxer».
        // Из-за этого шаг молча пропускался, в кэш попадал сырой выход Piper
        // с пиком 0.0 dB — клиппинг срезал атаку первого слога, и начало фразы
        // звучало как съеденное. Формат задаём явно, поломку больше не глотаем.
        $tmpPath = $path . '.tmp.wav';
        $filters = array('loudnorm=I=-16:LRA=7:TP=-1.5');
        $lead = $this->getLeadSilence();
        if ($lead > 0) {
            $filters[] = 'adelay=' . (int)round($lead * 1000);
        }
        // loudnorm считает внутри на 192 кГц и без -ar протаскивает эту частоту
        // в результат: файл вырастает в десять раз. Возвращаем частоту исходника,
        // потому что у голосов разных классов она разная (16 и 22.05 кГц).
        $srcRate = 22050;
        $probe = @shell_exec('ffprobe -v error -select_streams a:0 -show_entries stream=sample_rate' .
            ' -of csv=p=0 ' . escapeshellarg($path) . ' 2>/dev/null');
        if (is_string($probe) && (int)trim($probe) > 0) {
            $srcRate = (int)trim($probe);
        }
        exec('ffmpeg -y -i ' . escapeshellarg($path) .
            ' -af ' . escapeshellarg(implode(',', $filters)) .
            ' -ar ' . $srcRate . ' -f wav -c:a pcm_s16le ' .
            escapeshellarg($tmpPath) . ' 2>&1', $ffOut, $ffRet);
        if ($ffRet === 0 && file_exists($tmpPath) && filesize($tmpPath) > 0) {
            rename($tmpPath, $path);
        } else {
            @unlink($tmpPath);
            if (function_exists('DebMes')) {
                DebMes("piper_tts: post-processing failed for: $path (ffmpeg rc=$ffRet) " .
                    trim(implode(' ', $ffOut)), 'piper_tts');
            }
        }
    }

    private function cacheKeyPart($value)
    {
        $value = (string)$value;
        return strlen($value) . ':' . $value;
    }

    private function getCacheKey($message)
    {
        // Ключ обязан зависеть от всех параметров синтеза: иначе после смены
        // голоса или скорости на повторе той же фразы отдаётся WAV,
        // синтезированный со старыми настройками.
        // Каждое поле с префиксом длины — конкатенация однозначна для любых
        // строк, поэтому склейка разных полей не даёт коллизий.
        return $this->cacheKeyPart($message)
            . $this->cacheKeyPart($this->config['MODEL'])
            . $this->cacheKeyPart($this->config['LENGTH_SCALE'])
            . $this->cacheKeyPart($this->config['SENTENCE_SILENCE'])
            . $this->cacheKeyPart($this->getLeadSilence());
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

        if (empty($message)) {
            return;
        }

        $destination = '';
        if (isset($details['destination'])) {
            $destination = $details['destination'];
        }

        $cacheDir = $this->config['CACHE_DIR'];
        $useCache = (int)$this->config['USE_CACHE'] === 1;
        $md5 = md5($this->getCacheKey($message));
        $wavFile = $cacheDir . '/piper_tts_' . $md5 . '.wav';

        CreateDir($cacheDir);

        if (!$useCache || !file_exists($wavFile)) {
            $this->synthesizeToFile($message, $wavFile);
        } elseif ((int)$this->config['CACHE_CLEANUP'] === 1) {
            @touch($wavFile);
        }

        if ((int)$this->config['CACHE_CLEANUP'] === 1) {
            $this->cleanupCache();
        }

        if (!file_exists($wavFile)) {
            if (function_exists('DebMes')) DebMes("piper_tts: wav not found after synthesis", 'piper_tts');
            return;
        }

        // CACHE_DIR — это путь в файловой системе, а DOCUMENT_ROOT в CLI-контексте
        // существует, но пуст, из-за чего в браузер уходил абсолютный путь ФС.
        // Опорой служит ROOT ядра (он равен DOC_ROOT в конфигурации MajorDomo).
        $root = defined('ROOT') ? rtrim(ROOT, '/') : '';
        if ($root !== '' && strpos($wavFile, $root . '/') === 0) {
            $webPath = substr($wavFile, strlen($root));
        } else {
            $docRoot = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] !== ''
                ? rtrim($_SERVER['DOCUMENT_ROOT'], '/')
                : '/var/www/html';
            $webPath = str_replace($docRoot, '', $wavFile);
        }
        $webPath = str_replace('\\', '/', $webPath);
        $url = '/' . ltrim($webPath, '/');

        if ($event == 'SAYTO' && $destination !== '') {
            $level = isset($details['level']) ? $details['level'] : (isset($details['IMPORTANCE']) ? $details['IMPORTANCE'] : 0);
            processSubscriptionsSafe('SAY_CACHED_READY', array(
                'level'    => $level,
                'filename' => $wavFile,
                'event'    => 'SAYTO',
                'destination' => $destination,
                'message'  => $message,
            ));
            if (function_exists('DebMes')) {
                DebMes("piper_tts: SAY_CACHED_READY sent dest=$destination url=$url", 'piper_tts');
            }
            return;
        }

        $result = postToWebSocket("PIPER_TTS", array(
            'COMMAND' => 'PlayAudio',
            'URL' => $url,
        ), "PostEvent");

        if (function_exists('DebMes')) {
            DebMes("piper_tts: postToWebSocket result=" . ($result === false ? 'false' : 'ok') .
                " ws_port=" . $this->getWsPort() . " url=$url", 'piper_tts');
        }
    }

    private function cleanupCache()
    {
        $dir = $this->config['CACHE_DIR'];
        if (!is_dir($dir)) return;
        $maxAge = 864000; // 10 days
        $now = time();
        foreach (glob($dir . '/piper_tts_*.wav') as $f) {
            if ($now - filemtime($f) > $maxAge) {
                @unlink($f);
            }
        }
    }

    private function runPiperInstall()
    {
        $setupScript = '/tmp/piper_install.sh';
        $setupLog = '/tmp/piper_install.log';
        $marker = '/tmp/piper-tts-installing';
        $arch = $this->getArch();
        file_put_contents($marker, '1');
        $scriptContent = <<<SETUP
#!/usr/bin/env bash
set -euo pipefail
exec > $setupLog 2>&1
echo "[PiperSetup] arch=$arch"
echo "[PiperSetup] Installing system deps..."
DEBIAN_FRONTEND=noninteractive apt-get update -qq
DEBIAN_FRONTEND=noninteractive apt-get install -y -qq curl wget git libespeak-ng1 libstdc++6 python3-venv python3-dev
echo "[PiperSetup] Cloning piper1-gpl..."
mkdir -p /opt/piper
if [ ! -d /opt/piper/piper1-gpl ]; then
  git clone https://github.com/OHF-voice/piper1-gpl.git /opt/piper/piper1-gpl
fi
echo "[PiperSetup] Fixing ownership..."
  chown -R www-data:www-data /opt/piper
cd /opt/piper/piper1-gpl
echo "[PiperSetup] Creating venv..."
sudo -u www-data python3 -m venv .venv
echo "[PiperSetup] Installing piper (this may take a while)..."
sudo -u www-data .venv/bin/pip install --upgrade pip -q
sudo -u www-data .venv/bin/pip install .
echo "[PiperSetup] Linking piper..."
ln -sf /opt/piper/piper1-gpl/.venv/bin/piper /usr/local/bin/piper
echo "[PiperSetup] Downloading default voice irina-medium..."
mkdir -p /opt/piper/voices
mkdir -p /opt/piper/voices/ru_RU-irina-medium
curl -fSL -o /opt/piper/voices/ru_RU-irina-medium/ru_RU-irina-medium.onnx \
  "https://huggingface.co/rhasspy/piper-voices/resolve/main/ru/ru_RU/irina/medium/ru_RU-irina-medium.onnx"
curl -fSL -o /opt/piper/voices/ru_RU-irina-medium/ru_RU-irina-medium.onnx.json \
  "https://huggingface.co/rhasspy/piper-voices/resolve/main/ru/ru_RU/irina/medium/ru_RU-irina-medium.onnx.json"
chown -R www-data:www-data /opt/piper/voices/ru_RU-irina-medium 2>/dev/null
  chown -R www-data:www-data /opt/piper/voices 2>/dev/null
echo "[PiperSetup] Done"
rm -f /tmp/piper-tts-installing
SETUP;
        file_put_contents($setupScript, $scriptContent);
        chmod($setupScript, 0755);
        exec('nohup sudo bash ' . escapeshellarg($setupScript) . ' < /dev/null > /dev/null 2>&1 &');
    }

    function install($data = '')
    {
        $log = function ($msg) {
            if (function_exists('DebMes')) DebMes("piper_tts: $msg", 'piper_tts');
        };

        subscribeToEvent($this->name, 'SAY', '', 110);
        subscribeToEvent($this->name, 'SAYTO', '', 110);
        subscribeToEvent($this->name, 'SAYREPLY', '', 110);

        // --- Общий modules/prepend.php (загрузчик) ---
        $loaderPath = ROOT . 'modules/prepend.php';
        $htaccessPath = ROOT . '.htaccess';

        if (!file_exists($loaderPath)) {
            // На PHP 8.1+ mysqli по умолчанию работает в режиме MYSQLI_REPORT_STRICT
            // и бросает mysqli_sql_exception, поэтому проверка "if (!$link)" больше
            // не срабатывает: без try/catch падение БД превращается в фатальную
            // ошибку на каждом HTTP-запросе.
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
            $log('install: created modules/prepend.php');
        }

        // --- .htaccess ---
        if (file_exists($htaccessPath)) {
            $htContent = file_get_contents($htaccessPath);

            // Удалить старую прямую строку Piper, если осталась
            $oldPiper = 'php_value auto_prepend_file ' . ROOT . 'modules/piper_tts/prepend.php';
            $htContent = str_replace(array($oldPiper . "\r\n", $oldPiper . "\n", $oldPiper), '', $htContent);

            // Добавить строку с общим загрузчиком, если её нет
            $line = 'php_value auto_prepend_file ' . $loaderPath;
            if (strpos($htContent, 'modules/prepend.php') === false) {
                $htContent = $line . "\n" . $htContent;
                $log('install: added auto_prepend_file to .htaccess');
            }

            file_put_contents($htaccessPath, $htContent);
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

        // Проверить, установлен ли Vosk (тоже использует общий загрузчик)
        $voskStillInstalled = false;
        try {
            $res = SQLSelect("SELECT ID FROM project_modules WHERE NAME='vosk' AND HIDDEN=0");
            if (is_array($res) && count($res) > 0) {
                $voskStillInstalled = true;
            }
        } catch (Throwable $e) {
            // При недоступной БД считаем, что Vosk ещё стоит, и ничего не удаляем:
            // это безопаснее, чем снести загрузчик, который может быть нужен.
            $voskStillInstalled = true;
        }

        if (!$voskStillInstalled) {
            if (file_exists($htaccessPath)) {
                $htContent = file_get_contents($htaccessPath);
                $line = 'php_value auto_prepend_file ' . $loaderPath;
                $htContent = str_replace(array($line . "\r\n", $line . "\n", $line), '', $htContent);
                $htContent = preg_replace('/\n{3,}/', "\n\n", $htContent);
                file_put_contents($htaccessPath, $htContent);
            }
            if (file_exists($loaderPath)) {
                @unlink($loaderPath);
            }
        }

        parent::uninstall();
    }
}
