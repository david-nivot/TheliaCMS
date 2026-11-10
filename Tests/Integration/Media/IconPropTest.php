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

namespace TheliaCMS\Tests\Integration\Media;

use Thelia\Model\Lang;
use Thelia\Test\FixtureFactory;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\DocumentValidator;
use TheliaCMS\Content\Section;
use TheliaCMS\Content\Site\SiteComponent;
use TheliaCMS\Content\Site\SiteComponentRepository;
use TheliaCMS\Media\Icon\IconNames;
use TheliaCMS\Media\Icon\ThemeIcons;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Page\Admin\CmsPageWriter;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;
use TheliaLibrary\Model\LibraryImage;
use TheliaLibrary\TheliaLibrary;
use Twig\Environment;

/**
 * The icon prop in the back office: the source that lists the icons to pick
 * from, the field of the section form, and a document whose icon is gone.
 */
final class IconPropTest extends AdminScreenTestCase
{
    private const string SOURCE = '/admin/cms/partials/sources/icons';

    /** @var list<string> files written outside the transaction */
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

    public function testTheSourceNeedsTheRightToSeeThePages(): void
    {
        $this->logInAs((new FixtureFactory($this->getPropelConnection()))->restrictedAdmin([]));

        self::assertSame(403, $this->send('GET', self::SOURCE, [], self::XHR));
    }

    public function testTheSourceListsTheIconsOfTheThemeAndOfTheLibraryWithASafePreview(): void
    {
        $image = $this->svgImage('<path d="M0 0h1v1z"/>');
        $this->getService(IconNames::class)->assign($image->getId(), 'iconprop-listed');

        $this->client->request('GET', self::SOURCE, [], [], self::XHR);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $rows = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        $own = array_values(array_filter($rows, static fn (array $row): bool => 'cms:iconprop-listed' === $row['name']));
        self::assertCount(1, $own);
        self::assertSame(['name', 'label', 'group', 'set', 'preview'], array_keys($own[0]));
        self::assertSame('', $own[0]['set'], 'A CMS icon belongs to no set.');
        self::assertSame('cms', $own[0]['group']);
        self::assertSame('Iconprop listed', $own[0]['label']);
        self::assertStringStartsWith('data:image/svg+xml;base64,', $own[0]['preview'], 'A picture for an <img>, never markup.');
        self::assertStringContainsString('M0 0h1v1z', (string) base64_decode(substr($own[0]['preview'], \strlen('data:image/svg+xml;base64,')), true));
        self::assertStringContainsString('color="#212529"', (string) base64_decode(substr($own[0]['preview'], \strlen('data:image/svg+xml;base64,')), true), 'A color that follows the text is drawn dark.');

        $themeNames = array_column(array_filter($rows, static fn (array $row): bool => 'theme' === $row['group']), 'name');
        self::assertNotSame([], $themeNames, 'The active theme draws icons.');
        $listed = $this->getService(ThemeIcons::class)->names();
        sort($themeNames);
        self::assertSame($listed, $themeNames, 'The theme group is what the theme draws, no prefix.');
        self::assertSame([], array_diff(array_column($rows, 'group'), ['theme', 'cms']), 'Two groups.');

        // An icon of a sub-directory of the theme is listed with its set, the
        // others with none: the grid groups them so.
        foreach (array_filter($rows, static fn (array $row): bool => 'theme' === $row['group']) as $row) {
            self::assertSame(str_contains($row['name'], ':') ? substr($row['name'], 0, (int) strrpos($row['name'], ':')) : '', $row['set'], $row['name']);
        }
    }

    public function testAnIconRemovedFromTheLibraryLeavesTheList(): void
    {
        $image = $this->svgImage('<path d="M0 0h1v1z"/>');
        $names = $this->getService(IconNames::class);
        $names->assign($image->getId(), 'iconprop-gone-from-list');
        $names->remove($image->getId());

        $this->client->request('GET', self::SOURCE, [], [], self::XHR);

        self::assertStringNotContainsString('iconprop-gone-from-list', (string) $this->client->getResponse()->getContent());
    }

    public function testAnIconWhoseImageIsGoneFromTheLibraryLeavesTheList(): void
    {
        $image = $this->svgImage('<path d="M0 0h1v1z"/>');
        $this->getService(IconNames::class)->assign($image->getId(), 'iconprop-orphan');
        // TheliaLibrary deletes an image without any event: the row of the name stays.
        $image->delete();

        $this->client->request('GET', self::SOURCE, [], [], self::XHR);

        self::assertStringNotContainsString('iconprop-orphan', (string) $this->client->getResponse()->getContent());
    }

