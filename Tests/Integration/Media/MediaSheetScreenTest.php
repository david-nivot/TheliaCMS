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

use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Thelia\Model\LangQuery;
use TheliaCMS\Media\Admin\CmsMediaType;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;
use TheliaCMS\Tests\Support\CeilingImages;
use TheliaLibrary\Model\LibraryImage;

/**
 * The sheet of an image: where its description stands in every language, the
 * tags it can take, and a deletion asked in the screen.
 */
final class MediaSheetScreenTest extends AdminScreenTestCase
{
    public function testTheSheetSaysInWhichLanguagesTheImageIsDescribed(): void
    {
        $other = $this->otherLocale();
        $image = $this->image('Fauteuil', alt: 'Un fauteuil vert');

        $states = $this->states($this->screen($this->sheet($image)));

        self::assertSame('described', $states[$this->locale()]);
        self::assertSame('missing', $states[$other], 'Described in one language, still to describe in the others.');

        $image->setDecorative(1)->save();
        $states = $this->states($this->screen($this->sheet($image)));

        self::assertSame(['decorative'], array_values(array_unique($states)), 'Decorative holds for every language.');
    }

    public function testEachLanguageLeadsToTheSheetInThatLanguageKeepingTheList(): void
    {
        $image = $this->image('Chaise');
        $other = LangQuery::create()->findOneByLocale($this->otherLocale());
        self::assertNotNull($other);

        $link = $this->screen($this->sheet($image).'?tag=Chaises')
            ->filter(\sprintf('[data-testid="cms-media-descriptions"] a[data-locale="%s"]', $other->getLocale()))
            ->attr('href');

        self::assertStringContainsString(\sprintf('/admin/cms/media/%d?', $image->getId()), (string) $link);
        self::assertStringContainsString('edit_language_id='.$other->getId(), (string) $link);
        self::assertStringContainsString('tag=Chaises', (string) $link);
    }

    public function testTheTagsOfTheLibraryAreSuggested(): void
    {
        $library = $this->getService(CmsMediaLibrary::class);
        $library->retag($this->image('Autre'), ['Fauteuils', 'Salon']);
        $image = $this->image('Sans étiquette');

        $suggested = json_decode((string) $this->screen($this->sheet($image))->filter('input[data-cms-media-tags]')->attr('data-cms-media-tags'), true);

        self::assertSame(['Fauteuils', 'Salon'], $suggested);
    }

    public function testDeletingIsAskedInADialogOfTheScreen(): void
    {
        $screen = $this->screen($this->sheet($this->image('À supprimer')));
        $form = $screen->filter('form[data-cms-media-delete]');

        self::assertSame(1, $form->count());
        self::assertNull($form->attr('onsubmit'), 'Not the confirm() of the browser.');
        self::assertSame(1, $screen->filter('dialog[data-cms-media-delete-dialog] [data-cms-media-delete-confirm]')->count());
        self::assertSame(1, $screen->filter('script[src*="media-sheet.js"]')->count());
    }

    public function testAReplacingFileIsShownBeforeTheSheetIsSaved(): void
    {
        $screen = $this->screen($this->sheet($this->image('À remplacer')));

        self::assertSame(1, $screen->filter('input[type="file"][data-cms-media-replace]')->count());
        self::assertSame(1, $screen->filter('[data-cms-media-replace-preview] img')->count());
    }

    public function testAReplacingFileAboveTheCeilingIsRefusedInFrench(): void
    {
        if (UploadedFile::getMaxFilesize() <= CmsMediaType::MAX_IMAGE_BYTES) {
            self::markTestSkipped('upload_max_filesize is below the ceiling of an image: PHP refuses the heavy file first.');
        }

        $french = LangQuery::create()->findOneByLocale('fr_FR');
        self::assertNotNull($french);
        $image = $this->image('À remplacer');
        $url = $this->sheet($image).'?lang=fr_FR&edit_language_id='.$french->getId();

        $token = (string) $this->screen($url)->filter('form:not([data-cms-media-delete]) input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', $url, ['_token' => $token, 'cms_media' => ['decorative' => '1']], ['cms_media' => ['file' => CeilingImages::heavy()]], self::SAME_SITE);

        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'The sheet is shown again, not saved.');
        self::assertStringContainsString('L’image est trop lourde', (string) $this->client->getResponse()->getContent(), 'The refusal is said in the language of the editor.');
    }

    /**
     * @return array<string, string> state by locale
     */
    private function states(Crawler $screen): array
    {
        $states = [];

        $screen->filter('[data-testid="cms-media-descriptions"] a')->each(static function (Crawler $badge) use (&$states): void {
            $states[(string) $badge->attr('data-locale')] = (string) $badge->attr('data-state');
        });

        return $states;
    }

    private function sheet(LibraryImage $image): string
    {
        return \sprintf('/admin/cms/media/%d', $image->getId());
    }

    private function image(string $title, ?string $alt = null): LibraryImage
    {
        $image = (new LibraryImage())->setFileName(bin2hex(random_bytes(6)).'.jpg')->setWidth(800)->setHeight(600)->setDecorative(0);
        $image->setLocale($this->locale())->setTitle($title)->setAlt($alt);
        $image->save();

        $this->getService(CmsMediaLibrary::class)->tag($image);

        return $image;
    }

    private function otherLocale(): string
    {
        $other = LangQuery::create()->filterByActive(1)->filterByLocale($this->locale(), Criteria::NOT_EQUAL)->findOne();

        if (null === $other) {
            self::markTestSkipped('The shop has a single language.');
        }

        return (string) $other->getLocale();
    }
}
