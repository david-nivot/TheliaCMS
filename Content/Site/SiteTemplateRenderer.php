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

namespace TheliaCMS\Content\Site;

use Twig\Environment;
use Twig\Error\Error;
use Twig\Error\SyntaxError;
use Twig\Extension\SandboxExtension;
use Twig\Loader\ArrayLoader;
use Twig\Markup;
use Twig\Node\ForNode;
use Twig\Node\Node;
use Twig\Sandbox\SecurityError;
use Twig\Sandbox\SecurityPolicy;
use Twig\Source;

/**
 * Renders the template of a component of the site, written by a model and
 * kept in the database, in a Twig of its own: never the Twig of the site,
 * whose functions reach the session, the database and the file system.
 *
 * The sandbox is on for every template of this environment. A template may
 * test, loop and set a variable, print with the escaping filters and a few
 * that shape text, call a few functions of values (`min`, `max`), and
 * nothing else: no property of an object, no include. The only objects it
 * receives are the markup props, whose methods give back strings and counts. It receives the props and nothing else. Markup props
 * come as Twig\Markup, printed as they are; everything else is escaped.
 */
final class SiteTemplateRenderer
{
    /** @var list<string> */
    public const array TAGS = ['if', 'for', 'set'];

    /** @var list<string> */
    public const array FILTERS = ['escape', 'e', 'default', 'length', 'join', 'upper', 'lower', 'capitalize', 'title', 'trim', 'first', 'last', 'slice', 'nl2br', 'number_format', 'replace', 'split', 'keys'];

    /**
     * Functions of values only. Not `range`, nor `1..3` which compiles to it:
     * a range has no bound, and the loops of a component go over its props.
     *
     * @var list<string>
     */
    public const array FUNCTIONS = ['cycle', 'min', 'max'];

    /** @var list<string> */
    public const array TESTS = ['empty', 'defined', 'null', 'none', 'odd', 'even', 'iterable', 'same as', 'divisible by'];

    public const int MAX_LENGTH = 20000;

    /**
     * A list prop holds 24 items and an item holds no list: two loops inside
     * each other cover any props, a third one only multiplies a list by itself.
     */
    public const int MAX_LOOP_DEPTH = 2;

    private ?Environment $twig = null;

    /**
     * The problems of a template, none when it compiles under the policy.
     *
     * @return list<string>
     */
    public function problems(string $template): array
    {
        if ('' === trim($template)) {
            return ['The template is empty.'];
        }

        if (mb_strlen($template) > self::MAX_LENGTH) {
            return [\sprintf('The template is %d characters at most.', self::MAX_LENGTH)];
        }

        // The `constant` test reads a constant of PHP whatever the policy says:
        // Twig compiles it apart from the tests the sandbox checks. `extends`
        // and `use` load another template and are not checked by the policy
        // either: refused by name.
        if (1 === preg_match('/\bis\s+(?:not\s+)?constant\b/', $template)) {
            return ['The template uses what a component may not (the "constant" test).'];
        }

        if (1 === preg_match('/\{%[-~]?\s*(extends|use)\b/', $template, $match)) {
            return [\sprintf('The template uses what a component may not (the "%s" tag): a component is one template.', $match[1])];
        }

        try {
            // The sandbox checks a template when it is displayed, not when it
            // is compiled: the check is run here, without rendering anything.
            $this->twig()->createTemplate($template)->unwrap($this->twig())->checkSecurity();

            // Read in the tree, not by pattern: a comment, a `verbatim` or a
            // string holding the word `for` is not a loop.
            $loopLine = self::lineOfTooDeepLoop($this->twig()->parse($this->twig()->tokenize(new Source($template, 'component'))));
        } catch (SecurityError $exception) {
            return [\sprintf('The template uses what a component may not (%s). Allowed tags: %s; filters: %s; tests: %s; functions: %s.', $exception->getRawMessage(), implode(', ', self::TAGS), implode(', ', self::FILTERS), implode(', ', self::TESTS), implode(', ', self::FUNCTIONS))];
        } catch (SyntaxError $exception) {
            return [\sprintf('The template does not compile, line %d: %s', $exception->getTemplateLine(), $exception->getRawMessage())];
        } catch (Error $exception) {
            return ['The template does not compile: '.$exception->getRawMessage()];
        }

        if (null !== $loopLine) {
            return [\sprintf('The template puts loops more than %d deep, line %d: a component loops over a list, and over the list of an item at most.', self::MAX_LOOP_DEPTH, $loopLine)];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $props as the template reads them (SiteComponentSection::forTemplate)
     *
     * @throws Error when the template is refused or fails while rendering
     */
    public function render(string $template, array $props): string
    {
        if ([] !== $problems = $this->problems($template)) {
            throw new SecurityError(implode(' ', $problems));
        }

        return $this->twig()->createTemplate($template)->render(['props' => $props]);
    }

    private static function lineOfTooDeepLoop(Node $node, int $depth = 0): ?int
    {
        if ($node instanceof ForNode && ++$depth > self::MAX_LOOP_DEPTH) {
            return $node->getTemplateLine();
        }

        foreach ($node as $child) {
            if (null !== $line = self::lineOfTooDeepLoop($child, $depth)) {
                return $line;
            }
        }

        return null;
    }

    private function twig(): Environment
    {
        if (null !== $this->twig) {
            return $this->twig;
        }

        $twig = new Environment(new ArrayLoader(), [
            'autoescape' => 'html',
            'cache' => false,
            'strict_variables' => false,
        ]);

        // Printing a markup prop casts a Twig\Markup to a string: the one
        // method a template may call, and only through printing it.
        $twig->addExtension(new SandboxExtension(new SecurityPolicy(self::TAGS, self::FILTERS, [Markup::class => ['__tostring']], [], self::FUNCTIONS, self::TESTS), true));

        return $this->twig = $twig;
    }
}
