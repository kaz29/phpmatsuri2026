<?php
declare(strict_types=1);

namespace MiniXDebug\Service;

/**
 * 実況デバッガ：REPL で止まるたびに、前に止まったときから何が起きたかを実況中継風にしゃべる
 *
 * __dbg は文を実行する「前」に呼ばれるので、前回の停止と今を比べると「その間に起きたこと」がわかる。
 * 関数呼び出しは駅伝のたすき渡しに見立てる（会場が箱根なので）。
 */
final class Commentator
{
    /** 何も起きなかったときのつなぎ（順番に使う） */
    private const FILLERS = [
        '静かな立ち上がりです',
        'まだ大きな動きはありません',
        '落ち着いた展開です',
        '淡々と進んでいきます',
    ];

    private ?array $last = null;   // 前回の停止 [呼び出し番号, 関数名]
    private array $seen = [];      // 呼び出し番号 => 最後に止まったときの変数
    private array $visits = [];    // 呼び出し番号 => [行 => 止まった回数]
    private int $lastCallSeq = 0;  // 前回止まった時点の Debugger::$callSeq。これより大きい呼び出しは「新しく入った」
    private int $filler = 0;

    /** @var resource|null 読み上げ中の say */
    private $speech = null;

    /** AI の生成が返ってこないときに諦めるまでの秒数 */
    private const AI_TIMEOUT = 15.0;

    /** @var array{proc: resource, out: resource, buffer: string, started: float, rule: string, line: int}|null 生成中の claude -p */
    private ?array $generating = null;

    /** @var string[] 直前の実況（AI に言い回しの繰り返しを避けさせるため、最大2つ） */
    private array $recent = [];

    /** @var array<string, string> 英字の識別子の読み方（commentator/readings.json） */
    private array $readings = [];

    public function __construct(private string $mode, private bool $ai = false)
    {
        if ($this->mode === 'on' && trim((string) shell_exec('command -v say')) === '') {
            fwrite(STDERR, "say コマンドが見つからないので、実況は文字で表示します\n");
            $this->mode = 'text';
        }
        if ($this->ai && trim((string) shell_exec('command -v claude')) === '') {
            fwrite(STDERR, "claude コマンドが見つからないので、AI を使わずに実況します\n");
            $this->ai = false;
        }
        if ($this->ai) {
            $json = @file_get_contents(self::dir() . '/readings.json');
            $this->readings = $json !== false ? (json_decode($json, true) ?? []) : [];
        }
    }

    private static function dir(): string
    {
        return dirname(__DIR__, 2) . '/commentator';
    }

    public function comment(int $line, array $frames, bool $atBreakpoint): void
    {
        [$name, , $vars, $call] = end($frames);
        $name = self::label($name);
        $alive = array_column($frames, 3);  // いまスタックにある呼び出し
        $words = [];

        if ($this->last === null) {
            $words[] = 'さあ、デバッグ駅伝スタートです！';
        } else {
            [$lastCall, $lastName] = $this->last;
            if ($lastCall !== $call && !in_array($lastCall, $alive, true)) {
                $words[] = "{$lastName}、ゴール！";
            }
            if ($call > $this->lastCallSeq) {
                // 前回止まったあとに始まった呼び出し＝関数に入った
                $caller = self::label($frames[count($frames) - 2][0] ?? '{main}');
                $words[] = "{$caller} から {$name} へ、たすきが渡りました！";
                if ($vars) {
                    $args = [];
                    foreach (array_slice($vars, 0, 3, true) as $k => $v) {
                        $args[] = "\${$k} は " . $this->value($v);
                    }
                    $words[] = '引数 ' . implode('、', $args) . '！';
                }
            } else {
                if ($lastCall !== $call) {
                    $words[] = "{$name} に戻ってきました！";
                }
                // 同じ呼び出しで前に止まったときと比べて、変数の変化を実況する
                // （run で途中を飛ばした呼び出しは、前の状態を知らないので比べない）
                if (isset($this->seen[$call])) {
                    array_push($words, ...$this->diff($this->seen[$call], $vars));
                }
            }
        }

        // 同じ呼び出しの中で同じ行に戻ってきたら周回（ループ）
        $lap = $this->visits[$call][$line] = ($this->visits[$call][$line] ?? 0) + 1;
        if ($lap >= 2) {
            $words[] = "{$line}行目、{$lap}周目に入ります！";
        }

        $next = ltrim(Debugger::$lines[$line - 1] ?? '');
        if (str_starts_with($next, 'return')) {
            $words[] = 'さあ リターン、リターン です！';
        } elseif (str_starts_with($next, 'echo')) {
            $words[] = '出力が来ます！';
        }

        if ($words === []) {
            $words[] = "{$line}行目、" . self::FILLERS[$this->filler++ % count(self::FILLERS)];
        }

        // 呼び出しごとに最後の状態を覚えておく。スタックから消えた呼び出しは忘れる
        $this->seen[$call] = $vars;
        $this->seen = array_intersect_key($this->seen, array_flip($alive));
        $this->visits = array_intersect_key($this->visits, array_flip($alive));
        $this->last = [$call, $name];
        $this->lastCallSeq = Debugger::$callSeq;

        $rule = implode(' ', $words);
        if ($this->ai) {
            // AI にはブレークポイントのことを渡さない（毎回「止まった！」から始まってしまうため）
            $this->startAi($rule, $line, $frames, $vars);
        } else {
            $this->speak(($atBreakpoint ? 'ブレークポイント！ ここで止まったー！ ' : '') . $rule, $line);
        }
    }

