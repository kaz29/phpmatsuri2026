<?php
function add(int $a, int $b): int {
    $sum = $a + $b;
    return $sum;
}

function twice(int $n): int {
    $x = add($n, $n);
    return $x;
}

$items = ['a' => 1, 'b' => 2];
$total = 0;
foreach ($items as $key => $value) {
    $total = $total + twice($value);
}
echo "total = $total\n";
