<?php
declare(strict_types=1);

namespace MiniXDebug\Service;

final class Debugger
{
    public static array $frames = [['{main}', 0]];   // [関数名, 現在行]
    public static string $mode = 'step';             // step | next | out | run
    public static int $targetDepth = 0;              // next/out の基準深さ
    public static array $breakpoints = [];           // [line => true]
    public static array $lines = [];                 // 元ソース（表示用）
    public static array $hookLines = [];             // __dbg が入っている行（昇順）

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

    public static function repl(int $line, array $vars): void
    {
        printf("\033[33m%s:%d\033[0m  %s\n", end(self::$frames)[0], $line, trim(self::$lines[$line - 1]));
        while (true) {
            $in = readline('(dbg) ');
            if ($in === false) exit;
            [$cmd, $arg] = array_pad(explode(' ', trim($in), 2), 2, null);
            switch ($cmd) {
                case 's': self::$mode = 'step'; return;
                case 'n': self::$mode = 'next'; self::$targetDepth = count(self::$frames); return;
                case 'o': self::$mode = 'out';  self::$targetDepth = count(self::$frames); return;
                case 'c': self::$mode = 'run';  return;
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
