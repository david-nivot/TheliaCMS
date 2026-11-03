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

use Symfony\Component\Asset\Packages;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\UX\TwigComponent\ComponentRendererInterface;
use TheliaCMS\Content\ExampleRenderingInterface;
use TheliaCMS\Content\ImageSource;
use TheliaCMS\Content\PropSchema;
use TheliaCMS\Content\PropType;
use TheliaCMS\Content\SectionDefinitionInterface;

/**
 * A Twig component of the theme, as a section of a document.
 *
 * Named as the theme names it (`Molecules:Reinsurance:Base`), described by
 * what its template documents, rendered by the component renderer of Symfony
 * UX: the markup is the theme's own, so the stylesheet the theme built already
 * knows every class in it. It is drawn in the language of the page: a page is
 * rendered when it is saved, by whoever saves it, an editor working in another
 * language or a command run without any.
 */
final readonly class ThemeComponentSection implements SectionDefinitionInterface, ExampleRenderingInterface
{
    /**
     * @param array<string, string> $twigNames the name of each prop as the component spells it, by the name the schema uses
     * @param list<string>          $idLists   the props that hold several records, handed to the component as a list of ids
     * @param array<string, mixed>  $example   props the theme shows the component with, by the name the schema uses
     * @param Packages|null         $packages  what turns the path of a file of the theme into the address it is served from
     */
    public function __construct(
        private string $component,
        private string $description,
        private PropSchema $schema,
        private array $twigNames,
        private ComponentRendererInterface $renderer,
        private array $idLists = [],
        private array $example = [],
        private ?LocaleSwitcher $localeSwitcher = null,
        private ?Packages $packages = null,
    ) {
    }

    public function name(): string
    {
        return $this->component;
    }

    public function label(): string
    {
        $segments = explode(':', $this->component);

        return implode(' ', \array_slice($segments, -2));
    }

    public function description(): string
    {
        if ('' !== $this->description) {
            return $this->description;
        }

        return \sprintf('The "%s" component of the theme, as it is used on the rest of the site.', $this->label());
    }

    public function schema(): PropSchema
    {
        return $this->schema;
    }

    public function sample(): array
    {
        $sample = [];

        foreach ($this->schema->props as $prop) {
            $sample[$prop->name] = match ($prop->type) {
                PropType::Text => '' !== (string) $prop->default ? $prop->default : ($prop->required ? ucfirst(str_replace('_', ' ', $prop->name)) : ''),
                PropType::Url => '' !== (string) $prop->default ? $prop->default : '#',
                PropType::Image => null,
                PropType::Reference => $prop->required ? 1 : null,
                PropType::Items => \in_array($prop->name, $this->idLists, true) ? [['id' => 1], ['id' => 2]] : [],
                default => $prop->default,
            };
        }

        // What the theme declares wins over the defaults; a name the
        // component does not take is left out.
        // A file of the theme is not a value a document can store: it is
        // drawn by renderExample() only.
        $declared = array_diff_key(array_intersect_key($this->example, $sample), $this->themePictures());

        // The same inside the rows of a list: the picture of a row is drawn
        // by renderExample() only.
        foreach ($this->themeRowPictures() as $name => $rows) {
            foreach ($rows as $index => $fields) {
                foreach (array_keys($fields) as $field) {
                    $declared[$name][$index][$field] = null;
                }
            }
        }

        return [...$sample, ...$declared];
    }

    public function normalizeProps(array $props): array
    {
        return $this->schema->normalize($props);
    }

    public function renderExample(string $locale): string
    {
        $addresses = [];

        foreach ($this->themePictures() as $name => $path) {
            if (null !== $this->packages) {
                $addresses[$name] = $this->packages->getUrl($path);
            }
        }

        if (null !== $this->packages) {
            foreach ($this->themeRowPictures() as $name => $rows) {
                foreach ($rows as $index => $fields) {
                    foreach ($fields as $field => $path) {
                        $addresses[$name][$index][$field] = $this->packages->getUrl($path);
                    }
                }
            }
        }

        return $this->draw($this->normalizeProps($this->sample()), $locale, $addresses);
    }

    public function render(array $props, string $locale): string
    {
        return $this->draw($props, $locale, []);
    }

    /**
     * The picture props whose example is the path of a file of the theme,
     * by the name the schema uses.
     *
     * @return array<string, string>
     */
    private function themePictures(): array
    {
        $paths = [];

        foreach ($this->schema->props as $prop) {
            $value = $this->example[$prop->name] ?? null;

            if (PropType::Image === $prop->type && \is_string($value) && self::isThemePath($value)) {
                $paths[$prop->name] = trim($value);
            }
        }

        return $paths;
    }

    /**
     * The picture fields of the rows of a list whose example is the path of a
     * file of the theme: the path by field, by row, by the name of the list.
     *
     * @return array<string, array<int, array<string, string>>>
     */
    private function themeRowPictures(): array
    {
        $paths = [];

        foreach ($this->schema->props as $prop) {
            $rows = $this->example[$prop->name] ?? null;

            if (PropType::Items !== $prop->type || $prop->scalarItems || !\is_array($rows)) {
                continue;
            }

            foreach (array_values($rows) as $index => $row) {
                foreach ($prop->items as $item) {
                    $value = \is_array($row) ? ($row[$item->name] ?? null) : null;

                    if (PropType::Image === $item->type && \is_string($value) && self::isThemePath($value)) {
                        $paths[$prop->name][$index][$item->name] = trim($value);
                    }
                }
            }
        }

        return $paths;
    }

    /**
     * A relative path with no scheme and no leading slash, which is neither
     * an id of the media library nor an https address.
     */
    private static function isThemePath(string $value): bool
    {
        $value = trim($value);

        return 1 === preg_match('#^(?![/\\\\])(?![a-z][a-z0-9+.\-]*:)[^\s?\#]+$#i', $value)
            && 1 !== preg_match('/^\d+$/', $value);
    }

    /**
     * @param array<string, mixed>                                    $props
     * @param array<string, string|array<int, array<string, string>>> $addresses the address to draw a picture prop from, by prop, instead of reading it as an id; for the picture of a row of a list, by row and field
     */
    private function draw(array $props, string $locale, array $addresses): string
    {
        $forComponent = [];
        $images = [];
        $rowImages = [];
        $rowLists = [];

        foreach ($this->schema->props as $prop) {
            if (PropType::Image === $prop->type) {
                $images[] = $prop->name;
            } elseif (PropType::Items === $prop->type && !$prop->scalarItems && !\in_array($prop->name, $this->idLists, true)) {
                $rowLists[] = $prop->name;

                foreach ($prop->items as $item) {
                    if (PropType::Image === $item->type) {
                        $rowImages[$prop->name][] = $item->name;
                    }
                }
            }
        }

        foreach ($props as $name => $value) {
            // A picture is stored as an id of the media library; the component
            // prints what it is given in a src, as a template of the theme
            // hands it an address.
            if (isset($addresses[$name]) && \is_string($addresses[$name])) {
                $value = $addresses[$name];
            } elseif (\in_array($name, $images, true)) {
                $value = ImageSource::of($value);
            } elseif (isset($rowImages[$name]) && \is_array($value)) {
                $value = $this->rowsWithAddresses($value, $rowImages[$name], \is_array($addresses[$name] ?? null) ? $addresses[$name] : []);
            }

            // A row is stored as the schema names its fields and read by the
            // component as it spells them, as a prop of its own is.
            if (\in_array($name, $rowLists, true) && \is_array($value)) {
                $value = array_map(static fn (mixed $row): mixed => \is_array($row) ? self::twigKeys($row) : $row, $value);
            }

            // A prop left empty is left to the default of the component, which
            // may be an expression the reader could not evaluate.
            if ('' === $value || null === $value) {
                continue;
            }

            // Several records are stored as rows of ids, which the hand-edit
            // form and the model both write; the component wants the ids.
            if (\in_array($name, $this->idLists, true) && \is_array($value)) {
                $value = array_values(array_filter(array_map(static fn (mixed $row): int => (int) (\is_array($row) ? ($row['id'] ?? 0) : $row), $value)));
            }

            $forComponent[$this->twigNames[$name] ?? $name] = $value;
        }

        $draw = fn (): string => $this->renderer->createAndRender($this->component, $forComponent);

        return null === $this->localeSwitcher ? $draw() : $this->localeSwitcher->runWithLocale($locale, $draw);
    }

    /**
     * The rows of a list with the picture of each drawn from an address, as a
     * picture prop of its own is.
     *
     * @param array<mixed>                      $rows
     * @param list<string>                      $fields    the picture fields of a row
     * @param array<int, array<string, string>> $addresses by row and field, instead of reading the field as an id
     *
     * @return array<mixed>
     */
    private function rowsWithAddresses(array $rows, array $fields, array $addresses): array
    {
        $drawn = [];

        foreach (array_values($rows) as $index => $row) {
            foreach ($fields as $field) {
                if (\is_array($row) && \array_key_exists($field, $row)) {
                    $row[$field] = $addresses[$index][$field] ?? ImageSource::of($row[$field]);
                }
            }

            $drawn[] = $row;
        }

        return $drawn;
    }

    /**
     * @param array<array-key, mixed> $row
     *
     * @return array<array-key, mixed>
     */
    private static function twigKeys(array $row): array
    {
        $keys = array_map(static fn (int|string $key): int|string => \is_string($key) ? ThemeComponentSections::twigName($key) : $key, array_keys($row));

        return array_combine($keys, array_values($row));
    }
}
