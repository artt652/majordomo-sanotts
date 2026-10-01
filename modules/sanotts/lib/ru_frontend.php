<?php

/**
 * Русские правила модуля sanoTTS: текст -> фразы -> коды фонем для движка.
 *
 *   числа       в 1989 году -> в тысяча девятьсот восемьдесят девятом году,
 *               2 минуты -> две минуты, 40% -> сорок процентов, 07:30, 22,5°
 *   валюты      $868 млн, 5 GBP, 1,4 тыс. грн
 *   ё           дешевый -> дешёвый, еще -> ещё (словарь eyo-kernel, MIT)
 *   ударения    замо́к / за́мок, в саду́ / к са́ду, сто́ит / стои́т и др. омографы;
 *               ударение из текста: за́мок, зам+ок, замОк
 *   латиница    Wi-Fi -> вай-фай, HTTPS -> эйч ти ти пи эс, config.yaml
 *   фонемы      паузы на запятых, мягкий знак (соль, день), твёрдое л (жёлтый),
 *               приставки (суперкороткая), тэ в заимствованиях (тест)
 *
 * Регулярные выражения записаны в синтаксисе JS и переводятся в PCRE функцией
 * rx(): там \d \w \b — только ASCII, а в PHP с флагом /u они были бы Unicode.
 *
 * Код совместим с PHP 5.4+; mbstring не обязателен.
 */
class SanottsRuText
{
    const VERSION = 'ru-20261002b';

    // ---------------------------------------------------------------------
    // Совместимость с регулярными выражениями JS
    // ---------------------------------------------------------------------
    const WS = '\t\n\x0B\f\r \x{a0}\x{1680}\x{2000}-\x{200a}\x{2028}\x{2029}\x{202f}\x{205f}\x{3000}\x{feff}';
    const WORD = 'A-Za-z0-9_';
    private static $rxCache = array();

    /** JS-регулярка (источник + флаги) -> PCRE. */
    public static function rx($src, $flags = '')
    {
        $key = $flags . "\0" . $src;
        if (isset(self::$rxCache[$key])) return self::$rxCache[$key];
        $out = '';
        $cls = false;
        $n = strlen($src);
        $dotAll = strpos($flags, 's') !== false;
        for ($i = 0; $i < $n; $i++) {
            $c = $src[$i];
            if ($c === '\\' && $i + 1 < $n) {
                $e = $src[++$i];
                switch ($e) {
                    case 'd': $out .= $cls ? '0-9' : '[0-9]'; break;
                    case 'D': $out .= '[^0-9]'; break;
                    case 'w': $out .= $cls ? self::WORD : '[' . self::WORD . ']'; break;
                    case 'W': $out .= '[^' . self::WORD . ']'; break;
                    case 's': $out .= $cls ? self::WS : '[' . self::WS . ']'; break;
                    case 'S': $out .= '[^' . self::WS . ']'; break;
                    case 'b':
                        $out .= '(?:(?<=[' . self::WORD . '])(?![' . self::WORD . '])|(?<![' . self::WORD . '])(?=[' . self::WORD . ']))';
                        break;
                    case 'B':
                        $out .= '(?:(?<=[' . self::WORD . '])(?=[' . self::WORD . '])|(?<![' . self::WORD . '])(?![' . self::WORD . ']))';
                        break;
                    case 'u':
                        $out .= '\x{' . substr($src, $i + 1, 4) . '}';
                        $i += 4;
                        break;
                    case '/': $out .= '/'; break;
                    case '~': $out .= '\~'; break;
                    default: $out .= '\\' . $e;
                }
                continue;
            }
            if ($cls) {
                if ($c === ']') $cls = false;
                $out .= $c === '~' ? '\~' : $c;
                continue;
            }
            if ($c === '[') {
                if (substr($src, $i, 3) === '[^]') { $out .= '[\s\S]'; $i += 2; continue; }
                if (substr($src, $i, 2) === '[]') { $out .= '(?!)'; $i += 1; continue; }
                $cls = true;
                $out .= '[';
                if ($i + 1 < $n && $src[$i + 1] === '^') { $out .= '^'; $i++; }
                if ($i + 1 < $n && $src[$i + 1] === ']') { $out .= '\]'; $i++; }
                continue;
            }
            if ($c === '.' && !$dotAll) { $out .= '[^\n\r\x{2028}\x{2029}]'; continue; }
            if ($c === '~') { $out .= '\~'; continue; }
            $out .= $c;
        }
        $re = '~' . $out . '~uD' . (strpos($flags, 'i') !== false ? 'i' : '') . ($dotAll ? 's' : '');
        return self::$rxCache[$key] = $re;
    }

    public static function test($s, $src, $flags = '')
    {
        return preg_match(self::rx($src, $flags), $s) === 1;
    }

    /** String.prototype.match без g: группы (null — не участвовала) + 'index'. */
    public static function match($s, $src, $flags = '')
    {
        if (!preg_match(self::rx($src, $flags), $s, $m, PREG_OFFSET_CAPTURE)) return null;
        $g = array();
        foreach ($m as $k => $v) if (is_int($k)) $g[$k] = $v[1] >= 0 ? $v[0] : null;
        for ($k = count($m); $k < 12; $k++) if (!isset($g[$k])) $g[$k] = null;
        $g['index'] = $m[0][1];
        return $g;
    }

    /** String.prototype.match с g: список совпадений. */
    public static function matchAll($s, $src, $flags = '')
    {
        preg_match_all(self::rx($src, $flags), $s, $m);
        return $m[0];
    }

    /** JS-строка замены ($1, $&, $$) -> PHP. */
    private static function jsRepl($to)
    {
        $out = '';
        $n = strlen($to);
        for ($i = 0; $i < $n; $i++) {
            $c = $to[$i];
            if ($c === '\\') { $out .= '\\\\'; continue; }
            if ($c === '$' && $i + 1 < $n) {
                $e = $to[$i + 1];
                if ($e === '$') { $out .= '$'; $i++; continue; }
                if ($e === '&') { $out .= '${0}'; $i++; continue; }
                if ($e >= "0" && $e <= "9") { $out .= '${' . $e . '}'; $i++; continue; }
            }
            $out .= $c;
        }
        return $out;
    }

    /**
     * String.prototype.replace: $to — строка JS-замены или функция
     * ($groups, $byteOffset, $subject) -> строка. Флаг g — все совпадения.
     */
    public static function rep($s, $src, $flags, $to)
    {
        $re = self::rx($src, $flags);
        $global = strpos($flags, 'g') !== false;
        if (!($to instanceof Closure)) return preg_replace($re, self::jsRepl($to), $s, $global ? -1 : 1);
        $out = '';
        $pos = 0;
        $last = 0;
        $len = strlen($s);
        while ($pos <= $len && preg_match($re, $s, $m, PREG_OFFSET_CAPTURE, $pos)) {
            $off = $m[0][1];
            $whole = $m[0][0];
            $g = array();
            for ($k = 0; $k < 12; $k++) $g[$k] = (isset($m[$k]) && $m[$k][1] >= 0) ? $m[$k][0] : null;
            $out .= substr($s, $last, $off - $last) . $to($g, $off, $s);
            $last = $off + strlen($whole);
            if (!$global) break;
            if ($whole === '') {
                if ($off >= $len) break;
                $pos = $off + self::charLen($s, $off);
            } else {
                $pos = $last;
            }
        }
        return $out . substr($s, $last);
    }

    private static function charLen($s, $off)
    {
        $b = ord($s[$off]);
        return $b < 0x80 ? 1 : ($b < 0xE0 ? 2 : ($b < 0xF0 ? 3 : 4));
    }

    /** Символы строки (кодовые точки). */
    public static function chars($s)
    {
        if ($s === '' || $s === null) return array();
        return preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
    }

    private static $lcMap = null;
    private static $ucMap = null;

    /** toLowerCase (кириллица + латиница; mbstring, если есть). */
    public static function lc($s)
    {
        if (function_exists('mb_strtolower')) return mb_strtolower($s, 'UTF-8');
        self::caseMaps();
        return strtr($s, self::$lcMap);
    }

    public static function uc($s)
    {
        if (function_exists('mb_strtoupper')) return mb_strtoupper($s, 'UTF-8');
        self::caseMaps();
        return strtr($s, self::$ucMap);
    }

    private static function caseMaps()
    {
        if (self::$lcMap !== null) return;
        self::$lcMap = array();
        for ($c = 65; $c <= 90; $c++) self::$lcMap[chr($c)] = chr($c + 32);
        $up = self::chars('АБВГДЕЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯЁЄІЇЎҐ');
        $lo = self::chars('абвгдежзийклмнопрстуфхцчшщъыьэюяёєіїўґ');
        foreach ($up as $i => $u) self::$lcMap[$u] = $lo[$i];
        self::$ucMap = array_flip(self::$lcMap);
    }

    /** Первая буква заглавная (w[0].toUpperCase() + w.slice(1)). */
    public static function ucfirst($s)
    {
        $c = self::chars($s);
        if (!$c) return $s;
        return self::uc($c[0]) . substr($s, strlen($c[0]));
    }

    /** Длина в единицах UTF-16, как String.length. */
    public static function jsLen($s)
    {
        $n = 0;
        foreach (self::chars($s) as $c) $n += strlen($c) === 4 ? 2 : 1;
        return $n;
    }

    public static function trim($s)
    {
        return preg_replace('~^[' . self::WS . ']+|[' . self::WS . ']+$~uD', '', $s);
    }

    public static function trimEnd($s)
    {
        return preg_replace('~[' . self::WS . ']+$~uD', '', $s);
    }

    /** Строки в JS: s.split(/(\s+)/). */
    private static function splitWs($s)
    {
        return preg_split('~([' . self::WS . ']+)~u', $s, -1, PREG_SPLIT_DELIM_CAPTURE);
    }

    /** Array.prototype.sort по длине (убыв.), устойчиво. */
    private static function sortByLenDesc(array $keys)
    {
        $d = array();
        foreach ($keys as $i => $k) $d[] = array(self::jsLen($k), $i, $k);
        usort($d, function ($a, $b) {
            if ($a[0] !== $b[0]) return $b[0] - $a[0];
            return $a[1] - $b[1];
        });
        $out = array();
        foreach ($d as $x) $out[] = $x[2];
        return $out;
    }

    private static function idiv($a, $b)
    {
        return (int)floor($a / $b);
    }

    private static function jsNumber($s)
    {
        return $s === '' ? 0 : (float)$s;
    }

    // ---------------------------------------------------------------------
    // Сводки погоды и сценарии
    // MajorDoMo, — км/ч, м/с, мм рт. ст., часовые пояса. Идут первыми; дальше
    // числа и единицы согласует normalizeNumbers() («5 км в час» -> пять километров в час).
    // ---------------------------------------------------------------------
    public static function moduleRules($text)
    {
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', ' ', (string)$text);
        $text = self::rep($text, '(\d)\s*км\s*\/\s*ч(?![а-яё])', 'gi', '$1 км в час');
        $text = self::rep($text, '(^|[^а-яё])км\s*\/\s*ч(?![а-яё])', 'gi', '$1километров в час');
        $text = self::rep($text, '(\d)\s*м\s*\/\s*с(?![а-яё])', 'gi', '$1 м в секунду');
        $text = self::rep($text, '(^|[^а-яё])м\s*\/\s*с(?![а-яё])', 'gi', '$1метров в секунду');
        $text = self::rep($text, '(^|[^а-яё])рт\.?\s*ст\.?(?![а-яё])', 'gi', function ($g, $off, $s) {
            $end = substr($g[0], -1) === '.' && SanottsRuText::test(substr($s, $off + strlen($g[0])), '^(\s*$|\s+[А-ЯЁA-Z])') ? '.' : '';
            return $g[1] . 'ртутного столба' . $end;
        });
        $tzPos = array(0 => 'гринвичу', 1 => 'центральноевропейскому', 2 => 'калининграду', 3 => 'москве', 4 => 'самаре',
            5 => 'екатеринбургу', 6 => 'омску', 7 => 'красноярску', 8 => 'иркутску', 9 => 'якутску', 10 => 'владивостоку',
            11 => 'магадану', 12 => 'камчатке');
        $tzNeg = array(1 => 'азорам', 2 => 'бразилии', 3 => 'аргентине', 4 => 'нью-йорку', 5 => 'чикаго', 6 => 'денверу',
            7 => 'лос-анджелесу', 8 => 'анкориджу', 9 => 'гавайям', 10 => 'острову пасхи');
        // «по UTC+3» и «UTC+3» — одинаково «по москве».
        $text = self::rep($text, '(^|[^А-Яа-яЁё])(?:[Пп]о\s+)?(?:UTC|GMT)([+\-−])(\d{1,2})(?::00)?\b', 'g', function ($g) use ($tzPos, $tzNeg) {
            $h = (int)$g[3];
            if ($g[2] === '+' && isset($tzPos[$h])) return $g[1] . 'по ' . $tzPos[$h];
            if ($g[2] !== '+' && isset($tzNeg[$h])) return $g[1] . 'по ' . $tzNeg[$h];
            return $g[0];
        });
        $tz = array('UTC' => 'гринвичу', 'GMT' => 'гринвичу', 'MSK' => 'москве', 'EDT' => 'нью-йорку', 'EST' => 'нью-йорку',
            'PDT' => 'лос-анджелесу', 'PST' => 'лос-анджелесу', 'CDT' => 'чикаго', 'CST' => 'чикаго', 'MDT' => 'денверу',
            'MST' => 'денверу', 'CET' => 'центральноевропейскому', 'CEST' => 'центральноевропейскому',
            'EET' => 'восточноевропейскому', 'EEST' => 'восточноевропейскому', 'BST' => 'лондону', 'JST' => 'токио', 'KST' => 'сеулу');
        foreach ($tz as $abbr => $city) {
            $text = self::rep($text, '(^|[^А-Яа-яЁё])(?:[Пп]о\s+)?\b' . $abbr . '\b(?![+\-−]\d)', 'g', '$1по ' . $city);
        }
        return $text;
    }

    // ---------------------------------------------------------------------
    // Деление на фразы
    // ---------------------------------------------------------------------

    /** Единицы UTF-16: символ вне BMP занимает две позиции (вторая пустая). */
    private static function units($s)
    {
        $u = array();
        foreach (self::chars($s) as $c) {
            $u[] = $c;
            if (strlen($c) === 4) $u[] = '';
        }
        return $u;
    }

    public static function splitChunks($text)
    {
        $MAX = 120;
        $MIN = 40;
        $sents = self::matchAll($text, '[^.!?।。！？]+[.!?।。！？]*\s*', 'gu');
        if (!$sents) $sents = array($text);
        $ABBR = '(^|[\s(])(п|пп|ст|стр|гл|рис|табл|таб|ил|илл|разд|ч|т|л|прил|№|см|им|ул|д|кв|корп)\.$';
        for ($i = count($sents) - 2; $i >= 0; $i--) {
            $a = $sents[$i];
            $b = $sents[$i + 1];
            if (!self::test($a, '\s$', 'u') || self::test($b, '^\s*[\p{Ll}\p{Nd}§№(«"]', 'u') &&
                (self::test($b, '^\s*\p{Ll}', 'u') || self::test(self::trimEnd($a), $ABBR, 'iu'))) {
                $sents[$i] = $a . $b;
                array_splice($sents, $i + 1, 1);
                continue;
            }
            if (self::test(self::trimEnd($sents[$i]), '\p{Nd}\.$', 'u') && self::test($sents[$i + 1], '^\s*\p{Nd}', 'u')) {
                $sents[$i] = self::trimEnd($sents[$i]) . $sents[$i + 1];
                array_splice($sents, $i + 1, 1);
            }
        }
        $isWs = function ($c) {
            return $c !== '' && preg_match('~^[' . SanottsRuText::WS . ']$~u', $c) === 1;
        };
        $chunks = array();
        foreach ($sents as $s) {
            $s = self::trim($s);
            if ($s === '') continue;
            $u = self::units($s);
            while (count($u) > $MAX) {
                $cut = -1;
                $cnt = count($u);
                for ($j = 0; $j < $cnt; $j++) {
                    if ($u[$j] === '' || strpos(',;:、，；：', $u[$j]) === false) continue;
                    $end = $j + 1;
                    while ($end < $cnt && $isWs($u[$end])) $end++;
                    if ($end >= $MIN && $end <= $MAX) $cut = $end;
                    $j = $end - 1;
                }
                if ($cut < 0) {
                    $sp = -1;
                    for ($j = min($MAX, $cnt - 1); $j >= 0; $j--) if ($u[$j] === ' ') { $sp = $j; break; }
                    $cut = $sp > $MIN ? $sp + 1 : $MAX;
                }
                $chunks[] = self::trim(implode('', array_slice($u, 0, $cut)));
                $u = self::units(self::trim(implode('', array_slice($u, $cut))));
            }
            if ($u) $chunks[] = implode('', $u);
        }
        return $chunks ? $chunks : array($text);
    }

