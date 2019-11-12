<?php

namespace PhpToZephir\Converter\Printer\Stmt;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpToZephir\Converter\Dispatcher;
use PhpToZephir\Converter\Printer;
use PhpToZephir\Logger;
use PhpToZephir\NodeFetcher;
use PhpToZephir\ReservedWordReplacer;
use PhpToZephir\TypeFinder;

class ClassMethodPrinter
{
    /**
     * @var Dispatcher
     */
    private $dispatcher = null;
    /**
     * @var Logger
     */
    private $logger = null;
    /**
     * @var ReservedWordReplacer
     */
    private $reservedWordReplacer = null;
    /**
     * @var TypeFinder
     */
    private $typeFinder = null;
    /**
     * @var NodeFetcher
     */
    private $nodeFetcher = null;
    /**
     * @var string
     */
    private $lastMethod = null;
    private $globalVarNames = [
        'this',
        '_SERVER',
        '_GET',
        '_POST',
        '_FILES',
        '_COOKIE',
        '_SESSION',
        '_REQUEST',
        '_ENV',
    ];

    /**
     * @param Dispatcher           $dispatcher
     * @param Logger               $logger
     * @param ReservedWordReplacer $reservedWordReplacer
     * @param TypeFinder           $typeFinder
     * @param NodeFetcher          $nodeFetcher
     */
    public function __construct(
        Dispatcher $dispatcher,
        Logger $logger,
        ReservedWordReplacer $reservedWordReplacer,
        TypeFinder $typeFinder,
        NodeFetcher $nodeFetcher
    ) {
        $this->dispatcher           = $dispatcher;
        $this->logger               = $logger;
        $this->reservedWordReplacer = $reservedWordReplacer;
        $this->typeFinder           = $typeFinder;
        $this->nodeFetcher          = $nodeFetcher;
    }

    /**
     * @param string $value
     */
    public function setLastMethod($value)
    {
        $this->lastMethod = $value;
    }

    /**
     * @return string
     */
    public static function getType()
    {
        return 'pStmt_ClassMethod';
    }

    /**
     * @param Stmt\ClassMethod $node
     *
     * @return string
     */
    public function convert(Stmt\ClassMethod $node)
    {

        $types = $this->typeFinder->getTypes(
            $node,
            $this->dispatcher->getMetadata()
        );
        foreach ($node->params as $param) {
            if ($param->byRef === true) {
                $this->logger->logIncompatibility(
                    'reference',
                    sprintf('Reference not supported in parametter (var "%s")', $param->name),
                    $param,
                    $this->dispatcher->getMetadata()
                        ->getClass()
                );
            }
        }

        if ($node->byRef) {
            $this->logger->logIncompatibility(
                'reference',
                'Reference not supported',
                $node,
                $this->dispatcher->getMetadata()
                    ->getClass()
            );
        }

        $this->dispatcher->setLastMethod($node->name);

        $stmt             = $this->dispatcher->pModifiers($node->flags) . 'function ' . $node->name . '(';
        $varsInMethodSign = [];

        if (isset($types['params']) === true) {
            $params = [];
            foreach ($types['params'] as $type) {
                $varsInMethodSign[] = $type['name'];
                $stringType         = $this->printType($type);
                $params[]           = ((!empty($stringType)) ? $stringType . ' ' : '') . '' . $type['name'] . (($type['default'] === null) ? '' : ' = ' . $this->dispatcher->p($type['default']));
            }

            $stmt .= implode(', ', $params);
        }

        $stmt .= ')';
        $stmt .= $this->printReturn($node, $types);

        $stmt .= (null !== $node->stmts ? "\n{" . $this->printVars($node, $varsInMethodSign) .
                $this->dispatcher->pStmts($node->stmts) . "\n}" : ';') . "\n";

        return $stmt;
    }

