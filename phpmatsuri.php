<?php
declare(strict_types=1);

function info(int $year, string $location, ?string $option = null): string {
    return "🏮 \e[1;96mPHPMatsuri\e[0m \e[1;30;103m {$year} \e[0m at \e[1;4;95m{$location}\e[0m" . ($option !== null ? " \e[1;5;97;41m ✨ {$option} ✨ \e[0m" : "");
}

$PHP祭り = [
    [ 'year' => 2010, 'location' => 'Tokyo', 'option' => null],
    [ 'year' => 2011, 'location' => 'Osaka', 'option' => null],
    [ 'year' => 2012, 'location' => 'Fukuoka'],
    [ 'year' => 2013, 'location' => 'Sapporo', 'option' => null],
    [ 'year' => 2026, 'location' =>  'Hakone', 'option' => 'reboot'],
];

foreach ($PHP祭り as $event) {
    echo info($event['year'], $event['location'], $event['option'] ?? null) . "\n";
}





echo "\n\e[41m    \e[43m    \e[42m    \e[46m    \e[44m    \e[45m    \e[41m    \e[43m    \e[42m    \e[46m    \e[44m    \e[45m    \e[41m    \e[43m    \e[42m    \e[46m    \e[44m    \e[0m\n\e[1;5;93;45m  🎉🎉  asumikam、PHP祭り reboot 主催してくれてありがとう！  🎉🎉   \e[0m\n\e[41m    \e[43m    \e[42m    \e[46m    \e[44m    \e[45m    \e[41m    \e[43m    \e[42m    \e[46m    \e[44m    \e[45m    \e[41m    \e[43m    \e[42m    \e[46m    \e[44m    \e[0m\n\n";
