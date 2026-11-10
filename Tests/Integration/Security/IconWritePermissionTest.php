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

namespace TheliaCMS\Tests\Integration\Security;

use Thelia\Core\Security\AccessManager;
use Thelia\Test\FixtureFactory;
use TheliaCMS\Media\Admin\CmsMediaWriter;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Media\Icon\IconNames;
use TheliaCMS\Security\CmsResources;
use TheliaLibrary\Model\LibraryImage;
use TheliaLibrary\TheliaLibrary;

/**
 * Renaming an icon, or taking its name away, is a change of the media: it
 * takes the right to change them, not only the right to look at them.
 */
final class IconWritePermissionTest extends AdminScreenTestCase
{
    private const string CLEAN = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M0 0h24v24H0z"/></svg>';

    protected function tearDown(): void
    {
        foreach ($this->getService(CmsMediaLibrary::class)->images() as $image) {
            $file = TheliaLibrary::getImageDirectory().$image->getFileName();

            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function testAnEditorWhoMayOnlyReadTheMediaCannotRenameAnIconNorTakeItsNameAway(): void
    {
        $icon = $this->icon('lecture-seule');
        $sheet = \sprintf('/admin/cms/media/%d', $icon->getId());

        $this->logInAs((new FixtureFactory($this->getPropelConnection()))->restrictedAdmin([
            CmsResources::MEDIA => [AccessManager::VIEW],
        ]));

        $screen = $this->screen($sheet);
        $token = (string) $screen->filter('form[data-testid="cms-icon-rename"] input[name="_token"]')->attr('value');

        self::assertSame(403, $this->send('POST', $sheet.'/icon', ['_token' => $token, 'icon_name' => 'autre-nom']), 'icon_rename');
        self::assertSame(403, $this->send('POST', $sheet.'/icon/remove', ['_token' => $token]), 'icon_remove');
        self::assertSame('lecture-seule', $this->getService(IconNames::class)->nameOf((int) $icon->getId()), 'The icon keeps its name.');
    }

    private function icon(string $name): LibraryImage
    {
        return $this->getService(CmsMediaWriter::class)->addPreparedSvg(self::CLEAN, $name.'.svg', $this->locale(), $name);
    }
}
