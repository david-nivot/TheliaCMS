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

use Thelia\Core\Security\AccessManager;
use Thelia\Model\Admin;
use Thelia\Test\FixtureFactory;
use TheliaCMS\Security\CmsResources;

/**
 * Who may do what on the content screen, for both families of content: the
 * document of a page and the document of a catalogue item answer the same
 * rights, action by action. Writing through the generator takes the right to
 * generate, every other change takes the right to change pages, and looking
 * at the screen takes neither.
 */
final class ContentRightsTest extends AdminScreenTestCase
{
    private const array GENERATOR_ACTIONS = ['generate', 'revise', 'section', 'translate'];

    private const array CHANGE_ACTIONS = ['update', 'remove', 'duplicate', 'move', 'add', 'restore', 'publish', 'translation'];

    public function testWithoutTheGenerateRightOnlyTheGeneratorActionsAreRefused(): void
    {
        $this->logInAs($this->adminWith([CmsResources::PAGE => [AccessManager::VIEW, AccessManager::UPDATE]]));

        foreach ($this->families() as $family => $target) {
            $token = $this->tokenOfTheScripts($this->screen($target['screen']));

            foreach (self::GENERATOR_ACTIONS as $action) {
                self::assertSame(403, $this->post($target, $action, $token), \sprintf('%s of the %s is refused without the generate right.', $action, $family));
            }

            foreach ($this->changeActions($family) as $action) {
                self::assertSame(302, $this->post($target, $action, $token), \sprintf('%s of the %s is not a matter of the generate right.', $action, $family));
            }
        }
    }

    public function testWithoutTheRightToChangePagesOnlyTheChangesAreRefused(): void
    {
        $this->logInAs($this->adminWith([CmsResources::PAGE => [AccessManager::VIEW], CmsResources::GENERATE => [AccessManager::UPDATE]]));

        foreach ($this->families() as $family => $target) {
            $screen = $this->screen($target['screen']);
            self::assertSame(200, $this->client->getResponse()->getStatusCode(), \sprintf('The screen of the %s only takes the right to look.', $family));
            $token = $this->tokenOfTheScripts($screen);

            foreach ($this->changeActions($family) as $action) {
                self::assertSame(403, $this->post($target, $action, $token), \sprintf('%s of the %s is refused without the right to change pages.', $action, $family));
            }

            foreach (self::GENERATOR_ACTIONS as $action) {
                self::assertSame(302, $this->post($target, $action, $token), \sprintf('%s of the %s is not a matter of the right to change pages.', $action, $family));
            }
        }
    }

    public function testWithBothRightsNothingIsRefused(): void
    {
        $this->logInAs($this->adminWith([CmsResources::PAGE => [AccessManager::VIEW, AccessManager::UPDATE], CmsResources::GENERATE => [AccessManager::UPDATE]]));

        foreach ($this->families() as $family => $target) {
            $token = $this->tokenOfTheScripts($this->screen($target['screen']));

            foreach ([...self::GENERATOR_ACTIONS, ...$this->changeActions($family)] as $action) {
                self::assertSame(302, $this->post($target, $action, $token), \sprintf('%s of the %s is allowed with both rights.', $action, $family));
            }
        }
    }

    public function testTheScreenOfEitherFamilyTakesOnlyTheRightToLook(): void
    {
        $this->logInAs($this->adminWith([CmsResources::PAGE => [AccessManager::VIEW]]));

        foreach ($this->families() as $family => $target) {
            $this->screen($target['screen']);

            self::assertSame(200, $this->client->getResponse()->getStatusCode(), $family);
        }
    }

    /**
     * Only a catalogue item is taken offline from its content screen: a page
     * is, from its settings.
     *
     * @return list<string>
     */
    private function changeActions(string $family): array
    {
        return 'item' === $family ? [...self::CHANGE_ACTIONS, 'unpublish'] : self::CHANGE_ACTIONS;
    }

    /**
     * @param array<string, list<string>> $grants
     */
    private function adminWith(array $grants): Admin
    {
        return (new FixtureFactory($this->getPropelConnection()))->restrictedAdmin($grants);
    }

    /**
     * @return array<string, array{screen: string, base: string}>
     */
    private function families(): array
    {
        $page = $this->createPage('Rights matrix', published: false);
        $factory = new FixtureFactory($this->getPropelConnection());
        $content = $factory->content($factory->folder(), ['title' => 'Rights matrix', 'locale' => $this->locale()]);

        $pageBase = \sprintf('/admin/cms/pages/%d/content', $page->getId());
        $itemBase = \sprintf('/admin/cms/catalogue/content/%d/content', $content->getId());

        return [
            'page' => ['screen' => $pageBase, 'base' => $pageBase],
            'item' => ['screen' => $itemBase, 'base' => $itemBase],
        ];
    }

    /**
     * @param array{screen: string, base: string} $target
     */
    private function post(array $target, string $action, string $token): int
    {
        return $this->send('POST', $target['base'].'/'.$action, ['_token' => $token]);
    }
}
