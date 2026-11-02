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

namespace TheliaCMS\Tests\Integration\Page;

use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Thelia\Core\Security\AccessManager;
use Thelia\Test\FixtureFactory;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Model\CmsTag;
use TheliaCMS\Model\CmsTagQuery;
use TheliaCMS\Model\Map\CmsPageTableMap;
use TheliaCMS\Model\Map\CmsTagTableMap;
use TheliaCMS\Page\PageTagColor;
use TheliaCMS\Security\CmsResources;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;

/**
 * CMS > Tags and the tag of a page, requested over HTTP by an administrator.
 */
final class TagScreenTest extends AdminScreenTestCase
{
    private const string SCREEN = '/admin/cms/tags';

    public function testTheScreenListsTheTagsWithTheirPages(): void
    {
        $tag = $this->givenTag('Recette');
        $page = $this->createPage('Gratin de pâtes');
        $page->setTagId($tag->getId())->save();

        $screen = $this->screen(self::SCREEN);

        self::assertSame('Recette', $screen->filter(\sprintf('[data-testid="cms-tag-edit-%d"]', $tag->getId()))->text(''));
        self::assertSame('1', $screen->filter(\sprintf('[data-testid="cms-tag-pages-%d"]', $tag->getId()))->text(''));
    }

    public function testATagIsAdded(): void
    {
        self::assertSame(302, $this->submitName('Recette'));

        self::assertNotNull($this->tagNamed('Recette'));
    }

    public function testABlankNameIsRefused(): void
    {
        self::assertSame(200, $this->submitName('   '));

        self::assertGreaterThan(0, $this->client->getCrawler()->filter('.invalid-feedback, .form-error-message')->count(), 'The refusal is shown on the field.');
    }

    public function testATagTheSiteHasIsNotAddedTwice(): void
    {
        $this->givenTag('Recette');

        self::assertSame(200, $this->submitName('recette'));

        self::assertSame(1, CmsTagQuery::create()->filterByName('Recette')->count());
    }