    // ---------------------------------------------------------------------
    // Числа, даты, время, единицы
    // ---------------------------------------------------------------------
    private static $U = array(
        0 => array('ноль', 'ноля', 'нолю', 'нолём', 'ноле'),
        1 => array('один', 'одного', 'одному', 'одним', 'одном'),
        2 => array('два', 'двух', 'двум', 'двумя', 'двух'),
        3 => array('три', 'трёх', 'трём', 'тремя', 'трёх'),
        4 => array('четыре', 'четырёх', 'четырём', 'четырьмя', 'четырёх'),
        5 => array('пять', 'пяти', 'пяти', 'пятью', 'пяти'),
        6 => array('шесть', 'шести', 'шести', 'шестью', 'шести'),
        7 => array('семь', 'семи', 'семи', 'семью', 'семи'),
        8 => array('восемь', 'восьми', 'восьми', 'восемью', 'восьми'),
        9 => array('девять', 'девяти', 'девяти', 'девятью', 'девяти'),
        10 => array('десять', 'десяти', 'десяти', 'десятью', 'десяти'),
        11 => array('одиннадцать', 'одиннадцати', 'одиннадцати', 'одиннадцатью', 'одиннадцати'),
        12 => array('двенадцать', 'двенадцати', 'двенадцати', 'двенадцатью', 'двенадцати'),
        13 => array('тринадцать', 'тринадцати', 'тринадцати', 'тринадцатью', 'тринадцати'),
        14 => array('четырнадцать', 'четырнадцати', 'четырнадцати', 'четырнадцатью', 'четырнадцати'),
        15 => array('пятнадцать', 'пятнадцати', 'пятнадцати', 'пятнадцатью', 'пятнадцати'),
        16 => array('шестнадцать', 'шестнадцати', 'шестнадцати', 'шестнадцатью', 'шестнадцати'),
        17 => array('семнадцать', 'семнадцати', 'семнадцати', 'семнадцатью', 'семнадцати'),
        18 => array('восемнадцать', 'восемнадцати', 'восемнадцати', 'восемнадцатью', 'восемнадцати'),
        19 => array('девятнадцать', 'девятнадцати', 'девятнадцати', 'девятнадцатью', 'девятнадцати'),
    );
    private static $T = array(
        2 => array('двадцать', 'двадцати', 'двадцати', 'двадцатью', 'двадцати'),
        3 => array('тридцать', 'тридцати', 'тридцати', 'тридцатью', 'тридцати'),
        4 => array('сорок', 'сорока', 'сорока', 'сорока', 'сорока'),
        5 => array('пятьдесят', 'пятидесяти', 'пятидесяти', 'пятьюдесятью', 'пятидесяти'),
        6 => array('шестьдесят', 'шестидесяти', 'шестидесяти', 'шестьюдесятью', 'шестидесяти'),
        7 => array('семьдесят', 'семидесяти', 'семидесяти', 'семьюдесятью', 'семидесяти'),
        8 => array('восемьдесят', 'восьмидесяти', 'восьмидесяти', 'восемьюдесятью', 'восьмидесяти'),
        9 => array('девяносто', 'девяноста', 'девяноста', 'девяноста', 'девяноста'),
    );
    private static $H = array(
        1 => array('сто', 'ста', 'ста', 'ста', 'ста'),
        2 => array('двести', 'двухсот', 'двумстам', 'двумястами', 'двухстах'),
        3 => array('триста', 'трёхсот', 'трёмстам', 'тремястами', 'трёхстах'),
        4 => array('четыреста', 'четырёхсот', 'четырёмстам', 'четырьмястами', 'четырёхстах'),
        5 => array('пятьсот', 'пятисот', 'пятистам', 'пятьюстами', 'пятистах'),
        6 => array('шестьсот', 'шестисот', 'шестистам', 'шестьюстами', 'шестистах'),
        7 => array('семьсот', 'семисот', 'семистам', 'семьюстами', 'семистах'),
        8 => array('восемьсот', 'восьмисот', 'восьмистам', 'восемьюстами', 'восьмистах'),
        9 => array('девятьсот', 'девятисот', 'девятистам', 'девятьюстами', 'девятистах'),
    );
    private static $FEM1 = array('одна', 'одной', 'одной', 'одной', 'одной');
    private static $FEM2 = array('две', 'двух', 'двум', 'двумя', 'двух');
    private static $NEU1 = array('одно', 'одного', 'одному', 'одним', 'одном');
    private static $CASES = array('nom' => 0, 'gen' => 1, 'dat' => 2, 'ins' => 3, 'prep' => 4, 'acc' => 0);
    private static $SCALES = array(
        array(1e9, array('one' => array('миллиард', 'миллиарда', 'миллиарду', 'миллиардом', 'миллиарде'),
            'few' => array('миллиарда', 'миллиардов', 'миллиардам', 'миллиардами', 'миллиардах'),
            'many' => array('миллиардов', 'миллиардов', 'миллиардам', 'миллиардами', 'миллиардах')), 'm'),
        array(1e6, array('one' => array('миллион', 'миллиона', 'миллиону', 'миллионом', 'миллионе'),
            'few' => array('миллиона', 'миллионов', 'миллионам', 'миллионами', 'миллионах'),
            'many' => array('миллионов', 'миллионов', 'миллионам', 'миллионами', 'миллионах')), 'm'),
        array(1e3, array('one' => array('тысяча', 'тысячи', 'тысяче', 'тысячей', 'тысяче'),
            'few' => array('тысячи', 'тысяч', 'тысячам', 'тысячами', 'тысячах'),
            'many' => array('тысяч', 'тысяч', 'тысячам', 'тысячами', 'тысячах')), 'f'),
    );

    private static function below1000($n, $c, $gender, $acc)
    {
        $n = (int)$n;
        $out = array();
        if ($n >= 100) { $out[] = self::$H[self::idiv($n, 100)][$c]; $n %= 100; }
        if ($n >= 20) { $out[] = self::$T[self::idiv($n, 10)][$c]; $n %= 10; }
        if ($n > 0 || (!$out && $n === 0)) {
            if ($n === 1 && $gender === 'f') $out[] = $acc ? 'одну' : self::$FEM1[$c];
            elseif ($n === 1 && $gender === 'n') $out[] = self::$NEU1[$c];
            elseif ($n === 2 && $gender === 'f') $out[] = self::$FEM2[$c];
            elseif ($n > 0 || !$out) $out[] = self::$U[$n][$c];
        }
        return $out;
    }

    private static function pluralKind($n)
    {
        $n100 = fmod($n, 100);
        $n10 = fmod($n, 10);
        if ($n100 >= 11 && $n100 <= 14) return 'many';
        if ($n10 == 1) return 'one';
        if ($n10 >= 2 && $n10 <= 4) return 'few';
        return 'many';
    }

    public static function cardinal($n, $kase = 'nom', $gender = 'm')
    {
        $n = (float)$n;
        if ($n == 0) return self::$U[0][self::$CASES[$kase]];
        $c = self::$CASES[$kase];
        $acc = $kase === 'acc';
        $out = array();
        foreach (self::$SCALES as $sc) {
            list($v, $forms, $g) = $sc;
            if ($n >= $v) {
                $k = floor($n / $v);
                $n = fmod($n, $v);
                if (!($v == 1e3 && $k == 1 && $c === 0)) $out = array_merge($out, self::below1000($k, $c, $g, $acc && $g === 'f'));
                $kind = self::pluralKind($k);
                $form = $c === 0 ? $forms[$kind][0] : ($kind === 'one' ? $forms['one'][$c] : $forms['few'][$c]);
                if ($c === 0 && $acc && $v == 1e3 && $kind === 'one') $form = 'тысячу';
                $out[] = $form;
            }
        }
        if ($n > 0) $out = array_merge($out, self::below1000($n, $c, $gender, $acc));
        return implode(' ', $out);
    }

    private static $ORD_U = array(1 => array('перв', 'a'), 2 => array('втор', 'b'), 3 => array('трет', 'c'), 4 => array('четвёрт', 'a'),
        5 => array('пят', 'a'), 6 => array('шест', 'b'), 7 => array('седьм', 'b'), 8 => array('восьм', 'b'), 9 => array('девят', 'a'),
        10 => array('десят', 'a'), 11 => array('одиннадцат', 'a'), 12 => array('двенадцат', 'a'), 13 => array('тринадцат', 'a'),
        14 => array('четырнадцат', 'a'), 15 => array('пятнадцат', 'a'), 16 => array('шестнадцат', 'a'), 17 => array('семнадцат', 'a'),
        18 => array('восемнадцат', 'a'), 19 => array('девятнадцат', 'a'));
    private static $ORD_T = array(2 => array('двадцат', 'a'), 3 => array('тридцат', 'a'), 4 => array('сороков', 'b'),
        5 => array('пятидесят', 'a'), 6 => array('шестидесят', 'a'), 7 => array('семидесят', 'a'), 8 => array('восьмидесят', 'a'),
        9 => array('девяност', 'a'));
    private static $ORD_H = array(1 => 'сот', 2 => 'двухсот', 3 => 'трёхсот', 4 => 'четырёхсот', 5 => 'пятисот', 6 => 'шестисот',
        7 => 'семисот', 8 => 'восьмисот', 9 => 'девятисот');
    private static $GEN_PREFIX = array(1 => '', 2 => 'двух', 3 => 'трёх', 4 => 'четырёх', 5 => 'пяти', 6 => 'шести', 7 => 'семи',
        8 => 'восьми', 9 => 'девяти', 10 => 'десяти', 20 => 'двадцати', 30 => 'тридцати', 40 => 'сорока', 50 => 'пятидесяти', 100 => 'сто');
    private static $END = array(
        'a' => array('m' => array('ый', 'ого', 'ому', 'ым', 'ом', 'ый'), 'f' => array('ая', 'ой', 'ой', 'ой', 'ой', 'ую'), 'n' => array('ое', 'ого', 'ому', 'ым', 'ом', 'ое')),
        'b' => array('m' => array('ой', 'ого', 'ому', 'ым', 'ом', 'ой'), 'f' => array('ая', 'ой', 'ой', 'ой', 'ой', 'ую'), 'n' => array('ое', 'ого', 'ому', 'ым', 'ом', 'ое')),
        'c' => array('m' => array('ий', 'ьего', 'ьему', 'ьим', 'ьем', 'ий'), 'f' => array('ья', 'ьей', 'ьей', 'ьей', 'ьей', 'ью'), 'n' => array('ье', 'ьего', 'ьему', 'ьим', 'ьем', 'ье')),
    );
    private static $OCASE = array('nom' => 0, 'gen' => 1, 'dat' => 2, 'ins' => 3, 'prep' => 4, 'acc' => 5);

    public static function ordinal($n, $kase = 'nom', $gender = 'm')
    {
        $n = (float)$n;
        $i = self::$OCASE[$kase];
        $END = self::$END;
        $end = function ($cls) use ($END, $gender, $i) { return $END[$cls][$gender][$i]; };
        if (fmod($n, 1000) == 0 && $n >= 1000) {
            $k = $n / 1000;
            $head = $n >= 1e6 ? '' : (isset(self::$GEN_PREFIX[(int)$k]) ? self::$GEN_PREFIX[(int)$k] : str_replace(' ', '', self::cardinal($k, 'gen')));
            return $head . 'тысячн' . $end('a');
        }
        $rest = (int)fmod($n, 1000);
        $high = $n - $rest;
        $prefix = $high ? self::cardinal($high) . ' ' : '';
        if ($rest % 100 === 0) {
            $h = isset(self::$ORD_H[self::idiv($rest, 100)]) ? self::$ORD_H[self::idiv($rest, 100)] : 'undefined';
            return $prefix . $h . $end('a');
        }
        $hundreds = $rest >= 100 ? self::$H[self::idiv($rest, 100)][0] . ' ' : '';
        $r = $rest % 100;
        if ($r < 20) { list($s, $c) = self::$ORD_U[$r]; return $prefix . $hundreds . $s . $end($c); }
        if ($r % 10 === 0) { list($s, $c) = self::$ORD_T[self::idiv($r, 10)]; return $prefix . $hundreds . $s . $end($c); }
        list($s, $c) = self::$ORD_U[$r % 10];
        return $prefix . $hundreds . self::$T[self::idiv($r, 10)][0] . ' ' . $s . $end($c);
    }

    private static $PREP_CASE = array(
        'gen' => array('до', 'с', 'со', 'от', 'из', 'около', 'после', 'более', 'менее', 'больше', 'меньше', 'свыше',
            'ниже', 'выше', 'без', 'для', 'у', 'против', 'вместо', 'кроме', 'среди', 'начиная', 'порядка', 'вплоть'),
        'dat' => array('к', 'ко', 'по', 'согласно', 'благодаря'),
        'prep' => array('о', 'об', 'при'),
        'ins' => array('между', 'перед', 'над', 'под', 'за'),
    );

    /**
     * Падеж времени «ЧЧ:ММ» по тексту перед ним: предлог прямо перед временем
     * («с», «до», «к», «перед»…) или, после «и»/«или», — предлог перед
     * предыдущим временем. В остальных случаях — именительный («в 7:30»).
     */
    public static function timeCase($before)
    {
        $before = self::lc($before);
        if (preg_match('/(?:^|[\s(«"])([а-яё]+)\s+$/u', $before, $m)) {
            $k = self::caseFromPrep($m[1]);
            if ($k !== null) return $k;
            if (($m[1] === 'и' || $m[1] === 'или') &&
                preg_match('/(?:^|[\s(«"])([а-яё]+)\s+(?:[01]?\d|2[0-3]):[0-5]\d\s+(?:и|или)\s+$/u', $before, $m2)) {
                $k = self::caseFromPrep($m2[1]);
                if ($k !== null) return $k;
            }
        }
        return 'nom';
    }

    private static function caseFromPrep($p)
    {
        foreach (self::$PREP_CASE as $k => $set) if (in_array($p, $set, true)) return $k;
        return null;
    }

    const FEM_NOUN = '^(минут|гривн|гривен|секунд|недел|тысяч|штук|ламп|комнат|копе|розетк|батаре|камер|групп|зон|сцен|единиц|строк|попытк|ступен|спальн|квартир|шторк|штор|лампочк|позици|ночь|ноч|сутк|част)';
    const NEU_NOUN = '^(окн|окон|устройств|утр|очк)';
    const MONTHS = '^(января|февраля|марта|апреля|мая|июня|июля|августа|сентября|октября|ноября|декабря)$';
    const YEAR = '^(год|году|года|годом|годе|годах|г\.?)$';

    private static $UNITS = null;
    private static $HALF_NOUNS = null;
    private static $FEM_UNITS = array('мин', 'сек', 'тыс', 'грн', '₴', 'коп', 'иен', 'лир', 'руп', 'вон');
    private static $SCALES_ABBR = array('тыс', 'млн', 'млрд', 'трлн');

