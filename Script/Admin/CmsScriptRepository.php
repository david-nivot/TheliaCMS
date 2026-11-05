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

namespace TheliaCMS\Script\Admin;

use TheliaCMS\Model\CmsScript;
use TheliaCMS\Model\CmsScriptQuery;
use TheliaCMS\Script\ScriptPlacement;

/**
 * Reads the snippets the scripts screen lists and edits.
 */
final readonly class CmsScriptRepository
{
    /**
     * Every snippet, in the order it was added, under the place it is written
     * in; a place with no snippet is there with an empty list.
     *
     * @return array<string, list<CmsScript>>
     */
    public function byPlacement(): array
    {
        $grouped = [];

        foreach (ScriptPlacement::cases() as $placement) {
            $grouped[$placement->value] = [];
        }

        foreach (CmsScriptQuery::create()->orderById()->find() as $script) {
            $grouped[ScriptPlacement::fromStorage($script->getPlacement())->value][] = $script;
        }

        return $grouped;
    }

    public function find(int $id): ?CmsScript
    {
        return CmsScriptQuery::create()->findPk($id);
    }
}
