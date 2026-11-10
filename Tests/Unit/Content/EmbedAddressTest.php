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

namespace TheliaCMS\Tests\Unit\Content;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheliaCMS\Content\EmbedAddress;

/**
 * The only way an address typed for an `embed` prop becomes a video: a host of
 * the closed list over https, a strict identifier, nothing else kept.
 */
final class EmbedAddressTest extends TestCase
{
    #[DataProvider('accepted')]
    public function testReadsThePlatformAndTheIdentifier(string $address, string $value): void
    {
        self::assertSame($value, EmbedAddress::from($address)?->value());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function accepted(): iterable
    {
        yield 'youtube watch' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'youtube:dQw4w9WgXcQ'];
        yield 'youtube watch with other parameters' => ['https://youtube.com/watch?feature=share&v=dQw4w9WgXcQ&t=42s', 'youtube:dQw4w9WgXcQ'];
        yield 'youtube mobile' => ['https://m.youtube.com/watch?v=dQw4w9WgXcQ', 'youtube:dQw4w9WgXcQ'];
        yield 'youtube short' => ['https://www.youtube.com/shorts/abc_DEF-123', 'youtube:abc_DEF-123'];
        yield 'youtube embed' => ['https://www.youtube.com/embed/dQw4w9WgXcQ', 'youtube:dQw4w9WgXcQ'];
        yield 'youtube live' => ['https://www.youtube.com/live/dQw4w9WgXcQ?si=x', 'youtube:dQw4w9WgXcQ'];
        yield 'youtu.be' => ['https://youtu.be/dQw4w9WgXcQ?t=3', 'youtube:dQw4w9WgXcQ'];
        yield 'youtube-nocookie' => ['https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', 'youtube:dQw4w9WgXcQ'];
        yield 'host in capitals' => ['HTTPS://WWW.YouTube.com/watch?v=dQw4w9WgXcQ', 'youtube:dQw4w9WgXcQ'];
        yield 'surrounding spaces' => ["  https://youtu.be/dQw4w9WgXcQ\n", 'youtube:dQw4w9WgXcQ'];
        yield 'vimeo' => ['https://vimeo.com/76979871', 'vimeo:76979871'];
        yield 'vimeo channel' => ['https://vimeo.com/channels/staffpicks/76979871', 'vimeo:76979871'];
        yield 'vimeo player' => ['https://player.vimeo.com/video/76979871?h=abcdef', 'vimeo:76979871'];
        yield 'dailymotion video' => ['https://www.dailymotion.com/video/x84sh87', 'dailymotion:x84sh87'];
        yield 'dailymotion video with a slug' => ['https://www.dailymotion.com/video/x84sh87_a-title_news', 'dailymotion:x84sh87'];
        yield 'dailymotion embed' => ['https://www.dailymotion.com/embed/video/x84sh87', 'dailymotion:x84sh87'];
        yield 'dai.ly' => ['https://dai.ly/x84sh87', 'dailymotion:x84sh87'];
        yield 'the value kept earlier' => ['vimeo:76979871', 'vimeo:76979871'];
    }

