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

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Model\Admin;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Lang;
use Thelia\Model\ModuleConfigQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use TheliaCMS\Content\Document;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Page\Admin\CmsPageWriter;
use TheliaCMS\Page\Admin\PageDraft;
use TheliaCMS\Tests\Integration\BuildsItemTargets;

/**
 * The screens of the CMS, requested over HTTP by a logged-in administrator.
 *
 * Requests go through the whole kernel: routing, the guard of `/admin/cms`, the
 * controller and the template. A token is always read from the screen that
 * renders the action, so a template that forgets it fails the test.
 */
abstract class AdminScreenTestCase extends WebIntegrationTestCase
{
    use BuildsItemTargets;

    /**
     * What a browser adds to a request sent by a page of the same site. The
     * Symfony forms of the module check it on top of the back-office token.
     */
    protected const array SAME_SITE = ['HTTP_SEC_FETCH_SITE' => 'same-origin'];

    protected const array XHR = ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_SEC_FETCH_SITE' => 'same-origin'];

    private AdminInSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        if ('default-twig' !== ConfigQuery::read('active-admin-template')) {
            self::markTestSkipped('The screens of the module are written for the default-twig back office, which this shop does not run.');
        }

        $this->session = new AdminInSession();
        $this->dispatcher()->addSubscriber($this->session);
        $this->session->logIn((new FixtureFactory($this->getPropelConnection()))->admin());
    }

    protected function tearDown(): void
    {
        $this->session->logOut();
        $this->dispatcher()->removeSubscriber($this->session);

        parent::tearDown();

        // The rollback takes back the settings a test saved, not the copy the
        // shop keeps of them in memory for the rest of the run.
        ModuleConfigQuery::resetConfigCache();
    }

    protected function logInAs(Admin $admin): void
    {
        $this->session->logIn($admin);
    }

    protected function logOut(): void
    {
        $this->session->logOut();
    }

    protected function screen(string $url): Crawler
    {
        $crawler = $this->client->request('GET', $url);

        self::assertSame(200, $this->client->getResponse()->getStatusCode(), \sprintf('%s answers %d.', $url, $this->client->getResponse()->getStatusCode()));

        return $crawler;
    }

    /**
     * The token carried by the form of the screen posting to the given address.
     */
    protected function tokenOfTheForm(Crawler $screen, string $action): string
    {
        $forms = $screen->filter('form')->reduce(
            static fn (Crawler $form): bool => parse_url((string) $form->attr('action'), \PHP_URL_PATH) === $action,
        );

        self::assertGreaterThan(0, $forms->count(), \sprintf('No form of the screen posts to %s.', $action));

        $token = $forms->first()->filter('input[name="_token"]');

        self::assertSame(1, $token->count(), \sprintf('The form posting to %s carries no token.', $action));

        return (string) $token->attr('value');
    }

    /**
     * The token the layout of the back office gives its scripts.
     */
    protected function tokenOfTheScripts(Crawler $screen): string
    {
        $meta = $screen->filter('meta[name="bo-token"]');

        self::assertSame(1, $meta->count(), 'The layout renders no token for the scripts.');

        return (string) $meta->attr('content');
    }

    /**
     * @param array<string, mixed>  $parameters
     * @param array<string, string> $server
     */
    protected function send(string $method, string $url, array $parameters = [], array $server = self::SAME_SITE): int
    {
        $this->client->request($method, $url, $parameters, [], $server);

        return $this->client->getResponse()->getStatusCode();
    }

    protected function locale(): string
    {
        return Lang::getDefaultLanguage()->getLocale();
    }

    protected function createPage(string $title, bool $published = true): CmsPage
    {
        $writer = $this->getService(CmsPageWriter::class);
        \assert($writer instanceof CmsPageWriter);

        $page = new CmsPage();
        $page->setParent(0)->setPosition(0)->setVisible(1)->setPageType('default');

        $writer->saveDraft($page, $this->locale(), new PageDraft(title: $title));
        $writer->saveContent($page, $this->locale(), Document::richText(\sprintf('<h1>%s</h1><p>Written for the test.</p>', $title)));

        if ($published) {
            $writer->publish($page, $this->locale());
        }

        return $page;
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = $this->getService(EventDispatcherInterface::class);
        \assert($dispatcher instanceof EventDispatcherInterface);

        return $dispatcher;
    }
}
