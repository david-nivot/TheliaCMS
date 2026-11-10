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

use TheliaCMS\Media\Icon\IconNames;
use TheliaCMS\Media\Icon\KnownIcons;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\Tests\Integration\CountsStatements;
use TheliaLibrary\Model\LibraryImage;
use TheliaLibrary\TheliaLibrary;

/**
 * The live names are read once per request: `KnownIcons::isKnown()` is asked
 * for every icon of every page drawn.
 */
final class IconNamesMemoryTest extends CmsIntegrationTestCase
{
    use CountsStatements;

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

    public function testTwoQuestionsAboutAnIconCostOneQuery(): void
    {
        $names = $this->getService(IconNames::class);
        $known = $this->getService(KnownIcons::class);
        $image = $this->svgImage();
        $names->assign($image->getId(), 'iconmemory-live');
        $names->reset();

        $statements = $this->statementsFor(static function () use ($known): void {
            $known->isKnown('cms:iconmemory-live');
            $known->isKnown('cms:iconmemory-live');
            $known->isKnown('cms:iconmemory-gone');
        });

        self::assertSame(1, $statements);
    }

    public function testAWriteIsSeenByTheNextRead(): void
    {
        $names = $this->getService(IconNames::class);
        $first = $this->svgImage();
        $second = $this->svgImage();

        self::assertNull($names->fileOf('iconmemory-a'));
        $names->assign($first->getId(), 'iconmemory-a');
        self::assertNotNull($names->fileOf('iconmemory-a'));
        self::assertContains('iconmemory-a', $names->names());

        $names->assign($second->getId(), 'iconmemory-b');
        self::assertTrue($names->isTaken('iconmemory-b'));

        $names->remove($first->getId());
        self::assertNull($names->fileOf('iconmemory-a'));
        self::assertNotContains('iconmemory-a', $names->names());
    }

    public function testAResetForgetsWhatWasRead(): void
    {
        $names = $this->getService(IconNames::class);
        $image = $this->svgImage();
        $names->assign($image->getId(), 'iconmemory-reset');
        self::assertNotNull($names->fileOf('iconmemory-reset'));

        $this->getPropelConnection()->prepare('DELETE FROM cms_icon WHERE image_id = :image')->execute(['image' => $image->getId()]);
        self::assertNotNull($names->fileOf('iconmemory-reset'), 'Read once, kept.');

        $names->reset();

        self::assertNull($names->fileOf('iconmemory-reset'));
    }

    private function svgImage(): LibraryImage
    {
        $fileName = bin2hex(random_bytes(10)).'.svg';
        $path = TheliaLibrary::getImageDirectory().$fileName;
        file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 2 2"><path d="M0 0h1v1z"/></svg>');
        $this->files[] = $path;

        $image = new LibraryImage();
        $image->setLocale($this->locale())->setFileName($fileName)->setTitle($fileName);
        $image->save();

        return $image;
    }
}
