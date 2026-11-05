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

namespace TheliaCMS\Tests\PHPStan;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A file of `TheliaCMS\Content\` names no class of `TheliaCMS\Generation\`
 * (ADR 12): the document, its catalogue and its pipeline are there whether
 * the generator is or not.
 *
 * Reads every name of the file, so an import, `new`, a static call, a
 * constant, a type, an `instanceof`, a `catch` and an attribute are all
 * caught, written with a `use` or in full. A name in a comment is not.
 *
 * Lives in `Tests/`, out of the service container and the archive: PHPStan is
 * not installed in production, a class that implements its interface must not
 * be loaded there.
 *
 * @implements Rule<FileNode>
 */
final class ContentDoesNotDependOnGenerationRule implements Rule
{
    private const string CONTENT = 'TheliaCMS\Content\\';

    private const string GENERATION = 'TheliaCMS\Generation\\';

    public function getNodeType(): string
    {
        return FileNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $errors = [];

        foreach ($node->getNodes() as $statement) {
            if (!$statement instanceof Node\Stmt\Namespace_ || null === $statement->name || !str_starts_with($statement->name->toString().'\\', self::CONTENT)) {
                continue;
            }

            foreach ((new NodeFinder())->findInstanceOf($statement->stmts, Node\Name::class) as $name) {
                $resolved = ltrim($name->getAttribute('resolvedName', $name)->toString(), '\\');

                if (!str_starts_with($resolved.'\\', self::GENERATION)) {
                    continue;
                }

                $errors[] = RuleErrorBuilder::message(\sprintf('%s is a class of Generation: Content does not depend on Generation (ADR 12).', $resolved))
                    ->identifier('theliaCms.contentDependsOnGeneration')
                    ->line($name->getStartLine())
                    ->build();
            }
        }

        return $errors;
    }
}
