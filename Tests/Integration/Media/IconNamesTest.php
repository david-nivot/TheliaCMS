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

use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\UX\Icons\IconFactory;
use Symfony\UX\Icons\Registry\CacheIconRegistry;
use TheliaCMS\Media\Admin\CmsMediaWriter;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Media\Icon\CmsIconRegistry;
use TheliaCMS\Media\Icon\IconCacheInvalidator;
use TheliaCMS\Media\Icon\IconNames;
use TheliaCMS\Media\Icon\IconNameTakenException;
use TheliaCMS\Media\Icon\InvalidIconNameException;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaLibrary\Model\LibraryImage;
use TheliaLibrary\Model\LibraryImageQuery;
use TheliaLibrary\Model\Map\LibraryImageTableMap;
use TheliaLibrary\TheliaLibrary;

/**
 * The names of the SVGs of the library, and the cache of ux-icons that has to
 * follow them.
 */
final class IconNamesTest extends CmsIntegrationTestCase
{
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

    public function testANameIsGivenToAnImageAndReadBack(): void
    {
        $names = $this->getService(IconNames::class);
        $image = $this->svgImage('<path d="M0 0h1v1z"/>');

        $names->assign($image->getId(), 'iconnames-ok');

        self::assertSame('iconnames-ok', $names->nameOf($image->getId()));
        self::assertSame(TheliaLibrary::getImageDirectory().$image->getFileName(), $names->fileOf('iconnames-ok'));
        self::assertContains('iconnames-ok', $names->names());
        self::assertNull($names->fileOf('iconnames-other'));
    }

    public function testAnImageThatIsRenamedLeavesItsFormerName(): void
    {
        $names = $this->getService(IconNames::class);
        $image = $this->svgImage('<path d="M0 0h1v1z"/>');
        $names->assign($image->getId(), 'iconnames-before');

        $names->assign($image->getId(), 'iconnames-after');

        self::assertNull($names->fileOf('iconnames-before'));
        self::assertNotNull($names->fileOf('iconnames-after'));
        self::assertSame('iconnames-after', $names->nameOf($image->getId()));
    }

    public function testANameOfAnotherLiveImageIsRefused(): void
    {
        $names = $this->getService(IconNames::class);
        $first = $this->svgImage('<path d="M0 0h1v1z"/>');
        $second = $this->svgImage('<path d="M1 1h1v1z"/>');
        $names->assign($first->getId(), 'iconnames-taken');

        try {
            $names->assign($second->getId(), 'iconnames-taken');
            self::fail('The name belongs to a live image.');
        } catch (IconNameTakenException) {
            // expected
        }

        self::assertSame(TheliaLibrary::getImageDirectory().$first->getFileName(), $names->fileOf('iconnames-taken'));
        self::assertNull($names->nameOf($second->getId()));
    }

    public function testAnInvalidNameIsRefused(): void
    {
        $image = $this->svgImage('<path d="M0 0h1v1z"/>');

        $this->expectException(InvalidIconNameException::class);

        $this->getService(IconNames::class)->assign($image->getId(), 'Not Valid');
    }

    public function testAnOrphanRowIsIgnoredThenReplacedByTheNextImageThatTakesItsName(): void
    {
        $names = $this->getService(IconNames::class);
        $gone = $this->svgImage('<path d="M0 0h1v1z"/>');
        $names->assign($gone->getId(), 'iconnames-orphan');
        // The library deletes an image without telling anyone.
        $gone->delete();
        LibraryImageTableMap::clearInstancePool();
        self::assertNull(LibraryImageQuery::create()->findPk($gone->getId()));
        // The names are kept for the request: a deletion nobody tells about is seen by the next one.
        $names->reset();

        self::assertNull($names->fileOf('iconnames-orphan'));
        self::assertNotContains('iconnames-orphan', $names->names());

        $next = $this->svgImage('<path d="M2 2h1v1z"/>');
        $names->assign($next->getId(), 'iconnames-orphan');

        self::assertSame(TheliaLibrary::getImageDirectory().$next->getFileName(), $names->fileOf('iconnames-orphan'));
        self::assertSame(1, $this->rowsNamed('iconnames-orphan'));
    }

    public function testANameIsTakenOnlyWhileItsImageIsInTheLibrary(): void
    {
        $names = $this->getService(IconNames::class);
        $image = $this->svgImage('<path d="M0 0h1v1z"/>');

        self::assertFalse($names->isTaken('iconnames-taken'));

        $names->assign($image->getId(), 'iconnames-taken');
        self::assertTrue($names->isTaken('iconnames-taken'));
        self::assertFalse($names->isTaken('iconnames-taken-not'), 'Only the whole name.');

        // The library deletes an image without telling anyone: its row no longer holds the name.
        $image->delete();
        LibraryImageTableMap::clearInstancePool();
        $names->reset();
        self::assertFalse($names->isTaken('iconnames-taken'));
    }

    public function testTheNameOfAnImageIsRemoved(): void
    {
        $names = $this->getService(IconNames::class);
        $image = $this->svgImage('<path d="M0 0h1v1z"/>');
        $names->assign($image->getId(), 'iconnames-removed');

        $names->remove($image->getId());

        self::assertNull($names->nameOf($image->getId()));
        self::assertNull($names->fileOf('iconnames-removed'));
    }

