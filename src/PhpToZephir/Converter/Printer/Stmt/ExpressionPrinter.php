<?php

namespace PhpToZephir\Converter\Printer\Stmt;

use PhpParser\Node\Stmt;
use PhpToZephir\Converter\SimplePrinter;

class ExpressionPrinter extends SimplePrinter
{
    /**
     * @return string
     */
    public static function getType()
    {
        return 'pStmt_Expression';
    }

    /**
     * @param Stmt\Case_ $node
     *
     * @return string
     */
    public function convert($node)
    {
        return $this->dispatcher->p($node->expr) . ';';
    }
}
