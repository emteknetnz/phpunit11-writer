<?php

# dcm 'php vendor/emteknetnz/phpunit11-writer/run.php'

# ! Note: it's possible to have 2+ @dataProvider's in a single method which this script
# didn't account for. You can detect multiple @dataProviders on a fresh project using
# the following regex search @dataProvider.+\n\s+\* @dataProvider

use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Lexer;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\PhpVersion;
use PhpParser\Parser\Php8;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Use_;

require __DIR__ . '/../../autoload.php';

$vendors = [
    'silverstripe',
    'symbiote',
    'bringyourownideas',
    'colymba',
    'dnadesign',
    'tractorcow',
    // 'cwp',
];

/**
 * Gets classes from an AST
 */
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

/**
 * Gets use statements used for imports
 */
function getUses(array $ast): array
{
    return array_filter($ast[0]->stmts, fn($v) => $v instanceof Use_);
}

/**
 * Gets class methods
 */
function getMethods(Class_ $class): array
{
    $methods = array_filter($class->stmts, fn($v) => $v instanceof ClassMethod);
    // return in reverse order so updates don't affect line numbers
    return array_reverse($methods);
}

/**
 * Updates test class methods with a callback
 */
function updateTestClassMethods($callback)
{
    global $vendors;
    foreach ($vendors as $vendor) {
        $vendorDir = __DIR__ . "/../../$vendor";
        $filenames = shell_exec("cd $vendorDir && find . | grep Test.php");
        foreach (explode("\n", $filenames ?? '') as $filename) {
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
            if (empty($classes)) {
                continue;
            }
            $class = $classes[0];
            $methods = getMethods($class);
            $newCode = $callback($ast, $path, $methods, $code);
            if ($newCode !== $code) {
                echo "Updated $path\n";
                file_put_contents($path, $newCode);
            }
        }
    }
}

// Update phpunit/phpunit version
foreach ($vendors as $vendor) {
    $vendorDir = __DIR__ . "/../../$vendor";
    $paths = glob("$vendorDir/*/composer.json");
    foreach ($paths as $path) {
        $contents = file_get_contents($path);
        if (strpos($contents, 'phpunit/phpunit') === false) {
            continue;
        }
        $newContents = preg_replace('#"phpunit/phpunit": "[^"]+"#', '"phpunit/phpunit": "^11.3"', $contents);
        if ($newContents !== $contents) {
            echo "Updated $path\n";
            file_put_contents($path, $newContents);
        }
    }
}

// Update dataProvider methods to be static
updateTestClassMethods(function($ast, $path, $methods, $code) {
    /** @var ClassMethod $method */
    $dataProviders = [];
    foreach ($methods as $method) {
        $docComment = $method->getDocComment() ?? '';
        if (preg_match('#\* @dataProvider ([a-zA-Z0-9_]+)#', (string) $docComment, $matches)) {
            $dataProvider = $matches[1];
            $dataProviders[] = $dataProvider;
        }
    }
    /** @var ClassMethod $method */
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
        }
    }
    return $code;
});

// Convert phpunit annotations to phpunit attributes
$matrix = [
    [
        'dataProvider ([a-zA-Z0-9_]+)',
        'DataProvider(\'__matches_1__\')',
        'PHPUnit\Framework\Attributes\DataProvider',
    ],
    [
        'doesNotPerformAssertions',
        'DoesNotPerformAssertions',
        'PHPUnit\Framework\Attributes\DoesNotPerformAssertions',
    ],
];
foreach ($matrix as $data) {
    updateTestClassMethods(function($ast, $path, $methods, $code) use ($data) {
        [$annotation, $attribute, $import] = $data;
        /** @var ClassMethod $method */
        $addImport = false;
        foreach ($methods as $method) {
            $docComment = $method->getDocComment() ?? '';
            $doc = (string) $docComment;
            $rx = "#[ \t]+\* @$annotation\n#";
            if (preg_match($rx, $doc, $matches)) {
                $start = $docComment->getStartFilePos();
                $end = $docComment->getEndFilePos();
                $newDoc = preg_replace($rx, '', $doc);
                if ($newDoc == '/**     */') {
                    $newDoc = '';
                    // minus 5 to remove the leading spaces and newline
                    $start -= 5;
                }
                $newDoc .= "\n    #[$attribute]";
                if (isset($matches[1])) {
                    $newDoc = str_replace('__matches_1__', $matches[1], $newDoc);
                }
                $code = implode('', [
                    substr($code, 0, $start),
                    $newDoc,
                    substr($code, $end + 1),
                ]);
                $addImport = true;
            }
        }
        if ($addImport) {
            $uses = getUses($ast);
            $lastUse = end($uses);
            if (!$lastUse) {
                throw new Exception("No use statements found in $path");
            }
            $start = $lastUse->getStartFilePos();
            $end = $lastUse->getEndFilePos();
            $code = implode('', [
                substr($code, 0, $end + 1), // note: keeping the existing use statements
                "\nuse $import;",
                substr($code, $end + 1),
            ]);
        }
        return $code;
    });
}

// Remove annotations
updateTestClassMethods(function($ast, $path, $methods, $code) {
    /** @var ClassMethod $method */
    foreach ($methods as $method) {
        $docComment = $method->getDocComment() ?? '';
        $doc = (string) $docComment;
        $rxs = [
            // code-covered annotations
            "#[ \t]+\* @covers .+\n#",
            "#[ \t]+\* @uses .+\n#",
            // other annotations
            "#[ \t]+\* @group .+\n#",
            "#[ \t]+\* @depends .+\n#",
        ];
        foreach ($rxs as $rx) {
            if (!preg_match($rx, $doc)) {
                continue;
            }
            $start = $docComment->getStartFilePos();
            $end = $docComment->getEndFilePos();
            $newDoc = preg_replace($rx, '', $doc);
            if ($newDoc == '/**     */' || $newDoc == "/**\n     */") {
                $newDoc = '';
                // minus 5 to remove the leading spaces and newline
                $start -= 5;
            }
            $code = implode('', [
                substr($code, 0, $start),
                $newDoc,
                substr($code, $end + 1),
            ]);
        }
    }
    return $code;
});

// Change ->setMethods() to ->onlyMethods()
updateTestClassMethods(function($ast, $path, $methods, $code) {
    /** @var ClassMethod $method */
    foreach ($methods as $method) {
        $start = $method->getStartFilePos();
        $end = $method->getEndFilePos();
        $methodStr = substr($code, $start, $end - $start + 1);
        $methodStr = str_replace('->setMethods(', '->onlyMethods(', $methodStr);
        $code = implode('', [
            substr($code, 0, $start),
            $methodStr,
            substr($code, $end + 1),
        ]);
    }
    return $code;
});

// Remove empty last lines from dockblocks
updateTestClassMethods(function($ast, $path, $methods, $code) {
    $code = str_replace("\n     *\n     */\n", "\n     */\n", $code);
    return $code;
});

// Remove any leftover empty docblocks
updateTestClassMethods(function($ast, $path, $methods, $code) {
    $code = str_replace("\n    /**\n     */\n", "\n", $code);
    return $code;
});