    public function testEveryWriteThatChangesTheDrawingEmptiesTheCacheOfUxIcons(): void
    {
        $pool = new ArrayAdapter();
        $names = new IconNames(new IconCacheInvalidator($pool));
        $chain = new CacheIconRegistry(new CmsIconRegistry($names, new IconFactory(), new NullLogger()), $pool);
        $image = $this->svgImage('<path d="M0 0h1v1z"/>');

        // Unknown: the empty icon is cached.
        self::assertSame('', $chain->get('cms:iconnames-cache')->getInnerSvg());

        $names->assign($image->getId(), 'iconnames-cache');
        self::assertStringContainsString('M0 0h1v1z', $chain->get('cms:iconnames-cache')->getInnerSvg());

        $names->assign($image->getId(), 'iconnames-cache-renamed');
        self::assertSame('', $chain->get('cms:iconnames-cache')->getInnerSvg());
        self::assertStringContainsString('M0 0h1v1z', $chain->get('cms:iconnames-cache-renamed')->getInnerSvg());

        $names->remove($image->getId());
        self::assertSame('', $chain->get('cms:iconnames-cache-renamed')->getInnerSvg());
    }

    public function testTheWriterOfTheMediaScreensVentsTheCacheWhenItDeletesOrReplacesAnImage(): void
    {
        $names = $this->getService(IconNames::class);
        $pool = $this->poolOfUxIcons($names);
        $writer = $this->getService(CmsMediaWriter::class);
        $chain = new CacheIconRegistry(new CmsIconRegistry($names, new IconFactory(), new NullLogger()), $pool);
        $image = $this->svgImage('<path d="M0 0h1v1z"/>');
        $this->getService(CmsMediaLibrary::class)->tag($image);
        $names->assign($image->getId(), 'iconnames-writer');
        self::assertStringContainsString('M0 0h1v1z', $chain->get('cms:iconnames-writer')->getInnerSvg());

        $writer->replaceWithPreparedSvg($image, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 4 4"><path d="M3 3h1v1z"/></svg>', 'new.svg', $this->locale());
        $image->reload();
        $this->files[] = TheliaLibrary::getImageDirectory().$image->getFileName();
        self::assertStringContainsString('M3 3h1v1z', $chain->get('cms:iconnames-writer')->getInnerSvg());

        $writer->delete($image);
        self::assertNull($names->fileOf('iconnames-writer'));
        self::assertSame(0, $this->rowsNamed('iconnames-writer'));
        self::assertSame('', $chain->get('cms:iconnames-writer')->getInnerSvg());
    }

    public function testAFileAddedForANameTakenMeanwhileIsNotAdded(): void
    {
        $names = $this->getService(IconNames::class);
        $holder = $this->svgImage('<path d="M0 0h1v1z"/>');
        $names->assign($holder->getId(), 'iconnames-raced');
        $before = LibraryImageQuery::create()->count();

        try {
            $this->getService(CmsMediaWriter::class)->addPreparedSvg('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 4 4"><path d="M3 3h1v1z"/></svg>', 'raced.svg', $this->locale(), 'iconnames-raced');
            self::fail('The name belongs to another image.');
        } catch (IconNameTakenException) {
            // expected
        }

        LibraryImageTableMap::clearInstancePool();
        self::assertSame($before, LibraryImageQuery::create()->count(), 'The image created for the name is deleted with it.');
        self::assertSame(TheliaLibrary::getImageDirectory().$holder->getFileName(), $names->fileOf('iconnames-raced'));
    }

    public function testAFileAddedForANameIsNotAddedWhateverStopsTheName(): void
    {
        $failing = new IconNames(new IconCacheInvalidator(new class implements CacheInterface {
            public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
            {
                throw new \LogicException('Nothing is read from this cache.');
            }

            public function delete(string $key): bool
            {
                throw new \RuntimeException('The cache is down.');
            }
        }));
        $writer = new CmsMediaWriter(
            $this->getService(\TheliaLibrary\Service\LibraryImageService::class),
            $this->getService(CmsMediaLibrary::class),
            $this->getService(\TheliaLibrary\Service\Svg\SvgSanitizerInterface::class),
            $this->getService(\TheliaCMS\Media\Svg\PreparedSvgStore::class),
            $failing,
            $this->getService(IconCacheInvalidator::class),
            $this->getService(\TheliaCMS\Media\Video\VideoIntake::class),
            $this->getService(\TheliaCMS\Media\Video\VideoConversions::class),
        );
        $before = LibraryImageQuery::create()->count();

        try {
            $writer->addPreparedSvg('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 4 4"><path d="M3 3h1v1z"/></svg>', 'broken.svg', $this->locale(), 'iconnames-broken');
            self::fail('The error of the name goes up as it was.');
        } catch (\RuntimeException $exception) {
            self::assertSame('The cache is down.', $exception->getMessage());
        }

        LibraryImageTableMap::clearInstancePool();
        self::assertSame($before, LibraryImageQuery::create()->count(), 'The image created for the name is deleted with it.');
    }

    /**
     * The pool the container gave the invalidator: the one of ux-icons, or the
     * wiring is broken.
     */
    private function poolOfUxIcons(IconNames $names): CacheInterface
    {
        $invalidator = (new \ReflectionProperty(IconNames::class, 'cache'))->getValue($names);
        $pool = (new \ReflectionProperty(IconCacheInvalidator::class, 'cache'))->getValue($invalidator);

        self::assertInstanceOf(CacheInterface::class, $pool, 'The invalidator holds the cache pool of ux-icons.');

        return $pool;
    }

    private function rowsNamed(string $name): int
    {
        $statement = $this->getPropelConnection()->prepare('SELECT COUNT(*) FROM cms_icon WHERE name = :name');
        $statement->execute(['name' => $name]);

        return (int) $statement->fetchColumn();
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
