<?php

declare(strict_types=1);

/**
 * Part of ci-phpunit-test
 *
 * @link       https://github.com/kenjis/ci-phpunit-test
 */

namespace Kenjis\MonkeyPatch\Patcher;

require __DIR__ . '/ConstantPatcher/NodeVisitor.php';
require __DIR__ . '/ConstantPatcher/Proxy.php';

use Kenjis\MonkeyPatch\MonkeyPatchManager;
use Kenjis\MonkeyPatch\Patcher\ConstantPatcher\NodeVisitor;
use PhpParser\Lexer;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter;

use function in_array;
use function strtolower;

class ConstantPatcher extends AbstractPatcher
{
    /** @var string[] special constant names which we don't patch */
    private static $blacklist = [
        'true',
        'false',
        'null',
    ];
    public static $replacement;

    public function __construct()
    {
        $this->node_visitor = new NodeVisitor();
    }

    /**
     * @param string $name constant name
     */
    public static function isBlacklisted(string $name): bool
    {
        if (in_array(strtolower($name), self::$blacklist)) {
            return true;
        }

        return false;
    }

    public function patch(string $source): array
    {
        $patched = false;

        $parser = (new ParserFactory())
            ->create(
                MonkeyPatchManager::getPhpParser(),
                new Lexer(
                    ['usedAttributes' => ['startTokenPos', 'endTokenPos']]
                )
            );
        $traverser = new NodeTraverser();
        $traverser->addVisitor($this->node_visitor);

        $ast_orig = $parser->parse($source);
        $prettyPrinter = new PrettyPrinter\Standard();
        $source_ = $prettyPrinter->prettyPrintFile($ast_orig);

        $ast = $parser->parse($source);
        $traverser->traverse($ast);

        $new_source = $prettyPrinter->prettyPrintFile($ast);

        if ($source_ !== $new_source) {
            $patched = true;
        }

        return [
            $new_source,
            $patched,
        ];
    }
}
