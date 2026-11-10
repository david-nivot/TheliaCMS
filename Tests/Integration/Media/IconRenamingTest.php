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

use Symfony\Contracts\Translation\TranslatorInterface;
use TheliaCMS\Media\Admin\CmsMediaWriter;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Media\Icon\IconNames;
use TheliaCMS\Media\Icon\IconRenaming;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\TheliaCMS;
use TheliaLibrary\Model\LibraryImage;
use TheliaLibrary\TheliaLibrary;

/**
 * The rule that an icon a page cites neither is renamed nor loses its name,
 * and the writing that follows when it does not apply.
 */
final class IconRenamingTest extends CmsIntegrationTestCase
{
    private const string CLEAN = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M0 0h24v24H0z"/></svg>';

    protected function tearDown(): void
    {
        foreach ($this->getService(CmsMediaLibrary::class)->images() as $image) {
            $file = TheliaLibrary::getImageDirectory().$image->getFileName();

            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function testAnIconThatNobodyCitesIsRenamedWithOrWithoutItsPrefix(): void
    {
        $icon = $this->icon('renaming-before');

        $change = $this->renaming()->rename((int) $icon->getId(), ' cms:renaming-after ');

        self::assertTrue($change->made);
        self::assertSame($this->said('Icon renamed.'), $change->message);
        self::assertSame('renaming-after', $this->getService(IconNames::class)->nameOf((int) $icon->getId()));
    }

    public function testRenamingToTheCurrentNameIsToldAndWritesNothing(): void
    {
        $icon = $this->icon('renaming-same');
        $other = $this->icon('renaming-neighbour');
        $before = $this->getPropelConnection()->query('SELECT COUNT(*) FROM cms_icon')->fetchColumn();

        foreach (['renaming-same', ' cms:renaming-same '] as $typed) {
            $change = $this->renaming()->rename((int) $icon->getId(), $typed);

            self::assertFalse($change->made);
            self::assertSame($this->said('The name of the icon has not changed.'), $change->message);
        }

        self::assertSame('renaming-same', $this->getService(IconNames::class)->nameOf((int) $icon->getId()));
        self::assertSame('renaming-neighbour', $this->getService(IconNames::class)->nameOf((int) $other->getId()));
        self::assertEquals($before, $this->getPropelConnection()->query('SELECT COUNT(*) FROM cms_icon')->fetchColumn());
    }

    public function testANameThatIsInvalidOrTakenIsToldAndNothingChanges(): void
    {
        $icon = $this->icon('renaming-first');
        $this->icon('renaming-second');

        $invalid = $this->renaming()->rename((int) $icon->getId(), 'Not Valid');
        $taken = $this->renaming()->rename((int) $icon->getId(), 'renaming-second');

        self::assertFalse($invalid->made);
        self::assertSame($this->said('The icon name is made of lowercase letters and digits, separated by single hyphens, %d characters at most.', 64), $invalid->message);
        self::assertFalse($taken->made);
        self::assertSame($this->said('The icon name "%s" is already used by another image.', 'cms:renaming-second'), $taken->message);
        self::assertSame('renaming-first', $this->getService(IconNames::class)->nameOf((int) $icon->getId()));
    }

    public function testAnImageThatIsNotAnIconIsNeitherRenamedNorStripped(): void
    {
        $image = $this->getService(CmsMediaWriter::class)->addPreparedSvg(self::CLEAN, 'renaming-plain.svg', $this->locale());

        $renamed = $this->renaming()->rename((int) $image->getId(), 'renaming-plain');
        $stripped = $this->renaming()->removeName((int) $image->getId());

        self::assertFalse($renamed->made);
        self::assertSame($this->said('This image is not an icon.'), $renamed->message);
        self::assertFalse($stripped->made);
        self::assertSame($this->said('This image is not an icon.'), $stripped->message);
        self::assertNull($this->getService(IconNames::class)->nameOf((int) $image->getId()));
    }

    public function testAnIconThatAPageCitesKeepsItsNameWhateverIsAsked(): void
    {
        $icon = $this->icon('renaming-cited');
        $page = $this->createPage('Cites an icon', published: false);
        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne();
        self::assertNotNull($content);
        $content->setDraftDocument('{"icon":"cms:renaming-cited"}')->save();

        $renamed = $this->renaming()->rename((int) $icon->getId(), 'renaming-other');
        $stripped = $this->renaming()->removeName((int) $icon->getId());

        self::assertFalse($renamed->made);
        self::assertSame($this->said('The icon is cited by a page, an item or a component of the site: it cannot be renamed.'), $renamed->message);
        self::assertFalse($stripped->made);
        self::assertSame($this->said('The icon is cited by a page, an item or a component of the site: its name cannot be removed.'), $stripped->message);
        self::assertSame('renaming-cited', $this->getService(IconNames::class)->nameOf((int) $icon->getId()));
    }

    public function testTheNameOfAnIconNobodyCitesIsTakenAway(): void
    {
        $icon = $this->icon('renaming-removed');

        $change = $this->renaming()->removeName((int) $icon->getId());

        self::assertTrue($change->made);
        self::assertSame($this->said('The image is no longer an icon.'), $change->message);
        self::assertNull($this->getService(IconNames::class)->nameOf((int) $icon->getId()));
    }

    private function renaming(): IconRenaming
    {
        return $this->getService(IconRenaming::class);
    }

    private function icon(string $name): LibraryImage
    {
        return $this->getService(CmsMediaWriter::class)->addPreparedSvg(self::CLEAN, $name.'.svg', $this->locale(), $name);
    }

    private function said(string $english, mixed ...$values): string
    {
        return vsprintf($this->getService(TranslatorInterface::class)->trans($english, [], TheliaCMS::DOMAIN_NAME, $this->locale()), $values);
    }
}
