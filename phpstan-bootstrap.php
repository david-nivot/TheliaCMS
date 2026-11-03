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

// The module has no vendor of its own: PHPStan reads the classes of the Thelia
// install it sits in. The Propel Base and Map classes the models extend are
// generated under var/propel/<environment>/model, not in the module.
$theliaRoot = dirname(__DIR__, 3);
$loader = require $theliaRoot.'/vendor/autoload.php';

foreach (['test', 'dev'] as $environment) {
    $propelDirectory = $theliaRoot.'/var/propel/'.$environment;

    if (is_dir($propelDirectory.'/model/TheliaCMS/Model/Base')) {
        $loader->addPsr4('', $propelDirectory.'/model');
        $loader->addPsr4('TheliaMain\\', $propelDirectory.'/database/TheliaMain');

        return;
    }
}

throw new RuntimeException('The Propel models of the module are not built: run php bin/test-prepare from the root of the Thelia install first.');
