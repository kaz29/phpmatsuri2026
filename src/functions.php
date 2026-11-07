<?php
declare(strict_types=1);

use MiniXDebug\Service\Debugger;

function __dbg(int $line, array $vars): void
{
    Debugger::hook($line, $vars);
}
function __dbg_enter(string $name, int $line): void { Debugger::$frames[] = [$name, $line, []]; }
function __dbg_leave(): void { array_pop(Debugger::$frames); }
