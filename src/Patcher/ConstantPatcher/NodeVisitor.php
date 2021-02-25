<?php

declare(strict_types=1);

/**
 * Part of ci-phpunit-test
 *
 * @link       https://github.com/kenjis/ci-phpunit-test
 */

namespace Kenjis\MonkeyPatch\Patcher\ConstantPatcher;

use Kenjis\MonkeyPatch\Patcher\ConstantPatcher;
use PhpParser\Node;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\NodeVisitorAbstract;

use function call_user_func_array;
use function is_callable;
use function ucfirst;

class NodeVisitor extends NodeVisitorAbstract
{
    private $disable_const_rewrite_level = 0;

    public function enterNode(Node $node): void
    {
        $callback = [$this, 'before' . ucfirst($node->getType())];
        if (is_callable($callback)) {
            call_user_func_array($callback, [$node]);
        }
    }

    public function leaveNode(Node $node): void
    {
        if (! ($node instanceof ConstFetch)) {
            $callback = [$this, 'rewrite' . ucfirst($node->getType())];
            if (is_callable($callback)) {
                call_user_func_array($callback, [$node]);
            }

            return;
        }

        if ($this->disable_const_rewrite_level > 0) {
            return;
        }

        if (! ($node->name instanceof Name)) {
            return;
        }

        if (! $node->name->isUnqualified()) {
            return;
        }

        if (! ConstantPatcher::isBlacklisted((string) $node->name)) {
            $replacement = new FullyQualified('__ConstProxy__::get(\'' . (string) $node->name . '\')');
            $node->name = $replacement;
        }
    }

    /**
     * The following logic is from:
     * <https://github.com/badoo/soft-mocks/blob/06fe26a2c9ab4cae17b88648439952ab0586438f/src/QA/SoftMocks.php#L1572>
     * Thank you.
     *
     * The MIT License (MIT)
     * Copyright (c) 2016 Badoo Development
     */
    // Cannot rewrite constants that are used as default values in function arguments
    public function beforeParam(): void
    {
        $this->disable_const_rewrite_level++;
    }

    public function rewriteParam(): void
    {
        $this->disable_const_rewrite_level--;
    }

    // Cannot rewrite constants that are used as default values in constant declarations
    public function beforeConst(): void
    {
        $this->disable_const_rewrite_level++;
    }

    public function rewriteConst(): void
    {
        $this->disable_const_rewrite_level--;
    }

    // Cannot rewrite constants that are used as default values in property declarations
    public function beforeStmt_PropertyProperty(): void
    {
        $this->disable_const_rewrite_level++;
    }

    public function rewriteStmt_PropertyProperty(): void
    {
        $this->disable_const_rewrite_level--;
    }

    // Cannot rewrite constants that are used as default values in static variable declarations
    public function beforeStmt_StaticVar(): void
    {
        $this->disable_const_rewrite_level++;
    }

    public function rewriteStmt_StaticVar(): void
    {
        $this->disable_const_rewrite_level--;
    }
}
