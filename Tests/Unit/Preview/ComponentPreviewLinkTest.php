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
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use TheliaCMS\Preview\ComponentPreviewLink;

/**
 * The signed address of the frame that draws a component: any name of the
 * catalogue, colons included, and the signature is what opens it.
 */
final class ComponentPreviewLinkTest extends TestCase
{
    private const string SECRET = 'a-secret-nobody-else-has';

    private const string NOW = '2026-01-01 12:00:00';

    /**
     * @return iterable<string, array{string}>
     */
    public static function names(): iterable
    {
        yield 'a component of the theme' => ['Layouts:Subheader:Base'];
        yield 'a component of the module' => ['cms:richtext'];
        yield 'a partial' => ['Partial:cms-form'];
        yield 'a component of the site' => ['Site:PriceTable'];
    }

    #[DataProvider('names')]
    public function testAnyNameOfTheCatalogueHasItsSignedAddress(string $name): void
    {
        $now = new \DateTimeImmutable(self::NOW);
        $parameters = $this->parametersOf($this->links()->section($name, 'fr_FR', $now));

        self::assertSame('section-'.$name, $parameters['subject']);
        self::assertSame(1, preg_match('/^'.ComponentPreviewLink::SUBJECT_PATTERN.'$/', $parameters['subject']), 'The route takes the subject.');
        self::assertTrue($this->links()->isValid($parameters['subject'], 'fr_FR', (int) $parameters['expires'], $parameters['signature'], $now));
    }

    /**
     * Sabotage: leave the subject out of sign(): the address of one
     * component opens another.
     */
    public function testTheSignatureBelongsToTheNameItWasMadeFor(): void
    {
        $now = new \DateTimeImmutable(self::NOW);
        $parameters = $this->parametersOf($this->links()->section('Layouts:Subheader:Base', 'fr_FR', $now));

        self::assertFalse($this->links()->isValid('section-Layouts:Subheader:Other', 'fr_FR', (int) $parameters['expires'], $parameters['signature'], $now));
        self::assertFalse($this->links()->isValid('candidate-12', 'fr_FR', (int) $parameters['expires'], $parameters['signature'], $now));
    }

    public function testACodeOfTheSiteIsTheSectionOfItsName(): void
    {
        $now = new \DateTimeImmutable(self::NOW);

        self::assertSame($this->links()->section('Site:PriceTable', 'en_US', $now), $this->links()->component('PriceTable', 'en_US', $now));
    }

    public function testTheSubjectsTheRouteTakesAreTheCandidatesAndTheSections(): void
    {
        $pattern = '/^'.ComponentPreviewLink::SUBJECT_PATTERN.'$/';

        self::assertSame(1, preg_match($pattern, 'candidate-12'));
        self::assertSame(0, preg_match($pattern, 'candidate-0'));
        self::assertSame(0, preg_match($pattern, 'section-'), 'A name is needed.');
        self::assertSame(0, preg_match($pattern, 'section-a/b'), 'A slash is no part of a name.');
        self::assertSame(0, preg_match($pattern, 'page-12'));
    }

    private function links(): ComponentPreviewLink
    {
        return new ComponentPreviewLink(new class implements UrlGeneratorInterface {
            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                return 'https://shop.test/'.$name.'?'.http_build_query($parameters);
            }

            public function setContext(RequestContext $context): void
            {
            }

            public function getContext(): RequestContext
            {
                return new RequestContext();
            }
        }, self::SECRET);
    }

    /**
     * @return array<string, string>
     */
    private function parametersOf(string $url): array
    {
        /** @var array<string, string> $parameters */
        $parameters = [];
        parse_str(parse_url($url, \PHP_URL_QUERY) ?: '', $parameters);

        return $parameters;
    }
}
