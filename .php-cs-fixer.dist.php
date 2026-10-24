<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/config',
        __DIR__ . '/public',
    ])
    ->append([
        __FILE__,
        __DIR__ . '/bin/console',
    ]);

return (new PhpCsFixer\Config())
    // declare_strict_types is a RISKY rule: without this line the config does not
    // resolve at all. Dropping the rule (or this line) to make a check pass is a
    // standing-rule-4 violation — never do it.
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'declare_strict_types' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_quote' => true,
        'trailing_comma_in_multiline' => true,
    ])
    ->setFinder($finder);
