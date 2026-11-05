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

namespace TheliaCMS\Tests\Integration\Generation;

use Symfony\Component\Routing\RouterInterface;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;

/**
 * The addresses of the two content screens, a page and a catalogue item, as
 * a literal list: whatever serves them, no name, path, method or requirement
 * may move, the templates and the bookmarks of the editors depend on them.
 */
final class ContentRoutesTest extends CmsIntegrationTestCase
{
    private const string TYPES = 'product|category|content|folder|dealer';

    public function testThePageContentRoutesAreTheseAndNoOther(): void
    {
        $id = ['id' => '\d+'];
        $page = '/admin/cms/pages/{id}/content';

        self::assertSame([
            'admin.cms.pages.content.add' => [$page.'/add', ['POST'], $id],
            'admin.cms.pages.content.component.generate' => [$page.'/component/generate', ['POST'], $id],
            'admin.cms.pages.content.component.keep' => [$page.'/component/keep', ['POST'], $id],
            'admin.cms.pages.content.duplicate' => [$page.'/duplicate', ['POST'], $id],
            'admin.cms.pages.content.edit' => [$page, ['GET'], $id],
            'admin.cms.pages.content.generate' => [$page.'/generate', ['POST'], $id],
            'admin.cms.pages.content.move' => [$page.'/move', ['POST'], $id],
            'admin.cms.pages.content.publish' => [$page.'/publish', ['POST'], $id],
            'admin.cms.pages.content.remove' => [$page.'/remove', ['POST'], $id],
            'admin.cms.pages.content.restore' => [$page.'/restore', ['POST'], $id],
            'admin.cms.pages.content.revise' => [$page.'/revise', ['POST'], $id],
            'admin.cms.pages.content.section' => [$page.'/section', ['POST'], $id],
            'admin.cms.pages.content.translate' => [$page.'/translate', ['POST'], $id],
            'admin.cms.pages.content.update' => [$page.'/update', ['POST'], $id],
        ], $this->routesNamed('admin.cms.pages.content.'));
    }

    public function testTheItemContentRoutesAreTheseAndNoOther(): void
    {
        $requirements = ['id' => '\d+', 'type' => self::TYPES];
        $item = '/admin/cms/catalogue/{type}/{id}/content';

        self::assertSame([
            'admin.cms.catalogue.content.add' => [$item.'/add', ['POST'], $requirements],
            'admin.cms.catalogue.content.duplicate' => [$item.'/duplicate', ['POST'], $requirements],
            'admin.cms.catalogue.content.edit' => [$item, ['GET'], $requirements],
            'admin.cms.catalogue.content.generate' => [$item.'/generate', ['POST'], $requirements],
            'admin.cms.catalogue.content.move' => [$item.'/move', ['POST'], $requirements],
            'admin.cms.catalogue.content.publish' => [$item.'/publish', ['POST'], $requirements],
            'admin.cms.catalogue.content.remove' => [$item.'/remove', ['POST'], $requirements],
            'admin.cms.catalogue.content.restore' => [$item.'/restore', ['POST'], $requirements],
            'admin.cms.catalogue.content.revise' => [$item.'/revise', ['POST'], $requirements],
            'admin.cms.catalogue.content.section' => [$item.'/section', ['POST'], $requirements],
            'admin.cms.catalogue.content.translate' => [$item.'/translate', ['POST'], $requirements],
            'admin.cms.catalogue.content.unpublish' => [$item.'/unpublish', ['POST'], $requirements],
            'admin.cms.catalogue.content.update' => [$item.'/update', ['POST'], $requirements],
        ], $this->routesNamed('admin.cms.catalogue.content.'));
    }

    /**
     * @return array<string, array{string, list<string>, array<string, string>}>
     */
    private function routesNamed(string $prefix): array
    {
        $router = static::getContainer()->get('router');
        \assert($router instanceof RouterInterface);

        $found = [];

        foreach ($router->getRouteCollection()->all() as $name => $route) {
            if (str_starts_with($name, $prefix)) {
                $methods = $route->getMethods();
                sort($methods);
                $requirements = $route->getRequirements();
                ksort($requirements);
                $found[$name] = [$route->getPath(), $methods, $requirements];
            }
        }

        ksort($found);

        return $found;
    }
}
