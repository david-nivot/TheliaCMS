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

/**
 * What the usage block of the preparation screen draws, and the variables of
 * the template that say it: the only place where their names are written.
 */
final readonly class IconUsageBlock
{
    /**
     * @param string|null       $replaced  the name, prefixed, of the icon whose file the answer replaces
     * @param list<string>      $obstacles what a page would not keep of the drawing, in the language of the screen
     * @param string|null       $error     why the answer was not taken, in the language of the screen
     * @param IconCitation|null $citation  what a replacement changes in the pages that cite the icon
     */
    public function __construct(
        public bool $offered,
        public ?string $replaced,
        public array $obstacles,
        public bool $chosen,
        public string $name,
        public ?string $error,
        public ?IconCitation $citation,
    ) {
    }

    /** The block of a screen where the usage is neither offered nor a replacement of an icon. */
    public static function absent(?string $error): self
    {
        return new self(false, null, [], false, '', $error, null);
    }

    /**
     * @return array<string, mixed>
     */
    public function variables(): array
    {
        return [
            'icon_offered' => $this->offered,
            'icon_replaced' => $this->replaced,
            'icon_obstacles' => $this->obstacles,
            'icon_chosen' => $this->chosen,
            'icon_name' => $this->name,
            'icon_error' => $this->error,
            'icon_prefix' => IconName::PREFIX,
            'icon_citation' => $this->citation,
        ];
    }
}
