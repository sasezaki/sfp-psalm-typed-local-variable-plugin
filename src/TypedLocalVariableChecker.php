<?php
declare(strict_types=1);

namespace Sfp\Psalm\TypedLocalVariablePlugin;

use PhpParser;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\FileManipulation;
use Psalm\Internal\Analyzer\Statements\Expression\SimpleTypeInferer;
use Psalm\IssueBuffer;
use Psalm\Plugin\Hook\AfterExpressionAnalysisInterface;
use Psalm\Plugin\Hook\AfterFunctionLikeAnalysisInterface;
use Psalm\StatementsSource;
use Psalm\Storage\FunctionLikeStorage;
use Psalm\Type\Union;

final class TypedLocalVariableChecker implements AfterExpressionAnalysisInterface, AfterFunctionLikeAnalysisInterface

{
    /** @var array<string, \Psalm\Type\Union> */
    private static $initializeContextVars;

    public static function afterStatementAnalysis(
        PhpParser\Node\FunctionLike $stmt,
        FunctionLikeStorage $classlike_storage,
        StatementsSource $statements_source,
        Codebase $codebase,
        array &$file_replacements = []
    ) {
        self::$initializeContextVars = [];
    }

    /**
     * Called after an expression has been checked
     *
     * @param  PhpParser\Node\Expr  $expr
     * @param  Context              $context
     * @param  FileManipulation[]   $file_replacements
     *
     * @return null|false
     */
    public static function afterExpressionAnalysis(
        PhpParser\Node\Expr $expr,
        Context $context,
        StatementsSource $statements_source,
        Codebase $codebase,
        array &$file_replacements = []
    ) {

        if ($expr instanceof PhpParser\Node\Expr\Assign && $expr->var instanceof PhpParser\Node\Expr\Variable) {

            if (isset($context->vars_in_scope['$'.$expr->var->name])) {
                // hold variable initialize type
                if (! isset(self::$initializeContextVars[$expr->var->name])) {

                    if ($context->calling_method_id) {
                        $method_id = new \Psalm\Internal\MethodIdentifier(...explode('::', $context->calling_method_id));
                        foreach ($codebase->methods->getStorage($method_id)->params as $param) {
                            self::$initializeContextVars[$param->name] = $param->type;
                        }
                    }

                    if (! isset(self::$initializeContextVars[$expr->var->name])) {
                        self::$initializeContextVars[$expr->var->name] = $context->vars_in_scope['$'.$expr->var->name];
                    }
                }


                $varInScope = self::$initializeContextVars[$expr->var->name];

                $originalTypes = [];
                foreach ($varInScope->getAtomicTypes() as $atomicType) {
                    $originalTypes[] = (string)$atomicType;
                }


                $assignType = self::analyzeAssignmentType($expr, $codebase, $statements_source);

                if (!$assignType) {
                    // could not analyzed
                    return null;
                }

                $type_matched = false;
                $atomicTypes = [];
                foreach ($assignType->getAtomicTypes() as $k => $atomicType) {
                    if ($atomicType->isObjectType()) {
                        $class = (string) $atomicType;
                        foreach ($originalTypes as $originalType) {
                            if ($class === $originalType) {
                                $type_matched = true;
                                break;
                            }

                            if ($codebase->interfaceExists($originalType)) {
                                $atomicTypes[] = $class;
                                if ((new \ReflectionClass($class))->isSubclassOf($originalType)) {
                                    $type_matched = true;
                                }
                            }
                        }
                    } else {

                        $atomicTypes[] = (string) $atomicType;
                        if (in_array((string) $atomicType, $originalTypes, true)) {
                            $type_matched = true;
                        }
                    }

                }

                if (!$type_matched) {
                    if (IssueBuffer::accepts(
                        new UnmatchedTypeIssue(
                            sprintf('original types are %s, but assigned types are %s', implode('|', $originalTypes), implode('|', $atomicTypes)),
                            new CodeLocation($statements_source, $expr->expr)
                        ),
                        $statements_source->getSuppressedIssues()
                    )) {

                    }
                }
            }
        }
    }

    /**
     * @psalm-return Union|false
     */
    private static function analyzeAssignmentType(PhpParser\Node\Expr\Assign $expr, Codebase $codebase, StatementsSource $statements_source)
    {

        if ($expr->expr instanceof \Psalm\Type\Union) {
            return $statements_source->getNodeTypeProvider()->getType($expr->expr);
        }

        if ($expr->expr instanceof PhpParser\Node\Expr) {

            return SimpleTypeInferer::infer(
                $codebase,
                new \Psalm\Internal\Provider\NodeDataProvider(),
                $expr->expr,
                $statements_source->getAliases()
            );
        }

        return false;
    }
}
