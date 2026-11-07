<?php
declare(strict_types=1);

namespace MiniXDebug\Command;

use MiniXDebug\Service\Rewriter;
use MiniXDebug\Service\Debugger;
use MiniXDebug\Service\Dbgp;
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
    protected ?string $dump = null;
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
            ->addOption('dump', 'd', InputOption::VALUE_REQUIRED, '書き出すファイルパス')
            ->addOption('dbgp', null, InputOption::VALUE_OPTIONAL, 'DBGp で IDE に接続する（host:port）', false)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $target = $input->getOption('target');
        $this->dump = $input->getOption('dump');
        if (empty($target)) {
            $output->writeln('ターゲットファイルが指定されていません。');
            return Command::FAILURE;
        }

        $path = realpath($target);
        if ($path === false || !is_file($path)) {
            $output->writeln('ターゲットファイルが見つかりません: ' . $target);
            return Command::FAILURE;
        }

        // 書き出し先がまだないときは realpath が false になるので、ディレクトリ側で比べる
        if ($this->dump !== null) {
            $dumpPath = realpath($this->dump)
                ?: realpath(dirname($this->dump)) . DIRECTORY_SEPARATOR . basename($this->dump);
            if ($dumpPath === $path) {
                $output->writeln('--dump にターゲットファイルと同じパスは指定できません: ' . $this->dump);
                return Command::FAILURE;
            }
        }

        $dbgp = $input->getOption('dbgp');
        if ($dbgp !== false) {
            // --dbgp だけなら VS Code の既定（127.0.0.1:9003）。IDE 側が listen していて、こちらから接続しに行く
            $address = $dbgp ?? '127.0.0.1:9003';
            try {
                Debugger::$dbgp = Dbgp::connect($address, $path);
            } catch (\RuntimeException $e) {
                $output->writeln($e->getMessage());
                return Command::FAILURE;
            }
            $output->writeln("DBGp: $address に接続しました");
        }

        $this->debug($path);

        return Command::SUCCESS;
    }

    protected function debug(string $file)
    {
        $rewritten = $this->rewriter->rewrite($file);
        $tmp = tempnam(sys_get_temp_dir(), 'dbg');
        file_put_contents($tmp, $rewritten);

        if ($this->dump !== null) {
            file_put_contents($this->dump, $rewritten);
        }
        
        // REPL の exit や対象スクリプトの致命的エラーで抜けても一時ファイルを消す
        register_shutdown_function(static function () use ($tmp): void {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        });
        // Ctrl+C（SIGINT）はそのままだとシャットダウン関数が動かないので、exit に変換する
        // 入力待ちの最中でもハンドラが動くように、Debugger::prompt() は stream_select で待っている
        if (function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, static function (): void {
                echo PHP_EOL;
                exit(130);
            }, false); // システムコールを自動で再開しない（select はどちらでも EINTR で戻るので念のため）
        }
        echo $tmp . PHP_EOL;

        Debugger::$file = $file;
        Debugger::$lines = file($file);
        Debugger::$hookLines = $this->rewriter->hookLines;

        // IDE からブレークポイントなどを受け取り、run / step_into が来るまで待つ
        Debugger::$dbgp?->handshake();

        // 引数で渡して include → ローカル変数を汚さない
        (static function () { include func_get_arg(0); })($tmp);

        Debugger::finish();
    }
}
