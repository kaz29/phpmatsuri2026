<?php
declare(strict_types=1);

use MiniXDebug\Service\Debugger;

function __dbg(int $line, array $vars): void
{
    Debugger::$frames[array_key_last(Debugger::$frames)][1] = $line;
    if (Debugger::shouldPause($line)) Debugger::repl($line, $vars);
}
function __dbg_enter(string $name, int $line): void { Debugger::$frames[] = [$name, $line]; }
function __dbg_leave(): void { array_pop(Debugger::$frames); }
