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

namespace TheliaCMS\Media\Icon;

use Psr\Log\LoggerInterface;
use Symfony\UX\Icons\Exception\IconNotFoundException;
use Symfony\UX\Icons\Icon;
use Symfony\UX\Icons\IconFactory;
use Symfony\UX\Icons\IconRegistryInterface;

/**
 * The `cms:` icon set: the SVGs an editor named in the media library.
 *
 * Registered in the chain of ux-icons at priority 0, under the local sources
 * and above Iconify, which asks the network for any name it is given. A name of
 * the set that is not (or no longer) known therefore never throws: the chain
 * would fall to Iconify, and `ux_icon()` fails the page on an exception. It
 * draws nothing and warns instead.
 *
 * IconFactory and the `ux_icons.registry` tag are internal to ux-icons: checked
 * against symfony/ux-icons 2.36.1 (`IconFactory::fromFile(string): Icon`, which
 * also cleans the SVG of scripts). Wired by TheliaCMS::configureServices.
 */
final readonly class CmsIconRegistry implements IconRegistryInterface
{
    private const string EMPTY_VIEW_BOX = '0 0 24 24';

    public function __construct(
        private IconFileFinder $files,
        private IconFactory $iconFactory,
        private LoggerInterface $logger,
    ) {
    }

    public function get(string $name): Icon
    {
        if (!str_starts_with($name, IconName::PREFIX)) {
            throw new IconNotFoundException(\sprintf('The icon "%s" is not in the "cms" set.', $name));
        }

        $file = $this->files->fileOf(substr($name, \strlen(IconName::PREFIX)));

        if (null !== $file && is_file($file)) {
            try {
                return $this->iconFactory->fromFile($file);
            } catch (\Throwable $exception) {
                $this->logger->warning('The SVG of a CMS icon cannot be read.', ['icon' => $name, 'exception' => $exception]);

                return $this->emptyIcon();
            }
        }

        $this->logger->warning('A CMS icon is used that does not exist.', ['icon' => $name]);

        return $this->emptyIcon();
    }

    private function emptyIcon(): Icon
    {
        return new Icon('', ['viewBox' => self::EMPTY_VIEW_BOX]);
    }
}
