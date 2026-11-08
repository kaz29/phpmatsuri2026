<?php
declare(strict_types=1);

namespace MiniXDebug\Service\Rewrite;

use PhpParser\Node;

/**
 * {} なしの制御構文の本体を {} で包む
 * `if ($x) echo "A";` に __dbg を入れると `if ($x) __dbg(...);echo "A";` になり、
 * echo が条件に関係なく実行されてしまうため
 */
class WrapBraceless implements RewriteInterface
{
    public function rewrite(Node $node, RewriteContext $ctx): void
    {
        if ($node->stmts === [] || !$this->isBraceless($node, $ctx)) return;

        $ctx->open($node->stmts[0]->getStartFilePos(), '{');
        $ctx->close(end($node->stmts)->getEndFilePos() + 1, '}');
    }

    /**
     * 本体が {} なしか（`if ($x) echo 1;` など）
     * 本体の最初の文の直前にある（空白・コメント以外の）トークンが `{` でも `:`（代替構文）でもなければ {} なし
     */
    private function isBraceless(Node $node, RewriteContext $ctx): bool
    {
        $i = $node->stmts[0]->getStartTokenPos() - 1;
        while ($i >= 0 && $ctx->tokens[$i]->isIgnorable()) {
            $i--;
        }
        return $i < 0 || !in_array($ctx->tokens[$i]->text, ['{', ':'], true);
    }
}
