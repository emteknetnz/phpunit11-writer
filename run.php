<?php

# dcm 'php vendor/emteknetnz/phpunit11-writer/run.php'

use PhpParser\ParserFactory;
use PhpParser\{Node, NodeTraverser, NodeVisitorAbstract};
use PhpParser\Comment;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Comment\Doc;
use PhpParser\Lexer;
use PhpParser\PhpVersion;
use PhpParser\Parser\Php8;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Modifiers;

require __DIR__ . '/../../autoload.php';

$vendors = [
    'silverstripe',
    'symbiote',
    'bringyourownideas',
    'colymba',
    'dnadesign',
    'tractorcow',
    'cwp',
];

function getClasses(array $ast): array
{
    $ret = [];
    $a = ($ast[0] ?? null) instanceof Namespace_ ? $ast[0]->stmts : $ast;
    $ret = array_merge($ret, array_filter($a, fn($v) => $v instanceof Class_));
    // SapphireTest and other file with dual classes
    $i = array_filter($a, fn($v) => $v instanceof If_);
    foreach ($i as $if) {
        foreach ($if->stmts ?? [] as $v) {
            if ($v instanceof Class_) {
                $ret[] = $v;
            }
        }
    }
    return $ret;
}

function getMethods(Class_ $class): array
{
    return array_filter($class->stmts, fn($v) => $v instanceof ClassMethod);
}

foreach ($vendors as $vendor) {
    $vendorDir = __DIR__ . "/../../$vendor";
    $filenames = shell_exec("cd $vendorDir && find . | grep Test.php");

    foreach (explode("\n", $filenames) as $filename) {
        if (!$filename) {
            continue;
        }
        $path = "$vendorDir/$filename";
        $code = file_get_contents($path);

        $lexer = new Lexer([
            'usedAttributes' => [
                'comments',
                'startLine',
                'endLine',
                //'startTokenPos',
                //'endTokenPos',
                'startFilePos',
                'endFilePos'
            ]
        ]);
        $version = PhpVersion::getNewestSupported();
        $parser = new Php8($lexer, $version);
        try {
            $ast = $parser->parse($code);
        } catch (Error $error) {
            throw new Exception("Parse error: {$error->getMessage()}");
        }

        $classes = getClasses($ast);
        /** @var Class_ $class */
        $class = $classes[0];
        $methods = getMethods($class);
        /** @var ClassMethod $method */
        $dataProviders = [];
        foreach ($methods as $method) {
            $docblock = $method->getDocComment() ?? '';
            if (preg_match('#\* @dataProvider ([a-zA-Z0-9_]+)#', $docblock, $matches)) {
                $dataProvider = $matches[1];
                $dataProviders[] = $dataProvider;
            }
        }
        /** @var ClassMethod $method */
        $madeChanges = false;
        foreach ($methods as $method) {
            $name = $method->name->name;
            if (in_array($name, $dataProviders)) {
                if ($method->isStatic()) {
                    continue;
                }
                $start = $method->getStartFilePos();
                $end = $method->getEndFilePos();
                $methodStr = substr($code, $start, $end - $start + 1);
                $methodStr = str_replace("function $name", "static function $name", $methodStr);
                $code = implode('', [
                    substr($code, 0, $start),
                    $methodStr,
                    substr($code, $end + 1),
                ]);
                $madeChanges = true;
            }
            if ($madeChanges) {
                echo "Writing $path\n";
                file_put_contents($path, $code);
            }
        }

        // $traverser = new NodeTraverser;
        // $traverser->addVisitor(new class extends NodeVisitorAbstract {

        //     public function enterNode(Node $node) {
        //         if ($node instanceof Comment) {
        //             var_dump($node);die;
        //         }
        //     }

        //     // public function leaveNode(Node $node) {
        //     //     if ($node instanceof Node\Scalar\Int_) {
        //     //         return new Node\Scalar\String_((string) $node->value);
        //     //     }
        //     // }
        // });
        
        // $modifiedStmts = $traverser->traverse($ast);

        die;
    }
}
