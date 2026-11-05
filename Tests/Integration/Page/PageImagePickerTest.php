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

namespace TheliaCMS\Tests\Integration\Page;

use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Test\FixtureFactory;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Model\Map\CmsPageTableMap;
use TheliaCMS\Security\CmsResources;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;
use TheliaCMS\TheliaCMS;
use TheliaLibrary\Model\LibraryImage;

/**
 * The image of a page is picked in the media library like the image of a
 * section: the form holds its id, the picker reads the library by pages.
 */
final class PageImagePickerTest extends AdminScreenTestCase
{
    public function testTheImageOfAPageIsAnIdTheScreenDrawsAndPicks(): void
    {
        $page = $this->createPage('Avec image', published: false);

        $field = $this->screen($this->settings($page))->filter('input[name="cms_page[image]"]');

        self::assertSame(1, $field->count(), 'One field, not a grid of every image.');
        self::assertSame('number', $field->attr('type'));
        self::assertStringEndsWith('/admin/cms/media/picker', (string) $field->attr('data-cms-image-picker'));
        self::assertStringEndsWith('/admin/cms/media', (string) $field->attr('data-cms-image-upload'));
    }

    /**
     * A file the picker sends and the library refuses is told apart from the
     * grey line of the grid: its script receives the words naming the file
     * and the reason, and those of a sending that did not arrive.
     */
    public function testThePickerReceivesTheWordsOfARefusal(): void
    {
        $page = $this->createPage('Refus', published: false);
        $messages = json_decode($this->screen($this->settings($page))->filter('script[data-cms-media-picker-messages]')->text(), true, flags: \JSON_THROW_ON_ERROR);
        $translator = $this->getService(TranslatorInterface::class);

        self::assertSame($translator->trans('“%name%” was not added: %reason%', [], TheliaCMS::DOMAIN_NAME), $messages['refused']);
        self::assertStringContainsString('%name%', $messages['refused']);
        self::assertStringContainsString('%reason%', $messages['refused']);
        self::assertSame($translator->trans('The file did not arrive whole. Send it again.', [], TheliaCMS::DOMAIN_NAME), $messages['sendFailed']);
    }

    public function testWithoutTheRightToAddMediaThePickerSendsNothing(): void
    {
        $page = $this->createPage('Sans envoi', published: false);
        $this->logInAs((new FixtureFactory($this->getPropelConnection()))->restrictedAdmin([
            CmsResources::PAGE => [AccessManager::VIEW, AccessManager::UPDATE],
            CmsResources::MEDIA => [AccessManager::VIEW],
        ]));

        $field = $this->screen($this->settings($page))->filter('input[name="cms_page[image]"]');

        self::assertNull($field->attr('data-cms-image-upload'));
    }

    public function testAnImageOfTheLibraryIsKeptAndAnIdFromElsewhereIsNot(): void
    {
        $page = $this->createPage('Image choisie', published: false);
        $image = $this->image('Fauteuil');
        $foreign = (new LibraryImage())->setFileName('produit.jpg');
        $foreign->setLocale($this->locale())->setTitle('Image d’un produit');
        $foreign->save();

        self::assertSame($image->getId(), $this->save($page, (string) $image->getId()));
        self::assertNull($this->save($page, (string) $foreign->getId()), 'An image the CMS does not own is no image of a page.');
        self::assertNull($this->save($page, ''), 'Removed, the page has no image.');
    }

    private function save(CmsPage $page, string $imageId): ?int
    {
        $form = $this->screen($this->settings($page))->filter('form[name="cms_page"]')->form();
        $values = $form->getPhpValues();
        $values['cms_page']['image'] = $imageId;

        self::assertSame(302, $this->send('POST', $form->getUri(), $values));

        CmsPageTableMap::clearInstancePool();

        return CmsPageQuery::create()->findPk($page->getId())?->getImageId();
    }

    private function settings(CmsPage $page): string
    {
        return \sprintf('/admin/cms/pages/%d', $page->getId());
    }

    private function image(string $title): LibraryImage
    {
        $image = (new LibraryImage())->setFileName(bin2hex(random_bytes(6)).'.jpg')->setWidth(800)->setHeight(600)->setDecorative(0);
        $image->setLocale($this->locale())->setTitle($title)->setAlt('Une image');
        $image->save();

        $this->getService(CmsMediaLibrary::class)->tag($image);

        return $image;
    }
}