    // ------------------------------------------------------------------
    // AI（claude -p + commentator/CLAUDE.md）で実況文を作る

    /**
     * ルールで集めた事実を claude -p に渡して、読み上げやすい実況文を作らせる
     *
     * デバッガを待たせないように非同期で動かす。結果は、入力待ちのループから poll() で受け取る
     */
    private function startAi(string $rule, int $line, array $frames, array $vars): void
    {
        $this->cancelAi(); // 前の停止の生成がまだなら捨てる（最新の停止だけ実況する）

        $stack = implode(' → ', array_map(static fn(array $f) => self::label($f[0]), $frames));
        $code = trim(Debugger::$lines[$line - 1] ?? '');
        $varList = [];
        foreach (array_slice($vars, 0, 6, true) as $k => $v) {
            $varList[] = "\${$k} = " . $this->value($v);
        }
        $input = implode("\n", [
            '場所: ' . self::label((string) end($frames)[0]) . " の {$line}行目「{$code}」",
            "スタック: {$stack}",
            "起きたこと: {$rule}",
            '今の変数: ' . ($varList ? implode(', ', $varList) : 'なし'),
            '直前の実況: ' . ($this->recent ? '「' . implode('」「', $this->recent) . '」' : 'なし'),
        ]);

        // Haiku は CLAUDE.md の「英字はカタカナで」を守りきれないが、入力に読み方を付けると守る
        preg_match_all('/[A-Za-z][A-Za-z0-9_]*/', $input, $m);
        $readings = [];
        foreach (array_unique($m[0]) as $word) {
            if (isset($this->readings[$word])) {
                $readings[] = "{$word}={$this->readings[$word]}";
            }
        }
        if ($readings) {
            $input .= "\n\n読み方: " . implode(', ', $readings) . "\n（実況では英字を使わず、上の読み方のカタカナで書くこと）";
        }

        $proc = proc_open(
            ['claude', '-p', '--model', 'haiku', '--tools', '', '--strict-mcp-config', '--no-session-persistence'],
            [['pipe', 'r'], ['pipe', 'w'], ['file', '/dev/null', 'w']],
            $pipes,
            self::dir(), // ここの CLAUDE.md が実況者の振る舞い
        );
        if (!is_resource($proc)) {
            $this->speak($rule, $line);
            return;
        }
        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        $this->generating = ['proc' => $proc, 'out' => $pipes[1], 'buffer' => '', 'started' => microtime(true), 'rule' => $rule, 'line' => $line];
    }

    /** 生成中の claude -p の出力（入力待ちのループの stream_select に足す）。生成中でなければ null */
    public function pendingStream()
    {
        return $this->generating['out'] ?? null;
    }

