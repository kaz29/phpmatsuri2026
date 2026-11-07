<?php
declare(strict_types=1);

function info(int $year, string $location): string {
    return "PHPMatsuri {$year} at {$location}";
}

$PHP祭り = [
    [ 'year' => 2010, 'location' => 'Tokyo'],
    [ 'year' => 2011, 'location' => 'Osaka'],
    [ 'year' => 2012, 'location' => 'Fukuoka'],
    [ 'year' => 2013, 'location' => 'Sapporo'],
    [ 'year' => 2026, 'location' =>  'Hakone'],
];

foreach ($PHP祭り as $event) {
    echo info($event['year'], $event['location']) . "\n";
}
