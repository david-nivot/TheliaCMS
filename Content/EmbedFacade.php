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

namespace TheliaCMS\Content;

use Symfony\Contracts\Translation\TranslatorInterface;
use TheliaCMS\TheliaCMS;
use Twig\Markup;

/**
 * What a template receives for an `embed` prop: the markup of a facade, a
 * play button named after the prop and the platform, and nothing else. No
 * thumbnail and no request to the platform: the player is built by the script
 * of the module (assets/embed.js) when the visitor clicks, and that click is
 * the consent to load it. A link to the video stays under it for who cannot
 * run the script (a `noscript` element would not survive the filter of the
 * page).
 *
 * Everything printed is the platform and the identifier, both checked when
 * the prop was stored and again here, and the label of the prop, escaped.
 */
final readonly class EmbedFacade
{
    public const string ELEMENT_CLASS = 'cms-embed';

    public const string PLATFORM_ATTRIBUTE = 'data-cms-embed-platform';

    public const string ID_ATTRIBUTE = 'data-cms-embed-id';

    public const string TITLE_ATTRIBUTE = 'data-cms-embed-title';

    public function __construct(
        private ?TranslatorInterface $translator = null,
    ) {
    }

    /**
     * @param mixed  $stored the value of the prop (`platform:id`)
     * @param string $label  the label of the prop, which names the video for who cannot see it
     */
    public function markup(mixed $stored, string $label, string $locale): ?Markup
    {
        $video = \is_string($stored) ? EmbedAddress::from($stored) : null;

        if (null === $video) {
            return null;
        }

        $platform = $video->platform->label();
        $title = trim($label).' ('.$platform.')';
        $parameters = ['%platform%' => $platform, '%title%' => $title];

        return new Markup(\sprintf(
            '<div class="%s" %s="%s" %s="%s" %s="%s"><button type="button" class="cms-embed__play" aria-label="%s"><span class="cms-embed__icon" aria-hidden="true"></span></button><p class="cms-embed__notice">%s <a class="cms-embed__link" href="%s">%s</a></p></div>',
            self::ELEMENT_CLASS,
            self::PLATFORM_ATTRIBUTE,
            $video->platform->value,
            self::ID_ATTRIBUTE,
            $video->id,
            self::TITLE_ATTRIBUTE,
            self::escape($title),
            self::escape($this->trans('Play the video: %title%', $parameters, $locale)),
            self::escape($this->trans('Playing it loads %platform%.', $parameters, $locale)),
            self::escape($video->pageUrl()),
            self::escape($this->trans('Watch the video on %platform%', $parameters, $locale)),
        ), 'UTF-8');
    }

    /**
     * @param array<string, string> $parameters
     */
    private function trans(string $id, array $parameters, string $locale): string
    {
        return null === $this->translator ? strtr($id, $parameters) : $this->translator->trans($id, $parameters, TheliaCMS::DOMAIN_NAME, $locale);
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
