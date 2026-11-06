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

use Thelia\Core\Security\AccessManager;
use Thelia\Test\FixtureFactory;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Security\CmsResources;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;
use TheliaCMS\Tests\Support\Mp4Videos;
use TheliaLibrary\Model\LibraryImage;
use TheliaLibrary\Model\LibraryImageQuery;
use TheliaLibrary\Model\LibraryImageTagQuery;
use TheliaLibrary\Model\Map\LibraryImageTableMap;

/**
 * Several images of the list answered at once: a tag, the decorative flag,
 * and a deletion that keeps what a page still shows.
 */
final class MediaBulkTest extends AdminScreenTestCase
{
    private const string LIST = '/admin/cms/media';

    private const string BULK = '/admin/cms/media/bulk';

    public function testATagIsGivenToTheSelectedImagesAndTakenOffAgain(): void
    {
        $library = $this->getService(CmsMediaLibrary::class);
        $first = $this->image('Première');
        $second = $this->image('Deuxième');
        $other = $this->image('Autre');

        self::assertSame(302, $this->bulk('tag-add', [$first, $second], 'Salon'));
        self::assertSame(['Salon'], $library->tagsOf($this->reloaded($first)));
        self::assertSame(['Salon'], $library->tagsOf($this->reloaded($second)));
        self::assertSame([], $library->tagsOf($this->reloaded($other)), 'An image not selected keeps its tags.');

        $this->bulk('tag-add', [$first], 'Salon');
        self::assertSame(2, LibraryImageTagQuery::create()->filterByImageId($first->getId())->count(), 'A tag given twice is carried once, beside the one of the CMS.');

        $this->bulk('tag-remove', [$first], 'Salon');
        self::assertSame([], $library->tagsOf($this->reloaded($first)));
        self::assertNotNull($library->ownedImage((int) $first->getId()), 'The tag of the CMS stays.');
    }

    public function testTheSelectedImagesAreMarkedDecorativeAndBack(): void
    {
        $image = $this->image('Séparateur', alt: 'Un trait');

        $this->bulk('decorative', [$image]);
        self::assertSame(1, (int) $this->reloaded($image)->getDecorative());
        self::assertSame('Un trait', $this->reloaded($image)->setLocale($this->locale())->getAlt(), 'Its texts are kept.');

        $this->bulk('not-decorative', [$image]);
        self::assertSame(0, (int) $this->reloaded($image)->getDecorative());
    }

    public function testAVideoAmongTheSelectedImagesIsLeftOutOfTheDecorativeAnswerAndOfItsCount(): void
    {
        $image = $this->image('Séparateur');
        $video = Mp4Videos::row($this->getService(CmsMediaLibrary::class), $this->locale());

        self::assertSame(302, $this->bulk('decorative', [$image, $video]));
        $this->client->followRedirect();

        self::assertStringContainsString('1 image(s) marked decorative.', html_entity_decode((string) $this->client->getResponse()->getContent()), 'The count is what changed, not what was selected.');
        self::assertSame(1, (int) $this->reloaded($image)->getDecorative());
        self::assertSame(0, (int) $this->reloaded($video)->getDecorative());

        self::assertSame(302, $this->bulk('not-decorative', [$video]));
        $this->client->followRedirect();

        self::assertStringContainsString('Select at least one image.', html_entity_decode((string) $this->client->getResponse()->getContent()), 'Nothing changed, nothing announced.');
    }

    public function testDeletingKeepsTheImagesAPageStillShowsAndSaysWhich(): void
    {
        $used = $this->image('Affichée');
        $unused = $this->image('Oubliée');
        $this->createPage('Page avec image')->setImageId($used->getId())->save();

        self::assertSame(302, $this->bulk('delete', [$used, $unused]));

        self::assertTrue($this->exists($used), 'A page still shows it.');
        self::assertFalse($this->exists($unused));
        self::assertStringContainsString('Affichée', $this->screen(self::LIST)->html(), 'The screen names the image it kept.');
    }

    public function testAnImageTheCmsDoesNotOwnIsLeftAlone(): void
    {
        $foreign = (new LibraryImage())->setFileName('produit.jpg');
        $foreign->setLocale($this->locale())->setTitle('Image d’un produit');
        $foreign->save();

        $this->bulk('delete', [$foreign]);

        self::assertTrue($this->exists($foreign));
    }

    public function testDeletingTakesTheRightToDeleteMedia(): void
    {
        $image = $this->image('Protégée');
        $this->logInAs((new FixtureFactory($this->getPropelConnection()))->restrictedAdmin([
            CmsResources::MEDIA => [AccessManager::VIEW, AccessManager::UPDATE],
        ]));

        self::assertSame(403, $this->bulk('delete', [$image]));
        self::assertTrue($this->exists($image));
    }

    public function testTheListCarriesABoxPerImageForTheBulkForm(): void
    {
        $image = $this->image('Cochable');
        $screen = $this->screen(self::LIST.'?tag=Salon');

        self::assertSame(0, $screen->filter('[data-testid="cms-media-bulk"]')->count(), 'Nothing to select: no image in this list.');

        $screen = $this->screen(self::LIST);
        self::assertSame('cms-media-bulk', $screen->filter(\sprintf('[data-testid="cms-media-card-%d"] input[name="images[]"]', $image->getId()))->attr('form'));
        self::assertSame(1, $screen->filter('form#cms-media-bulk select[name="action"] option[value="delete"]')->count());
    }

    /**
     * @param list<LibraryImage> $images
     */
    private function bulk(string $action, array $images, string $tag = ''): int
    {
        // The token of the back office, which every form of the list carries.
        $token = $this->tokenOfTheForm($this->screen(self::LIST), self::LIST);

        return $this->send('POST', self::BULK, [
            '_token' => $token,
            'action' => $action,
            'tag' => $tag,
            'images' => array_map(static fn (LibraryImage $image): string => (string) $image->getId(), $images),
        ]);
    }

    private function exists(LibraryImage $image): bool
    {
        LibraryImageTableMap::clearInstancePool();

        return null !== LibraryImageQuery::create()->findPk($image->getId());
    }

    private function reloaded(LibraryImage $image): LibraryImage
    {
        LibraryImageTableMap::clearInstancePool();
        $found = LibraryImageQuery::create()->findPk($image->getId());
        self::assertNotNull($found);

        return $found;
    }

    private function image(string $title, ?string $alt = null): LibraryImage
    {
        $image = (new LibraryImage())->setFileName(bin2hex(random_bytes(6)).'.jpg')->setWidth(800)->setHeight(600)->setDecorative(0);
        $image->setLocale($this->locale())->setTitle($title)->setAlt($alt);
        $image->save();

        $this->getService(CmsMediaLibrary::class)->tag($image);

        return $image;
    }
}
