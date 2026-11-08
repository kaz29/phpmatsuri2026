<?php
declare(strict_types=1);

namespace MiniXDebug\Service\Rewrite;

use PhpParser\Node;

/**
 * 「文のリスト」を持つノードの各文の前に __dbg を挿入する
 */
class HookStmts implements RewriteInterface
{
    public function rewrite(Node $node, RewriteContext $ctx): void
    {
        if (!property_exists($node, 'stmts') || !is_array($node->stmts)) return;

        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Function_
                || $stmt instanceof Node\Stmt\ClassLike
                || $stmt instanceof Node\Stmt\ClassMethod
                || $stmt instanceof Node\Stmt\Declare_
                || $stmt instanceof Node\Stmt\Namespace_
                || $stmt instanceof Node\Stmt\Nop) {
                // 宣言系は「実行される文」じゃないのでスキップ
                // namespace は最初の文でないとエラーになるので、前に __dbg を入れてはいけない（中の文には入る）
                continue;
            }
            $ctx->hook($stmt);
        }
    }
}
