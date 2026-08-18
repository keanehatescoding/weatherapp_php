<?php declare(strict_types=1);
// PHP-CS-Fixer configuration for the WeatherApp codebase.
// Run locally:  composer global require friendsofphp/php-cs-fixer
//               php-cs-fixer fix --dry-run --diff

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/app', __DIR__ . '/tests'])
    ->append([
        __FILE__,
        __DIR__ . '/logic.php',
        __DIR__ . '/csrf.php',
        __DIR__ . '/utils.php',
        __DIR__ . '/Forecast.php',
        __DIR__ . '/Hourly.php',
        __DIR__ . '/Alerts.php',
        __DIR__ . '/Icons.php',
    ])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        'array_syntax' => ['syntax' => 'short'],
        'concat_space' => ['spacing' => 'one'],
        'declare_strict_types' => true,
        'elseif' => true,
        'encoding' => true,
        'full_opening_tag' => true,
        'indentation_type' => true,
        'line_ending' => true,
        'lowercase_keywords' => true,
        'lowercase_static_reference' => true,
        'method_argument_space' => ['on_multiline' => 'ensure_fully_multiline'],
        'no_closing_tag' => true,
        'no_trailing_whitespace' => true,
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'phpdoc_align' => false,
        'single_quote' => true,
        'strict_comparison' => true,
        'strict_param' => true,
        'trailing_comma_in_multiline' => true,
        'whitespace_after_comma_in_array' => true,
    ])
    ->setFinder($finder);