    public function testTheFieldOfASectionIsATextFieldThatCarriesTheWidgetAndItsValueIsReadBack(): void
    {
        $this->site('IconCard');
        $page = $this->pageWith([new Section('card', 'Site:IconCard', ['title' => 'Hello', 'icon' => 'cms:iconprop-first'])]);

        $screen = $this->screen($this->url($page));
        $field = $screen->filter('[data-testid="cms-section-edit-card"] form input[name="cms_section_card[icon]"]');

        self::assertSame(1, $field->count());
        self::assertSame('text', $field->attr('type'));
        self::assertSame(self::SOURCE, $field->attr('data-cms-icon-picker'));
        self::assertSame('cms:iconprop-first', $field->attr('value'), 'A name that is in no list is shown as it is.');
        self::assertSame('Choose an icon', trim((string) json_decode((string) $screen->filter('[data-cms-content]')->attr('data-cms-content-labels'), true)['iconChoose']), 'The words of the widget are given to the script.');

        $form = $screen->filter('[data-testid="cms-section-edit-card"] form[data-cms-section-form]')->form();
        $form['cms_section_card[icon]'] = 'arrow-left';
        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne();
        self::assertSame('arrow-left', Document::fromJson($content?->getDraftDocument())?->section('card')?->props['icon']);

        $form = $this->screen($this->url($page))->filter('[data-testid="cms-section-edit-card"] form[data-cms-section-form]')->form();
        self::assertSame('arrow-left', $form['cms_section_card[icon]']->getValue());
    }

    public function testAFieldLeftEmptyStoresNoIcon(): void
    {
        $this->site('IconCard');
        $page = $this->pageWith([new Section('card', 'Site:IconCard', ['title' => 'Hello', 'icon' => 'cms:iconprop-first'])]);

        $form = $this->screen($this->url($page))->filter('[data-testid="cms-section-edit-card"] form[data-cms-section-form]')->form();
        $form['cms_section_card[icon]'] = '';
        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne();
        self::assertSame('', Document::fromJson($content?->getDraftDocument())?->section('card')?->props['icon']);
    }

    public function testADocumentWhoseIconIsGoneStaysValid(): void
    {
        $this->site('IconCard');
        $image = $this->svgImage('<path d="M0 0h1v1z"/>');
        $names = $this->getService(IconNames::class);
        $names->assign($image->getId(), 'iconprop-removed');
        $document = new Document([new Section('card', 'Site:IconCard', ['title' => 'Hello', 'icon' => 'cms:iconprop-removed'])]);
        $validator = $this->getService(DocumentValidator::class);
        $validator->validate($document);

        $names->remove($image->getId());
        self::assertNotContains('iconprop-removed', $names->names());

        self::assertSame('cms:iconprop-removed', $validator->validate($document)->section('card')?->props['icon'], 'The page keeps its icon: only the shape of the name is checked.');
    }

    public function testCmsThemeIconsIsAFunctionOfTwigAndLeavesTheSandboxAlone(): void
    {
        $twig = $this->getService(Environment::class);

        self::assertNotNull($twig->getFunction('cms_theme_icons'));
        self::assertSame(
            implode(',', $this->getService(ThemeIcons::class)->names()),
            $twig->createTemplate('{{ cms_theme_icons()|join(",") }}')->render(),
        );
        self::assertNotContains('cms_theme_icons', \TheliaCMS\Content\Site\SiteTemplateRenderer::FUNCTIONS);
    }

    private function site(string $code): void
    {
        $this->getService(SiteComponentRepository::class)->save(new SiteComponent(
            $code,
            'Icon card',
            'A heading with an icon.',
            [
                ['name' => 'title', 'type' => 'text', 'label' => 'Heading'],
                ['name' => 'icon', 'type' => 'icon', 'label' => 'Icon'],
            ],
            ['title' => 'Hello', 'icon' => 'cms:iconprop-first'],
            '<section>{{ props.title }}</section>',
        ), SiteComponentRepository::STATUS_DRAFT);
    }

    /**
     * @param list<Section> $sections
     */
    private function pageWith(array $sections): CmsPage
    {
        $page = $this->createPage('Icon page', published: false);
        // A screen rendered before the sections are, as the screens of the module expect.
        $this->client->request('GET', '/admin/cms/pages');
        $this->getService(CmsPageWriter::class)->saveContent($page, $this->locale(), new Document($sections));

        return $page;
    }

    private function url(CmsPage $page): string
    {
        return \sprintf('/admin/cms/pages/%d/content?edit_language_id=%d', $page->getId(), Lang::getDefaultLanguage()->getId());
    }

    private function svgImage(string $body): LibraryImage
    {
        $fileName = bin2hex(random_bytes(10)).'.svg';
        $path = TheliaLibrary::getImageDirectory().$fileName;
        file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 2 2">'.$body.'</svg>');
        $this->files[] = $path;

        $image = new LibraryImage();
        $image->setLocale($this->locale())->setFileName($fileName)->setTitle($fileName);
        $image->save();

        return $image;
    }
}