    private static function units_()
    {
        if (self::$UNITS !== null) return;
        $U = array(
            '%' => array('процент', 'процента', 'процентов'),
            '°' => array('градус', 'градуса', 'градусов'),
            'квт·ч' => array('киловатт-час', 'киловатт-часа', 'киловатт-часов'),
            'квтч' => array('киловатт-час', 'киловатт-часа', 'киловатт-часов'),
            'квт' => array('киловатт', 'киловатта', 'киловатт'),
            'вт' => array('ватт', 'ватта', 'ватт'),
            'км' => array('километр', 'километра', 'километров'),
            'м' => array('метр', 'метра', 'метров'),
            'см' => array('сантиметр', 'сантиметра', 'сантиметров'),
            'мм' => array('миллиметр', 'миллиметра', 'миллиметров'),
            'кг' => array('килограмм', 'килограмма', 'килограммов'),
            'л' => array('литр', 'литра', 'литров'),
            'мин' => array('минута', 'минуты', 'минут'),
            'сек' => array('секунда', 'секунды', 'секунд'),
            'ч' => array('час', 'часа', 'часов'),
            'руб' => array('рубль', 'рубля', 'рублей'),
            '₽' => array('рубль', 'рубля', 'рублей'),
            'кб' => array('килобайт', 'килобайта', 'килобайт'),
            'мб' => array('мегабайт', 'мегабайта', 'мегабайт'),
            'гб' => array('гигабайт', 'гигабайта', 'гигабайт'),
            'тыс' => array('тысяча', 'тысячи', 'тысяч'),
            'млн' => array('миллион', 'миллиона', 'миллионов'),
            'млрд' => array('миллиард', 'миллиарда', 'миллиардов'),
            'трлн' => array('триллион', 'триллиона', 'триллионов'),
            'грн' => array('гривна', 'гривны', 'гривен'),
            '₴' => array('гривна', 'гривны', 'гривен'),
            'долл' => array('доллар', 'доллара', 'долларов'),
            'usd' => array('доллар', 'доллара', 'долларов'),
            '$' => array('доллар', 'доллара', 'долларов'),
            'eur' => array('евро', 'евро', 'евро'),
            '€' => array('евро', 'евро', 'евро'),
            'коп' => array('копейка', 'копейки', 'копеек'),
            'евр' => array('евро', 'евро', 'евро'),
            'фнт' => array('фунт', 'фунта', 'фунтов'),
            'иен' => array('иена', 'иены', 'иен'),
            'юан' => array('юань', 'юаня', 'юаней'),
            'тнг' => array('тэнгэ', 'тэнгэ', 'тэнгэ'),
            'брб' => array('белорусский рубль', 'белорусских рубля', 'белорусских рублей'),
            'фрн' => array('франк', 'франка', 'франков'),
            'злт' => array('злотый', 'злотых', 'злотых'),
            'лир' => array('лира', 'лиры', 'лир'),
            'руп' => array('рупия', 'рупии', 'рупий'),
            'вон' => array('вона', 'воны', 'вон'),
            'бтк' => array('биткоин', 'биткоина', 'биткоинов'),
            'эфр' => array('эфир', 'эфира', 'эфиров'),
            'цнт' => array('цент', 'цента', 'центов'),
            'пнс' => array('пенс', 'пенса', 'пенсов'),
        );
        foreach (array(array('км', 'километр'), array('м', 'метр'), array('дм', 'дециметр'), array('см', 'сантиметр'), array('мм', 'миллиметр')) as $x) {
            list($ab, $base) = $x;
            $U[$ab . 'кв'] = array("квадратный $base", "квадратных {$base}а", "квадратных {$base}ов", "квадратного {$base}а");
            $U[$ab . 'куб'] = array("кубический $base", "кубических {$base}а", "кубических {$base}ов", "кубического {$base}а");
        }
        self::$UNITS = $U;
        $H = array(
            array('час', 'часа', 'часов'), array('минута', 'минуты', 'минут', 1), array('секунда', 'секунды', 'секунд', 1),
            array('день', 'дня', 'дней'), array('неделя', 'недели', 'недель', 1), array('месяц', 'месяца', 'месяцев'), array('год', 'года', 'лет'),
            array('раз', 'раза', 'раз'), array('литр', 'литра', 'литров'), array('метр', 'метра', 'метров'), array('километр', 'километра', 'километров'),
            array('килограмм', 'килограмма', 'килограммов'), array('тонна', 'тонны', 'тонн', 1), array('тысяча', 'тысячи', 'тысяч', 1),
            array('миллион', 'миллиона', 'миллионов'), array('миллиард', 'миллиарда', 'миллиардов'), array('рубль', 'рубля', 'рублей'),
            array('гривна', 'гривны', 'гривен', 1), array('доллар', 'доллара', 'долларов'), array('процент', 'процента', 'процентов'),
            array('балл', 'балла', 'баллов'), array('стакан', 'стакана', 'стаканов'), array('ложка', 'ложки', 'ложек', 1),
            array('этаж', 'этажа', 'этажей'), array('градус', 'градуса', 'градусов'), array('года', 'года', 'лет'),
        );
        self::$HALF_NOUNS = array();
        foreach ($H as $h) self::$HALF_NOUNS[] = array('forms' => array($h[0], $h[1], $h[2]), 'f' => !empty($h[3]));
    }

    private static function unit($k)
    {
        return ($k !== null && isset(self::$UNITS[$k])) ? self::$UNITS[$k] : null;
    }

    private static function unitWord($tok, $word, $nextTok)
    {
        $m = self::match($tok, '^([^\s,.;:!?)]+?)(\.?)([,;:!?)]*\.?)$');
        if (!$m) return $tok;
        $endsSentence = $m[2] !== '' && $m[2] !== null && ($nextTok === null || $nextTok === '' || self::test($nextTok, '^[А-ЯЁA-Z]'));
        return $word . ($endsSentence ? '.' : '') . $m[3];
    }

    private static function nounAgree($unit, $n, $kase, $isDecimal)
    {
        $forms = self::$UNITS[$unit];
        if ($isDecimal) return isset($forms[3]) ? $forms[3] : $forms[1];
        if ($kase !== 'nom' && $kase !== 'acc') return self::pluralKind($n) === 'one' ? (isset($forms[3]) ? $forms[3] : $forms[1]) : $forms[2];
        $idx = array('one' => 0, 'few' => 1, 'many' => 2);
        $f = $forms[$idx[self::pluralKind($n)]];
        if ($kase === 'acc' && self::pluralKind($n) === 'one') return self::rep(self::rep($f, 'а$', '', 'у'), 'я$', '', 'ю');
        return $f;
    }

    private static function decimalWords($intPart, $frac, $kase)
    {
        $iv = self::jsNumber($intPart);
        $whole = self::cardinal($iv, $kase === 'acc' ? 'nom' : $kase, 'f');
        $cel = fmod($iv, 10) == 1 && fmod($iv, 100) != 11 ? ($kase === 'nom' || $kase === 'acc' ? 'целая' : 'целой') : 'целых';
        $fl = self::jsLen($frac);
        $denomN = $fl === 1 ? 'десят' : ($fl === 2 ? 'сот' : 'тысячн');
        $fn = self::jsNumber($frac);
        $fracWords = self::cardinal($fn, $kase === 'acc' ? 'nom' : $kase, 'f');
        $one = fmod($fn, 10) == 1 && fmod($fn, 100) != 11;
        $denom = $denomN . ($one ? ($kase === 'nom' || $kase === 'acc' ? 'ая' : 'ой') : 'ых');
        return "$whole $cel $fracWords $denom";
    }

    private static $STANDALONE = array(
        array('(^|[\s(])км²(?=[\s,.;:!?)]|$)', '$1квадратный километр'),
        array('(^|[\s(])м²(?=[\s,.;:!?)]|$)', '$1квадратный метр'),
        array('(^|[\s(])м³(?=[\s,.;:!?)]|$)', '$1кубический метр'),
        array('(^|[\s(])кВт[·*]?ч(?=[\s,.;:!?)]|$)', '$1киловатт-час'),
        array('(^|[\s(])кВт(?=[\s,.;:!?)]|$)', '$1киловатт'),
        array('(^|[\s(])°C(?=[\s,.;:!?)]|$)', '$1градусов Цельсия'),
    );

    public static function normalizeNumbers($text)
    {
        self::units_();
        if (!self::test($text, '\d')) {
            foreach (self::$STANDALONE as $x) $text = self::rep($text, $x[0], 'g', $x[1]);
            return $text;
        }
        $text = self::rep($text, '(?<![\d:])([01]?\d|2[0-3]):([0-5]\d)(?![\d:])', 'g', function ($g, $off, $str) {
            // Падеж — по предлогу перед временем: «с 22:07» — с двадцати двух ноль семи,
            // «к 7:30» — к семи тридцати, «перед 9:15» — перед девятью пятнадцатью.
            // «с 9:00 до 18:30 и 19:00» — у времени после «и»/«или» падеж предыдущего.
            $kase = SanottsRuText::timeCase(substr($str, 0, $off));
            // Час «1» — словом «час»: в час тридцать, до часа тридцати.
            $one = array('nom' => 'час', 'acc' => 'час', 'gen' => 'часа', 'dat' => 'часу', 'ins' => 'часом', 'prep' => 'часе');
            // Полночь — «ноль» в любом падеже: с ноль двадцати.
            $h = SanottsRuText::num($g[1]);
            $hh = $h == 1 ? $one[$kase] : ($h == 0 ? 'ноль' : SanottsRuText::cardinal($h, $kase));
            $mm = SanottsRuText::num($g[2]);
            if ($mm == 0 && $h == 0) return 'ноль ноль';
            return $mm == 0 ? "$hh ноль ноль" : ($g[2][0] === '0' ? "$hh ноль " . SanottsRuText::cardinal($mm, $kase) : "$hh " . SanottsRuText::cardinal($mm, $kase));
        });
        $text = self::rep($text, '(\d)\s*(км|дм|см|мм|м)(²|³|2|3)(?![\dА-Яа-яЁё])', 'g', function ($g) {
            return $g[1] . ' ' . $g[2] . (SanottsRuText::test($g[3], '[²2]') ? 'кв' : 'куб');
        });
        $text = self::rep($text, '(\d)\s*(кв|куб)\.\s?(км|дм|см|мм|м)(?![А-Яа-яЁё])', 'g', function ($g) {
            return $g[1] . ' ' . $g[3] . $g[2];
        });
        $text = self::rep($text, '(?<![\d,.])(\d{1,3})((?:[   ]\d{3})+)(?![\d,.]\d|\d)', 'g', function ($g) {
            return $g[1] . SanottsRuText::rep($g[2], '[   ]', 'g', '');
        });
        $text = self::rep($text, '(?<![\d,])(\d{1,3}),(\d{1,3})\s*тыс\.?(?![а-яё])', 'gi', function ($g, $off, $str) {
            $n = SanottsRuText::num($g[1]) * 1000 + SanottsRuText::num(str_pad($g[2], 3, '0'));
            $dotEnd = substr($g[0], -1) === '.' && SanottsRuText::test(substr($str, $off + strlen($g[0])), '^(\s*$|\s+[А-ЯЁ])');
            return (string)(int)$n . ($dotEnd ? '.' : '');
        });
        $parts = self::splitWs($text);
        $bare = function ($t) {
            return SanottsRuText::rep(SanottsRuText::rep(SanottsRuText::lc((string)$t), '[^а-яёa-z.·₽₴$€]', 'g', ''), '\.$', '', '');
        };
        $words = array();
        foreach ($parts as $i => $p) if (self::trim($p) !== '') $words[] = $i;
        $W = count($words);
        $tokAt = function ($kk) use (&$parts, $words) {
            return isset($words[$kk]) ? $parts[$words[$kk]] : null;
        };
        $SCALES_ABBR = self::$SCALES_ABBR;
        $currencyAfterScale = function ($kk) use (&$parts, $words, $W, $bare, $tokAt, $SCALES_ABBR) {
            if ($kk >= $W) return;
            $b = $bare($parts[$words[$kk]]);
            $u = SanottsRuText::unitForms($b);
            if ($u && !in_array($b, $SCALES_ABBR, true)) $parts[$words[$kk]] = SanottsRuText::unitWordP($parts[$words[$kk]], $u[2], $tokAt($kk + 1));
        };
        for ($k = 0; $k < $W; $k++) {
            $i = $words[$k];
            $tok = $parts[$i];
            $m = self::match($tok, '^([^\d+\-−]*)([+\-−]?)(\d[\d\u00a0 ]*)(?:[.,](\d+))?(-?(?:й|го|му|м|я|ю|е|ое|ая|ый|ой|ом|ым|ую|ей))?(%|°[CС]?|°)?([^а-яёА-ЯЁ\d]*)$');
            if (!$m) continue;
            $pre = $m[1]; $sign = $m[2]; $intRaw = $m[3]; $frac = $m[4]; $ordSuffix = $m[5]; $unit = $m[6]; $post = $m[7];
            $n = self::jsNumber(self::rep($intRaw, '\D', 'g', ''));
            if ($n > 999999999999) continue;
            $prevTok = $k > 0 ? $parts[$words[$k - 1]] : '';
            $prev = self::test($prevTok, '[,.;:!?]$') ? '' : $bare($prevTok);
            $nextTok = ($post === '' || $post === null) && $k + 1 < $W ? $parts[$words[$k + 1]] : '';
            $next = $bare($nextTok);
            $kase = self::caseFromPrep($prev);
            if ($kase === null) $kase = 'nom';
            $unitKey = self::unit($next) ? $next : null;
            $minus = $sign === '-' || $sign === '−' ? 'минус ' : ($sign === '+' ? 'плюс ' : '');
            $unit0 = ($unit !== null && $unit !== '') ? self::chars($unit)[0] : null;
            $hasFrac = $frac !== null && $frac !== '';

            if ($ordSuffix !== null && $ordSuffix !== '') {
                $sfx = self::rep($ordSuffix, '-', '', '');
                $map = array('й' => array('nom', 'm'), 'ый' => array('nom', 'm'), 'ой' => array('nom', 'm'), 'го' => array('gen', 'm'), 'му' => array('dat', 'm'),
                    'ом' => array('prep', 'm'), 'ым' => array('ins', 'm'), 'я' => array('nom', 'f'), 'ая' => array('nom', 'f'), 'ю' => array('acc', 'f'), 'ую' => array('acc', 'f'),
                    'е' => array('nom', 'n'), 'ое' => array('nom', 'n'), 'ей' => array('gen', 'f'));
                list($oc, $og) = isset($map[$sfx]) ? $map[$sfx] : array('nom', 'm');
                if ($sfx === 'м') $oc = in_array($prev, self::$PREP_CASE['prep'], true) || in_array($prev, array('в', 'на'), true) ? 'prep' : 'ins';
                if ($sfx === 'й' && self::test($next, self::FEM_NOUN)) { $og = 'f'; $oc = self::caseFromPrep($prev); if ($oc === null) $oc = 'gen'; }
                $w = self::ordinal($n, $oc, $og);
            } elseif (!$hasFrac && !$unit && self::test($next, self::YEAR) && $n >= 1 && $n <= 3000) {
                $g = self::rep($next, '\.$', '', '');
                if ($g === 'году') $kase = in_array($prev, self::$PREP_CASE['dat'], true) ? 'dat' : 'prep';
                elseif ($g === 'года') $kase = 'gen';
                elseif ($g === 'годом') $kase = 'ins';
                elseif ($g === 'годе' || $g === 'годах') $kase = 'prep';
                elseif ($g === 'г') {
                    if (in_array($prev, array('в', 'во'), true) || in_array($prev, self::$PREP_CASE['prep'], true)) $kase = 'prep';
                    elseif (in_array($prev, self::$PREP_CASE['dat'], true)) $kase = 'dat';
                    else { $kase = self::caseFromPrep($prev); if ($kase === null) $kase = 'nom'; }
                } else $kase = ($prev === 'в' || $prev === 'на' || $prev === 'за') ? 'acc' : 'nom';
                $w = self::ordinal($n, $kase, 'm');
                if ($g === 'г') {
                    $fullMap = array('nom' => 'год', 'acc' => 'год', 'gen' => 'года', 'dat' => 'году', 'prep' => 'году', 'ins' => 'годом');
                    $full = $fullMap[$kase];
                    $parts[$words[$k + 1]] = self::rep($parts[$words[$k + 1]], '^г\.?', '', function () use ($full) { return $full; });
                }
            } elseif (!$hasFrac && !$unit && self::test($next, self::MONTHS) && $n >= 1 && $n <= 31) {
                $dc = $kase;
                if ($dc === 'nom' && in_array($prev, array('на', 'за', 'про'), true)) $dc = 'acc';
                if ($dc === 'nom' && $prev !== '' && !in_array($prev, array('сегодня', 'завтра', 'вчера', 'послезавтра', 'позавчера', 'это', 'наступило', 'наступит', 'дата', 'число'), true)) $dc = 'gen';
                $w = self::ordinal($n, $dc, 'n');
            } elseif ($frac === '5' && ($kase === 'nom' || $kase === 'acc') && ($unit || $unitKey !== null || self::halfNoun($next))) {
                if ($unit) $h = array('forms' => self::$UNITS[$unit0], 'f' => false);
                elseif ($unitKey !== null) $h = array('forms' => self::$UNITS[$unitKey], 'f' => in_array($unitKey, self::$FEM_UNITS, true));
                else $h = self::halfNoun($next);
                list($one, $few, $many) = $h['forms'];
                $kind = self::pluralKind($n);
                if ($n == 1) $w = $h['f'] ? 'полторы' : 'полтора';
                else $w = self::cardinal($n, 'nom', $h['f'] ? 'f' : 'm') . ' с половиной';
                $noun = $n == 1 ? $few : ($kind === 'one' ? $one : ($kind === 'few' ? $few : $many));
                if ($unit) $w .= ' ' . $noun;
                else $parts[$words[$k + 1]] = $unitKey !== null ? self::unitWord($parts[$words[$k + 1]], $noun, $tokAt($k + 2))
                    : self::rep($parts[$words[$k + 1]], '^[А-Яа-яЁё]+', '', function () use ($noun) { return $noun; });
                if ($unitKey !== null && in_array($unitKey, self::$SCALES_ABBR, true)) $currencyAfterScale($k + 2);
            } elseif ($hasFrac) {
                $w = self::decimalWords(self::rep($intRaw, '\D', 'g', ''), $frac, $kase);
                if ($unit) $w .= ' ' . self::nounAgree($unit0, $n, $kase, true);
                elseif ($unitKey !== null) $parts[$words[$k + 1]] = self::unitWord($parts[$words[$k + 1]], self::nounAgree($unitKey, $n, $kase, true), $tokAt($k + 2));
                if ($unitKey !== null && in_array($unitKey, self::$SCALES_ABBR, true)) $currencyAfterScale($k + 2);
            } else {
                $gender = 'm';
                if (self::test($next, self::FEM_NOUN)) $gender = 'f';
                elseif (self::test($next, self::NEU_NOUN)) $gender = 'n';
                $kk = $kase;
                $prevEff = $prev;
                if (self::test($prev, '^(ещё|еще|всего|лишь|только|почти|примерно|приблизительно|аж|целых)$') && $k > 1) {
                    $t2 = $parts[$words[$k - 2]];
                    $prevEff = self::test($t2, '[,.;:!?]$') ? '' : $bare($t2);
                }
                $accVerb = self::test($prevEff, '^(за|у|до|пере|вы|по|с|от|при|на)?(плат|плач|трат|трач|получ|отда|дать|даст|дадим|собра|собер|заработа|сэконом|накоп|копи|перевест|переве|верн|внес|внест|списа|спиш|снял|снима|сним|полож|стоит|стоят|стоил|сто́ит|отдаст|обойд|обход|потребует|требует|стоить|вложи|вклады|инвестир|сэкономи|сниз|повыс|подорожа|подешев)');
                if ((in_array($prevEff, array('в', 'во', 'на', 'за', 'через', 'про'), true) || $accVerb) && !self::test($next, '(ах|ях)$')
                    && (($unitKey !== null && (in_array($unitKey, self::$SCALES_ABBR, true) || self::test($unitKey, '^(мин|сек|ч|руб|₽|грн|₴|коп|долл|usd|\$|eur|€)$')))
                        || self::test($next, '^(минут|секунд|час|дн|день|сут|недел|месяц|лет|год|рубл|гривн|гривен|доллар|евро|копе|тысяч|миллион|миллиард|раз|штук|процент)'))) {
                    $kk = 'acc';
                    $kase = 'acc';
                }
                if ($kk === 'nom' && $gender === 'f' && self::test($next, 'у$') && fmod($n, 10) == 1 && fmod($n, 100) != 11) $kk = 'acc';
                if ($kk === 'nom' && in_array($prev, array('в', 'на', 'через', 'за'), true) && self::test($next, '(ах|ях)$')) $kk = 'prep';
                if ($unitKey !== null && !$unit && in_array($unitKey, self::$FEM_UNITS, true)) $gender = 'f';
                $w = self::cardinal($n, $kk, $gender);
                if ($unit) $w .= ' ' . self::nounAgree($unit0, $n, $kase, false);
                elseif ($unitKey !== null) $parts[$words[$k + 1]] = self::unitWord($parts[$words[$k + 1]], self::nounAgree($unitKey, $n, $kase, false), $tokAt($k + 2));
                if ($unitKey !== null && in_array($unitKey, self::$SCALES_ABBR, true)) $currencyAfterScale($k + 2);
            }
            $parts[$i] = $pre . $minus . $w . $post;
        }
        $out = implode('', $parts);
        foreach (self::$STANDALONE as $x) $out = self::rep($out, $x[0], 'g', $x[1]);
        return $out;
    }

