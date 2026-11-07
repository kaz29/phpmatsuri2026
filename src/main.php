<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';

use MiniXDebug\Command\Debug;
use Symfony\Component\Console\Application;

$application = new Application();
$application->addCommand(new Debug());
$application->run();
