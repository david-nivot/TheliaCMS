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

namespace TheliaCMS\Tests\Unit\Content\Site;

use PHPUnit\Framework\TestCase;
use TheliaCMS\Content\EmbedFacade;
use TheliaCMS\Content\InvalidDocumentException;
use TheliaCMS\Content\PropSchema;
use TheliaCMS\Content\PropType;
use TheliaCMS\Content\Site\SiteComponent;
use TheliaCMS\Content\Site\SiteComponentSection;
use TheliaCMS\Content\Site\SiteComponentValidator;
use TheliaCMS\Content\Site\SitePropReader;
use TheliaCMS\Content\Site\SiteTemplateRenderer;

/**
 * The `embed` prop of a component of the site: declared, checked, and handed
 * to the template as the markup of a facade.
 */
final class EmbedPropTest extends TestCase
{
    private function schema(): PropSchema
    {
        return (new SitePropReader())->read([
            ['name' => 'video', 'type' => 'embed', 'label' => 'Presentation video'],
            ['name' => 'clips', 'type' => 'items', 'label' => 'Clips', 'items' => [
                ['name' => 'title', 'type' => 'text', 'label' => 'Title'],
                ['name' => 'clip', 'type' => 'embed', 'label' => 'Clip'],
            ]],
        ]);
    }

    public function testTheReaderKnowsTheTypeAtTheTopAndInTheRowsOfAList(): void
    {
        $schema = $this->schema();

        self::assertSame(PropType::Embed, $schema->props[0]->type);
        self::assertSame(PropType::Embed, $schema->props[1]->items[1]->type);
        self::assertContains('embed', SitePropReader::TYPES);
    }

    public function testOnlyThePlatformAndTheIdentifierAreKept(): void
    {
        $values = $this->schema()->normalize([
            'video' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&list=PLsecret&t=9',
            'clips' => [['title' => 'One', 'clip' => 'https://vimeo.com/76979871']],
        ]);

        self::assertSame('youtube:dQw4w9WgXcQ', $values['video']);
        self::assertSame('vimeo:76979871', $values['clips'][0]['clip']);
        self::assertSame($values['video'], $this->schema()->normalize(['video' => $values['video']])['video'], 'What was kept reads back as itself.');
    }

    public function testAnEmptyVideoIsNoVideo(): void
    {
        self::assertSame('', $this->schema()->normalize(['video' => ''])['video']);
    }

    public function testAnAddressThatIsNoVideoIsRefusedWithoutPrintingItBack(): void
    {
        $typed = 'https://youtube.com.evil.tld/watch?v=dQw4w9WgXcQ"><script>1</script>';

        try {
            $this->schema()->normalize(['video' => $typed]);
            self::fail('A host out of the list is refused.');
        } catch (InvalidDocumentException $exception) {
            $message = implode(' ', array_map('strval', $exception->problems));

            self::assertStringContainsString('"video"', $message);
            self::assertStringContainsString('Dailymotion', $message);
            self::assertStringNotContainsString('evil', $message);
            self::assertStringNotContainsString('script', $message);
        }
    }

    public function testTheTemplateReceivesTheFacadeAsMarkupAndNothingToLoad(): void
    {
        $component = new SiteComponent('VideoBand', 'Video band', 'A video.', [['name' => 'video', 'type' => 'embed', 'label' => 'Presentation video']], ['video' => 'youtube:dQw4w9WgXcQ'], '<section class="band">{{ props.video }}</section>');
        $kept = (new SiteComponentValidator())->validate($component);
        $schema = (new SitePropReader())->read($kept->props);

        $html = (new SiteComponentSection($kept, $schema, new SiteTemplateRenderer()))->render($kept->sample, 'en_US');

        self::assertStringContainsString('data-cms-embed-platform="youtube"', $html);
        self::assertStringContainsString('data-cms-embed-id="dQw4w9WgXcQ"', $html);
        self::assertStringContainsString('<button type="button" class="cms-embed__play" aria-label="Play the video: Presentation video (YouTube)">', $html);
        self::assertStringContainsString('Playing it loads YouTube.', $html);
        self::assertStringContainsString('<a class="cms-embed__link" href="https://www.youtube.com/watch?v&#61;dQw4w9WgXcQ">Watch the video on YouTube</a>', $html);
        self::assertStringNotContainsString('<iframe', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertSame(1, substr_count($html, 'youtube.com'), 'Only the link under the facade names the platform address.');
        self::assertStringNotContainsString('src=', $html);
    }

    public function testTheRowsOfAListGetTheirOwnFacade(): void
    {
        $values = SiteComponentSection::forTemplate($this->schema()->props, ['video' => '', 'clips' => [['title' => 'One', 'clip' => 'vimeo:76979871'], ['title' => 'Two', 'clip' => '']]]);

        self::assertNull($values['video']);
        self::assertStringContainsString('data-cms-embed-platform="vimeo"', (string) $values['clips'][0]['clip']);
        self::assertStringContainsString('aria-label="Play the video: Clip (Vimeo)"', (string) $values['clips'][0]['clip']);
        self::assertNull($values['clips'][1]['clip']);
    }

    public function testTheLabelIsEscapedAndTheValueNeverReprinted(): void
    {
        $markup = (string) (new EmbedFacade())->markup('dailymotion:x84sh87', '"><b>Intro</b>', 'en_US');

        self::assertStringNotContainsString('<b>', $markup);
        self::assertStringContainsString('&quot;&gt;&lt;b&gt;Intro&lt;/b&gt; (Dailymotion)', $markup);
        self::assertNull((new EmbedFacade())->markup('youtube:short', 'Video', 'en_US'));
        self::assertNull((new EmbedFacade())->markup(12, 'Video', 'en_US'));
    }
}