    /** Для замыканий (PHP 5.4 не пускает их к private static). */
    public static function num($s) { return self::jsNumber($s); }
    public static function unitForms($k) { self::units_(); return self::unit($k); }
    public static function unitWordP($tok, $word, $next) { return self::unitWord($tok, $word, $next); }

    private static function halfNoun($next)
    {
        foreach (self::$HALF_NOUNS as $h) if (in_array($next, $h['forms'], true)) return $h;
        return null;
    }

    // ---- римские числа ----
    public static function romanValue($r)
    {
        if (!self::test($r, '^M{0,3}(CM|CD|D?C{0,3})(XC|XL|L?X{0,3})(IX|IV|V?I{0,3})$') || $r === '') return 0;
        $V = array('I' => 1, 'V' => 5, 'X' => 10, 'L' => 50, 'C' => 100, 'D' => 500, 'M' => 1000);
        $n = 0;
        $len = strlen($r);
        for ($i = 0; $i < $len; $i++) {
            $a = $V[$r[$i]];
            $b = $i + 1 < $len ? $V[$r[$i + 1]] : 0;
            $n += $a < $b ? -$a : $a;
        }
        return $n;
    }

    public static function romanCase($noun, $prev)
    {
        $w = self::rep(self::lc($noun), '\.$', '', '');
        if ($w === 'в' || $w === 'вв') {
            $plural = $w === 'вв';
            if (in_array($prev, array('в', 'во', 'на'), true)) $k = 'prep';
            else { $k = self::caseFromPrep($prev); if ($k === null) $k = $prev !== '' ? 'gen' : 'nom'; }
            $forms = $plural ? array('nom' => 'века', 'gen' => 'веков', 'dat' => 'векам', 'ins' => 'веками', 'prep' => 'веках', 'acc' => 'века')
                : array('nom' => 'век', 'gen' => 'века', 'dat' => 'веку', 'ins' => 'веком', 'prep' => 'веке', 'acc' => 'век');
            return array('g' => 'm', 'k' => $k, 'word' => $forms[$k]);
        }
        $NOUNS = array(
            array('^(век|съезд|том|раздел|созыв|квартал|чемпионат|фестиваль|форум|турнир|конгресс|собор|легион|полк|корпус|интернационал)(|а|у|ом|е|ов|ам|ами|ах|я|ю|ем)$', 'm'),
            array('^(глав|част|степен|категори|олимпиад|конференци|династи|международн|республик|симфони|книг|серии|сери)(а|ы|е|у|ой|и|ь|ью|я|ю|ей)$', 'f'),
            array('^(тысячелети|столети|издани|чтени)(е|я|ю|ем|и)$', 'n'),
        );
        $M_END = array('' => 'nom', 'а' => 'gen', 'я' => 'gen', 'у' => 'dat', 'ю' => 'dat', 'ом' => 'ins', 'ем' => 'ins', 'е' => 'prep',
            'ов' => 'gen', 'ам' => 'dat', 'ами' => 'ins', 'ах' => 'prep');
        $F_END = array('а' => 'nom', 'я' => 'nom', 'ь' => 'nom', 'ы' => 'gen', 'и' => 'gen', 'е' => 'prep', 'у' => 'acc', 'ю' => 'acc', 'ой' => 'ins', 'ью' => 'ins', 'ей' => 'ins');
        $N_END = array('е' => 'nom', 'я' => 'gen', 'ю' => 'dat', 'ем' => 'ins', 'и' => 'prep');
        foreach ($NOUNS as $x) {
            $m = self::match($w, $x[0]);
            if (!$m) continue;
            $g = $x[1];
            $endMap = $g === 'm' ? $M_END : ($g === 'f' ? $F_END : $N_END);
            $e = (string)$m[2];
            $k = isset($endMap[$e]) ? $endMap[$e] : 'nom';
            if ($g === 'f' && $k === 'prep' && !in_array($prev, array('в', 'во', 'на', 'о', 'об', 'при'), true)) $k = 'dat';
            if ($k === 'nom' && in_array($prev, array('в', 'на', 'за', 'про', 'через'), true) && $g !== 'm') $k = 'acc';
            return array('g' => $g, 'k' => $k, 'word' => null);
        }
        return null;
    }

    public static function normalizeRoman($text)
    {
        if (!self::test($text, '[IVXLCDM]')) return $text;
        $R = '[IVXLCDM]+';
        $text = self::rep($text, "(^|[^A-Za-z])($R)(?:\\s*([-–—])\\s*($R))?\\s+(вв?\\.|[А-Яа-яЁё]+)(?![А-Яа-яЁё])", 'g',
            function ($g, $off, $s) {
                list($m, $pre, $a, $dash, $b, $noun) = $g;
                $na = SanottsRuText::romanValue($a);
                $nb = ($b !== null && $b !== '') ? SanottsRuText::romanValue($b) : 0;
                if (!$na || (($b !== null && $b !== '') && !$nb)) return $m;
                $before = substr($s, 0, $off) . $pre;
                if (SanottsRuText::test($before, '[,.;:!?—–(]\s*$')) $prev = '';
                else { $pm = SanottsRuText::match($before, '([А-Яа-яЁё]+)\s*$'); $prev = SanottsRuText::lc($pm ? $pm[1] : ''); }
                $rc = SanottsRuText::romanCase($noun, $prev);
                if (!$rc) return $m;
                $k = $rc['k'] === 'acc' && $rc['g'] === 'm' ? 'nom' : $rc['k'];
                $w1 = SanottsRuText::ordinal($na, $k, $rc['g']);
                $w2 = $nb ? SanottsRuText::ordinal($nb, $k, $rc['g']) : '';
                $dot = $rc['word'] && SanottsRuText::test(substr($s, $off + strlen($m)), '^(\s*$|\s+[А-ЯЁA-Z])') ? '.' : '';
                return $pre . $w1 . ($nb ? '–' . $w2 : '') . ' ' . ($rc['word'] ? $rc['word'] : $noun) . $dot;
            });
        $text = self::rep($text, "(^|[^А-Яа-яЁё])([А-Яа-яЁё]+)\\s+($R)(?![A-Za-zА-Яа-яЁё])", 'g', function ($g, $off, $s) {
            list($m, $pre, $noun, $r) = $g;
            $n = SanottsRuText::romanValue($r);
            if (!$n) return $m;
            $pm = SanottsRuText::match(substr($s, 0, $off), '([А-Яа-яЁё]+)\s*$');
            $prev = SanottsRuText::lc($pm ? $pm[1] : '');
            $rc = SanottsRuText::romanCase($noun, $prev);
            if (!$rc || $rc['word']) return $m;
            $k = $rc['k'] === 'acc' && $rc['g'] === 'm' ? 'nom' : $rc['k'];
            return $pre . $noun . ' ' . SanottsRuText::ordinal($n, $k, $rc['g']);
        });
        $text = self::rep($text, "([А-ЯЁ][а-яё]+)\\s+($R)(?![A-Za-zА-Яа-яЁё])", 'g', function ($g) {
            list($m, $name, $r) = $g;
            $n = SanottsRuText::romanValue($r);
            if (!$n || $n > 30) return $m;
            $low = SanottsRuText::lc($name);
            $f = SanottsRuText::test($low, '^(екатерин|елизавет|анн|мари|виктори|изабелл|маргарит|жанн|елен|ольг|софь|софи|александр[аыеуой]$)', 'i');
            if ($f) $k = SanottsRuText::test($low, '[ыи]$') ? 'gen' : (SanottsRuText::test($low, 'е$') ? 'dat' : (SanottsRuText::test($low, '[ую]$') ? 'acc' : (SanottsRuText::test($low, '(ой|ей)$') ? 'ins' : 'nom')));
            else $k = SanottsRuText::test($low, '[ая]$') ? 'gen' : (SanottsRuText::test($low, '[ую]$') ? 'dat' : (SanottsRuText::test($low, '(ом|ем|ём)$') ? 'ins' : (SanottsRuText::test($low, 'е$') ? 'prep' : 'nom')));
            $o = SanottsRuText::ordinal($n, $k, $f ? 'f' : 'm');
            return $name . ' ' . SanottsRuText::ucfirst($o);
        });
        return $text;
    }

    public static function normalizeDims($text)
    {
        if (!self::test($text, '\d\s*[×xхХ*]\s*\d') && !self::test($text, '\d\s*=\s*\d')) return $text;
        $N = '\d+(?:[.,]\d+)?';
        $text = self::rep($text, "(?<![\\w.])($N)\\s*[×xхХ*]\\s*($N)\\s*=\\s*($N)", 'g', '$1 умножить на $2 равно $3');
        $text = self::rep($text, "(?<![\\w.])($N)\\s*\\+\\s*($N)\\s*=\\s*($N)", 'g', '$1 плюс $2 равно $3');
        $text = self::rep($text, "(?<![\\w.])($N)\\s*[-−]\\s*($N)\\s*=\\s*($N)", 'g', '$1 минус $2 равно $3');
        $text = self::rep($text, "(?<![\\w.])($N)\\s*[:/÷]\\s*($N)\\s*=\\s*($N)", 'g', '$1 разделить на $2 равно $3');
        do {
            $prev = $text;
            $text = self::rep($text, "(?<![\\w.])(?!0x)($N)\\s*[×xхХ*]\\s*(?=\\d)", 'g', '$1 на ');
        } while ($text !== $prev);
        return $text;
    }

    private static $CUR_SYM = array('$' => 'долл', 'US$' => 'долл', '€' => 'евр', '£' => 'фнт', '¥' => 'иен', '₽' => 'руб', '₴' => 'грн', '₸' => 'тнг',
        '₿' => 'бтк', '₹' => 'руп', '₺' => 'лир', '₩' => 'вон', 'zł' => 'злт');
    private static $CUR_CODE = array('USD' => 'долл', 'EUR' => 'евр', 'GBP' => 'фнт', 'JPY' => 'иен', 'CNY' => 'юан', 'RMB' => 'юан', 'RUB' => 'руб', 'UAH' => 'грн',
        'KZT' => 'тнг', 'BYN' => 'брб', 'CHF' => 'фрн', 'PLN' => 'злт', 'TRY' => 'лир', 'INR' => 'руп', 'KRW' => 'вон', 'BTC' => 'бтк', 'ETH' => 'эфр');
    private static $CENTS = array('долл' => 'цнт', 'евр' => 'цнт', 'фнт' => 'пнс', 'руб' => 'коп', 'грн' => 'коп');

    public static function curKey($c)
    {
        if (isset(self::$CUR_SYM[$c])) return self::$CUR_SYM[$c];
        return isset(self::$CUR_CODE[$c]) ? self::$CUR_CODE[$c] : null;
    }

    public static function cents($key)
    {
        return isset(self::$CENTS[$key]) ? self::$CENTS[$key] : null;
    }

    public static function normalizeCurrency($text)
    {
        $syms = array();
        foreach (self::sortByLenDesc(array_keys(self::$CUR_SYM)) as $k) $syms[] = str_replace('$', '\$', $k);
        $sym = implode('|', $syms);
        $code = implode('|', array_keys(self::$CUR_CODE));
        $NUM = '\d[\d\u00a0\u202f ]*?\d|\d';
        $SCALE = '(?:\s*(тыс|млн|млрд|трлн)\.?)?';
        $text = self::rep($text, "(^|[^\\wА-Яа-яЁё])($sym|(?:$code)(?=\\s))\\s?($NUM)(?:([.,])(\\d+))?$SCALE(?![\\d])", 'g',
            function ($g, $off, $s) {
                list($m, $pre, $c, $int, $sep, $frac, $scale) = $g;
                $key = SanottsRuText::curKey($c);
                $rest = substr($s, $off + strlen($m));
                $end = substr($m, -1) === '.' && SanottsRuText::test($rest, '^(\s*$|\s+[А-ЯЁA-Z])') ? '.' : '';
                $hasFrac = $frac !== null && $frac !== '';
                $hasScale = $scale !== null && $scale !== '';
                if ($hasFrac && !$hasScale && $sep === '.' && strlen($frac) === 2 && SanottsRuText::cents($key))
                    return "$pre$int $key " . (int)$frac . ' ' . SanottsRuText::cents($key) . $end;
                $num = $int . ($hasFrac ? ',' . $frac : '');
                return $pre . $num . ($hasScale ? ' ' . $scale : '') . ' ' . $key . $end;
            });
        $text = self::rep($text, "(\\d|тыс\\.?|млн\\.?|млрд\\.?|трлн\\.?)\\s?($sym|(?:$code)(?![A-Za-z]))", 'g', function ($g) {
            return $g[1] . ' ' . SanottsRuText::curKey($g[2]);
        });
        return $text;
    }

