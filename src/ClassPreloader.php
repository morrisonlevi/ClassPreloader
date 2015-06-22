<?php

/*
 * This file is part of Class Preloader.
 *
 * (c) Graham Campbell <graham@cachethq.io>
 * (c) Michael Dowling <mtdowling@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ClassPreloader;

use ClassPreloader\Parser\NodeTraverser;
use PhpParser\Node\Stmt\Namespace_ as NamespaceNode;
use PhpParser\Parser;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;
use RuntimeException;

/**
 * This is the class preloader class.
 *
 * This is the main point of entry for interacting with this package.
 */
class ClassPreloader
{
    /**
     * The printer.
     *
     * @var \PhpParser\PrettyPrinter\Standard
     */
    protected $printer;

    /**
     * The parser.
     *
     * @var \PhpParser\Parser
     */
    protected $parser;

    /**
     * The traverser.
     *
     * @var \ClassPreloader\Parser\NodeTraverser
     */
    protected $traverser;

    /**
     * Create a new class preloader.
     *
     * @param \PhpParser\PrettyPrinter\Standard|null $printer
     * @param \PhpParser\Parser|null                 $parser
     * @param \ClassPreloader\Parser\NodeTraverser   $traverser
     *
     * @return void
     */
    public function __construct(PrettyPrinter $printer, Parser $parser, NodeTraverser $traverser)
    {
        $this->printer = $printer;
        $this->parser = $parser;
        $this->traverser = $traverser;
    }

    /**
     * Prepare the output file and directory.
     *
     * @param string $output
     *
     * @throws \RuntimeException
     *
     * @return resource
     */
    public function prepareOutput($output)
    {
        $dir = dirname($output);

        if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
            throw new RuntimeException("Unable to create directory $dir");
        }

        $handle = fopen($output, 'w');

        if (!$handle) {
            throw new RuntimeException("Unable to open $output for writing");
        }

        fwrite($handle, "<?php\n");

        return $handle;
    }

    /**
     * Get a pretty printed string of code from a file while applying visitors.
     *
     * @param string $file
     *
     * @throws \RuntimeException
     *
     * @return string
     */
    public function getCode($file, $comments = true)
    {
        if (!is_readable($file)) {
            throw new RuntimeException("Cannot open {$file} for reading");
        }

        if ($comments) {
            $content = file_get_contents($file);
        } else {
            $content = php_strip_whitespace($file);
        }

        $parsed = $this->parser->parse($content);
        $stmts = $this->traverser->traverseFile($parsed, $file);
        $pretty = $this->printer->prettyPrint($stmts);

        // Remove the open PHP tag
        if (substr($pretty, 5) === '<?php') {
            $pretty = substr($pretty, 7);
        }

        return $this->getCodeWrappedIntoNamespace($parsed, $pretty);
    }

    /**
     * Wrap the code into a namespace.
     *
     * @param array  $parsed
     * @param string $pretty
     *
     * @return string
     */
    protected function getCodeWrappedIntoNamespace(array $parsed, $pretty)
    {
        if ($this->parsedCodeHasNamespaces($parsed)) {
            $pretty = preg_replace('/^\s*(namespace.*);/i', '${1} {', $pretty, 1)."\n}\n";
        } else {
            $pretty = sprintf("namespace {\n%s\n}\n", $pretty);
        }

        return preg_replace('/(?<!.)[\r\n]+/', '', $pretty);
    }

    /**
     * Check parsed code for having namespaces.
     *
     * @param array $parsed
     *
     * @return bool
     */
    protected function parsedCodeHasNamespaces(array $parsed)
    {
        // Namespaces can only be on first level in the code,
        // so we make only check on it.
        $node = array_filter(
            $parsed,
            function ($value) {
                return $value instanceof NamespaceNode;
            }
        );

        return !empty($node);
    }
}
