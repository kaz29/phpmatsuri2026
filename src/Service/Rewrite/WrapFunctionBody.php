<?php
declare(strict_types=1);

namespace MiniXDebug\Service\Rewrite;

use PhpParser\Node;

/**
 * 関数・メソッド・クロージャの本体を enter / try-finally leave で包む
 * 最初の文の __dbg より前に enter を入れたいので、HookStmts より先に処理する
 */
class WrapFunctionBody implements RewriteInterface
{
    public function rewrite(Node $node, RewriteContext $ctx): void
    {
        assert($node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassMethod || $node instanceof Node\Expr\Closure);
        if ($node->stmts === null) return; // abstract

        $name = $node instanceof Node\Expr\Closure ? '{closure}' : $node->name->toString();

        // 本体の { は、引数・use・戻り値の型より後ろにある最初の {
        $from = $node->getStartFilePos();
        $uses = $node instanceof Node\Expr\Closure ? $node->uses : [];
        foreach ([...$node->params, ...$uses, $node->returnType] as $part) {
            if ($part !== null) {
                $from = max($from, $part->getEndFilePos());
            }
        }
        $open  = strpos($ctx->code, '{', $from); // 本体の {
        $close = $node->getEndFilePos();         // 本体の }
        $ctx->open($open + 1, "__dbg_enter('$name', {$node->getStartLine()}); try {");
        $ctx->close($close, "} finally { __dbg_leave(); }");
    }
}
