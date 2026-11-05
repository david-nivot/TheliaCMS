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

namespace TheliaCMS\Page\Admin;

use Symfony\Contracts\Translation\TranslatorInterface;
use TheliaCMS\Http\Admin\AdminControllerSupport;
use TheliaCMS\Model\CmsPage;

/**
 * What a publication of a page says once it went through: the content is
 * published, and the page is online only when its settings let it be.
 */
final readonly class PageOnlineMessage
{
    public function __construct(
        private AdminControllerSupport $support,
        private CmsPageAdminRepository $pages,
        private TranslatorInterface $translator,
    ) {
    }

    public function say(CmsPage $page, string $locale): string
    {
        return match ($this->pages->statusOf($page, $locale)) {
            PageStatus::Unpublished => $this->support->translate('The content is published, but the page is hidden: show it to put it online.'),
            PageStatus::Scheduled => $this->support->translate('The content is published: the page goes online on %date%.', [
                '%date%' => $this->dateOf($page->getPublishAt()),
            ]),
            default => $this->support->translate('The page is online.'),
        };
    }

    private function dateOf(?\DateTimeInterface $date): string
    {
        if (null === $date) {
            return '';
        }

        $formatted = (new \IntlDateFormatter($this->translator->getLocale(), \IntlDateFormatter::MEDIUM, \IntlDateFormatter::SHORT))->format($date);

        return false === $formatted ? $date->format('Y-m-d H:i') : $formatted;
    }
}