    // ---------------------------------------------------------------------
    // Латиница, ссылки, сокращения
    // ---------------------------------------------------------------------
    private static $TERMS = array(
        'home assistant' => 'хоум ассистант', 'homeassistant' => 'хоум ассистант', 'home' => 'хоум', 'assistant' => 'ассистант',
        'wi-fi' => 'вай-фай', 'wifi' => 'вай-фай', 'bluetooth' => 'блютус', 'zigbee' => 'зигби', 'z-wave' => 'зет-вейв',
        'matter' => 'мэттер', 'thread' => 'тред', 'mqtt' => 'эм кью ти ти', 'esphome' => 'и эс пи хоум', 'esp32' => 'и эс пи тридцать два',
        'linux' => 'линукс', 'windows' => 'виндоус', 'android' => 'андроид', 'ios' => 'ай о эс', 'macos' => 'мак о эс',
        'ubuntu' => 'убунту', 'debian' => 'дебиан', 'docker' => 'докер', 'raspberry' => 'распберри', 'pi' => 'пай',
        'synology' => 'синолоджи', 'termux' => 'термукс', 'majordomo' => 'мажордомо', 'python' => 'пайтон',
        'javascript' => 'джаваскрипт', 'node' => 'ноуд', 'github' => 'гитхаб', 'google' => 'гугл', 'yandex' => 'яндекс',
        'alice' => 'алиса', 'alexa' => 'алекса', 'telegram' => 'телеграм', 'whatsapp' => 'вотсап', 'youtube' => 'ютуб',
        'ethernet' => '+эзэрнэт', 'router' => 'роутер', 'server' => 'сервер', 'online' => 'онлайн', 'offline' => 'офлайн',
        'update' => 'апдейт', 'login' => 'логин', 'password' => 'пароль', 'admin' => 'админ', 'user' => 'юзер',
        'config' => 'конфиг', 'yaml' => 'ямл', 'json' => 'джейсон', 'html' => 'эйч ти эм эл', 'css' => 'си эс эс',
        'example' => 'экзампл', 'com' => 'ком', 'org' => 'орг', 'net' => 'нет', 'ru' => 'ру', 'local' => 'локал', 'www' => 'дабл-ю дабл-ю дабл-ю',
        'ok' => 'ок+эй', 'okay' => 'ок+эй', 'tts' => 'ти ти эс', 'stt' => 'эс ти ти', 'sano' => 'сано', 'sanotts' => 'сано ти-ти-эс',
        'smart' => 'смарт', 'hub' => 'хаб', 'cloud' => 'клауд', 'api' => 'эй пи ай', 'app' => 'апп', 'web' => 'веб',
        'on' => 'он', 'off' => 'офф', 'the' => 'зе', 'and' => 'энд', 'of' => 'оф', 'to' => 'ту', 'in' => 'ин',
        'light' => 'лайт', 'switch' => 'свитч', 'sensor' => 'сенсор', 'automation' => 'автомейшн', 'script' => 'скрипт',
        'xiaomi' => 'сяом+и', 'aqara' => 'акара', 'philips' => 'филипс', 'hue' => 'хью', 'yeelight' => 'йилайт', 'tuya' => 'туя',
        'sonoff' => 'сонофф', 'tasmota' => 'тасмота', 'shelly' => 'шелли', 'ikea' => 'икея', 'tradfri' => 'традфри',
        'http' => 'эйч ти ти пи', 'https' => 'эйч ти ти пи эс', 'url' => 'ю ар эл', 'ip' => 'ай пи', 'dns' => 'дэ эн эс',
        'usb' => 'ю эс би', 'hdmi' => 'эйч ди эм ай', 'ssh' => 'эс эс эйч', 'vpn' => 'ви пи эн', 'nas' => 'нас',
        'fahrenheit' => 'фаренгейт', 'celsius' => 'цельсий', 'kelvin' => 'кельвин', 'starlink' => 'старлинк',
        'usd' => 'долларов', 'eur' => 'евро',
        'openai' => 'оп+эн а+и', 'chatgpt' => 'чат джи пи т+и', 'gpt' => 'джи пи т+и', 'excel' => 'экс+эль', 'word' => 'ворд',
        'hugging face' => 'х+агин ф+эйс', 'huggingface' => 'х+агин ф+эйс', 'hugging' => 'х+агин', 'face' => 'ф+эйс',
        'loot' => 'лут', 'resolve' => 'рес+олв', 'claude' => 'клод', 'gemini' => 'дж+емини', 'llama' => 'л+ама',
        'dashboard' => 'дашборд', 'lovelace' => 'лавлейс', 'node-red' => 'ноуд-ред', 'grafana' => 'графана',
    );
    private static $LETTER = array('a' => 'эй', 'b' => 'би', 'c' => 'си', 'd' => 'ди', 'e' => 'и', 'f' => 'эф', 'g' => 'джи', 'h' => 'эйч', 'i' => 'ай', 'j' => 'джей',
        'k' => 'кей', 'l' => 'эл', 'm' => 'эм', 'n' => 'эн', 'o' => 'о', 'p' => 'пи', 'q' => 'кью', 'r' => 'ар', 's' => 'эс', 't' => 'ти', 'u' => 'ю', 'v' => 'ви',
        'w' => 'дабл-ю', 'x' => 'экс', 'y' => 'уай', 'z' => 'зед');
    private static $RULES = array(
        array('tion', 'шн'), array('sion', 'жн'), array('ough', 'оу'), array('igh', 'ай'), array('tch', 'ч'), array('sch', 'ск'),
        array('ch', 'ч'), array('sh', 'ш'), array('th', 'з'), array('ph', 'ф'), array('wh', 'у'), array('ck', 'к'), array('qu', 'кв'), array('ng', 'нг'),
        array('oo', 'у'), array('ee', 'и'), array('ea', 'и'), array('ai', 'эй'), array('ay', 'эй'), array('ey', 'эй'), array('oy', 'ой'), array('oi', 'ой'),
        array('ou', 'ау'), array('ow', 'оу'), array('au', 'о'), array('aw', 'о'), array('ew', 'ью'), array('ie', 'и'), array('ue', 'ю'),
        array('x', 'кс'), array('j', 'дж'), array('w', 'у'), array('y', 'и'), array('q', 'к'),
        array('a', 'а'), array('b', 'б'), array('c', 'к'), array('d', 'д'), array('e', 'е'), array('f', 'ф'), array('g', 'г'), array('h', 'х'), array('i', 'и'),
        array('k', 'к'), array('l', 'л'), array('m', 'м'), array('n', 'н'), array('o', 'о'), array('p', 'п'), array('r', 'р'), array('s', 'с'), array('t', 'т'),
        array('u', 'у'), array('v', 'в'), array('z', 'з'),
    );

    private static function term($lw)
    {
        return isset(self::$TERMS[$lw]) ? self::$TERMS[$lw] : null;
    }

    public static function transliterate($word)
    {
        $w = strtolower($word);
        if (strlen($w) > 3 && substr($w, -1) === 'e' && !preg_match('/[aeiou]e$/D', $w)) $w = substr($w, 0, -1);
        $out = '';
        $len = strlen($w);
        for ($i = 0; $i < $len;) {
            $nx = $i + 1 < $len ? $w[$i + 1] : '';
            if ($w[$i] === 'c' && $nx !== '' && strpos('eiy', $nx) !== false) { $out .= 'с'; $i++; continue; }
            if ($w[$i] === 'y' && $i === 0) { $out .= 'й'; $i++; continue; }
            if ($w[$i] === 'e' && $i === 0) { $out .= 'э'; $i++; continue; }
            $hit = null;
            foreach (self::$RULES as $r) if (substr_compare($w, $r[0], $i, strlen($r[0])) === 0) { $hit = $r; break; }
            if ($hit) { $out .= $hit[1]; $i += strlen($hit[0]); } else { $out .= $w[$i]; $i++; }
        }
        return $out;
    }

    public static function sayWord($w)
    {
        $lw = strtolower($w);
        if (($t = self::term($lw)) !== null) return $t;
        if (preg_match('/^[A-Z]{2,6}$/D', $w) || preg_match('/^[A-Z]{2,6}s$/D', $w)) {
            if (substr($w, -1) === 's' && preg_match('/[A-Z]{2,}s$/D', $w)) $w = substr($w, 0, -1);
            $out = array();
            $len = strlen($w);
            for ($i = 0; $i < $len; $i++) {
                $c = strtolower($w[$i]);
                $out[] = isset(self::$LETTER[$c]) ? self::$LETTER[$c] : 'undefined';
            }
            return implode(' ', $out);
        }
        if (preg_match('/^[a-z]$/Di', $w)) return self::$LETTER[$lw];
        return self::transliterate($w);
    }

    public static function sayPart($p)
    {
        if (($t = self::term(strtolower($p))) !== null) return $t;
        if (strpos($p, '-') !== false) {
            $out = array();
            foreach (explode('-', $p) as $x) $out[] = preg_match('/^[0-9]+$/D', $x) ? $x : self::sayWord($x);
            return implode('-', $out);
        }
        if (preg_match('/^([A-Za-z]+)([0-9]+)$/D', $p, $m)) return self::sayWord($m[1]) . ' ' . $m[2];
        return self::sayWord($p);
    }

    private static $REF_FORMS = array(
        'пункт' => 'пункт пункта пункту пункт пунктом пункте',
        'подпункт' => 'подпункт подпункта подпункту подпункт подпунктом подпункте',
        'статья' => 'статья статьи статье статью статьёй статье',
        'глава' => 'глава главы главе главу главой главе',
        'раздел' => 'раздел раздела разделу раздел разделом разделе',
        'параграф' => 'параграф параграфа параграфу параграф параграфом параграфе',
        'страница' => 'страница страницы странице страницу страницей странице',
        'рисунок' => 'рисунок рисунка рисунку рисунок рисунком рисунке',
        'таблица' => 'таблица таблицы таблице таблицу таблицей таблице',
        'иллюстрация' => 'иллюстрация иллюстрации иллюстрации иллюстрацию иллюстрацией иллюстрации',
        'лист' => 'лист листа листу лист листом листе',
        'часть' => 'часть части части часть частью части',
        'том' => 'том тома тому том томом томе',
        'пример' => 'пример примера примеру пример примером примере',
        'формула' => 'формула формулы формуле формулу формулой формуле',
        'приложение' => 'приложение приложения приложению приложение приложением приложении',
    );
    private static $REF_ABBR = array(
        'п' => 'пункт', 'пп' => 'подпункт', 'ст' => 'статья', 'гл' => 'глава', 'разд' => 'раздел', 'стр' => 'страница',
        'рис' => 'рисунок', 'табл' => 'таблица', 'таб' => 'таблица', 'ил' => 'иллюстрация', 'илл' => 'иллюстрация',
        'л' => 'лист', 'ч' => 'часть', 'т' => 'том', 'прим' => 'пример', 'ф-ла' => 'формула', 'прил' => 'приложение',
    );
    private static $CASE_IDX = array('nom' => 0, 'gen' => 1, 'dat' => 2, 'acc' => 3, 'ins' => 4, 'prep' => 5);
    private static $REF_PREP = array(
        'prep' => array('в', 'во', 'на', 'о', 'об', 'при'),
        'dat' => array('по', 'к', 'ко', 'согласно', 'благодаря', 'вопреки', 'соответственно'),
        'gen' => array('из', 'до', 'для', 'от', 'с', 'со', 'после', 'без', 'у', 'кроме', 'около', 'вследствие', 'ввиду', 'из-за', 'помимо'),
        'acc' => array('см', 'смотри', 'смотрите', 'про', 'через', 'открой', 'откройте', 'отметим'),
        'ins' => array('над', 'под', 'перед', 'между', 'соответствии'),
    );

    public static function refCase($before)
    {
        $prevRef = self::match($before, '([а-яё]+)\.?\s*\d[\d .]*?(,|\s+и|\s+или)?\s*$', 'i');
        if ($prevRef) {
            $w0 = self::lc($prevRef[1]);
            $c = null;
            if (isset(self::$REF_ABBR[$w0])) $c = self::refCase(substr($before, 0, $prevRef['index']));
            else {
                $caseKeys = array_keys(self::$CASE_IDX);
                foreach (self::$REF_FORMS as $forms) {
                    $i = array_search($w0, explode(' ', $forms), true);
                    if ($i !== false) { $c = $caseKeys[$i]; break; }
                }
            }
            if ($c) return ($prevRef[2] !== null && $prevRef[2] !== '') ? $c : 'gen';
        }
        $w = self::match(self::lc($before), '([а-яё-]+)\.?\s*$');
        if (!$w) return 'nom';
        if ($w[1] === 'с' && self::test($before, 'соответствии\s+с\s*$', 'i')) return 'ins';
        foreach (self::$REF_PREP as $c => $list) if (in_array($w[1], $list, true)) return $c;
        return 'nom';
    }

    private static function spaced($d)
    {
        return implode(' ', explode('.', $d));
    }

    public static function refWord($lemma, $before)
    {
        $f = explode(' ', self::$REF_FORMS[$lemma]);
        return $f[self::$CASE_IDX[self::refCase($before)]];
    }

    private static $ABBREV = array(
        array('в\s?т\.\s?ч\.', 'gi', 'в том числе'), array('т\.\s?е\.', 'g', 'то есть'), array('т\.\s?к\.', 'g', 'так как'),
        array('т\.\s?д\.', 'g', 'так далее'), array('т\.\s?п\.', 'g', 'тому подобное'), array('т\.\s?н\.', 'g', 'так называемый'),
        array('т\.\s?о\.', 'g', 'таким образом'), array('до\s+н\.\s?э\.', 'gi', 'до нашей эры'), array('н\.\s?э\.', 'g', 'нашей эры'),
        array('и\s+др\.', 'g', 'и другие'), array('и\s+пр\.', 'g', 'и прочее'), array('напр\.', 'gi', 'например'), array('мн\.\s?др\.', 'g', 'многое другое'),
        array('с\.\s?г\.', 'g', 'сего года'), array('г\.\s?г\.', 'g', 'годы'), array('ок\.(?=\s*\d)', 'g', 'около'), array('прим\.\s?ред\.', 'gi', 'примечание редакции'),
    );

    public static function expandAbbrev($text)
    {
        foreach (self::$ABBREV as $a) {
            list($src, $flags, $to) = $a;
            if (strpos($flags, 'i') === false) $flags .= 'i';
            $text = self::rep($text, '(^|[^А-Яа-яЁё.])' . $src, $flags, function ($g, $off, $s) use ($to) {
                $m = $g[0];
                $pre = $g[1];
                $rest = substr($s, $off + strlen($m));
                $end = SanottsRuText::test($rest, '^(\s*$|\s+[А-ЯЁA-Z])') ? '.' : '';
                $word = SanottsRuText::test(substr($m, strlen($pre)), '^[А-ЯЁ]') ? SanottsRuText::ucfirst($to) : $to;
                return $pre . $word . $end;
            });
        }
        return $text;
    }

    public static function dropLinks($text)
    {
        $URL = '(?:https?:\/\/|www\.)[^\s()«»"\/]+\/[^\s()«»"]+|\b[\w-]+(?:\.[\w-]+)*\.[a-z]{2,}\/[^\s()«»"]+';
        $text = self::rep($text, '\s*\(\s*(?:' . $URL . ')\s*\)', 'gi', '');
        $text = self::rep($text, $URL, 'gi', function ($g, $off, $s) {
            $m = $g[0];
            $tail = SanottsRuText::test($m, '[.,;:!?]$') ? substr($m, -1) : '';
            if (SanottsRuText::test(substr($s, 0, $off), '(ссылк[аеуой]|адрес[уе]?|сайте?|странице|канале?)[\s:]*$', 'i')) return "\x01" . $tail;
            return 'ссылка' . $tail;
        });
        return self::rep($text, '[\s:]*\u0001', 'g', '');
    }

    public static function normalizeRefs($text)
    {
        $text = self::dropLinks($text);
        $text = self::expandAbbrev($text);
        $text = self::rep($text, '(?<![\d.])(0?[1-9]|[12]\d|3[01])\.(0[1-9]|1[0-2])\.((?:19|20)\d\d)(?![\d.]\d)(\s*г\.?(?![а-яё]))?', 'gi', function ($g) {
            $MON = array('января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря');
            return (int)$g[1] . ' ' . $MON[(int)$g[2] - 1] . ' ' . $g[3] . ' года';
        });
        $text = self::rep($text, '(^|[\s(])[Сс]м\.(?=\s*(?:[А-Яа-яЁё]+\.?\s*)?[\d§])', 'g', '$1смотри');
        $keys = array();
        foreach (self::sortByLenDesc(array_keys(self::$REF_ABBR)) as $k) $keys[] = str_replace('-', '\\-', $k);
        $abbr = implode('|', $keys);
        $text = self::rep($text, '(^|[^А-Яа-яЁё.\d-])(' . $abbr . ')\.\s?(\d+(?:\.\d+)*)(?!\d)', 'gi', function ($g, $off, $s) {
            list($m, $pre, $a, $num) = $g;
            $before = substr($s, 0, $off) . $pre;
            if (SanottsRuText::test($a, '^[лтч]$', 'i') && SanottsRuText::test($before, '\d\s*$')) return $m;
            return $pre . SanottsRuText::refWord(SanottsRuText::refAbbr(SanottsRuText::lc($a)), $before) . ' ' . implode(' ', explode('.', $num));
        });
        $text = self::rep($text, '§\s?(\d+(?:\.\d+)*)(?!\d)', 'g', function ($g, $off, $s) {
            return SanottsRuText::refWord('параграф', substr($s, 0, $off)) . ' ' . implode(' ', explode('.', $g[1]));
        });
        $text = self::rep($text, '([А-Яа-яЁё]+)\s+(\d+(?:\.\d+)+)(?!\d)', 'g', function ($g) {
            $stem = SanottsRuText::lc($g[1]);
            return SanottsRuText::refLemma($stem) !== null ? $g[1] . ' ' . implode(' ', explode('.', $g[2])) : $g[0];
        });
        $text = self::rep($text, '(лист[а-я]*\s+\d+)\s*об\.(?![а-яё])', 'gi', function ($g, $off, $str) {
            return $g[1] . ' оборот' . (SanottsRuText::test(substr($str, $off + strlen($g[0])), '^(\s*$|\s+[А-ЯЁ])') ? '.' : '');
        });
        $text = self::rep($text, '(\d+)\s*об\.?\s*\/\s*мин(?![а-яё])', 'gi', function ($g) {
            $v = SanottsRuText::num($g[1]);
            $f = fmod($v, 10) == 1 && fmod($v, 100) != 11 ? 'оборот'
                : (in_array(fmod($v, 10), array(2.0, 3.0, 4.0)) && !in_array(fmod($v, 100), array(12.0, 13.0, 14.0)) ? 'оборота' : 'оборотов');
            return $g[1] . " $f в минуту";
        });
        $text = self::rep($text, '\bv(\d+(?:\.\d+)+)\b', 'gi', function ($g) {
            return 'версия ' . implode(' ', explode('.', $g[1]));
        });
        $text = self::rep($text, '([A-Za-z][A-Za-z0-9+-]*|[Вв]ерси[яиюей]{1,2})\s+(\d+\.\d+)(?!\d|\.\d)', 'g', function ($g) {
            return $g[1] . ' ' . implode(' ', explode('.', $g[2]));
        });
        return $text;
    }

