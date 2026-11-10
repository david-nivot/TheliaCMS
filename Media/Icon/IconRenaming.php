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

use Symfony\Contracts\Translation\TranslatorInterface;
use TheliaCMS\Locale\TranslatableText;

/**
 * Renames an icon, or takes its name away.
 *
 * The rule is the same for both: an icon that a page or an item cites keeps
 * its name, because their documents would keep the old one and the icon would
 * disappear from them at their next publication.
 */
final readonly class IconRenaming
{
    public function __construct(
        private IconNames $names,
        private IconCitations $citations,
        private TranslatorInterface $translator,
    ) {
    }

    /** @param string $name the name typed, with or without its prefix */
    public function rename(int $imageId, string $name): IconChange
    {
        $current = $this->names->nameOf($imageId);

        if (null === $current) {
            return $this->told('This image is not an icon.', false);
        }

        if ($this->citations->of($current)->isCited()) {
            return $this->told('The icon is cited by a page, an item or a component of the site: it cannot be renamed.', false);
        }

        $name = IconName::normalize($name);

        if ($name === $current) {
            return $this->told('The name of the icon has not changed.', false);
        }

        try {
            $this->names->assign($imageId, $name);
        } catch (InvalidIconNameException) {
            return new IconChange(IconName::invalidMessage()->trans($this->translator), false);
        } catch (IconNameTakenException) {
            return new IconChange(TranslatableText::of('The icon name "%s" is already used by another image.', IconName::prefixed($name))->trans($this->translator), false);
        }

        return $this->told('Icon renamed.', true);
    }

    public function removeName(int $imageId): IconChange
    {
        $current = $this->names->nameOf($imageId);

        if (null === $current) {
            return $this->told('This image is not an icon.', false);
        }

        if ($this->citations->of($current)->isCited()) {
            return $this->told('The icon is cited by a page, an item or a component of the site: its name cannot be removed.', false);
        }

        $this->names->remove($imageId);

        return $this->told('The image is no longer an icon.', true);
    }

    private function told(string $sentence, bool $made): IconChange
    {
        return new IconChange(TranslatableText::of($sentence)->trans($this->translator), $made);
    }
}
