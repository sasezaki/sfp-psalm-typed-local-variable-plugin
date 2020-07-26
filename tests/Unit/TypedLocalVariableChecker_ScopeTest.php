<?php


namespace SfpTest\Psalm\TypedLocalVariablePlugin\Unit;


use Psalm\IssueBuffer;

final class TypedLocalVariableChecker_ScopeTest extends AbstractTestCase
{

    /**
     * @test
     */
    public function closureVariableNamesAreSeparatedScope() : void
    {
        $this->addFile(
            __METHOD__,
            <<<'CODE'
<?php
function d () : void {
    $x = 3;$x = 2;
    function () : void {
        /** @var int $x */
        $x = 1;
        $y = 3;
        $y = 4;
    };
    function () : void {
        /** @var bool $x */
        $x = true;
    };
};
CODE
        );
        $this->analyzeFile(__METHOD__,  new \Psalm\Context());
//        $this->assertSame(0, IssueBuffer::getErrorCount());

//        var_dump(IssueBuffer::getIssuesData());
    }
}