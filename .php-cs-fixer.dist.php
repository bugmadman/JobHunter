<?php

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude('var')
    ->notPath([
        'config/bundles.php',
        'config/reference.php',
    ])
    // No .php extension, so the default *.php pattern skips it
    ->append([__DIR__.'/bin/console'])
;

return (new PhpCsFixer\Config())
    // declare_strict_types is a risky rule: it changes how scalar arguments are coerced
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        'declare_strict_types' => true,
        'blank_line_before_statement' => ['statements' => ['return']],
        'return_assignment' => true,
    ])
    ->setFinder($finder)
;
