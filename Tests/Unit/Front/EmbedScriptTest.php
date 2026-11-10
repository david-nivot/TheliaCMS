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

namespace TheliaCMS\Tests\Unit\Front;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use TheliaCMS\Content\EmbedPlatform;
use TheliaCMS\Front\EmbedScript;
use TheliaCMS\Front\EmbedScriptController;
use TheliaCMS\Front\EmbedScriptThemeHook;

/**
 * The script that loads the player of an embedded video, and the hook that
 * links it on every theme.
 */
final class EmbedScriptTest extends TestCase
{
    public function testTheScriptChecksTheSamePatternsAsTheServer(): void
    {
        $source = (string) file_get_contents((new EmbedScript())->path());

        foreach (EmbedPlatform::cases() as $platform) {
            // PHP writes /^…$/D, the script /^…$/: the body between is the same.
            $body = substr($platform->idPattern(), 1, -2);

            self::assertStringContainsString('id: /'.$body.'/', $source, $platform->value);
        }
    }

    public function testTheScriptBuildsEachPlayerFromAClosedListOfHostsAndNeverFromThePage(): void
    {
        $source = (string) file_get_contents((new EmbedScript())->path());

        self::assertStringContainsString("'https://www.youtube-nocookie.com/embed/' + id", $source);
        self::assertStringContainsString("'https://player.vimeo.com/video/' + id", $source);
        self::assertStringContainsString("'https://www.dailymotion.com/embed/video/' + id", $source);
        self::assertStringContainsString("setAttribute('loading', 'lazy')", $source);
        self::assertStringContainsString("setAttribute('referrerpolicy'", $source);
        self::assertStringContainsString('frame.focus()', $source);
        self::assertStringNotContainsString('innerHTML', $source);
        self::assertStringNotContainsString('insertAdjacentHTML', $source);
        self::assertStringNotContainsString('document.write', $source);
        self::assertStringNotContainsString('eval(', $source);
    }

    public function testTheVersionFollowsTheContentOfTheFile(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', (new EmbedScript())->version());
    }

    public function testTheControllerServesTheFileForAYear(): void
    {
        $response = (new EmbedScriptController(new EmbedScript()))();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/javascript; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertStringContainsString('max-age=31536000', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('immutable', (string) $response->headers->get('Cache-Control'));
    }

    public function testTheHookLinksTheScriptAtTheBottomOfTheBodyAndNowhereElse(): void
    {
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->method('generate')->with('cms.embed_script', ['v' => (new EmbedScript())->version()])->willReturn('/cms/embed.js?v=abc&x=1');
        $hook = new EmbedScriptThemeHook(new EmbedScript(), $urls);

        self::assertTrue($hook->supports('layout.body.bottom'));
        self::assertFalse($hook->supports('layout.head.bottom'));
        self::assertSame('<script src="/cms/embed.js?v=abc&amp;x=1" defer></script>', $hook->render('layout.body.bottom', []));
        self::assertSame('', $hook->render('layout.head.bottom', []));
    }
}
