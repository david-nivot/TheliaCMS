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

namespace TheliaCMS\Tests\Unit\Preview;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheliaCMS\Content\EmbedFacade;

/**
 * The frames that show a component are scripted (the player of a video is
 * built by assets/embed.js) and have no origin: scripts, never the origin of
 * the back office, or a script of the frame would reach it.
 */
final class ComponentPreviewFrameTest extends TestCase
{
    private const string TEMPLATES = __DIR__.'/../../../templates/backOffice/default-twig';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function frames(): iterable
    {
        yield 'the preview of a candidate or of a kept component' => ['components/site/_preview.html.twig', 'cms-site-component-frame'];
        yield 'the prototype of a component on the content screen' => ['content/edit.html.twig', 'data-cms-frame-prototype'];
    }

    #[DataProvider('frames')]
    public function testTheFrameAllowsScriptsAndNeverTheOrigin(string $template, string $marker): void
    {
        $source = (string) file_get_contents(self::TEMPLATES.'/'.$template);

        self::assertSame(1, preg_match('/<iframe\b[^>]*'.preg_quote($marker, '/').'[^>]*>/s', $source, $frame), 'The frame is in the template.');
        self::assertSame(1, preg_match('/\ssandbox="([^"]*)"/', $frame[0], $sandbox), 'The frame is sandboxed.');
        self::assertSame(['allow-scripts'], preg_split('/\s+/', trim($sandbox[1])), 'Scripts, and nothing else.');
        self::assertStringNotContainsString('allow-same-origin', $frame[0]);
    }

    public function testTheFacadeOfThePublicPageIsStillTheButtonTheScriptTurnsIntoAPlayer(): void
    {
        $facade = (string) (new EmbedFacade())->markup('youtube:dQw4w9WgXcQ', 'Intro', 'en_US');

        self::assertStringContainsString('class="cms-embed"', $facade);
        self::assertStringContainsString('class="cms-embed__play"', $facade);
        self::assertStringNotContainsString('<iframe', $facade, 'No player until the click.');
    }
}
