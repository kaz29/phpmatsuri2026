<?php
declare(strict_types=1);

namespace MiniXDebug\Service;

use MiniXDebug\Service\Rewrite\{HookStmts, RewriteContext, RewriteInterface, WrapBraceless, WrapFunctionBody};
use PhpParser\{Node, NodeTraverser, NodeVisitorAbstract, ParserFactory};
use PhpParser\Node\{Expr, Stmt};

class Rewriter
{
    /** @var int[] 直近の rewrite() で __dbg を挿入した行番号（昇順） */
    public array $hookLines = [];

    /**
     * ノードのクラス => かける処理のリスト
     * 配列の順番が処理の順番（同じ位置に挿入するときの並びが決まるので大事）
     * @var array<class-string<Node>, RewriteInterface[]>
     */
    private array $rules;

    /** 表にないノードで stmts を持つものにかける処理 */
    private RewriteInterface $default;

    public function __construct(
        private string $tempDir = '/tmp'
    ) {
        $fnBody = new WrapFunctionBody();
        $braces = new WrapBraceless();
        $stmts  = new HookStmts();
        $this->default = $stmts;

        $this->rules = [
            Stmt\Function_::class   => [$fnBody, $stmts],
            Stmt\ClassMethod::class => [$fnBody, $stmts],
            Expr\Closure::class     => [$fnBody, $stmts],
            Stmt\If_::class         => [$braces, $stmts],
            Stmt\ElseIf_::class     => [$braces, $stmts],
            Stmt\Else_::class       => [$braces, $stmts],
            Stmt\For_::class        => [$braces, $stmts],
            Stmt\Foreach_::class    => [$braces, $stmts],
            Stmt\While_::class      => [$braces, $stmts],
            Stmt\Do_::class         => [$braces, $stmts],
            Stmt\ClassLike::class   => [], // クラスの本体（プロパティ・定数・メソッドの宣言）は実行される文ではない
        ];
    }

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
        $ctx = new RewriteContext($code, $parser->getTokens());

        $visitor = new class($this, $ctx) extends NodeVisitorAbstract {
            public function __construct(private Rewriter $rewriter, private RewriteContext $ctx) {}

            public function enterNode(Node $node)
            {
                foreach ($this->rewriter->rulesFor($node) as $rule) {
                    $rule->rewrite($node, $this->ctx);
                }
                return null;
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
        $this->hookLines = $ctx->hookLines();

        return $ctx->apply();
    }

    /**
     * ノードにかける処理を対応表から引く
     * クラス自身 → 親クラスの順に見る（ClassLike の行で Class_ / Interface_ / Trait_ / Enum_ をまとめて扱うため）
     * @return RewriteInterface[]
     */
    public function rulesFor(Node $node): array
    {
        foreach ([$node::class, ...array_values(class_parents($node))] as $class) {
            if (isset($this->rules[$class])) {
                return $this->rules[$class];
            }
        }
        return property_exists($node, 'stmts') && is_array($node->stmts) ? [$this->default] : [];
    }
}