    /** 生成中の出力を読み、終わっていたら実況する。タイムアウトしたらルールの文で実況する */
    public function poll(): void
    {
        if ($this->generating === null) {
            return;
        }
        $g = &$this->generating;
        $g['buffer'] .= (string) stream_get_contents($g['out']);

        $timedOut = microtime(true) - $g['started'] > self::AI_TIMEOUT;
        if (!feof($g['out']) && !$timedOut) {
            return;
        }
        $rule = $g['rule'];
        $line = $g['line'];
        $text = $timedOut ? '' : $this->speakable(self::cleanup($g['buffer']));
        $this->cancelAi();

        // 失敗・タイムアウト・空のときは、ルールで作った文で実況する（デモ中に黙らないように）
        $this->speak($text !== '' ? $text : $rule, $line);
    }

    /** 対象スクリプトが終わったとき、最後の実況の生成を待つ */
    public function drain(): void
    {
        while ($this->generating !== null) {
            $r = [$this->generating['out']];
            $w = $e = null;
            @stream_select($r, $w, $e, 1);
            $this->poll();
        }
    }

    private function cancelAi(): void
    {
        if ($this->generating === null) {
            return;
        }
        fclose($this->generating['out']);
        if (proc_get_status($this->generating['proc'])['running']) {
            proc_terminate($this->generating['proc']);
        }
        proc_close($this->generating['proc']);
        $this->generating = null;
    }

    /** 念のため、残った英字を読み方の辞書で置き換え、say が読めない記号を消す */
    private function speakable(string $text): string
    {
        $text = preg_replace_callback('/[A-Za-z][A-Za-z0-9_]*/', fn($m) => $this->readings[$m[0]] ?? $m[0], $text);
        return trim(preg_replace('/->|[$\{\}\(\);\[\]`]/', '', $text));
    }

    /** モデルの出力から実況文だけを取り出す（1行目、前後の引用符や空白を取る） */
    private static function cleanup(string $output): string
    {
        foreach (preg_split('/\R/u', trim($output)) as $line) {
            // trim() はバイト単位なので、マルチバイトの「」は正規表現で外す（trim だと「マ」の先頭バイトまで削れる）
            $line = preg_replace('/^[\s"\'「」『』*`]+|[\s"\'「」『』*`]+$/u', '', $line);
            if ($line !== '') {
                return $line;
            }
        }
        return '';
    }

    /** @return string[] */
    private function diff(array $before, array $after): array
    {
        $words = [];
        foreach ($after as $k => $v) {
            if (!array_key_exists($k, $before)) {
                $value = $this->value($v);
                // 最初の1つだけ、実況らしく繰り返す
                $words[] = $words === []
                    ? "\${$k} に {$value}！ \${$k} に {$value} が入りました！"
                    : "\${$k} には {$value}！";
            } elseif ($before[$k] !== $v) {
                $words[] = is_array($v) || is_object($v)
                    ? "\${$k} の中身が変わりました！"
                    : "\${$k}、" . $this->value($before[$k]) . ' から ' . $this->value($v) . ' へ！';
            }
            if (count($words) >= 2) {
                break; // しゃべりすぎない
            }
        }
        return $words;
    }

    private static function label(string $function): string
    {
        return $function === '{main}' ? 'メイン' : $function;
    }

    private function value(mixed $v): string
    {
        return match (true) {
            $v === null      => 'ヌル',
            $v === true      => '真',
            $v === false     => '偽',
            is_int($v), is_float($v) => (string) $v,
            is_string($v)    => '「' . mb_strimwidth($v, 0, 20, '…') . '」という文字列',
            is_array($v)     => '要素' . count($v) . '個の配列',
            is_object($v)    => $v::class . ' のオブジェクト',
            default          => '謎の値',
        };
    }

    private function speak(string $text, int $line): void
    {
        $this->recent = array_slice([...$this->recent, $text], -2);

        if ($this->mode === 'text') {
            // VS Code につないでいるときはデバッグコンソールに出す。だめならターミナルに出す
            if (Debugger::$dbgp?->notifyUser("🎙 「{$text}」\n", $line)) {
                return;
            }
            echo "\033[35m🎙 「{$text}」\033[0m\n";
            return;
        }

        // 前の実況がまだ続いていたら切る（連打しても声が重ならない）
        if ($this->speech !== null) {
            if (proc_get_status($this->speech)['running']) {
                proc_terminate($this->speech);
            }
            proc_close($this->speech);
        }
        // $ は「ドル」と読まれてしまうので外す
        $this->speech = proc_open(
            ['say', '-v', 'Kyoko', '-r', '230', str_replace('$', '', $text)],
            [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $pipes,
        );
    }
}
