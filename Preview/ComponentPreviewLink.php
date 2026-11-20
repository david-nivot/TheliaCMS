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

namespace TheliaCMS\Preview;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Signs the address that draws a component in the theme, for the frame of the
 * back office: a candidate the generator wrote (`candidate-<id of the ledger
 * row>`) or any component of the catalogue (`section-<name>`, such as
 * `section-Layouts:Subheader:Base` or `section-Site:PriceTable`).
 *
 * The frame is a page of the front office, which knows nothing of the session
 * of the back office: the address carries the authorisation, for an hour, and
 * its key is salted apart from the links that show a draft page.
 */
final readonly class ComponentPreviewLink
{
    public const int LIFETIME_IN_SECONDS = 3600;

    public const string SUBJECT_PATTERN = '(?:candidate-[1-9][0-9]*|section-[A-Za-z0-9:_-]{1,120})';

    private const string ROUTE = 'cms.component.preview';

    public function __construct(
        private UrlGeneratorInterface $urls,
        #[Autowire('%kernel.secret%')]
        private string $applicationSecret,
    ) {
    }

    public function candidate(int $logId, string $locale, ?\DateTimeImmutable $now = null): string
    {
        return $this->urlFor('candidate-'.$logId, $locale, $now);
    }

    /**
     * A component made on this site, by its code.
     */
    public function component(string $code, string $locale, ?\DateTimeImmutable $now = null): string
    {
        return $this->section('Site:'.$code, $locale, $now);
    }

    /**
     * Any component of the catalogue, by the name the catalogue knows it by.
     */
    public function section(string $name, string $locale, ?\DateTimeImmutable $now = null): string
    {
        return $this->urlFor('section-'.$name, $locale, $now);
    }

    public function isValid(string $subject, string $locale, int $expiresAt, string $signature, ?\DateTimeImmutable $now = null): bool
    {
        if ($expiresAt < ($now ?? new \DateTimeImmutable())->getTimestamp()) {
            return false;
        }

        return hash_equals($this->sign($subject, $locale, $expiresAt), $signature);
    }

    private function urlFor(string $subject, string $locale, ?\DateTimeImmutable $now): string
    {
        $expiresAt = ($now ?? new \DateTimeImmutable())->getTimestamp() + self::LIFETIME_IN_SECONDS;

        return $this->urls->generate(self::ROUTE, [
            'subject' => $subject,
            'locale' => $locale,
            'expires' => $expiresAt,
            'signature' => $this->sign($subject, $locale, $expiresAt),
        ]);
    }

    private function sign(string $subject, string $locale, int $expiresAt): string
    {
        return hash_hmac(
            'sha256',
            implode('|', [$subject, $locale, $expiresAt]),
            hash_hmac('sha256', 'thelia-cms.component-preview', $this->applicationSecret),
        );
    }
}
