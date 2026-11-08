<?php
declare(strict_types=1);

namespace MiniXDebug\Service\Rewrite;

use PhpParser\{Node, Token};

/**
 * 書き換えの処理が共有する状態（元のコード・トークン・挿入する文字列）
 */
class RewriteContext
{
    /** @var array<int, string[]> [filePos => 挿入文字列のリスト] */
    private array $inserts = [];

    /** @var array<int, true> __dbg を挿入した行番号 */
    private array $hookLines = [];

    /** @param Token[] $tokens */
    public function __construct(
        public readonly string $code,
        public readonly array $tokens,
    ) {}

    /** 開く側の挿入：外側のノードが先に訪問されるので、後ろに足すと外→内の順になる */
    public function open(int $pos, string $s): void
    {
        $this->inserts[$pos][] = $s;
    }

    /** 閉じる側の挿入：内側のノードが後から訪問されるので、前に足すと内→外の順になる */
    public function close(int $pos, string $s): void
    {
        $this->inserts[$pos] ??= [];
        array_unshift($this->inserts[$pos], $s);
    }

    /** 文の前に __dbg を挿入する */
    public function hook(Node\Stmt $stmt): void
    {
        $line = $stmt->getStartLine();
        $this->open($stmt->getStartFilePos(), "__dbg($line, get_defined_vars());");
        $this->hookLines[$line] = true;
    }

    /** @return int[] __dbg を挿入した行番号（昇順） */
    public function hookLines(): array
    {
        $lines = array_keys($this->hookLines);
        sort($lines);
        return $lines;
    }

    /** 挿入を反映したコードを返す */
    public function apply(): string
    {
        // 後ろから挿入すると前方の位置がずれない
        $inserts = $this->inserts;
        krsort($inserts);
        $code = $this->code;
        foreach ($inserts as $pos => $strs) {
            $code = substr_replace($code, implode('', $strs), $pos, 0);
        }
        return $code;
    }
}