    public static function refAbbr($a) { return self::$REF_ABBR[$a]; }

    public static function refLemma($stem)
    {
        foreach (self::$REF_FORMS as $l => $forms) if (in_array($stem, explode(' ', $forms), true)) return $l;
        return null;
    }

    public static function normalizeLatin($text)
    {
        $text = self::normalizeRefs($text);
        // Порт — только после адреса: IP, домена или имени хоста (localhost:8123,
        // nas:5000). Время рядом с латиницей («Wi-Fi в 7:30») портом не считается.
        $text = self::rep($text, '\b((?:\d{1,3}\.){3}\d{1,3}|(?:[A-Za-z0-9-]+\.)+[A-Za-z][A-Za-z0-9-]*|[A-Za-z][A-Za-z0-9-]*):(\d{2,5})\b(?!:)', 'g', '$1 порт $2');
        $text = self::rep($text, '\b(\d+(?:\.\d+){2,})\b', 'g', function ($g) { return implode(' точка ', explode('.', $g[0])); });
        $text = self::rep($text, '(?<![\d.,])(\d+)\.(\d+)(?![\d.,]\d|\d)', 'g', '$1 точка $2');
        $text = self::rep($text, 'fahrenheit\s*\(?°\s?F\)?', 'gi', 'Фаренгейт');
        $text = self::rep($text, '(\d)\s?°\s?F\b', 'g', '$1° по Фаренгейту');
        $text = self::rep($text, '°\s?F\b', 'g', 'градусы Фаренгейта');
        $text = self::rep($text, '°\s?[CС]\b', 'g', '°');
        if (!self::test($text, '[A-Za-z]')) return $text;
        $text = self::rep($text, '\b(https?):\/\/', 'gi', function ($g) { return SanottsRuText::sayTerm(strtolower($g[1])) . ' '; });
        foreach (self::$TERMS as $k => $v) {
            if (strpos($k, ' ') === false) continue;
            $text = self::rep($text, str_replace(' ', '\s+', $k), 'gi', function () use ($v) { return $v; });
        }
        $text = self::rep($text, '\b[A-Za-z0-9][A-Za-z0-9_-]*(?:\.[A-Za-z0-9_-]+)+\b', 'g', function ($g) {
            $m = $g[0];
            if (preg_match('/^[0-9]+(\.[0-9]+)?$/D', $m)) return $m;
            $out = array();
            foreach (explode('.', $m) as $p) {
                if (preg_match('/^[0-9]+$/D', $p)) { $out[] = $p; continue; }
                $q = array();
                foreach (explode('_', $p) as $x) $q[] = SanottsRuText::sayPart($x);
                $out[] = implode(' ', $q);
            }
            return implode(' точка ', $out);
        });
        $text = self::rep($text, '[A-Za-z][A-Za-z0-9]*(?:-[A-Za-z0-9]+)*', 'g', function ($g) { return SanottsRuText::sayPart($g[0]); });
        return $text;
    }

    public static function sayTerm($k) { return self::term($k); }

    // ---------------------------------------------------------------------
    // Ё
    // ---------------------------------------------------------------------
    private static $yoMap = null;
    /** Путь к yo_safe.txt; индекс строится рядом ($yoIndexDir) один раз. */
    public static $yoSource = null;
    public static $yoIndexDir = null;
    private static $yoMem = null;
    private static $yoHead = null;
    private static $yoFh = null;

    private static function yoBuiltin()
    {
        if (self::$yoMap !== null) return;
        $ADJ = array('ый', 'ая', 'ое', 'ые', 'ого', 'ому', 'ым', 'ом', 'ой', 'ую', 'ых', 'ыми');
        $ADJ_K = array('ий', 'ая', 'ое', 'ие', 'ого', 'ому', 'им', 'ом', 'ой', 'ую', 'их', 'ими');
        $NOUN = array('', 'а', 'у', 'ом', 'е', 'ы', 'ов', 'ам', 'ами', 'ах');
        $FEM = array('а', 'и', 'е', 'у', 'ой', 'ам', 'ами', 'ах');
        $SETS = array(
            array(array('дешёв', 'чёрн', 'жёлт', 'зелён', 'тёмн', 'тяжёл', 'весёл', 'солён', 'учён', 'мёртв', 'твёрд',
                'пёстр', 'копчён', 'ядрён', 'смышлён', 'утончён', 'краснощёк'), $ADJ),
            array(array('лёгк', 'жёстк', 'чётк'), $ADJ_K),
            array(array('самолёт', 'вертолёт', 'полёт', 'отчёт', 'расчёт', 'учёт', 'зачёт', 'пулемёт', 'звездолёт', 'звездочёт',
                'подъём', 'объём', 'приём', 'заём', 'налёт', 'перелёт', 'взлёт', 'прилёт', 'вылёт'), $NOUN),
            array(array('ёлк', 'ёмкост'), $FEM),
        );
        $WORDS = array('ещё', 'её', 'неё', 'своё', 'моё', 'твоё', 'шёл', 'пошёл', 'нашёл', 'пришёл', 'ушёл', 'вошёл',
            'зашёл', 'подошёл', 'прошёл', 'перешёл', 'обошёл', 'дошёл', 'отошёл', 'сошёл', 'ребёнок', 'ребёнка', 'ребёнку',
            'ребёнком', 'ребёнке', 'лёд', 'пёс', 'ёж', 'ёжик', 'ёлка', 'счёт', 'счётом', 'счёте', 'дёшево',
            'идёт', 'придёт', 'пойдёт', 'найдёт', 'даёт', 'встаёт', 'живёт', 'поёт', 'несёт', 'ведёт', 'везёт', 'растёт',
            'ждёт', 'зовёт', 'пьёт', 'льёт', 'бьёт', 'поймёт', 'начнёт', 'возьмёт', 'идём', 'пойдём', 'найдём',
            'придётся', 'обойдётся', 'найдётся', 'начнётся', 'разберётся', 'возьмётся', 'берётся', 'даётся',
            'остаётся', 'продаётся', 'создаётся', 'ведётся', 'несётся', 'зовётся', 'льётся', 'бьётся', 'смеётся',
            'поётся', 'сдаётся', 'раздаётся', 'удаётся', 'признаётся', 'придётся', 'дождётся', 'пройдётся',
            'идёте', 'пойдёте', 'даёте', 'живёте', 'ждёте', 'всё-таки', 'причём', 'о чём', 'при чём', 'твёрдо', 'чётко', 'жёстко');
        $map = array();
        foreach ($SETS as $set) foreach ($set[0] as $s) foreach ($set[1] as $e) $map[str_replace('ё', 'е', $s . $e)] = $s . $e;
        foreach ($WORDS as $w) if (strpos($w, ' ') === false) $map[str_replace('ё', 'е', $w)] = $w;
        self::$yoMap = $map;
    }

    /** Разбор yo_safe.txt, как loadYoDict(): ключ (е вместо ё) -> форма. */
    public static function parseYoDict($text)
    {
        $dict = array();
        foreach (preg_split('/\r?\n/', $text) as $line) {
            $line = explode('#', $line);
            $line = self::trim($line[0]);
            if ($line === '') continue;
            if (strpos($line, '(') !== false) {
                $p = preg_split('/[(|)]/', $line);
                $forms = array();
                for ($i = 1; $i < count($p) - 1; $i++) $forms[] = $p[0] . $p[$i];
            } else {
                $forms = array($line);
            }
            foreach ($forms as $f) {
                $lowerOnly = substr($f, 0, 1) === '_';
                if ($lowerOnly) $f = substr($f, 1);
                $key = str_replace(array('ё', 'Ё'), array('е', 'Е'), $f);
                $dict[$key] = $f;
                if (!$lowerOnly && !preg_match('/^[А-ЯЁ]/u', $f)) $dict[self::ucfirst($key)] = self::ucfirst($f);
            }
        }
        return $dict;
    }

    /**
     * Индекс словаря на диске: отсортированные строки "ключ\tформа" и
     * каждая 64-я строка в заголовке — поиск без загрузки 100 тыс. форм.
     */
    private static function yoOpen()
    {
        if (self::$yoHead !== null || self::$yoMem !== null) return;
        self::$yoMem = array();
        if (!self::$yoSource || !is_file(self::$yoSource)) return;
        $dir = self::$yoIndexDir ? self::$yoIndexDir : dirname(self::$yoSource);
        $stamp = substr(md5_file(self::$yoSource), 0, 12);
        $data = $dir . '/yo_index_' . $stamp . '.txt';
        $head = $dir . '/yo_index_' . $stamp . '.head';
        if (!is_file($data) || !is_file($head)) {
            $dict = self::parseYoDict((string)file_get_contents(self::$yoSource));
            $ok = false;
            if (is_dir($dir) && is_writable($dir)) {
                $keys = array();
                foreach ($dict as $k => $v) $keys[] = (string)$k;
                sort($keys, SORT_STRING);
                $body = '';
                $hd = array();
                foreach ($keys as $i => $k) {
                    if ($i % 64 === 0) $hd[] = array($k, strlen($body));
                    $body .= $k . "\t" . $dict[$k] . "\n";
                }
                $tmp = $data . '.' . getmypid();
                $tmpH = $head . '.' . getmypid();
                if (@file_put_contents($tmp, $body) !== false && @file_put_contents($tmpH, serialize($hd)) !== false
                    && @rename($tmp, $data) && @rename($tmpH, $head)) {
                    $ok = true;
                    foreach (glob($dir . '/yo_index_*') as $old) {
                        if (strpos($old, $stamp) === false) @unlink($old);
                    }
                }
                @unlink($tmp);
                @unlink($tmpH);
            }
            if (!$ok) { self::$yoMem = $dict; return; }
        }
        $hd = @unserialize((string)file_get_contents($head));
        $fh = @fopen($data, 'rb');
        if (!is_array($hd) || !$fh) {
            self::$yoMem = self::parseYoDict((string)file_get_contents(self::$yoSource));
            return;
        }
        self::$yoMem = null;
        self::$yoHead = $hd;
        self::$yoFh = $fh;
    }

    private static function yoGet($w)
    {
        self::yoOpen();
        if (self::$yoMem !== null) return isset(self::$yoMem[$w]) ? self::$yoMem[$w] : null;
        $hd = self::$yoHead;
        $lo = 0;
        $hi = count($hd) - 1;
        if ($hi < 0 || strcmp($w, $hd[0][0]) < 0) return null;
        while ($lo < $hi) {
            $mid = ($lo + $hi + 1) >> 1;
            if (strcmp($hd[$mid][0], $w) <= 0) $lo = $mid; else $hi = $mid - 1;
        }
        fseek(self::$yoFh, $hd[$lo][1]);
        for ($i = 0; $i < 64; $i++) {
            $line = fgets(self::$yoFh);
            if ($line === false) break;
            $tab = strpos($line, "\t");
            $k = substr($line, 0, $tab);
            $c = strcmp($k, $w);
            if ($c === 0) return rtrim(substr($line, $tab + 1), "\n");
            if ($c > 0) break;
        }
        return null;
    }

    public static function restoreYo($text)
    {
        self::yoBuiltin();
        return self::rep($text, '[А-Яа-яЁё]+(?:-[А-Яа-яЁё]+)?', 'g', function ($g) {
            $w = $g[0];
            if (!preg_match('/[еЕ]/u', $w)) return $w;
            return SanottsRuText::yoWord($w);
        });
    }

    public static function yoWord($w)
    {
        $d = self::yoGet($w);
        if ($d !== null) return $d;
        $lw = self::lc($w);
        if (!isset(self::$yoMap[$lw])) return $w;
        $yo = self::$yoMap[$lw];
        $c = self::chars($w);
        $first = $c[0];
        return (self::uc($first) === $first && self::lc($first) !== $first) ? self::ucfirst($yo) : $yo;
    }

    // ---------------------------------------------------------------------
    // Ударения
    // ---------------------------------------------------------------------
    const VOWELS = 'аеёиоуыэюя';
    const NUMWORD = '^(тысяч[аиу]?|миллион\S*|миллиард\S*|сто|двести|триста|четыреста|\S+сот|\S+десят|сорок|девяносто|двадцать|тридцать|\S+надцать|десять|один|одна|два|две|три|четыре|пять|шесть|семь|восемь|девять)$';
    private static $LOC2 = null;
    private static $HOMO = null;
    private static $GEN_PREP = array('у', 'до', 'с', 'со', 'от', 'ото', 'около', 'возле', 'вдоль', 'из', 'изо', 'без',
        'для', 'против', 'близ', 'мимо', 'напротив', 'посреди', 'вокруг', 'недалеко');
    private static $LOC_PREP = array('в', 'во', 'на');
    private static $COST_PREV = array('сколько', 'много', 'мало', 'дорого', 'дёшево', 'дешево', 'недорого', 'недёшево',
        'недешево', 'ничего', 'не', 'того', 'столько', 'немного', 'немало', 'копейки', 'гроши');
    private static $PLURAL_PRON = array('эти', 'те', 'все', 'мои', 'твои', 'наши', 'ваши', 'свои', 'их', 'какие', 'такие',
        'многие', 'некоторые', 'другие', 'новые', 'чьи');
    public static $PREFIXES = array('супер', 'мега', 'гипер', 'ультра', 'сверх', 'экстра', 'квази', 'псевдо');

    public static function isVowel($ch)
    {
        return $ch !== '' && $ch !== null && strpos(self::VOWELS, $ch) !== false && preg_match('/^.$/u', $ch);
    }

    public static function nVowels($w)
    {
        $n = 0;
        foreach (self::chars($w) as $c) if (self::isVowel($c)) $n++;
        return $n;
    }

    public static function genPrep($w) { return in_array($w, self::$GEN_PREP, true); }

    public static function pluralCue($c, $verbBefore = true)
    {
        if ((self::test($c['prev'], '(ые|ие)$') && !self::test($c['prev'], '([аяе]ни|[аеиоуя]ти|ови|оби)е$')) || in_array($c['prev'], self::$PLURAL_PRON, true)) return true;
        if (self::test($c['next'], '^(звучат|звучали|слышны|были|стали|есть|идут|шли|говорят|говорили|стоят|стояли|растут|звучит)$')) return true;
        if (self::test($c['prev'], '(ны|ты)$') && self::jsLen($c['prev']) > 4) return true;
        if (self::test($c['next'], '(ют|ят|ут|ат|ли)$') && self::jsLen($c['next']) > 3 && !self::genPrep($c['prev'])) return true;
        if ($verbBefore && self::test($c['prev'], '(ю|у|ешь|ет|ем|ете|ют|ят|ишь|ит|им|ите|ли|л|ла|ть)$') && self::jsLen($c['prev']) > 3
            && !self::test($c['prev'], '(ого|его|ой|ей|ых|их|тели|ели)$')) return true;
        return false;
    }