    /**
     * @param Stmt\ClassMethod $node
     * @param array            $varsInMethodSign
     *
     * @return string
     */
    private function printVars(Stmt\ClassMethod $node, array $varsInMethodSign)
    {
        $var = '';

        $vars = array_diff(array_unique(array_filter($this->collectVars($node))), $varsInMethodSign);
        if (!empty($vars)) {
            $var .= "\n    var " . implode(', ', $vars) . ";\n";
        }

        // dirty...
        Printer\Expr\ArrayDimFetchPrinter::resetCreatedVars();

        return $var;
    }

    /**
     * @param Stmt\ClassMethod $node
     * @param array            $types
     *
     * @return string
     */
    private function printReturn(Stmt\ClassMethod $node, array $types)
    {
        $stmt = '';

        $returnType = $node->getReturnType();
        $nullAble   = false;
        if (trim($node->name) !== '__construct') {
            if ($returnType !== null) {

                if ($returnType instanceof NullableType) {
                    $nullAble   = true;
                    $returnType = $returnType->type;
                }
                if ($returnType instanceof Identifier) {
                    $stmt .= ' -> ' . $returnType->name;
                } elseif ($returnType instanceof FullyQualified) {
                    $stmt .= ' -> ' . '<\\' . implode('\\', $returnType->parts) . '>';
                } elseif ($returnType instanceof Name) {
                    $stmt .= ' -> ' . '<' . implode('\\', $returnType->parts) . '>';
                } elseif (array_key_exists('return', $types) === true && $this->hasReturnStatement($node) === true) {
                    $returns = $this->prepareReturnTypes($types);
                    if (count($returns) > 0) {
                        $stmt .= ' -> ' . implode(' | ', $returns);
                    }
                } elseif (array_key_exists('return', $types) === false && $this->hasReturnStatement($node) === false) {
                    // $stmt     .= ' -> void';
                    $nullAble = false;
                }
                if ($nullAble) {
                    $stmt .= ' | null';
                }
            } else {
                if (array_key_exists('return', $types) === true && $this->hasReturnStatement($node) === true) {
                    $returns = $this->prepareReturnTypes($types);
                    if (count($returns) > 0) {
                        $stmt .= ' -> ' . implode(' | ', $returns);
                    }
                } elseif (array_key_exists('return', $types) === false && $this->hasReturnStatement($node) === false) {
                    // $stmt .= ' -> void';
                } elseif (array_key_exists('return', $types) === true) {
                    $returns = $this->prepareReturnTypes($types);
                    if (count($returns) > 0) {
                        $stmt .= ' -> ' . implode(' | ', $returns);
                    }
                }
            }
        }

        return $stmt;
    }

    /**
     * @param Stmt\ClassMethod $nodes
     *
     * @return bool
     */
    private function hasReturnStatement(
        $node
    ) {
        $nodeFinder = new NodeFinder();

        return $nodeFinder->findFirstInstanceOf([$node], Stmt\Return_::class) !== null;
    }

