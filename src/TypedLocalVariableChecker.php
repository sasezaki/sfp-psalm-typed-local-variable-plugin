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
        FunctionLikeStorage $function_like_storage,
        StatementsSource $statements_source,
        Codebase $codebase,
        array &$file_replacements = []
    ) {
        self::$initializeContextVars = [];

        var_dump($function_like_storage->cased_name);
        foreach ($stmt->getStmts() as $stmt) {
            if ($stmt->getType() === 'Stmt_Function') {
                var_dump($stmt);
            }
        }

//        var_dump($classlike_storage->stmt_location->getHash());

//        var_dump($function_like_storage->cased_name);
//        var_dump($function_like_storage->location->get);
//        var_dump($function_like_storage->stmt_location->getLineNumber(), $function_like_storage->stmt_location->getColumn());
//        var_dump($function_like_storage->stmt_location->getEndLineNumber(), $function_like_storage->stmt_location->getEndColumn());
    }

    private static function getIdentifier(Context $context)
    {
        if ($context->calling_function_id) {
            return $context->calling_function_id;
        }
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

//        var_dump($expr->getType());

        if ($expr instanceof PhpParser\Node\Expr\Assign && $expr->var instanceof PhpParser\Node\Expr\Variable) {


            //store expr s & run analyse afterStatementAnalysis ?

//            self::



            if (! isset($context->vars_in_scope['$'.$expr->var->name])) {
                return null;
            }

            // hold variable initialize type
            if (! isset(self::$initializeContextVars[$expr->var->name])) {

//                $code_location = new CodeLocation($statements_source, $expr);
//
//                var_dump($codebase->);

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

//            var_dump($expr->var->name, $expr->getEndLine(), $expr->getEndFilePos(), PHP_EOL);
//            $context->calling_method_id

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