    private static function homographs()
    {
        if (self::$HOMO !== null) return;
        $list = array(
            array('forms' => 'замок замка замку замком замке замки замков замкам замками замках', 'def' => 'last', 'alt' => 'first',
                'clause' => '(средневеков|старинн|древн|рыцар|корол|княз|графск|крепост|песочн|воздушн|башн|дворц|экскурс|холм|скал|призрак|сказочн|феодал|замков)'),
            array('forms' => 'мука муки муке муку мукой', 'def' => 'last', 'alt' => 'first',
                'clause' => '(душевн|адск|страдан|мучен|творческ|сплошн|вечн|нечеловеч|невыносим|терп|какая мука|одна мука)'),
            array('forms' => 'атлас атласа атласе атласом атласу атласы атласов', 'def' => 'first', 'alt' => 2,
                'clause' => '(ткан|шёлк|шелк|плать|лент|блестящ|бархат|сатин|сшит|шить|юбк|блузк|подкладк)'),
            array('forms' => 'хлопок хлопка хлопку хлопком хлопке хлопки хлопков', 'def' => 'first', 'alt' => 'last',
                'clause' => '(громк|раздал|услыш|резк|выстрел|ладош|взрыв|петард|дверь|дверью|оглуш|звук|щелч)'),
            array('forms' => 'парить парю паришь парит парим парите парят паря парил парила парило парили', 'def' => 2, 'alt' => 'first',
                'clause' => '(бан[яеиюь]|бане|парилк|веник|ноги|овощ|рыб|пароварк|котлет|утюг|одежд|на пару)'),
            array('forms' => 'жуков', 'def' => 2, 'alt' => 'first',
                'when' => function ($c) { return $c['capitalMid'] || SanottsRuText::test($c['clause'], '(маршал|георги|генерал)'); }),
            array('forms' => 'сорок', 'def' => 'first', 'alt' => 2, 'clause' => '(стая|стаю|птиц|трещ|белобок)'),
            array('forms' => 'уже', 'def' => 2, 'alt' => 'first',
                'when' => function ($c) {
                    return $c['nextAny'] === 'чем' || in_array($c['prev'], array('гораздо', 'намного', 'немного', 'чуть', 'значительно', 'заметно', 'ещё', 'еще'), true)
                        && $c['next'] !== '' && !SanottsRuText::test($c['next'], '^(не|был|есть)');
                }),
            array('forms' => 'мою', 'def' => 'last', 'alt' => 'first',
                'when' => function ($c) {
                    return $c['prev'] === 'я' || in_array($c['next'], array('посуду', 'руки', 'полы', 'пол', 'окна', 'голову', 'ноги', 'лицо', 'овощи', 'фрукты', 'волосы'), true);
                }),
            array('forms' => 'плачу', 'def' => 'first', 'alt' => 'last',
                'clause' => '((^| )за( |$)|налог|деньг|рубл|долг|сч[её]т|штраф|кредит|аренд|картой|наличн|коммунал|квартплат|электричеств|ипотек)'),
            array('forms' => 'воды', 'def' => 'last', 'alt' => 'first',
                'when' => function ($c) {
                    return in_array($c['prev'], array('подземные', 'грунтовые', 'талые', 'вешние', 'минеральные', 'территориальные', 'сточные', 'морские', 'мутные', 'тихие'), true) || $c['next'] === 'отошли';
                }),
            array('forms' => 'реки', 'def' => 'last', 'alt' => 'first',
                'when' => function ($c) {
                    return SanottsRuText::test($c['prev'], '(ые|ие|ой|ей)$') && !SanottsRuText::genPrep($c['prev']) && !in_array($c['prev'], array('две', 'три', 'четыре', 'обе', 'одной', 'этой', 'той', 'нашей', 'большой', 'горной'), true)
                        || in_array($c['prev'], array('эти', 'те', 'все', 'многие', 'наши', 'некоторые'), true)
                        || (SanottsRuText::test($c['next'], '^(текут|впадают|разлились|замёрзли|замерзли|вышли|полны|разливаются|текли|были|есть)$') && !SanottsRuText::genPrep($c['prev']));
                }),
            array('forms' => 'заросли', 'def' => 'last', 'alt' => 'first',
                'when' => function ($c) {
                    return in_array($c['prev'], array('в', 'во', 'из', 'сквозь', 'через', 'среди', 'за', 'эти', 'те', 'густые', 'непроходимые', 'колючие'), true)
                        || (SanottsRuText::test($c['prev'], '(ые|ие)$') && count($c['before']) > 0) || count($c['before']) === 0 && SanottsRuText::test($c['clause'], '(^| )(были|стали|тянулись|начались)( |$)');
                }),
            array('forms' => 'дела', 'def' => 'last', 'alt' => 'first',
                'when' => function ($c) {
                    return in_array($c['prev'], array('из', 'без', 'для', 'до', 'от', 'против', 'вне', 'после', 'кроме', 'вместо', 'суть', 'материалы',
                        'материалов', 'номер', 'копия', 'том', 'рассмотрения', 'ведения', 'обстоятельства', 'сути'), true);
                }),
            array('forms' => 'вечера', 'def' => 'last', 'alt' => 'first',
                'when' => function ($c) {
                    return SanottsRuText::test($c['prev'], SanottsRuText::NUMWORD) || SanottsRuText::test($c['prev'], '(ого|его)$')
                        || in_array($c['prev'], array('часов', 'часа', 'часу', 'до', 'с', 'со', 'от', 'после', 'около', 'среди', 'вместо', 'без', 'для', 'у',
                            'середины', 'конца', 'начала', 'половины', 'половина', 'середина', 'конец', 'начало'), true);
                }),
        );
        foreach (array('голоса', 'слова', 'города', 'леса', 'луга', 'снега', 'поезда', 'корпуса') as $f) {
            $list[] = array('forms' => $f, 'def' => 'first', 'alt' => 'last',
                'when' => function ($c) { return SanottsRuText::pluralCue($c) || count($c['before']) === 0; });
        }
        $list[] = array('forms' => 'дома', 'def' => 'first', 'alt' => 'last', 'when' => function ($c) { return SanottsRuText::pluralCue($c, false); });
        foreach (array('стены', 'горы', 'руки', 'ноги') as $f) {
            $list[] = array('forms' => $f, 'def' => 'first', 'alt' => 'last',
                'when' => function ($c) { return SanottsRuText::genPrep($c['prev']) || (SanottsRuText::test($c['prev'], '(ой|ей)$') && SanottsRuText::genPrep($c['prev2'])); });
        }
        $list[] = array('forms' => 'зимы', 'def' => 'last', 'alt' => 'first', 'when' => function ($c) { return SanottsRuText::pluralCue($c, false); });
        $list[] = array('forms' => 'войска', 'def' => 'last', 'alt' => 'first',
            'when' => function ($c) { return SanottsRuText::genPrep($c['prev']) || SanottsRuText::test($c['prev'], '(ого|его)$'); });
        $list[] = array('forms' => 'дорогой', 'def' => 'last', 'alt' => 2,
            'when' => function ($c) {
                return (SanottsRuText::test($c['prev'], '(ой|ей)$') && SanottsRuText::jsLen($c['prev']) > 2)
                    || SanottsRuText::test($c['prev'], '^(шли|шёл|шла|идти|иди|идём|ехали|ехал|ехать|пошли|пошёл|пошла|поехали|возвращались|вернулись|прошли|добирались)$');
            });
        $list[] = array('forms' => 'войны', 'def' => 'last', 'alt' => 'first',
            'when' => function ($c) { return SanottsRuText::pluralCue($c) || (count($c['before']) === 0 && !SanottsRuText::test($c['next'], '^не$')); });
        $list[] = array('forms' => 'села', 'def' => 'last', 'alt' => 'first',
            'when' => function ($c) {
                return !SanottsRuText::test($c['prev'], SanottsRuText::NUMWORD) && (SanottsRuText::pluralCue($c, false)
                    || SanottsRuText::test($c['prev'], '^(она|я|ты|мама|девочка|девушка|женщина|птица|кошка|собака|бабушка|жена|сестра|дочь|муха|машина|батарея)$')
                    || SanottsRuText::test($c['next'], '^(на|в|за|у|рядом|около|возле|напротив|поближе|обедать|писать|читать|отдохнуть|поесть)$'));
            });
        $list[] = array('forms' => 'часа', 'def' => 'first', 'alt' => 'last',
            'when' => function ($c) { return SanottsRuText::test($c['prev'], '^(два|три|четыре|оба|полтора|половиной|пару|пара)$'); });
        $list[] = array('forms' => 'среду', 'def' => 'first', 'alt' => 'last',
            'clause' => '(окружающ|внешн|водн|питательн|городск|природн|экологи|рабоч|программн)');
        self::$HOMO = array();
        foreach ($list as $h) foreach (preg_split('/\s+/', $h['forms']) as $f) self::$HOMO[$f] = $h;
        self::$LOC2 = array_flip(preg_split('/\s+/', 'саду лесу году углу мосту шкафу берегу снегу порту полу краю дыму носу бою строю ' .
            'тылу ходу цеху пруду лугу кругу ряду раю виду плену долгу посту соку спирту свету меду ' .
            'мозгу рту пуху жиру пылу бреду глазу крыму аду роду гробу корню поту балу лбу льду ветру чаду ' .
            'борту цвету катку стогу бору хлеву полку'));
    }

    private static function resolveN($spec, $w)
    {
        return $spec === 'first' ? 1 : ($spec === 'last' ? self::nVowels($w) : $spec);
    }

    private static function costReading($c)
    {
        if (in_array($c['prev'], self::$COST_PREV, true) || self::test($c['next'], '^(\d|рубл|руб\.|долл|евро|денег|копе|тысяч|миллион|дорог|дёшев|дешев|того|внимания|труда|попробовать)')) return true;
        if ($c['next'] === 'ли') return true;
        if (self::test($c['next'], self::NUMWORD) || self::test($c['next'], '^(сто|тысяч|миллион|миллиард)')) return true;
        if (self::test($c['next'], '^(дороже|дешевле|целое|половину|вдвое|втрое|порядка|прилично|баснословн|космос|копейки|гроши|недорого|немало|немного|столько|уйму|кучу|бешен)')) return true;
        if (self::test($c['next'], '^(как|около|примерно|почти|всего|больше|меньше|от|до|свыше|более|менее|минимум|максимум|лишь|только)$')
            && self::test($c['clause'], '(дешев|дешёв|дорог|цен[аеуыой]|стоимост|бюджетн|зарплат|кошел|рубл|гривен|грн|долл|евро|денег|деньг|купить|покупк|прайс|тариф)')) return true;
        if (self::test($c['next'], '(ть|ти|чь|ться|тись)$') && self::jsLen($c['next']) > 3) return true;
        return in_array('сколько', $c['before'], true);
    }

    private static function locAfterPrep($c)
    {
        if (in_array($c['prev'], self::$LOC_PREP, true)) return true;
        if (!self::test($c['prev'], '(ом|ем|ём|ой|ей)$') || self::test($c['prev'], '(ому|ему)$')) return false;
        $b = $c['before'];
        $n = count($b);
        for ($j = $n - 2; $j >= 0 && $j >= $n - 8; $j--) {
            $t = $b[$j];
            if (in_array($t, self::$LOC_PREP, true)) return true;
            if (!(self::test($t, self::NUMWORD) || self::test($t, '(ом|ем|ём|ой|ей)$'))) return false;
        }
        return false;
    }

    private static function contextStress($w, $c)
    {
        self::homographs();
        if (isset(self::$LOC2[$w]) && self::locAfterPrep($c)) return self::nVowels($w);
        if ($w === 'берега') return self::genPrep($c['prev']) ? 1 : 3;
        if ($w === 'стоит' || $w === 'стоят') return self::costReading($c) ? 1 : 0;
        if (isset(self::$HOMO[$w])) {
            $h = self::$HOMO[$w];
            $alt = (isset($h['clause']) && self::test($c['clause'], $h['clause'])) || (isset($h['when']) && call_user_func($h['when'], $c));
            return self::resolveN($alt ? $h['alt'] : $h['def'], $w);
        }
        return 0;
    }

    /** Ударение из текста: за́мок, зам+ок, замОк -> array(pre, word, N, post) или null. */
    private static function explicitStress($tok)
    {
        $m = self::match($tok, '^([^а-яёА-ЯЁ+]*)([а-яёА-ЯЁ+\u0301]+)([^а-яёА-ЯЁ]*)$');
        if (!$m) return null;
        $raw = $m[2];
        $w = '';
        $n = 0;
        $count = 0;
        $rc = self::chars($raw);
        if (strpos($raw, "\xCC\x81") !== false || strpos($raw, '+') !== false) {
            $wc = array();
            for ($i = 0; $i < count($rc); $i++) {
                $ch = $rc[$i];
                if ($ch === '+') {
                    $nx = isset($rc[$i + 1]) ? $rc[$i + 1] : null;
                    if ($nx !== null && self::isVowel(self::lc($nx))) $n = $count + 1;
                    continue;
                }
                if ($ch === "\xCC\x81") {
                    if ($wc && self::isVowel(self::lc($wc[count($wc) - 1]))) $n = $count;
                    continue;
                }
                $wc[] = $ch;
                if (self::isVowel(self::lc($ch))) $count++;
            }
            $w = implode('', $wc);
        } else {
            $caps = array();
            foreach ($rc as $i => $ch) if ($i > 0 && self::isVowel(self::lc($ch)) && $ch !== self::lc($ch)) $caps[] = array($ch, $i);
            if (count($caps) !== 1) return null;
            $tail = implode('', array_slice($rc, 1));
            $p = strpos($tail, $caps[0][0]);
            $rest = substr($tail, 0, $p) . substr($tail, $p + strlen($caps[0][0]));
            if ($rest !== self::lc($rest)) return null;
            $ci = $caps[0][1];
            $w = implode('', array_slice($rc, 0, $ci)) . self::lc($caps[0][0]) . implode('', array_slice($rc, $ci + 1));
            $n = self::nVowels(self::lc(implode('', array_slice(self::chars($w), 0, $ci + 1))));
        }
        if (!$n) return null;
        return array($m[1], $w, $n, $m[3]);
    }

    public static function lineBreaksToPauses($text)
    {
        if (!self::test($text, '[\r\n]')) return $text;
        $text = self::rep($text, '[ \t]*[\r\n]+[ \t]*', 'g', "\n");
        $text = self::rep($text, '([,.;:!?…—–-])\n', 'g', '$1 ');
        $text = self::rep($text, '\n', 'g', ', ');
        return self::rep($text, '^\s*,\s*|,\s*$', 'g', '');
    }

    public static function bracketsToPauses($text)
    {
        if (!self::test($text, '[()[\]{}«»"“”„]')) return $text;
        $text = self::rep($text, '[«»"“”„]', 'g', '');
        $text = self::rep($text, '\s*[()[\]{}]+\s*', 'g', ', ');
        $text = self::rep($text, ',\s*([,.;:!?…])', 'g', '$1');
        $text = self::rep($text, '(^|[.!?…]\s*),\s*', 'g', '$1');
        $text = self::rep($text, ',\s*$', '', '');
        return self::rep($text, '\s{2,}', 'g', ' ');
    }

    public static function stemStress($text)
    {
        return self::rep($text, '([А-Яа-яЁё]*телек)(ом(?:а|у|ом|е|ы|ов|ам|ами|ах)?)(?![А-Яа-яЁё])', 'gi', '$1+$2');
    }

    public static function splitPrefix($word)
    {
        $w = self::lc($word);
        foreach (self::$PREFIXES as $p) {
            if (strpos($w, $p) === 0) {
                $rest = substr($w, strlen($p));
                if (self::jsLen($rest) >= 4 && self::test($rest, '[аеёиоуыэюя]')) return array($p, $rest);
            }
        }
        return null;
    }

    private static function countVowelsRe($s)
    {
        return preg_match_all('/[аеёиоуыэюя]/u', $s);
    }

    /**
     * Текст с метками ударений: array('text' => ..., 'extra' => строки для ru_extra).
     */
    public static function stressMarks($text, $numbers = true)
    {
        $text = self::dropLinks($text);
        $text = self::lineBreaksToPauses($text);
        $text = self::bracketsToPauses($text);
        $text = self::normalizeCurrency($text);
        $text = self::normalizeDims($text);
        $text = self::normalizeRoman($text);
        $text = self::normalizeLatin($text);
        if ($numbers) $text = self::normalizeNumbers($text);
        $text = self::restoreYo($text);
        $text = self::stemStress($text);
        $text = self::rep($text, '(^|[^А-Яа-яЁё])([Сс]амо)-(?=[а-яё]{3,})', 'g', '$1$2');
        $parts = self::splitWs($text);
        $bare = function ($t) { return SanottsRuText::rep(SanottsRuText::lc($t), '[^а-яё0-9]', 'g', ''); };
        $brk = function ($t) { return SanottsRuText::test($t, '[,.;:!?…]$'); };
        $idx = array();
        foreach ($parts as $i => $p) if (self::trim($p) !== '') $idx[] = $i;
        $extra = array();
        $clauseStart = 0;
        $N = count($idx);
        $bares = array();
        foreach ($idx as $k => $j) $bares[$k] = $bare($parts[$j]);
        for ($k = 0; $k < $N; $k++) {
            $tok = $parts[$idx[$k]];
            if ($k > 0 && $brk($parts[$idx[$k - 1]])) $clauseStart = $k;
            $ex = self::explicitStress($tok);
            if ($ex) {
                list($pre, $w, $n, $post) = $ex;
                $parts[$idx[$k]] = $pre . $w . str_repeat('ъ', $n) . $post;
                $extra[self::lc($w) . str_repeat('ъ', $n) . ' $' . $n] = true;
                continue;
            }
            $w = $bares[$k];
            if ($w === '' || self::test($w, 'ъ$')) continue;
            $clauseEnd = $k;
            while ($clauseEnd < $N - 1 && !$brk($parts[$idx[$clauseEnd]])) $clauseEnd++;
            $words = array();
            for ($j = $clauseStart; $j <= $clauseEnd; $j++) $words[] = $bare($parts[$idx[$j]]);
            $c = array(
                'prev' => $k > $clauseStart ? $bare($parts[$idx[$k - 1]]) : '',
                'prev2' => $k > $clauseStart + 1 ? $bare($parts[$idx[$k - 2]]) : '',
                'next' => $k + 1 < $N && !$brk($tok) ? $bare($parts[$idx[$k + 1]]) : '',
                'nextAny' => $k + 1 < $N ? $bare($parts[$idx[$k + 1]]) : '',
                'before' => array_slice($words, 0, $k - $clauseStart),
                'clause' => implode(' ', $words),
                'capitalMid' => self::test(self::rep($tok, '^[^а-яёА-ЯЁ]+', '', ''), '^[А-ЯЁ][а-яё]') && $k > $clauseStart && !$brk($parts[$idx[$k - 1]]),
            );
            $n = self::contextStress($w, $c);
            if (!$n) continue;
            $parts[$idx[$k]] = self::rep($tok, '([а-яёА-ЯЁ]+)([^а-яёА-ЯЁ]*)$', '', '$1' . str_repeat('ъ', $n) . '$2');
            $extra[$w . str_repeat('ъ', $n) . ' $' . $n] = true;
        }
        foreach (array_keys($extra) as $line) {
            $m = self::match($line, '^([а-яё-]+?)(ъ+)\s+\$(\d+)');
            $sp = $m ? self::splitPrefix($m[1]) : null;
            if (!$sp) continue;
            $n = (int)$m[3] - self::countVowelsRe($sp[0]);
            if ($n > 0) $extra[$sp[1] . str_repeat('ъ', $n) . ' $' . $n] = true;
        }
        return array('text' => implode('', $parts), 'extra' => array_map('strval', array_keys($extra)));
    }

