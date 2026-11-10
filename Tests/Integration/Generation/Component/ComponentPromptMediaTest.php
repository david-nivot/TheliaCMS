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

namespace TheliaCMS\Tests\Integration\Generation\Component;

use TheliaCMS\Content\SectionCatalog;
use TheliaCMS\Content\Site\SiteComponentValidator;
use TheliaCMS\Front\BlockStyles;
use TheliaCMS\Generation\Component\ComponentPrompt;
use TheliaCMS\Generation\Component\ComponentRequest;
use TheliaCMS\Generation\Component\SampleMedia;
use TheliaCMS\Generation\ShopContext;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Skill\SkillRepository;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\Tests\Unit\Content\Site\PriceTable;
use TheliaLibrary\Model\LibraryImage;

/**
 * What the generator of components is told of the media library: the pictures
 * and videos its sample may show, and the rule to choose among them.
 */
final class ComponentPromptMediaTest extends CmsIntegrationTestCase
{
    private const string SECTION = '# Pictures and videos for the sample';

    public function testAnEmptyLibraryAddsNoSectionAndTheRuleSaysNull(): void
    {
        $message = $this->prompt()->userMessage(new ComponentRequest('Une galerie.', $this->locale()));
        $rules = $this->rules();

        self::assertStringNotContainsString('Pictures and videos for the sample', $message);
        self::assertStringContainsString('or null when none of them fits or when the message lists none', $rules);
        self::assertStringContainsString('with no list, every `image` and `video` of the sample is null', $rules);
    }

    public function testTheMediaAreListedOneLineEachWithTheirIdKindTextAndSize(): void
    {
        $landscape = $this->media('image/jpeg', 'Atelier', 'Établi d\'atelier avec des outils', 1600, 900);
        $video = $this->media('video/mp4', 'Visite de l\'atelier', 'ignored for a video', null, null);
        $untitled = $this->media('image/png', '', '', 400, 200);

        $message = $this->prompt()->userMessage(new ComponentRequest('Une galerie.', $this->locale()));
        $section = substr($message, (int) strpos($message, self::SECTION));
        $section = substr($section, 0, (int) strpos($section, '# The component asked'));

        self::assertStringContainsString(\sprintf("\n- %d (image, 1600 × 900): Établi d'atelier avec des outils\n", $landscape), $section, 'The alt text is what the picture shows.');
        self::assertStringContainsString(\sprintf("\n- %d (video): Visite de l'atelier\n", $video), $section, 'A video has no alt text: its title, and no size.');
        self::assertStringContainsString(\sprintf("\n- %d (image, 400 × 200): no description\n", $untitled), $section);
        self::assertStringContainsString('takes the id of one of them for an `image` or `video` prop', $section, 'The rule is next to the list.');
        self::assertLessThan(strpos($message, '# The component asked'), strpos($message, self::SECTION), 'The list comes before the request.');
        self::assertLessThan(strpos($section, \sprintf('- %d (video)', $video)), strpos($section, \sprintf('- %d (image, 400', $untitled)), 'Pictures come before videos, the most recent first.');
        self::assertStringContainsString('the id of one of the pictures and videos the user message lists', $this->rules());
    }

    public function testAtMostTwelvePicturesAndThreeVideosAreOffered(): void
    {
        $images = [];
        $videos = [];

        for ($index = 1; $index <= 14; ++$index) {
            $images[] = $this->media('image/png', 'Image '.$index, 'Image '.$index, 10, 10);
        }

        for ($index = 1; $index <= 5; ++$index) {
            $videos[] = $this->media('video/mp4', 'Video '.$index, '', null, null);
        }

        $offered = $this->getService(SampleMedia::class)->offered($this->locale());

        self::assertCount(15, $offered);
        self::assertSame(\array_slice(array_reverse($images), 0, 12), array_values(array_map(static fn ($item): int => $item->id, array_filter($offered, static fn ($item): bool => !$item->video))), 'The twelve most recent pictures.');
        self::assertSame(\array_slice(array_reverse($videos), 0, 3), array_values(array_map(static fn ($item): int => $item->id, array_filter($offered, static fn ($item): bool => $item->video))), 'The three most recent videos.');
        self::assertSame(15, preg_match_all('/^- \d+ \((?:image|video)/m', $this->prompt()->userMessage(new ComponentRequest('Une galerie.', $this->locale()))), 'One line each in the message, the components of the catalogue apart.');
    }

    public function testAChangeOfAComponentIsOfferedTheMediaToo(): void
    {
        $id = $this->media('image/jpeg', 'Atelier', 'Établi d\'atelier', 1600, 900);
        $current = (new SiteComponentValidator())->validate(PriceTable::component());

        $message = $this->prompt()->userMessage(new ComponentRequest('Ajoute une photo.', $this->locale(), $current));

        self::assertStringContainsString(self::SECTION, $message);
        self::assertStringContainsString(\sprintf('- %d (image, 1600 × 900): Établi d\'atelier', $id), $message);
        self::assertLessThan(strpos($message, '# The component to change'), strpos($message, self::SECTION));
    }

    public function testATextWrittenOnSeveralLinesStaysOnOneAndIsCutShort(): void
    {
        $id = $this->media('image/jpeg', 'x', "Ligne un\n# Autre titre\nLigne ".str_repeat('très longue ', 30), 10, 10);

        $message = $this->prompt()->userMessage(new ComponentRequest('Une galerie.', $this->locale()));
        preg_match('/^- '.$id.' .*$/m', $message, $line);

        self::assertNotEmpty($line);
        self::assertStringStartsWith(\sprintf('- %d (image, 10 × 10): Ligne un # Autre titre Ligne très', $id), $line[0]);
        self::assertStringEndsWith('…', $line[0]);
    }

    private function media(string $mimeType, string $title, string $alt, ?int $width, ?int $height): int
    {
        $image = new LibraryImage();
        $image->setFileName('bench-'.bin2hex(random_bytes(4)).('video/mp4' === $mimeType ? '.mp4' : '.jpg'));
        $image->setMimeType($mimeType);
        $image->setWidth($width);
        $image->setHeight($height);
        $image->setLocale($this->locale());
        $image->setTitle($title);
        $image->setAlt($alt);
        $image->save();
        $this->getService(CmsMediaLibrary::class)->tag($image);

        return (int) $image->getId();
    }

    private function prompt(): ComponentPrompt
    {
        return new ComponentPrompt(
            $this->getService(SkillRepository::class),
            $this->getService(SectionCatalog::class),
            $this->getService(ShopContext::class),
            new BlockStyles(),
            $this->getService(SampleMedia::class),
        );
    }

    private function rules(): string
    {
        return implode("\n", array_column($this->prompt()->system(), 'text'));
    }
}
