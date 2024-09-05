<?php

use PhpParser\ParserFactory;

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

foreach ($vendors as $vendor) {
    $vendorDir = __DIR__ . "/../../$vendor";
    $files = shell_exec("cd $vendorDir && find . | grep Test.php");

    $contents = file_get_contents($filename);

    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    try {
        $ast = $parser->parse($contents);
    } catch (Error $error) {
        echo "Parse error: {$error->getMessage()}\n";
        return;
    }
}
