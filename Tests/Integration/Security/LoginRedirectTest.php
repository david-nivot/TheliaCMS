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

namespace TheliaCMS\Tests\Integration\Security;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Test\FixtureFactory;
use TheliaCMS\Security\CmsResources;

/**
 * A screen of the CMS opened in a browser by someone who is not logged in
 * leads to the login of the back office, which brings them back to it, as the
 * screens of the core do. Everything else that is refused stays a 403: a
 * script would be handed the login page, and an administrator without the
 * right has nothing to log in for.
 */
final class LoginRedirectTest extends AdminScreenTestCase
{
    /** What a browser sends when the address is typed or a link followed. */
    private const array BROWSER = [
        'HTTP_ACCEPT' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
    ];

    public function testABrowserIsSentToTheLoginAndBack(): void
    {
        $this->logOut();

        self::assertSame(302, $this->send('GET', '/admin/cms/pages/74/content?edit_language_id=1', [], self::BROWSER));
        self::assertSame(
            $this->url('admin.login', ['_target_path' => '/admin/cms/pages/74/content?edit_language_id=1']),
            $this->client->getResponse()->headers->get('Location'),
            'The login is told where to come back to.',
        );

        self::assertSame(302, $this->send('HEAD', '/admin/cms/pages', [], self::BROWSER), 'A HEAD reads like a GET.');
    }

    public function testAScriptIsStillRefused(): void
    {
        $this->logOut();

        self::assertSame(403, $this->send('GET', '/admin/cms/media/picker', [], ['HTTP_ACCEPT' => 'application/json']), 'A script asking for JSON.');
        self::assertSame(403, $this->send('GET', '/admin/cms/pages', [], [...self::BROWSER, ...self::XHR]), 'A script sending X-Requested-With.');
        self::assertSame(403, $this->send('GET', '/admin/cms/pages', [], ['HTTP_ACCEPT' => '*/*']), 'A client asking for no HTML, as curl does.');
    }

    public function testAWriteIsStillRefused(): void
    {
        $this->logOut();

        self::assertSame(403, $this->send('POST', '/admin/cms/pages/from-type', [], self::BROWSER));
    }

    public function testAnAdministratorWithoutTheRightIsStillRefused(): void
    {
        $this->logInAs((new FixtureFactory($this->getPropelConnection()))->restrictedAdmin([
            CmsResources::PAGE => [AccessManager::VIEW],
        ]));

        self::assertSame(403, $this->send('GET', '/admin/cms/settings', [], self::BROWSER));
    }

    public function testAHiddenScreenStaysHidden(): void
    {
        $this->logOut();

        self::assertSame(404, $this->send('GET', '/admin/cms/scripts', [], self::BROWSER));
    }

    /**
     * @param array<string, string> $parameters
     */
    private function url(string $route, array $parameters = []): string
    {
        return $this->getService(UrlGeneratorInterface::class)->generate($route, $parameters);
    }
}
