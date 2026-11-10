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

namespace TheliaCMS\Tests\Unit\Media\Icon;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\UX\Icons\Exception\IconNotFoundException;
use Symfony\UX\Icons\IconFactory;
use TheliaCMS\Media\Icon\CmsIconRegistry;
use TheliaCMS\Media\Icon\IconFileFinder;

final class CmsIconRegistryTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/theliacms-icons-'.bin2hex(random_bytes(6));
        (new Filesystem())->mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testAnIconOutsideTheSetThrowsWithoutReadingTheNames(): void
    {
        $registry = new CmsIconRegistry($this->finder([]), new IconFactory(), $logger = $this->logger());

        foreach (['home', 'lucide:home', 'cmsx:home'] as $name) {
            try {
                $registry->get($name);
                self::fail(\sprintf('"%s" is not in the set and has to throw.', $name));
            } catch (IconNotFoundException) {
                // expected
            }
        }

        self::assertSame([], $logger->records);
    }

    public function testAKnownIconDrawsTheFile(): void
    {
        $file = $this->directory.'/known.svg';
        file_put_contents($file, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><path d="M0 0h10v10z"/></svg>');
        $registry = new CmsIconRegistry($this->finder(['home' => $file]), new IconFactory(), $logger = $this->logger());

        $icon = $registry->get('cms:home');

        self::assertStringContainsString('<path d="M0 0h10v10z"', $icon->getInnerSvg());
        self::assertSame('0 0 10 10', $icon->getAttributes()['viewBox']);
        self::assertSame([], $logger->records);
    }

    public function testAnUnknownIconIsEmptyAndWarnsInsteadOfThrowing(): void
    {
        $registry = new CmsIconRegistry($this->finder([]), new IconFactory(), $logger = $this->logger());

        $icon = $registry->get('cms:nowhere');

        self::assertSame('', $icon->getInnerSvg());
        self::assertSame('0 0 24 24', $icon->getAttributes()['viewBox']);
        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame('cms:nowhere', $logger->records[0]['context']['icon']);
    }

    public function testAKnownNameWhoseFileIsGoneIsEmptyAndWarns(): void
    {
        $registry = new CmsIconRegistry($this->finder(['home' => $this->directory.'/missing.svg']), new IconFactory(), $logger = $this->logger());

        self::assertSame('', $registry->get('cms:home')->getInnerSvg());
        self::assertCount(1, $logger->records);
    }

    public function testAFileThatIsNoSvgIsEmptyAndWarns(): void
    {
        $file = $this->directory.'/broken.svg';
        file_put_contents($file, '<html></html>');
        $registry = new CmsIconRegistry($this->finder(['home' => $file]), new IconFactory(), $logger = $this->logger());

        self::assertSame('', $registry->get('cms:home')->getInnerSvg());
        self::assertCount(1, $logger->records);
    }

    /**
     * @param array<string, string> $files
     */
    private function finder(array $files): IconFileFinder
    {
        return new class($files) implements IconFileFinder {
            /** @param array<string, string> $files */
            public function __construct(private readonly array $files)
            {
            }

            public function fileOf(string $name): ?string
            {
                return $this->files[$name] ?? null;
            }

            public function names(): array
            {
                return array_keys($this->files);
            }
        };
    }

    private function logger(): object
    {
        return new class extends AbstractLogger {
            /** @var list<array{level: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'context' => $context];
            }
        };
    }
}
