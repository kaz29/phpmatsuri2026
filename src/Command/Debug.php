<?php
declare(strict_types=1);

namespace MiniXDebug\Command;

use MiniXDebug\Service\Rewriter;
use MiniXDebug\Service\Debugger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * MiniXDebug実行コマンド
 */
#[AsCommand(name: 'debug', description: 'デバッガを起動する')]
class Debug extends Command
{
    protected Rewriter $rewriter;

    public function __construct()
    {
        parent::__construct();

        $this->rewriter = new Rewriter();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('デバッガを起動する')
            ->addOption('target', 't', InputOption::VALUE_REQUIRED, 'デバッグ対象ファイル')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $target = $input->getOption('target');
        if (empty($target)) {
            $output->writeln('ターゲットファイルが指定されていません。');
            return Command::FAILURE;
        }

        if (realpath($target) !== $target) {
            throw new \Exception('このファイルはデバッグできません');
        }

        $output->writeln('Target file: ' . $target);

        $this->debug($target);

        return Command::SUCCESS;
    }

    protected function debug(string $file)
    {
        $t = $this->rewriter->rewrite($file);
        $tmp = tempnam(sys_get_temp_dir(), 'dbg');
        file_put_contents($tmp, $t);
        echo $tmp . PHP_EOL;

        Debugger::$lines = file($file);
    
        // 引数で渡して include → ローカル変数を汚さない
        (static function () { include func_get_arg(0); })($tmp);
        unlink($tmp);
    }
}
