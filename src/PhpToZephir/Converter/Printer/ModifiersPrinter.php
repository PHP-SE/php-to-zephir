<?php
namespace PhpToZephir\Converter\Printer;

use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpToZephir\Converter\SimplePrinter;

class ModifiersPrinter extends SimplePrinter
{
    /**
     * @return string
     */
    public static function getType()
    {
        return 'pModifiers';
    }

    /**
     * @param int $modifiers
     *
     * @return string
     */
    public function convert($flags)
    {
        $strs = [];
        if ($flags & Class_::MODIFIER_PUBLIC) {
            $strs[] = 'public';
        }
        if ($flags & Class_::MODIFIER_PROTECTED) {
            $strs[] = 'protected';
        }
        if ($flags & Class_::MODIFIER_PRIVATE) {
            $strs[] = 'private';
        }
        if ($flags & Class_::MODIFIER_ABSTRACT) {
            $strs[] = 'abstract';
        }
        if ($flags & Class_::MODIFIER_STATIC) {
            $strs[] = 'static';
        }
        if ($flags & Class_::MODIFIER_FINAL) {
            $strs[] = 'final';
        }

        if (count($strs)) {
            return implode(' ', $strs).' ';
        }

        return '';
    }
}