<?php
declare(strict_types=1);

namespace MiniXDebug\Service;

use PhpParser\{Node, NodeTraverser, NodeVisitorAbstract, ParserFactory};

class Rewriter
{
    /** @var int[] 直近の rewrite() で __dbg を挿入した行番号（昇順） */
    public array $hookLines = [];

    public function __construct(
        private string $tempDir = '/tmp'
    ) {}

    /**
     * @return string 書き換え後のファイル内容
     */
    public function rewrite(string $file): string
    {
        $code = file_get_contents($file);

        return $this->_rewrite($code);
    }

    protected function _rewrite(string $code): string
    {
        $ast = (new ParserFactory)->createForHostVersion()->parse($code);
        $inserts = []; // [filePos => 挿入文字列]

        $visitor = new class($inserts, $code) extends NodeVisitorAbstract {
            public function __construct(private array &$inserts, private string $code) {}

            public function enterNode(Node $node)
            {
                // (a) 「文のリスト」を持つノードの各文の前に __dbg を挿入
                if (property_exists($node, 'stmts') && is_array($node->stmts)) {
                    foreach ($node->stmts as $stmt) {
                        if ($stmt instanceof Node\Stmt\Function_
                            || $stmt instanceof Node\Stmt\ClassLike
                            || $stmt instanceof Node\Stmt\ClassMethod
                            || $stmt instanceof Node\Stmt\Declare_
                            || $stmt instanceof Node\Stmt\Nop) {
                            continue; // 宣言系は「実行される文」じゃないのでスキップ
                        }
                        $line = $stmt->getStartLine();
                        $this->inserts[$stmt->getStartFilePos()][] =
                            "__dbg($line, get_defined_vars());";
                    }
                }
                // (b) 関数本体を enter / try-finally leave で包む
                if ($node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassMethod) {
                    if ($node->stmts === null) return; // abstract
                    $name  = $node->name->toString();
                    $from  = $node->params ? end($node->params)->getEndFilePos() : $node->getStartFilePos();
                    $open  = strpos($this->code, '{', $from);       // 本体の {
                    $close = $node->getEndFilePos();                // 本体の }
                    $this->inserts[$open + 1][] = "__dbg_enter('$name', {$node->getStartLine()}); try {";
                    $this->inserts[$close][]    = "} finally { __dbg_leave(); }";
                }
            }
        };

        // ルート（ファイル直下）の文も対象にするため、ダミーの親で包む
        $root = new class($ast) extends Node\Stmt { 
            public function __construct(public array $stmts) { parent::__construct(); }
            public function getSubNodeNames(): array { return ['stmts']; }
            public function getType(): string { return 'Root'; }
        };
        $t = new NodeTraverser();
        $t->addVisitor($visitor);
        $t->traverse([$root]);

        // ブレークポイントをずらすために、フックを入れた行の一覧を残す
        $lines = [];
        foreach ($inserts as $strs) {
            foreach ($strs as $s) {
                if (preg_match('/^__dbg\((\d+),/', $s, $m)) {
                    $lines[(int) $m[1]] = true;
                }
            }
        }
        $this->hookLines = array_keys($lines);
        sort($this->hookLines);

        // 後ろから挿入すると前方の位置がずれない
        krsort($inserts);
        foreach ($inserts as $pos => $strs) {
            $code = substr_replace($code, implode('', $strs), $pos, 0);
        }
        return $code;
    }
}