    // ---------------------------------------------------------------------
    // Коды фонем
    // ---------------------------------------------------------------------
    const PAD = 0, BOS = 1, EOS = 2, SPACE = 3, SOFT = 119, PRIMARY = 120, SECONDARY = 121;
    private static $CLASSES = array(
        array('л', array(77)), array('н', array(26)), array('м', array(25)), array('вф', array(34, 19)), array('пб', array(28, 15)),
    );
    private static $VOWEL_IDS = array(14 => 1, 18 => 1, 21 => 1, 27 => 1, 33 => 1, 37 => 1, 50 => 1, 51 => 1, 59 => 1, 61 => 1, 74 => 1, 85 => 1, 100 => 1, 102 => 1, 128 => 1);
    private static $VOICED_FINAL = array(34 => 19, 17 => 32, 15 => 28, 66 => 23, 38 => 31, 108 => 96, 107 => 55);
    private static $EMPHASIS_WORDS = array('заросли', 'сорок');

    public static function core($ids)
    {
        $seq = array();
        foreach ($ids as $i) if ($i !== self::PAD) $seq[] = $i;
        if ($seq && $seq[0] === self::BOS) array_shift($seq);
        if ($seq && $seq[count($seq) - 1] === self::EOS) array_pop($seq);
        return $seq;
    }

    public static function frame($seq)
    {
        $out = array(self::BOS, self::PAD);
        foreach ($seq as $p) { $out[] = $p; $out[] = self::PAD; }
        $out[] = self::EOS;
        return $out;
    }

    private static function splitWords($seq)
    {
        $out = array(array());
        foreach ($seq as $i) {
            if ($i === self::SPACE) $out[] = array();
            else $out[count($out) - 1][] = $i;
        }
        $r = array();
        foreach ($out as $w) if ($w) $r[] = $w;
        return $r;
    }

    private static function joinWords($words)
    {
        $out = array();
        foreach ($words as $i => $w) {
            if ($i) $out[] = self::SPACE;
            foreach ($w as $x) $out[] = $x;
        }
        return $out;
    }

    private static function fixWord($letters, &$ph)
    {
        $changed = 0;
        foreach (self::$CLASSES as $cls) {
            $pos = array();
            foreach ($letters as $i => $l) if (strpos($cls[0], $l) !== false) $pos[] = $i;
            if (!$pos) continue;
            $php = array();
            foreach ($ph as $j => $x) if (in_array($x, $cls[1], true)) $php[] = $j;
            if (count($php) !== count($pos)) continue;
            for ($k = count($pos) - 1; $k >= 0; $k--) {
                if (!isset($letters[$pos[$k] + 1]) || $letters[$pos[$k] + 1] !== 'ь') continue;
                $j = $php[$k];
                if (isset($ph[$j + 1]) && $ph[$j + 1] === self::SOFT) continue;
                array_splice($ph, $j + 1, 0, array(self::SOFT));
                $changed++;
            }
        }
        return $changed;
    }

    private static function fixHardL($letters, &$ph)
    {
        $pos = array();
        foreach ($letters as $i => $l) if ($l === 'л') $pos[] = $i;
        $php = array();
        foreach ($ph as $j => $x) if ($x === 77) $php[] = $j;
        if (!$pos || count($php) !== count($pos)) return 0;
        $ls = implode('', $letters);
        if (strpos($ls, 'солнц') === 0) return 0;
        $changed = 0;
        for ($k = count($pos) - 1; $k >= 0; $k--) {
            $next = isset($letters[$pos[$k] + 1]) ? $letters[$pos[$k] + 1] : '';
            if ($next === '' || strpos('бвгджзйкмнпрстфхцчшщ', $next) === false) continue;
            $after = implode('', array_slice($letters, $pos[$k] + 1));
            if (!preg_match('/[аеёиоуыэюя]/u', $after)) continue;
            if ($after === 'ся' || $after === 'сь') continue;
            $j = $php[$k];
            if ($j + 1 >= count($ph) || $ph[$j + 1] === self::SOFT || $ph[$j + 1] === self::SPACE) continue;
            array_splice($ph, $j + 1, 0, array(self::SPACE));
            $changed++;
        }
        return $changed;
    }

    private static function letters($t)
    {
        return self::chars(self::rep(self::lc($t), '[^а-яё]', 'g', ''));
    }

    private static function tokens($s)
    {
        $out = array();
        foreach (preg_split('~[' . self::WS . ']+~u', $s) as $t) if ($t !== '') $out[] = $t;
        return $out;
    }

    private static function hasAlnum($t)
    {
        return preg_match('/[0-9a-zA-Zа-яА-ЯёЁ]/u', $t) === 1;
    }

    public static function fixSoftSign($clauseText, $seq, $g2p)
    {
        $words = self::splitWords($seq);
        $tokens = self::tokens($clauseText);
        $counts = array();
        foreach ($tokens as $t) $counts[] = self::hasAlnum($t) ? count(self::splitWords(self::core($g2p($t)))) : 0;
        $pos = array_fill(0, count($tokens), -1);
        if (array_sum($counts) === count($words)) {
            $w = 0;
            foreach ($tokens as $i => $t) { if ($counts[$i] === 1) $pos[$i] = $w; $w += $counts[$i]; }
        } else {
            $key = function ($ph) {
                $o = array();
                foreach ($ph as $x) if ($x >= 14 && $x !== 120 && $x !== 121) $o[] = $x;
                return implode(',', $o);
            };
            $wk = array();
            foreach ($words as $w) $wk[] = $key($w);
            $from = 0;
            foreach ($tokens as $i => $t) {
                if ($counts[$i] !== 1) continue;
                $k = $key(self::core($g2p($t)));
                for ($j = $from; $j < count($wk); $j++) {
                    if ($wk[$j] === $k) { $pos[$i] = $j; $from = $j + 1; break; }
                }
            }
        }
        $changed = 0;
        foreach ($tokens as $i => $t) {
            $letters = self::letters($t);
            if ($pos[$i] >= 0 && in_array('ь', $letters, true)) $changed += self::fixWord($letters, $words[$pos[$i]]);
            if ($pos[$i] >= 0 && preg_match('/л[бвгджзйкмнпрстфхцчшщ]/u', implode('', $letters))) $changed += self::fixHardL($letters, $words[$pos[$i]]);
        }
        $changed += self::devoiceMarked($tokens, $pos, $words);
        $changed += self::compoundPrefix($tokens, $pos, $words, $g2p);
        $changed += self::hardBorrowed($tokens, $pos, $words);
        $changed += self::emphasizeMoved($tokens, $pos, $words, $g2p);
        return $changed ? self::joinWords($words) : $seq;
    }

    private static function emphasizeMoved($tokens, $pos, &$words, $g2p)
    {
        $changed = 0;
        foreach ($tokens as $i => $t) {
            $m = self::match($t, '^[^а-яёА-ЯЁ]*([а-яёА-ЯЁ-]+?)(ъ+)[^а-яёА-ЯЁ]*$');
            if ($pos[$i] >= 0 && $m && in_array(self::lc($m[1]), self::$EMPHASIS_WORDS, true)) {
                $plain = implode(',', self::core($g2p($m[1])));
                $marked = implode(',', self::core($g2p($m[1] . $m[2])));
                if ($plain !== $marked) {
                    $ph = &$words[$pos[$i]];
                    $s = array_search(self::PRIMARY, $ph, true);
                    if ($s !== false) {
                        for ($j = $s + 1; $j < count($ph); $j++) {
                            if (!isset(self::$VOWEL_IDS[$ph[$j]])) continue;
                            $more = false;
                            for ($q = $j + 1; $q < count($ph); $q++) if (isset(self::$VOWEL_IDS[$ph[$q]])) { $more = true; break; }
                            if ($more) { array_splice($ph, $j + 1, 0, array($ph[$j])); $changed++; }
                            break;
                        }
                    }
                    unset($ph);
                }
            }
        }
        return $changed;
    }

    private static function compoundPrefix($tokens, $pos, &$words, $g2p)
    {
        $changed = 0;
        foreach ($tokens as $i => $t) {
            $m = self::match($t, '^[^а-яёА-ЯЁ]*([а-яёА-ЯЁ]+)[^а-яёА-ЯЁ]*$');
            $sp = $pos[$i] >= 0 && $m ? self::splitPrefix($m[1]) : null;
            if (!$sp) continue;
            $pre = array();
            foreach (self::core($g2p($sp[0])) as $x) $pre[] = $x === self::PRIMARY ? self::SECONDARY : $x;
            $mk = self::match($sp[1], '^(.*?)(ъ+)$');
            $restWord = $sp[1];
            if ($mk) {
                $n = strlen($mk[2]) / 2 - self::countVowelsRe($sp[0]);
                $restWord = $mk[1] . ($n > 0 ? str_repeat('ъ', $n) : '');
            }
            $rest = self::core($g2p($restWord));
            $trail = array();
            $ph = &$words[$pos[$i]];
            for ($j = count($ph) - 1; $j >= 0 && $ph[$j] < 14 && $ph[$j] !== 3; $j--) array_unshift($trail, $ph[$j]);
            $preV = 0;
            foreach ($pre as $x) if (isset(self::$VOWEL_IDS[$x])) $preV++;
            $same = self::vIdx($ph) === $preV + self::vIdx($rest);
            if (!$same && $pre && $rest && !in_array(self::SPACE, $rest, true)) {
                $new = $pre;
                foreach ($rest as $x) if ($x >= 14 || $x === self::SOFT) $new[] = $x;
                foreach ($trail as $x) $new[] = $x;
                $ph = $new;
                $changed++;
            }
            unset($ph);
        }
        return $changed;
    }

    private static function vIdx($seq)
    {
        $n = 0;
        foreach ($seq as $x) {
            if ($x === self::PRIMARY) return $n;
            if (isset(self::$VOWEL_IDS[$x])) $n++;
        }
        return -1;
    }

    private static $HARD_BEFORE_E = array(
        array('^т[еэ]ст(ы|а|у|ом|е|ов|ам|ами|ах|ер\S*|ир\S*|ов\S+)?$', 1),
        array('^модем\S*$', 1), array('^отел(ь|я|ю|ем|е|и|ей|ям|ями|ях)$', 1), array('^кафе$', 1), array('^шоссе$', 1),
        array('^роутер\S*$', 1), array('^теннис\S*$', 1), array('^темп(а|у|ом|е|ы|ов|ами|ах)?$', 1), array('^кемпинг\S*$', 1),
        array('^бутерброд\S*$', 1), array('^свитер\S*$', 2), array('^принтер\S*$', 2), array('^сканер\S*$', 1),
        array('^интерфейс\S*$', 1), array('^стенд\S*$', 1), array('^тент\S*$', 1), array('^дефолт\S*$', 1),
    );

    private static function hardBorrowed($tokens, $pos, &$words)
    {
        $changed = 0;
        foreach ($tokens as $i => $t) {
            if ($pos[$i] < 0) continue;
            $lw = implode('', self::letters($t));
            $hit = null;
            foreach (self::$HARD_BEFORE_E as $h) if (self::test($lw, $h[0])) { $hit = $h; break; }
            if (!$hit) continue;
            $ph = &$words[$pos[$i]];
            $seen = 0;
            for ($j = 1; $j < count($ph); $j++) {
                if ($ph[$j] === self::SOFT && $ph[$j - 1] >= 14) {
                    if (++$seen === $hit[1]) { array_splice($ph, $j, 1); $changed++; break; }
                }
            }
            unset($ph);
        }
        return $changed;
    }

    private static function devoiceMarked($tokens, $pos, &$words)
    {
        $changed = 0;
        foreach ($tokens as $i => $t) {
            if ($pos[$i] >= 0 && self::test($t, '[бвгджз]ъ+[^а-яёА-ЯЁ]*$', 'i')) {
                $nl = isset($tokens[$i + 1]) ? self::letters($tokens[$i + 1]) : array();
                $nextFirst = $nl ? $nl[0] : '';
                if ($nextFirst === '' || strpos('бгджз', $nextFirst) === false) {
                    $ph = &$words[$pos[$i]];
                    for ($j = count($ph) - 1; $j >= 0; $j--) {
                        if ($ph[$j] === self::SOFT || $ph[$j] < 14) continue;
                        if (isset(self::$VOICED_FINAL[$ph[$j]])) { $ph[$j] = self::$VOICED_FINAL[$ph[$j]]; $changed++; }
                        break;
                    }
                    unset($ph);
                }
            }
        }
        return $changed;
    }

    public static function clauses($text)
    {
        $text = self::rep($text, '\s+[—–]\s+', 'g', ', ');
        $out = array();
        foreach (self::matchAll($text, '[^]+?(?:[,.;:!?…]+(?=\s|$)|$)', 'g') as $s) {
            $s = self::trim($s);
            if (self::hasAlnum($s)) $out[] = $s;
        }
        return $out;
    }

    /** Все строки, для которых ruIds() спросит g2p (с запасом). */
    public static function g2pRequests($markedText)
    {
        $req = array();
        foreach (self::clauses($markedText) as $c) {
            $req[$c] = true;
            foreach (self::tokens($c) as $t) {
                if (self::hasAlnum($t)) $req[$t] = true;
                $m = self::match($t, '^[^а-яёА-ЯЁ]*([а-яёА-ЯЁ]+)[^а-яёА-ЯЁ]*$');
                $sp = $m ? self::splitPrefix($m[1]) : null;
                if ($sp) {
                    $req[$sp[0]] = true;
                    $mk = self::match($sp[1], '^(.*?)(ъ+)$');
                    $restWord = $sp[1];
                    if ($mk) {
                        $n = strlen($mk[2]) / 2 - self::countVowelsRe($sp[0]);
                        $restWord = $mk[1] . ($n > 0 ? str_repeat('ъ', $n) : '');
                    }
                    $req[$restWord] = true;
                }
                $m = self::match($t, '^[^а-яёА-ЯЁ]*([а-яёА-ЯЁ-]+?)(ъ+)[^а-яёА-ЯЁ]*$');
                if ($m && in_array(self::lc($m[1]), self::$EMPHASIS_WORDS, true)) {
                    $req[$m[1]] = true;
                    $req[$m[1] . $m[2]] = true;
                }
            }
        }
        return array_map('strval', array_keys($req));
    }

    /**
     * Фраза -> всё для синтеза: array('extra' => строки словаря для ударений,
     * 'final' => текст, который разбирается на фонемы). stressMarks() —
     * дважды: второй проход ловит то, что открыл первый.
     */
    public static function prepare($chunk)
    {
        $m = self::stressMarks($chunk);
        $f = self::stressMarks($m['text']);
        return array('marked' => $m['text'], 'extra' => $m['extra'], 'final' => $f['text']);
    }

    /** Коды фонем (ruIds) по тексту из prepare(); $g2p — текст -> коды с рамкой. */
    public static function idsFromFinal($final, $g2p)
    {
        $seq = array();
        foreach (self::clauses($final) as $c) {
            $part = self::core($g2p($c));
            $part = self::fixSoftSign($c, $part, $g2p);
            if (!$part) continue;
            if ($seq && $seq[count($seq) - 1] !== self::SPACE) $seq[] = self::SPACE;
            foreach ($part as $x) $seq[] = $x;
        }
        while ($seq && $seq[count($seq) - 1] === self::SPACE) array_pop($seq);
        return self::frame($seq);
    }
}

