<?php

function add(int $a, int $b): int {
    $sum = $a + $b;
    return $sum;
}

$result = add(3, 5);
echo "result: $result" . PHP_EOL;
