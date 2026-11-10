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

namespace TheliaCMS\Tests\Integration\Menu;

use Symfony\Component\DomCrawler\Crawler;
use Thelia\Model\LangQuery;
use TheliaCMS\Menu\MenuCache;
use TheliaCMS\Model\CmsMenu;
use TheliaCMS\Model\CmsMenuItem;
use TheliaCMS\Model\CmsMenuItemQuery;
use TheliaCMS\Model\CmsMenuQuery;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;

/**
 * The menu screens offer a "Visible on the site" box on the menu and on each
 * entry, and show what is hidden without deleting it.
 */
final class MenuVisibilityScreenTest extends AdminScreenTestCase
{
    private const string CODE = 'visibility-screen';

    public function testAnEntryIsHiddenFromItsFormAndShownGreyedInTheTree(): void
    {
        $menu = $this->menu();
        $parent = $this->entry($menu, 'Parent', 1);
        $child = $this->entry($menu, 'Enfant', 1, $parent);

        $screen = $this->screen($this->entryUrl($menu, $parent));
        $box = $this->formPostingTo($screen, $this->entryPath($menu, $parent))->filter('input[type="checkbox"][name$="[visible]"]');
        self::assertSame(1, $box->count(), 'The entry form has a visibility box.');
        self::assertNotNull($box->attr('checked'), 'A visible entry opens with the box ticked.');

        $this->submitUntickedBox($screen, $this->entryPath($menu, $parent));

        self::assertSame(0, (int) CmsMenuItemQuery::create()->findPk($parent->getId())?->getVisible());
        self::assertSame(1, (int) CmsMenuItemQuery::create()->findPk($child->getId())?->getVisible(), 'Only the entry itself is changed.');

        $tree = $this->screen($this->menuUrl($menu));
        $parentRow = $tree->filter(\sprintf('[data-testid="cms-menu-entry-%d"]', $parent->getId()));
        $childRow = $tree->filter(\sprintf('[data-testid="cms-menu-entry-%d"]', $child->getId()));
        self::assertSame('true', $parentRow->attr('data-hidden'));
        self::assertSame('true', $childRow->attr('data-hidden'), 'What sits under a hidden entry is hidden too.');
        self::assertSame(1, $tree->filter(\sprintf('[data-testid="cms-menu-entry-%d-hidden"]', $parent->getId()))->count());
        self::assertStringContainsString('Hidden by the entry above', $childRow->text());
    }

    public function testANewEntryIsVisibleUnlessTheBoxIsUnticked(): void
    {
        $menu = $this->menu();
        $screen = $this->screen($this->menuUrl($menu));

        $form = $this->formPostingTo($screen, \sprintf('/admin/cms/menus/%d/entries/new', $menu->getId()));
        self::assertNotNull($form->filter('input[name$="[visible]"]')->attr('checked'), 'A new entry starts visible.');

        $fields = $form->form();
        $name = (string) preg_replace('/\[targetType\]$/', '', (string) $form->filter('select[name$="[targetType]"]')->attr('name'));
        $fields[$name.'[targetType]']->select('url');
        $fields[$name.'[url]']->setValue('https://example.com/annonce');
        $fields[$name.'[label]']->setValue('Annonce');
        $this->client->submit($fields, [], self::SAME_SITE);

        self::assertTrue($this->client->getResponse()->isRedirection(), strip_tags((string) $this->client->getResponse()->getContent()));
        self::assertSame(1, (int) CmsMenuItemQuery::create()->filterByMenuId($menu->getId())->findOne()?->getVisible());
    }

    public function testAMenuIsHiddenFromItsSettingsAndTheListSaysSo(): void
    {
        $menu = $this->menu();
        $this->entry($menu, 'Annonce', 1);

        $screen = $this->screen($this->menuUrl($menu));
        self::assertSame(0, $screen->filter('[data-testid="cms-menu-hidden"]')->count());

        $this->submitUntickedBox($screen, '/admin/cms/menus/'.$menu->getId());

        self::assertSame(0, (int) CmsMenuQuery::create()->findPk($menu->getId())?->getVisible());
        self::assertSame(1, CmsMenuItemQuery::create()->filterByMenuId($menu->getId())->count(), 'The entries are kept.');
        self::assertSame(1, $this->screen($this->menuUrl($menu))->filter('[data-testid="cms-menu-hidden"]')->count());
        self::assertSame(1, $this->screen('/admin/cms/menus')->filter(\sprintf('[data-testid="cms-menu-hidden-%d"]', $menu->getId()))->count());
    }

    private function submitUntickedBox(Crawler $screen, string $path): void
    {
        $form = $this->formPostingTo($screen, $path);
        $fields = $form->form();
        $name = (string) $form->filter('input[type="checkbox"][name$="[visible]"]')->attr('name');
        $fields[$name]->untick();
        $this->client->submit($fields, [], self::SAME_SITE);

        self::assertTrue($this->client->getResponse()->isRedirection(), strip_tags((string) $this->client->getResponse()->getContent()));
    }

    private function formPostingTo(Crawler $screen, string $path): Crawler
    {
        $form = $screen->filter('form')->reduce(
            static fn (Crawler $candidate): bool => parse_url((string) $candidate->attr('action'), \PHP_URL_PATH) === $path,
        );
        self::assertSame(1, $form->count(), \sprintf('No form of the screen posts to %s.', $path));

        return $form;
    }

    private function menuUrl(CmsMenu $menu): string
    {
        return '/admin/cms/menus/'.$menu->getId().'?edit_language_id='.$this->languageId();
    }

    private function entryPath(CmsMenu $menu, CmsMenuItem $item): string
    {
        return \sprintf('/admin/cms/menus/%d/entries/%d', $menu->getId(), $item->getId());
    }

    private function entryUrl(CmsMenu $menu, CmsMenuItem $item): string
    {
        return $this->entryPath($menu, $item).'?edit_language_id='.$this->languageId();
    }

    private function languageId(): int
    {
        return (int) LangQuery::create()->findOneByLocale($this->locale())?->getId();
    }

    private function menu(): CmsMenu
    {
        $menu = (new CmsMenu())->setCode(self::CODE);
        $menu->setLocale($this->locale())->setTitle('Menu de test');
        $menu->save();

        return $menu;
    }

    private function entry(CmsMenu $menu, string $label, int $position, ?CmsMenuItem $parent = null): CmsMenuItem
    {
        $item = (new CmsMenuItem())
            ->setMenuId($menu->getId())
            ->setParent(null === $parent ? 0 : (int) $parent->getId())
            ->setPosition($position)
            ->setTargetType('url')
            ->setUrl('https://example.com/'.rawurlencode($label));
        $item->setLocale($this->locale())->setLabel($label);
        $item->save();

        $this->getService(MenuCache::class)->invalidate();

        return $item;
    }
}
