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
        $parser = (new ParserFactory)->createForHostVersion();
        $ast = $parser->parse($code);
        $inserts = []; // [filePos => 挿入文字列]

        $visitor = new class($inserts, $code, $parser->getTokens()) extends NodeVisitorAbstract {
            /** @param \PhpParser\Token[] $tokens */
            public function __construct(private array &$inserts, private string $code, private array $tokens) {}

            /** 開く側の挿入：外側のノードが先に訪問されるので、後ろに足すと外→内の順になる */
            private function open(int $pos, string $s): void
            {
                $this->inserts[$pos][] = $s;
            }

            /** 閉じる側の挿入：内側のノードが後から訪問されるので、前に足すと内→外の順になる */
            private function close(int $pos, string $s): void
            {
                $this->inserts[$pos] ??= [];
                array_unshift($this->inserts[$pos], $s);
            }

            /**
             * 制御構文の本体が {} なしか（`if ($x) echo 1;` など）
             * 本体の最初の文の直前にある（空白・コメント以外の）トークンが `{` でも `:`（代替構文）でもなければ {} なし
             */
            private function isBraceless(Node $node): bool
            {
                $i = $node->stmts[0]->getStartTokenPos() - 1;
                while ($i >= 0 && $this->tokens[$i]->isIgnorable()) {
                    $i--;
                }
                return $i < 0 || !in_array($this->tokens[$i]->text, ['{', ':'], true);
            }

            public function enterNode(Node $node)
            {
                // (b) 関数本体を enter / try-finally leave で包む
                //     最初の文の __dbg より前に enter を入れたいので、(a) より先に処理する
                //     クロージャも同じように包む（アロー関数は本体に文がないので対象外）
                if ($node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassMethod || $node instanceof Node\Expr\Closure) {
                    if ($node->stmts === null) return; // abstract
                    $name  = $node instanceof Node\Expr\Closure ? '{closure}' : $node->name->toString();
                    // 本体の { は、引数・use・戻り値の型より後ろにある最初の {
                    $from  = $node->getStartFilePos();
                    foreach ([...$node->params, ...($node instanceof Node\Expr\Closure ? $node->uses : []), $node->returnType] as $part) {
                        if ($part !== null) {
                            $from = max($from, $part->getEndFilePos());
                        }
                    }
                    $open  = strpos($this->code, '{', $from);       // 本体の {
                    $close = $node->getEndFilePos();                // 本体の }
                    $this->open($open + 1, "__dbg_enter('$name', {$node->getStartLine()}); try {");
                    $this->close($close, "} finally { __dbg_leave(); }");
                }

                // (c) {} なしの制御構文の本体を {} で包む
                //     `if ($x) echo "A";` に __dbg を入れると `if ($x) __dbg(...);echo "A";` になり、
                //     echo が条件に関係なく実行されてしまうため
                if (($node instanceof Node\Stmt\If_
                        || $node instanceof Node\Stmt\ElseIf_
                        || $node instanceof Node\Stmt\Else_
                        || $node instanceof Node\Stmt\For_
                        || $node instanceof Node\Stmt\Foreach_
                        || $node instanceof Node\Stmt\While_
                        || $node instanceof Node\Stmt\Do_)
                    && $node->stmts !== []
                    && $this->isBraceless($node)) {
                    $this->open($node->stmts[0]->getStartFilePos(), '{');
                    $this->close(end($node->stmts)->getEndFilePos() + 1, '}');
                }

                // (a) 「文のリスト」を持つノードの各文の前に __dbg を挿入
                //     クラスの本体（プロパティ・定数・メソッドの宣言）は実行される文ではないので入れない
                if (property_exists($node, 'stmts') && is_array($node->stmts) && !$node instanceof Node\Stmt\ClassLike) {
                    foreach ($node->stmts as $stmt) {
                        if ($stmt instanceof Node\Stmt\Function_
                            || $stmt instanceof Node\Stmt\ClassLike
                            || $stmt instanceof Node\Stmt\ClassMethod
                            || $stmt instanceof Node\Stmt\Declare_
                            || $stmt instanceof Node\Stmt\Nop) {
                            continue; // 宣言系は「実行される文」じゃないのでスキップ
                        }
                        $line = $stmt->getStartLine();
                        $this->open($stmt->getStartFilePos(), "__dbg($line, get_defined_vars());");
                    }
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