    /**
     * @param \ArrayIterator|array $node
     * @param array                $vars
     *
     * @return \ArrayIterator|array
     */
    private function collectVars(
        $node,
        array $vars = []
    ) {
        $noFetcher = new NodeFetcher();

        foreach ($noFetcher->foreachNodes($node) as &$stmt) {
            if ($stmt['node'] instanceof Expr\Assign) {
                if (($stmt['node']->var instanceof Expr\PropertyFetch) === false
                    && ($stmt['node']->var instanceof Expr\StaticPropertyFetch) === false
                    && ($stmt['node']->var instanceof Expr\ArrayDimFetch) === false
                    && ($stmt['node']->var instanceof Expr\List_) === false
                ) {
                    if (is_object($stmt['node']->var->name) === false) { // if true it is a dynamic var

                        $vars[] = $stmt['node']->var->name;
                    }
                } elseif (($stmt['node']->var instanceof Expr\List_) === true) {
                    $varInList = [];
                    foreach ($stmt['node']->var->items as $var) {

                        if (null !== $var) {
                            $varInList[] = ucfirst($this->dispatcher->p($var));
                            if (($var instanceof Expr\ArrayDimFetch) === false) {
                                $vars[] = $this->dispatcher->p($var);
                            }
                        }
                    }

                    $vars[] = 'tmpList' . str_replace(['[', ']', '"'], '', implode('', $varInList));
                }
            } elseif ($stmt['node'] instanceof Expr\Variable) {

                if ($stmt['node']->name) {
                    if (is_string($stmt['node']->name)) {
                        $name = $this->removeGlobalVars($stmt['node']->name);
                        if ($name !== '') {
                            $vars[] = $name;
                        }
                    }
                }
            } elseif ($stmt['node'] instanceof Stmt\Foreach_) {
                if (null !== $stmt['node']->keyVar) {
                    $vars[] = $stmt['node']->keyVar->name;
                }
                $vars[] = $stmt['node']->valueVar->name;
            } elseif ($stmt['node'] instanceof Stmt\For_) {
                foreach ($stmt['node']->init as $init) {
                    if ($init instanceof Expr\Assign) {
                        $vars[] = $init->var->name;
                    }
                }
            } elseif ($stmt['node'] instanceof Stmt\If_) {
                foreach ($this->nodeFetcher->foreachNodes($stmt['node']->cond) as $nodeData) {
                    $node = $nodeData['node'];
                    if ($node instanceof Expr\Array_) {
                        $vars[] = 'tmpArray' . md5(serialize($node->items));
                    }
                }
            } elseif ($stmt['node'] instanceof Stmt\Catch_) {
                if (is_string($stmt['node']->name)) {
                    
                    $vars[] = $stmt['node']->name;
                } else {
                    $vars[] = $stmt['node']->var->name;
                }
            } elseif ($stmt['node'] instanceof Stmt\Return_ && $stmt['node']->expr instanceof Expr\Array_) {
                $vars[] = 'tmpArray' . md5(serialize($stmt['node']->expr->items));
            } elseif ($stmt['node'] instanceof Stmt\Static_) {
                foreach ($stmt['node']->vars as $var) {
                    $vars[] = $var->name;
                }
            } elseif ($stmt['node'] instanceof Arg && $stmt['node']->value instanceof Expr\Array_) {
                $vars[] = 'tmpArray' . md5(serialize($stmt['node']->value->items));
            }

            if ($stmt['node'] instanceof Expr\ArrayDimFetch && !in_array("PhpParser\Node\Expr\ArrayDimFetch",
                    $stmt['parentClass'])
            ) {
                $varCreatedInArray = $this->dispatcher->pExpr_ArrayDimFetch($stmt['node'], true);
                foreach ($varCreatedInArray['vars'] as $var) {
                    $vars[] = $var;
                }
            }
        }
        if (count($vars) > 0) {
            $vars = array_map([$this->reservedWordReplacer, 'replace'], $vars);
        }

        return $vars;
    }

    /**
     * @param array $type
     *
     * @return string
     * @throws \Exception
     *
     */
    private function printType(
        $type
    ) {
        if (isset($type['type']) === false) {
            return '';
        }
        if (isset($type['type']['isClass']) === false) {
            throw new \Exception('isClass not found');
        }
        if (isset($type['type']['value']) === false) {
            throw new \Exception('value not found');
        }

        return ($type['type']['isClass'] === true) ? '<' . $type['type']['value'] . '>' : $type['type']['value'];
    }

    /**
     * @param array $types
     *
     * @return array
     */
    private function prepareReturnTypes(
        $types
    ) {
        $returns = [];
        if (is_array($types['return']['type'])) {
            foreach ($types['return']['type'] as $return) {
                if (!empty($return['value'])) {
                    if ($return['isClass']) {
                        $returns[] = '<' . $return['value'] . '>';
                    } else {
                        $returns[] = $return['value'];
                    }
                }
            }
            $returns = array_unique($returns);
        }

        return $returns;
    }

    private function removeGlobalVars($name)
    {
        if (in_array($name, $this->globalVarNames)) {
            return '';
        }

        return $name;
    }
}

