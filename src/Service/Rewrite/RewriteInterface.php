<?php
declare(strict_types=1);

namespace MiniXDebug\Service\Rewrite;

use PhpParser\Node;

/**
 * ノード1つに対する書き換えの処理
 * どのノードにどの処理をかけるかは Rewriter の対応表で決める
 */
interface RewriteInterface
{
    public function rewrite(Node $node, RewriteContext $ctx): void;
}
