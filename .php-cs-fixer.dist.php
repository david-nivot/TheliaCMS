<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

// The rules of the Thelia core (.php-cs-fixer.dist.php of thelia/thelia), so
// the module reads like the code it extends. Run from the module directory:
//
//     php-cs-fixer fix --dry-run --diff
$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__)
    ->exclude([
        'Model/Base',
        'Model/Map',
        'assets',
        'docs',
        'skills',
        'templates',
    ])
    ->name('*.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new PhpCsFixer\Config())
    ->setCacheFile(__DIR__.'/.php-cs-fixer.cache')
    ->setRiskyAllowed(true)
    ->setRules([
        '@PHP8x0Migration' => true,
        '@PHP8x0Migration:risky' => true,
        '@PHP8x1Migration' => true,
        '@PHP8x2Migration' => true,
        '@PHP8x3Migration' => true,
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'yoda_style' => false,
        'declare_strict_types' => true,
        'header_comment' => [
            'header' => implode("\n", [
                'This file is part of the Thelia package.',
                'http://www.thelia.net',
                '',
                '(c) OpenStudio <info@thelia.net>',
                '',
                'For the full copyright and license information, please view the LICENSE',
                'file that was distributed with this source code.',
            ]),
        ],
    ])
    ->setFinder($finder);
