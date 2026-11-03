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

/**
 * One prop of a section: its name, its type, and what a value of it may be.
 *
 * Built by the section that declares it, read by the schema that checks the
 * values, by the sheet that describes the section to whoever writes a
 * document, and by the JSON schema a generator is constrained with.
 */
final readonly class Prop
{
    /**
     * @param array<string, string> $choices value => label, for a Choice
     * @param list<Prop>            $items   the props of one item, for Items
     * @param array<string, string> $icons   a pictogram per choice, for a Choice drawn as buttons
     *
     * With `scalarItems`, the list is stored, and handed to the component, as a
     * list of scalars (`["one", "two"]`): its single item prop, named `value`,
     * is the field of a row of the form, and the row is not part of the value
     */
    public function __construct(
        public string $name,
        public PropType $type,
        public string $label,
        public mixed $default = null,
        public bool $required = false,
        public ?string $help = null,
        public array $choices = [],
        public ?int $min = null,
        public ?int $max = null,
        public array $items = [],
        public ?int $minItems = null,
        public ?int $maxItems = null,
        public ?string $picker = null,
        public bool $searchable = false,
        public array $icons = [],
        public bool $scalarItems = false,
    ) {
        if (1 !== preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException(\sprintf('A prop name is lowercase letters, digits and underscores: "%s" given.', $name));
        }

        if (PropType::Choice === $type && [] === $choices) {
            throw new \InvalidArgumentException(\sprintf('The choice prop "%s" names no choice.', $name));
        }

        if (PropType::Items === $type && [] === $items) {
            throw new \InvalidArgumentException(\sprintf('The items prop "%s" describes no item.', $name));
        }

        if ($scalarItems && (PropType::Items !== $type || 1 !== \count($items) || 'value' !== $items[0]->name)) {
            throw new \InvalidArgumentException(\sprintf('The list of scalars "%s" holds one item prop, named "value".', $name));
        }
    }

    public static function text(string $name, string $label, string $default = '', bool $required = false, int $max = 255, ?string $help = null): self
    {
        return new self($name, PropType::Text, $label, $default, $required, $help, max: $max);
    }

    public static function html(string $name, string $label, string $default = '', bool $required = false, ?string $help = null): self
    {
        return new self($name, PropType::Html, $label, $default, $required, $help);
    }

    public static function integer(string $name, string $label, int $default, int $min, int $max, ?string $help = null): self
    {
        return new self($name, PropType::Integer, $label, $default, false, $help, min: $min, max: $max);
    }

    public static function boolean(string $name, string $label, bool $default = false, ?string $help = null): self
    {
        return new self($name, PropType::Boolean, $label, $default, false, $help);
    }

    /**
     * @param array<string, string> $choices value => label
     */
    /**
     * @param array<string, string> $icons a pictogram per choice, `bi-…` (Bootstrap Icons) or one
     *                                     of the module (`width-narrow`, `width-column`, `width-full`)
     */
    public static function choice(string $name, string $label, array $choices, string $default, ?string $help = null, array $icons = []): self
    {
        return new self($name, PropType::Choice, $label, $default, false, $help, choices: $choices, icons: $icons);
    }

    public static function image(string $name, string $label, bool $required = false, ?string $help = null): self
    {
        return new self($name, PropType::Image, $label, null, $required, $help);
    }

    public static function url(string $name, string $label, string $default = '', bool $required = false, ?string $help = null): self
    {
        return new self($name, PropType::Url, $label, $default, $required, $help, max: 2048);
    }

    /**
     * @param list<Prop> $items
     */
    public static function items(string $name, string $label, array $items, int $min = 0, int $max = 12, ?string $help = null, bool $scalarItems = false): self
    {
        return new self($name, PropType::Items, $label, [], $min > 0, $help, items: $items, minItems: $min, maxItems: $max, scalarItems: $scalarItems);
    }

    /**
     * @param string $source what the id names: `page`, `menu`, `form`, `product`, `category`, `folder`
     */
    /**
     * @param string      $source     what the reference names, for the model: "a page", "a product"
     * @param string|null $picker     the address of the list a screen picks the record from, `{id, name}` rows
     * @param bool        $searchable whether the picker answers `?q=` with the matching rows rather than listing them all
     */
    public static function reference(string $name, string $label, string $source, bool $required = true, ?string $help = null, ?string $picker = null, bool $searchable = false): self
    {
        return new self($name, PropType::Reference, $label, null, $required, trim($source.'. '.($help ?? '')), picker: $picker, searchable: $searchable);
    }

    public static function json(string $name, string $label, mixed $default = [], ?string $help = null): self
    {
        return new self($name, PropType::Json, $label, $default, false, $help);
    }

    /**
     * The prop as a JSON schema, for a generator constrained to produce it.
     *
     * @return array<string, mixed>
     */
    public function toJsonSchema(): array
    {
        $description = trim($this->label.(null !== $this->help ? '. '.rtrim($this->help, '.').'.' : '.'));

        if (!$this->required) {
            $description .= match ($this->type) {
                PropType::Image, PropType::Reference => ' 0 when there is none.',
                PropType::Text, PropType::Html, PropType::Url, PropType::Json => ' Empty when not needed.',
                PropType::Items => ' An empty list when not needed.',
                default => '',
            };
        }

        $schema = match ($this->type) {
            PropType::Text => ['type' => 'string', 'maxLength' => $this->max ?? 255],
            PropType::Html => ['type' => 'string'],
            PropType::Integer => ['type' => 'integer', 'minimum' => $this->min, 'maximum' => $this->max],
            PropType::Boolean => ['type' => 'boolean'],
            // Cast: PHP turns a numeric key such as '1' into an integer, and
            // the API refuses an integer in an enum of strings.
            PropType::Choice => ['type' => 'string', 'enum' => array_map(strval(...), array_keys($this->choices))],
            PropType::Image => ['type' => $this->required ? ['integer', 'string'] : ['integer', 'string', 'null']],
            PropType::Url => ['type' => 'string', 'maxLength' => $this->max ?? 2048],
            PropType::Items => [
                'type' => 'array',
                'minItems' => $this->minItems ?? 0,
                'maxItems' => $this->maxItems ?? 12,
                'items' => $this->scalarItems ? $this->items[0]->toJsonSchema() : (new PropSchema($this->items))->toJsonSchema(),
            ],
            PropType::Reference => ['type' => $this->required ? 'integer' : ['integer', 'null']],
            PropType::Json => ['type' => ['array', 'object']],
        };

        return array_filter(['description' => $description] + $schema, static fn (mixed $value): bool => null !== $value);
    }
}
