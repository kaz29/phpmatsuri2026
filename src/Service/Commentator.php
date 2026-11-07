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

    public function __construct(private string $mode)
    {
        if ($this->mode === 'on' && trim((string) shell_exec('command -v say')) === '') {
            fwrite(STDERR, "say コマンドが見つからないので、実況は文字で表示します\n");
            $this->mode = 'text';
        }
    }

    public function comment(int $line, array $frames, bool $atBreakpoint): void
    {
        [$name, , $vars, $call] = end($frames);
        $name = self::label($name);
        $alive = array_column($frames, 3);  // いまスタックにある呼び出し
        $words = [];

        if ($atBreakpoint) {
            $words[] = 'ブレークポイント！ ここで止まったー！';
        }

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
            $words[] = 'さあ return、return です！';
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

        $this->speak(implode(' ', $words), $line);
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
