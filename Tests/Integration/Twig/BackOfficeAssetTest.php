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

namespace TheliaCMS\Tests\Integration\Twig;

use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;

/**
 * A site that updated the module serves the scripts of the module it now has,
 * not the copy the core published for the version before.
 */
final class BackOfficeAssetTest extends AdminScreenTestCase
{
    private const string SCRIPT = 'assets/media-list.js';

    public function testAScreenServesTheScriptOfTheInstalledModule(): void
    {
        $this->screen('/admin/cms/media');
        $served = rtrim(THELIA_WEB_DIR, '/').'/assets/backOffice/default-twig/TheliaCMS/'.self::SCRIPT;
        $source = \dirname(__DIR__, 3).'/templates/backOffice/default-twig/'.self::SCRIPT;
        self::assertFileExists($served, 'The first page publishes the script.');

        // The copy an update left behind: the script of the version before.
        file_put_contents($served, '// The script of the version before.');
        touch($served, (int) strtotime('2026-10-30 10:00:00'));

        try {
            $this->screen('/admin/cms/media');

            self::assertFileEquals($source, $served);
        } finally {
            // The site keeps a working script, whatever the test found.
            copy($source, $served);
            touch($served, (int) filemtime($source));
        }
    }
}