    #[DataProvider('refused')]
    public function testRefusesEverythingElse(string $address): void
    {
        self::assertNull(EmbedAddress::from($address));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refused(): iterable
    {
        yield 'empty' => [''];
        yield 'a neighbour host' => ['https://youtube.com.evil.tld/watch?v=dQw4w9WgXcQ'];
        yield 'a host that ends like youtube' => ['https://evilyoutube.com/watch?v=dQw4w9WgXcQ'];
        yield 'a sub-domain not listed' => ['https://evil.youtube.com/watch?v=dQw4w9WgXcQ'];
        yield 'a user that looks like the host' => ['https://youtube.com@evil.tld/watch?v=dQw4w9WgXcQ'];
        yield 'a user on the real host' => ['https://user:pass@www.youtube.com/watch?v=dQw4w9WgXcQ'];
        yield 'a port' => ['https://www.youtube.com:8443/watch?v=dQw4w9WgXcQ'];
        yield 'javascript' => ['javascript:alert(1)//youtube.com/watch?v=dQw4w9WgXcQ'];
        yield 'plain http' => ['http://www.youtube.com/watch?v=dQw4w9WgXcQ'];
        yield 'no scheme' => ['www.youtube.com/watch?v=dQw4w9WgXcQ'];
        yield 'a protocol-relative address' => ['//www.youtube.com/watch?v=dQw4w9WgXcQ'];
        yield 'a data address' => ['data:text/html,<script>alert(1)</script>'];
        yield 'a line break in the address' => ["https://www.youtube.com/watch?v=dQw4w9WgXcQ\nhttps://evil.tld"];
        yield 'a line break in the id' => ["https://www.youtube.com/watch?v=dQw4w9WgX\ncQ"];
        yield 'a tab' => ["https://www.youtube.com/watch?v=dQw4w9WgXc\tQ"];
        yield 'a backslash' => ['https://www.youtube.com\\@evil.tld/watch?v=dQw4w9WgXcQ'];
        yield 'a control character in a parameter nobody reads' => ["https://www.youtube.com/watch?v=dQw4w9WgXcQ&x=\x07"];
        yield 'a backslash in a parameter nobody reads' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&x=\\'];
        yield 'an id too short' => ['https://www.youtube.com/watch?v=dQw4w9WgX'];
        yield 'an id too long' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQQ'];
        yield 'an id with a quote' => ['https://www.youtube.com/watch?v=dQw4w9Wg"Q'];
        yield 'an id with markup' => ['https://youtu.be/<script>alert1</script>'];
        yield 'a query array' => ['https://www.youtube.com/watch?v[]=dQw4w9WgXcQ'];
        yield 'no id' => ['https://www.youtube.com/watch'];
        yield 'the home of youtube' => ['https://www.youtube.com/'];
        yield 'a channel of youtube' => ['https://www.youtube.com/@someone'];
        yield 'youtube-nocookie outside of embed' => ['https://www.youtube-nocookie.com/watch?v=dQw4w9WgXcQ'];
        yield 'a vimeo id with letters' => ['https://vimeo.com/76979x71'];
        yield 'a vimeo id too long' => ['https://vimeo.com/1234567890123'];
        yield 'a vimeo unlisted video' => ['https://vimeo.com/76979871/abcdef0123'];
        yield 'a vimeo profile' => ['https://vimeo.com/someone'];
        yield 'a dailymotion id without its x' => ['https://www.dailymotion.com/video/84sh87'];
        yield 'a dailymotion id in capitals' => ['https://dai.ly/X84SH87'];
        yield 'a dailymotion id with a dot' => ['https://dai.ly/x84.sh87'];
        yield 'a dailymotion neighbour host' => ['https://dailymotion.com.evil.tld/video/x84sh87'];
        yield 'a stored value with a bad platform' => ['twitch:76979871'];
        yield 'a stored value with a bad id' => ['youtube:short'];
        yield 'a stored value with a second colon' => ['vimeo:7697:9871'];
        yield 'too long' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&x='.str_repeat('a', 2100)];
        yield 'bytes that are not UTF-8' => ["https://youtu.be/dQw4w9WgXc\xff"];
    }

    public function testBuildsTheAddressOfThePageFromTheCheckedPartsOnly(): void
    {
        self::assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', EmbedAddress::from('https://youtu.be/dQw4w9WgXcQ?t=9&utm=evil')?->pageUrl());
        self::assertSame('https://vimeo.com/76979871', EmbedAddress::from('https://player.vimeo.com/video/76979871?h=abc')?->pageUrl());
        self::assertSame('https://www.dailymotion.com/video/x84sh87', EmbedAddress::from('https://dai.ly/x84sh87')?->pageUrl());
    }
}
