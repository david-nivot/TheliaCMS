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

namespace TheliaCMS\Tests\Integration\Content\Site;

use Propel\Runtime\Propel;
use Symfony\Component\Filesystem\Filesystem;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\Section;
use TheliaCMS\Content\Site\SiteComponent;
use TheliaCMS\Content\Site\SiteComponentRepository;
use TheliaCMS\Content\Site\SiteComponentValidator;
use TheliaCMS\Media\Icon\IconNames;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Page\Admin\CmsPageWriter;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaLibrary\Model\LibraryImage;
use TheliaLibrary\TheliaLibrary;
use Twig\Environment;

/**
 * A component of the site that draws its icon prop with `cms_icon()`, in a
 * page saved the way the back office saves it.
 */
final class SiteIconRenderingTest extends CmsIntegrationTestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function testTheIconOfTheLibraryIsDrawnInTheStoredPage(): void
    {
        $this->component();
        $image = $this->svgImage('<path d="M0 0h1v1z" fill="currentColor"/>');
        $this->getService(IconNames::class)->assign($image->getId(), 'siteicon-drawn');

        $html = $this->storedHtml('cms:siteicon-drawn');

        self::assertStringContainsString('<path d="M0 0h1v1z" fill="currentColor"', $html);
        self::assertStringContainsString('class="mark"', $html);
        self::assertStringContainsString('aria-hidden="true"', $html, 'An icon with no label is hidden from screen readers.');
        self::assertStringContainsString('<h2>Hello</h2>', $html);
    }

    public function testAnUnknownIconLeavesAPageSavedWithoutIt(): void
    {
        $this->component();

        $html = $this->storedHtml('cms:siteicon-never-existed');

        self::assertStringContainsString('<h2>Hello</h2>', $html);
        self::assertStringNotContainsString('<svg', $html);
        self::assertStringNotContainsString('<path', $html);
    }

    public function testAnIconTakenAwayAfterThePageWasWrittenIsLeftOutOfTheNextSave(): void
    {
        $this->component();
        $image = $this->svgImage('<path d="M0 0h1v1z"/>');
        $names = $this->getService(IconNames::class);
        $names->assign($image->getId(), 'siteicon-removed');
        self::assertStringContainsString('<path', $this->storedHtml('cms:siteicon-removed'));

        $names->remove($image->getId());

        self::assertStringNotContainsString('<path', $this->storedHtml('cms:siteicon-removed'));
    }

    public function testTheToolkitStoryListsTheIconsOfTheLibraryWithTheirDrawing(): void
    {
        $twig = $this->getService(Environment::class);
        $story = '@TheliaCMSModule/front/sections/toolkit/icons.html.twig';
        $names = $this->getService(IconNames::class);

        // The test runs in a transaction: the table is emptied for this test alone.
        Propel::getConnection('TheliaMain')->exec('DELETE FROM cms_icon');
        self::assertStringContainsString('No CMS icon yet', $twig->render($story), 'An empty library says so.');

        $image = $this->svgImage('<path d="M0 0h1v1z"/>');
        $names->assign($image->getId(), 'siteicon-story');

        $html = $twig->render($story);

        self::assertStringContainsString('<span class="IconGrid-name">cms:siteicon-story</span>', $html, 'Laid out as the icons of the theme.');
        self::assertStringContainsString('d="M0 0h1v1z"', $html);
    }

    private function component(): void
    {
        $component = new SiteComponent(
            'IconCard',
            'Icon card',
            'A heading with an icon.',
            [
                ['name' => 'title', 'type' => 'text', 'label' => 'Heading'],
                ['name' => 'icon', 'type' => 'icon', 'label' => 'Icon'],
            ],
            ['title' => 'Hello', 'icon' => ''],
            '<section><h2>{{ props.title }}</h2>{{ cms_icon(props.icon, {class: "mark", onclick: "alert(1)"}) }}</section>',
        );
        $this->getService(SiteComponentRepository::class)->save((new SiteComponentValidator())->validate($component), SiteComponentRepository::STATUS_READY);
    }

    private function storedHtml(string $icon): string
    {
        $page = $this->createPage('Icon page', published: false);
        $this->getService(CmsPageWriter::class)->saveContent($page, $this->locale(), new Document([
            new Section('card', 'Site:IconCard', ['title' => 'Hello', 'icon' => $icon]),
        ]));

        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne();
        self::assertNotNull($content);

        return (string) $content->getDraftHtml();
    }

    private function svgImage(string $body): LibraryImage
    {
        $fileName = bin2hex(random_bytes(10)).'.svg';
        $path = TheliaLibrary::getImageDirectory().$fileName;
        // The directory of the library does not exist yet on a fresh install, as in the CI.
        (new Filesystem())->dumpFile($path, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 2 2">'.$body.'</svg>');
        $this->files[] = $path;

        $image = new LibraryImage();
        $image->setLocale($this->locale())->setFileName($fileName)->setTitle($fileName);
        $image->save();

        return $image;
    }
}
