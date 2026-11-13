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

use Psr\Log\AbstractLogger;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Contracts\Cache\CacheInterface;
use TheliaCMS\Media\Admin\CmsMediaWriter;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Media\Icon\IconCacheInvalidator;
use TheliaCMS\Media\Icon\IconNames;
use TheliaCMS\Media\Svg\PreparedSvgStore;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaLibrary\Model\LibraryImage;
use TheliaLibrary\Service\LibraryImageService;
use TheliaLibrary\Service\Svg\SvgSanitizerInterface;
use TheliaLibrary\TheliaLibrary;

/**
 * A file added for an icon name is deleted when the name cannot be given; when
 * that deletion fails too, the failure is logged and the error that stopped the
 * name is the one that goes up.
 */
final class PreparedSvgCleanupTest extends CmsIntegrationTestCase
{
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

    public function testACleanupThatFailsIsLoggedAndTheErrorOfTheNameGoesUp(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{string, string, array<mixed>}> */
            public array $records = [];

            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $level, (string) $message, $context];
            }
        };
        $real = $this->getService(LibraryImageService::class);
        $images = new class($real) extends LibraryImageService {
            public function __construct(private readonly LibraryImageService $real)
            {
            }

            public function createImage(File $file, ?string $title = null, ?string $locale = null): LibraryImage
            {
                return $this->real->createImage($file, $title, $locale);
            }

            public function deleteImage($imageId): bool
            {
                throw new \LogicException('The file cannot be deleted.');
            }
        };
        $writer = new CmsMediaWriter(
            $images,
            $this->getService(CmsMediaLibrary::class),
            $this->getService(SvgSanitizerInterface::class),
            $this->getService(PreparedSvgStore::class),
            new IconNames(new IconCacheInvalidator(new class implements CacheInterface {
                public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
                {
                    throw new \LogicException('Nothing is read from this cache.');
                }

                public function delete(string $key): bool
                {
                    throw new \RuntimeException('The cache is down.');
                }
            })),
            $this->getService(IconCacheInvalidator::class),
            $this->getService(\TheliaCMS\Media\Video\VideoIntake::class),
            $this->getService(\TheliaCMS\Media\Video\VideoConversions::class),
            $logger,
        );

        try {
            $writer->addPreparedSvg('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 4 4"><path d="M3 3h1v1z"/></svg>', 'broken.svg', $this->locale(), 'cleanup-broken');
            self::fail('The error of the name goes up.');
        } catch (\RuntimeException $exception) {
            self::assertSame('The cache is down.', $exception->getMessage(), 'Not the failure of the cleanup: the one that stopped the name.');
        }

        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0][0]);
        self::assertSame('cleanup-broken', $logger->records[0][2]['name']);
        self::assertSame('The file cannot be deleted.', $logger->records[0][2]['exception']->getMessage());
    }
}