    public function testATagIsRenamed(): void
    {
        $tag = $this->givenTag('Recette');
        $url = \sprintf('%s/%d', self::SCREEN, $tag->getId());

        $form = $this->screen($url)->filter('[data-testid="cms-tag-rename"]')->form();
        $form['page_tag[name]'] = 'Recettes';

        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));
        CmsTagTableMap::clearInstancePool();
        self::assertSame('Recettes', CmsTagQuery::create()->findPk($tag->getId())?->getName());
    }

    public function testATagIsAddedInAColorOfItsOwnWithTheTextChosen(): void
    {
        $form = $this->screen(self::SCREEN)->filter('[data-testid="cms-tag-add"]')->form();
        $form['page_tag[name]'] = 'Recette';
        $form['page_tag[color][colored]']->tick();
        $form['page_tag[color][background]'] = '#DC4A7B';
        $form['page_tag[color][automatic_text]']->untick();
        $form['page_tag[color][text]'] = '#ffffff';

        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        $tag = $this->tagNamed('Recette');
        self::assertSame('#dc4a7b', $tag?->getColor());
        self::assertSame('#ffffff', $tag?->getTextColor());
        $badge = $this->screen(self::SCREEN)->filter(\sprintf('[data-testid="cms-tag-badge-%d"]', $tag->getId()));
        self::assertSame('background-color: #dc4a7b; color: #ffffff;', $badge->attr('style'));
        self::assertStringNotContainsString(PageTagColor::NO_COLOR_BADGE, (string) $badge->attr('class'));
    }

    public function testATagIsRecoloredThenLosesItsColor(): void
    {
        $tag = $this->givenTag('Recette');
        $page = $this->createPage('Gratin de pâtes');
        $page->setTagId($tag->getId())->save();
        $url = \sprintf('%s/%d', self::SCREEN, $tag->getId());

        $form = $this->screen($url)->filter('[data-testid="cms-tag-rename"]')->form();
        self::assertFalse($form['page_tag[color][colored]']->hasValue(), 'A tag without a color has the box unticked.');
        $form['page_tag[color][colored]']->tick();
        $form['page_tag[color][background]'] = '#007040';

        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));
        self::assertSame('#007040', $this->tagNamed('Recette')?->getColor());
        self::assertNull($this->tagNamed('Recette')?->getTextColor(), 'The text is left to the contrast.');
        self::assertSame('background-color: #007040; color: #ffffff;', $this->screen('/admin/cms/pages?q=Gratin')->filter(\sprintf('[data-testid="cms-page-tag-%d"]', $page->getId()))->attr('style'));

        $form = $this->screen($url)->filter('[data-testid="cms-tag-rename"]')->form();
        self::assertTrue($form['page_tag[color][colored]']->hasValue(), 'The screen shows the color the tag has.');
        self::assertSame('#007040', $form['page_tag[color][background]']->getValue());
        $form['page_tag[color][colored]']->untick();

        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));
        self::assertNull($this->tagNamed('Recette')?->getColor());
        self::assertStringContainsString(PageTagColor::NO_COLOR_BADGE, (string) $this->screen('/admin/cms/pages?q=Gratin')->filter(\sprintf('[data-testid="cms-page-tag-%d"]', $page->getId()))->attr('class'));
    }

    public function testACodeThatIsNotAColorIsRefused(): void
    {
        $tag = $this->givenTag('Recette');
        $url = \sprintf('%s/%d', self::SCREEN, $tag->getId());
        $form = $this->screen($url)->filter('[data-testid="cms-tag-rename"]')->form();
        $values = $form->getPhpValues();
        $values['page_tag']['color'] = ['colored' => '1', 'background' => '#ff00ff;x:y', 'automatic_text' => '1', 'text' => '#ffffff'];

        self::assertSame(200, $this->send('POST', $form->getUri(), $values));
        self::assertNull($this->tagNamed('Recette')?->getColor());
    }

    public function testATagIsDeletedAndItsPagesDetached(): void
    {
        $tag = $this->givenTag('Recette');
        $page = $this->createPage('Gratin de pâtes');
        $page->setTagId($tag->getId())->save();

        $screen = $this->screen(self::SCREEN);
        $action = \sprintf('%s/%d/delete', self::SCREEN, $tag->getId());

        self::assertSame(302, $this->send('POST', $action, ['_token' => $this->tokenOfTheForm($screen, $action)]));

        self::assertNull($this->tagNamed('Recette'));
        CmsPageTableMap::clearInstancePool();
        self::assertNull(CmsPageQuery::create()->findPk($page->getId())?->getTagId());
        self::assertStringContainsString('1', $this->client->followRedirect()->filter('[role="alert"]')->text(''));
    }

    public function testATagThatDoesNotExistAnswers404(): void
    {
        $token = $this->tokenOfTheScripts($this->screen(self::SCREEN));

        self::assertSame(404, $this->send('POST', self::SCREEN.'/999999999/delete', [], [...self::XHR, 'HTTP_X_CSRF_TOKEN' => $token]));
        $this->client->request('GET', self::SCREEN.'/999999999');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testThePageFormOffersTheTagsAndSavesTheOneChosen(): void
    {
        $tag = $this->givenTag('Recette');
        $page = $this->createPage('Gratin de pâtes');

        $form = $this->screen(\sprintf('/admin/cms/pages/%d', $page->getId()))->filter('form[name="cms_page"]')->form();
        $field = $form->get('cms_page[tag]');
        self::assertInstanceOf(ChoiceFormField::class, $field);
        self::assertContains((string) $tag->getId(), $field->availableOptionValues());
        $field->select((string) $tag->getId());

        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));
        CmsPageTableMap::clearInstancePool();
        self::assertSame((int) $tag->getId(), (int) CmsPageQuery::create()->findPk($page->getId())?->getTagId());

        $listing = $this->screen('/admin/cms/pages?q=Gratin');
        self::assertSame('Recette', trim($listing->filter(\sprintf('[data-testid="cms-page-tag-%d"]', $page->getId()))->text('')));
    }

    public function testSeeingTheTagsIsNotTheRightToChangeThem(): void
    {
        $tag = $this->givenTag('Recette');
        $this->logInAs((new FixtureFactory($this->getPropelConnection()))->restrictedAdmin([
            CmsResources::PAGE => [AccessManager::VIEW],
        ]));

        $token = $this->tokenOfTheScripts($this->screen(self::SCREEN));
        $server = [...self::XHR, 'HTTP_X_CSRF_TOKEN' => $token];

        self::assertSame(403, $this->send('POST', self::SCREEN, ['page_tag' => ['name' => 'Actualité']], $server));
        self::assertSame(403, $this->send('POST', \sprintf('%s/%d', self::SCREEN, $tag->getId()), ['page_tag' => ['name' => 'Renommée']], $server));
        self::assertSame(403, $this->send('POST', \sprintf('%s/%d/delete', self::SCREEN, $tag->getId()), [], $server));

        self::assertNull($this->tagNamed('Actualité'));
        self::assertSame('Recette', $this->tagNamed('Recette')?->getName());
    }

    private function submitName(string $name): int
    {
        $form = $this->screen(self::SCREEN)->filter('[data-testid="cms-tag-add"]')->form();
        $form['page_tag[name]'] = $name;

        return $this->send('POST', $form->getUri(), $form->getPhpValues());
    }

    private function givenTag(string $name): CmsTag
    {
        $tag = (new CmsTag())->setName($name);
        $tag->save();

        return $tag;
    }

    private function tagNamed(string $name): ?CmsTag
    {
        CmsTagTableMap::clearInstancePool();

        return CmsTagQuery::create()->filterByName($name)->findOne();
    }
}
