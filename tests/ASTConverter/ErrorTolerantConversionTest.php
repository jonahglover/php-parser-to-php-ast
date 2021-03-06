<?php declare(strict_types = 1);
namespace ASTConverter\Tests;
use ASTConverter\ASTConverter;

require_once __DIR__ . '/../../src/util.php';

class ErrorTolerantConversionTest extends \PHPUnit\Framework\TestCase {
    public function testIncompleteVar() {
        $incomplete_contents = <<<'EOT'
<?php
function foo() {
  $a = $
}
EOT;
        $valid_contents = <<<'EOT'
<?php
function foo() {

}
EOT;
        $this->_testFallbackFromParser($incomplete_contents, $valid_contents);
    }

    public function testIncompleteVarWithPlaceholder() {
        $incomplete_contents = <<<'EOT'
<?php
function foo() {
  $a = $
}
EOT;
        $valid_contents = <<<'EOT'
<?php
function foo() {
  $a = $__INCOMPLETE_VARIABLE__;
}
EOT;
        $this->_testFallbackFromParser($incomplete_contents, $valid_contents, true);
    }

    public function testIncompleteProperty() {
        $incomplete_contents = <<<'EOT'
<?php
function foo() {
  $c;
  $a = $b->
}
EOT;
        $valid_contents = <<<'EOT'
<?php
function foo() {
  $c;

}
EOT;
        $this->_testFallbackFromParser($incomplete_contents, $valid_contents);
    }

    public function testIncompletePropertyWithPlaceholder() {
        $incomplete_contents = <<<'EOT'
<?php
function foo() {
  $c;
  $a = $b->
}
EOT;
        $valid_contents = <<<'EOT'
<?php
function foo() {
  $c;
  $a = $b->__INCOMPLETE_PROPERTY__;
}
EOT;
        $this->_testFallbackFromParser($incomplete_contents, $valid_contents, true);
    }

    public function testIncompleteMethod() {
        $incomplete_contents = <<<'EOT'
<?php
function foo() {
  $b;
  $a = Bar::
}
EOT;
        $valid_contents = <<<'EOT'
<?php
function foo() {
  $b;

}
EOT;
        $this->_testFallbackFromParser($incomplete_contents, $valid_contents);
    }

    public function testIncompleteMethodWithPlaceholder() {
        $incomplete_contents = <<<'EOT'
<?php
function foo() {
  $b;
  $a = Bar::
}
EOT;
        $valid_contents = <<<'EOT'
<?php
function foo() {
  $b;
  $a = Bar::__INCOMPLETE_CLASS_CONST__;
}
EOT;
        $this->_testFallbackFromParser($incomplete_contents, $valid_contents, true);
    }

    public function testMiscNoise() {
        $incomplete_contents = <<<'EOT'
<?php
function foo() {
  $b;
  |
}
EOT;
        $valid_contents = <<<'EOT'
<?php
function foo() {
  $b;

}
EOT;
        $this->_testFallbackFromParser($incomplete_contents, $valid_contents);
    }

    public function testMiscNoiseWithPlaceholders() {
        $incomplete_contents = <<<'EOT'
<?php
function foo() {
  $b;
  |
}
EOT;
        $valid_contents = <<<'EOT'
<?php
function foo() {
  $b;

}
EOT;
        $this->_testFallbackFromParser($incomplete_contents, $valid_contents, true);
    }

    public function testIncompleteArithmeticWithPlaceholders() {
        $incomplete_contents = <<<'EOT'
<?php
function foo() {
  ($b * $c) +
}
EOT;
        $valid_contents = <<<'EOT'
<?php
function foo() {
  $b * $c;
}
EOT;
        $this->_testFallbackFromParser($incomplete_contents, $valid_contents, true);
    }

    public function testMissingSemicolon() {
        $incomplete_contents = <<<'EOT'
<?php
function foo() {
    $y = 3
    $x = intdiv(3, 2);
}
EOT;
        $valid_contents = <<<'EOT'
<?php
function foo() {
    $y = 3;
    $x = intdiv(3, 2);
}
EOT;
        $this->_testFallbackFromParser($incomplete_contents, $valid_contents);
    }

// Another test (Won't work with php-parser, might work with tolerant-php-parser
/**
        $incomplete_contents = <<<'EOT'
<?php
class C{
    public function foo() {
        $x = 3;


    public function bar() {
    }
}
EOT;
        $valid_contents = <<<'EOT'
<?php
class C{
    public function foo() {
        $x = 3;
    }

    public function bar() {
    }
}
EOT;
 */

    private function _testFallbackFromParser(string $incomplete_contents, string $valid_contents, bool $should_add_placeholders = false) {
        $supports40 = ConversionTest::hasNativeASTSupport(40);
        $supports45 = ConversionTest::hasNativeASTSupport(45);
        $supports50 = ConversionTest::hasNativeASTSupport(50);
        if (!($supports40 || $supports45 || $supports50)) {
            $this->fail('No supported AST versions to test');
        }
        if ($supports40) {
            $this->_testFallbackFromParserForASTVersion($incomplete_contents, $valid_contents, 40, $should_add_placeholders);
        }
        if ($supports45) {
            $this->_testFallbackFromParserForASTVersion($incomplete_contents, $valid_contents, 45, $should_add_placeholders);
        }
        if ($supports50) {
            $this->_testFallbackFromParserForASTVersion($incomplete_contents, $valid_contents, 50, $should_add_placeholders);
        }
    }

    private function _testFallbackFromParserForASTVersion(string $incomplete_contents, string $valid_contents, int $ast_version, bool $should_add_placeholders) {
        $ast = \ast\parse_code($valid_contents, $ast_version);
        $this->assertInstanceOf('\ast\Node', $ast, 'Examples(for validContents) must be syntactically valid PHP parseable by php-ast');
        $errors = [];
        $converter = new ASTConverter();
        $converter->setShouldAddPlaceholders($should_add_placeholders);
        $php_parser_node = $converter->phpParserParse($incomplete_contents, true, $errors);
        $fallback_ast = $converter->phpParserToPhpAst($php_parser_node, $ast_version);
        $this->assertInstanceOf('\ast\Node', $fallback_ast, 'The fallback must also return a tree of php-ast nodes');
        $fallback_ast_repr = var_export($fallback_ast, true);
        $original_ast_repr = var_export($ast, true);

        if ($fallback_ast_repr !== $original_ast_repr) {
            $dump = 'could not dump';
            $node_dumper = new \PhpParser\NodeDumper([
                'dumpComments' => true,
                'dumpPositions' => true,
            ]);
            try {
                $dump = $node_dumper->dump($php_parser_node);
            } catch (\PhpParser\Error $e) {
            }
            $original_ast_dump = \ast_dump($ast);
            // $parser_export = var_export($php_parser_node, true);
            $this->assertSame($original_ast_repr, $fallback_ast_repr,  <<<EOT
The fallback must return the same tree of php-ast nodes
Code:
$incomplete_contents

Closest Valid Code:
$valid_contents

Original AST:
$original_ast_dump

PHP-Parser(simplified):
$dump
EOT

            /*
PHP-Parser(unsimplified):
$parser_export
             */
);
        }
    }
}
