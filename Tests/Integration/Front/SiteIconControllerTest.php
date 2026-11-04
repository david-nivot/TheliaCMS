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

namespace TheliaCMS\Tests\Integration\Front;

use Thelia\Model\ConfigQuery;
use TheliaCMS\Front\SiteIconController;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;

/**
 * The icon of the site is a file an administrator uploads and anybody can open
 * at its address: an SVG among them is a document that could carry a script.
 */
final class SiteIconControllerTest extends CmsIntegrationTestCase
{
    private const string LIBRARY = 'var/cache/test/cms-site-icon';

    private string $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = THELIA_ROOT.self::LIBRARY.\DIRECTORY_SEPARATOR.'store';

        if (!is_dir($this->store)) {
            mkdir($this->store, 0o777, true);
        }

        ConfigQuery::write('images_library_path', self::LIBRARY);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->store.'/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->store);
        rmdir(\dirname($this->store));
        ConfigQuery::resetCache();

        parent::tearDown();
    }

    public function testAnSvgIconOpenedAtItsAddressRunsNothing(): void
    {
        file_put_contents(
            $this->store.'/icon.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16"><script>alert(document.cookie)</script><circle cx="8" cy="8" r="8"/></svg>',
        );
        ConfigQuery::write('favicon_file', 'icon.svg');

        $response = $this->getService(SiteIconController::class)();

        self::assertSame('image/svg+xml', $response->headers->get('Content-Type'));
        self::assertSame(
            'sandbox',
            $response->headers->get('Content-Security-Policy'),
            'Opened on its own, the icon gets no origin of the site: no script, no cookie, no storage.',
        );
    }
}
