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

namespace TheliaCMS\Content\Theme;

use Psr\Log\LoggerInterface;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\UX\TwigComponent\ComponentRendererInterface;
use Thelia\Core\Template\TemplateDefinition;
use Thelia\Core\Template\TemplateHelperInterface;
use TheliaCMS\Content\ImageFraming;
use TheliaCMS\Content\PropSchema;
use TheliaCMS\Content\SectionHidingInterface;
use TheliaCMS\Content\SectionProviderInterface;
use TheliaCMS\Media\Icon\KnownIcons;

/**
 * The components the active theme opens to the pages of the CMS, read from
 * its `config/theliacms.yaml` at every call: the theme is a setting stored
 * in the database, and a declaration edited while working on a theme shows
 * up at the next request.
 *
 * A declared component the theme chain does not ship is logged and skipped
 * rather than fatal: a page must keep rendering while a theme is reworked.
 */
final readonly class ThemeComponentSections implements SectionProviderInterface, SectionHidingInterface
{
    public function __construct(
        private TemplateHelperInterface $templates,
        private ComponentPropsReader $reader,
        private ComponentRendererInterface $renderer,
        private LoggerInterface $logger,
        private ?LocaleSwitcher $localeSwitcher = null,
        private ?Packages $packages = null,
        private ?ImageFraming $framing = null,
        private ?KnownIcons $icons = null,
    ) {
    }

    public function sections(): iterable
    {
        $declaration = $this->declaration();

        foreach ($declaration->components as $component) {
            try {
                $read = $this->reader->read($component);
            } catch (\InvalidArgumentException $exception) {
                $this->logger->warning('TheliaCMS: a component the theme opens to pages cannot be read: {message}', ['message' => $exception->getMessage()]);

                continue;
            }

            $schema = null === $this->framing ? $read['schema'] : new PropSchema($this->framing->extend($read['schema']->props));
            $twigNames = [];

            foreach ($schema->props as $prop) {
                $twigNames[$prop->name] = self::twigName($prop->name);
            }

            yield new ThemeComponentSection($component, $read['description'], $schema, $twigNames, $this->renderer, $read['id_lists'], self::example($declaration->examples[$component] ?? []), $this->localeSwitcher, $this->packages, $this->icons, $this->logger);
        }
    }

    /**
     * The sections of the module the theme puts away, because it opens a
     * component of its own for the same thing.
     */
    public function hidden(): array
    {
        return $this->declaration()->hidden;
    }

    public function declaration(): ThemeSections
    {
        $theme = $this->templates->getActiveFrontTemplate();

        return ThemeSections::declaredIn(array_map(
            static fn (TemplateDefinition $template): string => $template->getAbsolutePath(),
            [$theme, ...array_values($theme->getParentList() ?? [])],
        ));
    }

    /**
     * The example of the theme, read with the names the props of a document
     * use: the theme writes `embedUrl` as the component does.
     *
     * @param array<string, mixed> $props
     *
     * @return array<string, mixed>
     */
    private static function example(array $props): array
    {
        $example = [];

        foreach ($props as $name => $value) {
            $example[ComponentPropsReader::propName((string) $name)] = $value;
        }

        return $example;
    }

    /**
     * `snake_case` back to the `camelCase` the component declares. A prop the
     * component spells in lowercase comes back unchanged.
     */
    public static function twigName(string $name): string
    {
        return lcfirst(str_replace('_', '', ucwords($name, '_')));
    }
}
