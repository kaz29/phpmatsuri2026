<?php
declare(strict_types=1);

namespace MiniXDebug\Service;

final class Debugger
{
    public static array $frames = [['{main}', 0, [], 0]]; // [関数名, 現在行, 最後の __dbg で受け取った変数, 呼び出し番号]
    public static int $callSeq = 0;                  // 関数を呼ぶたびに増やす（同じ深さでも別の呼び出しを見分ける）
    public static string $mode = 'step';             // step | next | out | run
    public static int $targetDepth = 0;              // next/out の基準深さ
    public static array $breakpoints = [];           // [line => true]
    public static array $lines = [];                 // 元ソース（表示用）
    public static array $hookLines = [];             // __dbg が入っている行（昇順）
    public static string $file = '';                 // デバッグ対象（元ファイル）の絶対パス
    public static ?Dbgp $dbgp = null;                // DBGp で IDE につないでいるとき。null なら stdin の REPL
    public static ?Commentator $commentator = null;  // 実況デバッガ

    /** __dbg から呼ばれる。止まるべき文なら IDE か REPL に制御を渡す */
    public static function hook(int $line, array $vars): void
    {
        $top = array_key_last(self::$frames);
        self::$frames[$top][1] = $line;
        self::$frames[$top][2] = $vars;
        if (!self::shouldPause($line)) {
            return;
        }
        if (self::$dbgp !== null) {
            // 実況は止まったと IDE に伝える前に送る（デバッグコンソールで停止より先に出る）
            self::$commentator?->comment($line, self::$frames, isset(self::$breakpoints[$line]));
            self::$dbgp->onBreak();
        } else {
            self::repl($line, $vars);
        }
    }

    /** 対象スクリプトが最後まで実行されたとき */
    public static function finish(): void
    {
        self::$dbgp?->onFinish();
    }

    /** ステップ実行の状態を設定する（REPL と DBGp で共通） */
    public static function resume(string $mode): void
    {
        self::$mode = $mode;
        self::$targetDepth = count(self::$frames);
    }

    /**
     * 指定行以降で最初にフックがある行を返す（} だけの行や空行に置かれたブレークポイントをずらす）
     * 本物の Xdebug も同様に調整し、breakpoint_set の応答で実際の行を返す
     */
    public static function resolveBreakpointLine(int $line): ?int
    {
        foreach (self::$hookLines as $hook) {
            if ($hook >= $line) {
                return $hook;
            }
        }
        return null;
    }

    public static function shouldPause(int $line): bool
    {
        $depth = count(self::$frames);
        return isset(self::$breakpoints[$line])
            || match (self::$mode) {
                'step' => true,                         // step_into: 次の文なら何でも
                'next' => $depth <= self::$targetDepth, // step_over: 深く潜ってる間は止まらない
                'out'  => $depth <  self::$targetDepth, // step_out : 今の関数を抜けたら
                'run'  => false,
            };
    }

    /**
     * 1行読む。入力待ちの最中でも Ctrl+C（SIGINT）で抜けられるようにしてある
     *
     * - readline()（libedit）は入力待ち中の SIGINT を握りつぶす
     * - fgets() も EINTR のとき read を1回やり直すので、1回目の Ctrl+C が効かない
     * - stream_select() はやり直さずに戻るので、そこで pcntl のハンドラが動く
     */
    private static function prompt(string $prompt): string|false
    {
        echo $prompt;
        do {
            $r = [STDIN];
            $w = $e = null;
            $ready = @stream_select($r, $w, $e, null);
        } while ($ready === false || $ready === 0);

        return fgets(STDIN);
    }

    public static function repl(int $line, array $vars): void
    {
        printf("\033[33m%s:%d\033[0m  %s\n", end(self::$frames)[0], $line, trim(self::$lines[$line - 1]));
        self::$commentator?->comment($line, self::$frames, isset(self::$breakpoints[$line]));
        while (true) {
            $in = self::prompt('(dbg) ');
            if ($in === false) exit;
            [$cmd, $arg] = array_pad(explode(' ', trim($in), 2), 2, null);
            switch ($cmd) {
                case 's': self::resume('step'); return;
                case 'n': self::resume('next'); return;
                case 'o': self::resume('out');  return;
                case 'c': self::resume('run');  return;
                case 'b':
                    $req = (int) $arg;
                    $bp = self::resolveBreakpointLine($req);
                    if ($bp === null) {
                        echo "line $req 以降に止まれる行がありません\n";
                    } else {
                        self::$breakpoints[$bp] = true;
                        echo $bp === $req ? "breakpoint at $bp\n" : "breakpoint at $bp (line $req から移動)\n";
                    }
                    break;
                case 'p': var_dump($vars[ltrim($arg, '$')] ?? null); break;
                case 'l': foreach ($vars as $k => $v) echo "\$$k = ", json_encode($v), "\n"; break;
                case 'bt':
                    foreach (array_reverse(self::$frames) as $i => [$fn, $ln]) echo "#$i $fn:$ln\n";
                    break;
                default: echo "s=step into, n=step over, o=step out, c=continue, b N, p \$x, l=locals, bt\n";
            }
        }
    }
}
