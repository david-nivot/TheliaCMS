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

namespace TheliaCMS\Tests\Integration\Skill;

use Symfony\Component\DomCrawler\Form;
use Thelia\Core\Security\AccessManager;
use Thelia\Test\FixtureFactory;
use TheliaCMS\Model\CmsSkillQuery;
use TheliaCMS\Model\Map\CmsSkillTableMap;
use TheliaCMS\Security\CmsResources;
use TheliaCMS\Skill\SkillRepository;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;

/**
 * CMS > Skills, requested over HTTP by an administrator: the list of what the
 * module ships, editing one, putting the shipped text back, and what a
 * profile that may only look is refused.
 */
final class SkillScreenTest extends AdminScreenTestCase
{
    private const string SCREEN = '/admin/cms/skills';

    /** A skill the module ships, so the test does not depend on the theme. */
    private const string CODE = 'seo';

    public function testTheScreenListsTheShippedSkills(): void
    {
        $screen = $this->screen(self::SCREEN);

        foreach (['accessibility', 'catalogue', 'copywriting', 'design-system', 'page-structure', self::CODE, 'translation'] as $code) {
            self::assertSame(1, $screen->filter(\sprintf('[data-testid="cms-skill-row-%s"]', $code))->count(), $code.' is listed.');
            self::assertSame(0, $screen->filter(\sprintf('[data-testid="cms-skill-edited-%s"]', $code))->count(), $code.' is as shipped.');
        }

        self::assertSame('1', $screen->filter(\sprintf('[data-testid="cms-skill-enabled-%s"]', self::CODE))->attr('data-enabled'));

        $entry = $screen->filter('[data-testid="bo-nav-cms-skills"]');
        self::assertSame(1, $entry->count(), 'The sidebar offers the skills.');
        self::assertSame(self::SCREEN, parse_url((string) $entry->attr('href'), \PHP_URL_PATH));
        self::assertSame(self::SCREEN, parse_url((string) $screen->filter('[data-testid="bo-nav-cms"] .nav-link[href$="/admin/cms/settings"]')->closest('li')?->previousAll()->first()->filter('a')->attr('href'), \PHP_URL_PATH), 'Skills come just before Settings.');
    }

    public function testEditingASkillSavesItsTextAndWhetherItIsReadAndTheListSaysSo(): void
    {
        $form = $this->editForm();
        $form['cms_skill[body]'] = 'Write the title under sixty characters.';
        $form['cms_skill[enabled]']->untick();
        $form['cms_skill[position]'] = '42';

        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        $saved = $this->row();
        self::assertSame('Write the title under sixty characters.', $saved['body']);
        self::assertSame(0, $saved['enabled']);
        self::assertSame(42, $saved['position']);

        $list = $this->client->followRedirect();
        self::assertSame(1, $list->filter(\sprintf('[data-testid="cms-skill-edited-%s"]', self::CODE))->count(), 'The list marks the skill as edited.');
        self::assertSame('0', $list->filter(\sprintf('[data-testid="cms-skill-enabled-%s"]', self::CODE))->attr('data-enabled'));
        self::assertStringContainsString('saved', $list->filter('[role="alert"]')->text(''));
    }

    public function testABlankTextIsRefused(): void
    {
        $form = $this->editForm();
        $form['cms_skill[body]'] = '   ';

        self::assertSame(200, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        self::assertGreaterThan(0, $this->client->getCrawler()->filter('.invalid-feedback, .form-error-message')->count(), 'The refusal is shown on the field.');
        self::assertNotSame('', $this->row()['body']);
    }

    public function testRestoringASkillPutsTheShippedTextBack(): void
    {
        $shipped = $this->row()['body'];
        $form = $this->editForm();
        $form['cms_skill[body]'] = 'Rewritten by the site.';
        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));
        self::assertSame('Rewritten by the site.', $this->row()['body']);

        $screen = $this->screen(self::SCREEN.'/'.self::CODE);
        $action = self::SCREEN.'/'.self::CODE.'/restore';

        self::assertSame(302, $this->send('POST', $action, ['_token' => $this->tokenOfTheForm($screen, $action)]));

        self::assertSame($shipped, $this->row()['body']);
        self::assertSame(0, $this->client->followRedirect()->filter(\sprintf('[data-testid="cms-skill-edited-%s"]', self::CODE))->count());
    }

    public function testSeeingTheSkillsIsNotTheRightToChangeThem(): void
    {
        $before = $this->row()['body'];
        $this->logInAs((new FixtureFactory($this->getPropelConnection()))->restrictedAdmin([
            CmsResources::SETTINGS => [AccessManager::VIEW],
        ]));

        $list = $this->screen(self::SCREEN);
        self::assertSame(9, $list->filter('[data-testid^="cms-skill-row-"]')->count(), 'The list is readable.');
        self::assertSame(0, $list->filter('[data-testid^="cms-skill-up-"]')->count(), 'No arrow to reorder is offered.');

        $form = $this->editForm();
        $form['cms_skill[body]'] = 'Rewritten without the right to.';
        self::assertSame(403, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        $token = $this->tokenOfTheScripts($this->screen(self::SCREEN));
        self::assertSame(403, $this->send('POST', self::SCREEN.'/'.self::CODE.'/restore', [], [...self::XHR, 'HTTP_X_CSRF_TOKEN' => $token]));

        $position = $this->row()['position'];
        self::assertSame(403, $this->send('POST', self::SCREEN.'/'.self::CODE.'/move/up', [], [...self::XHR, 'HTTP_X_CSRF_TOKEN' => $token]));

        self::assertSame($before, $this->row()['body']);
        self::assertSame($position, $this->row()['position']);
    }

    public function testTheArrowsOfTheListMoveASkillOneSlot(): void
    {
        $screen = $this->screen(self::SCREEN);
        $codes = $screen->filter('[data-testid^="cms-skill-row-"]')->each(static fn ($row): string => substr((string) $row->attr('data-testid'), \strlen('cms-skill-row-')));
        $offset = array_search(self::CODE, $codes, true);
        self::assertIsInt($offset);
        self::assertGreaterThan(0, $offset, 'The test moves a skill that is not first.');

        self::assertNotNull($screen->filter(\sprintf('[data-testid="cms-skill-up-%s"]', $codes[0]))->attr('disabled'), 'The first cannot go up.');
        self::assertNotNull($screen->filter(\sprintf('[data-testid="cms-skill-down-%s"]', end($codes)))->attr('disabled'), 'The last cannot go down.');

        $action = self::SCREEN.'/'.self::CODE.'/move/up';
        self::assertSame(302, $this->send('POST', $action, ['_token' => $this->tokenOfTheForm($screen, $action)]));

        $list = $this->client->followRedirect();
        $after = $list->filter('[data-testid^="cms-skill-row-"]')->each(static fn ($row): string => substr((string) $row->attr('data-testid'), \strlen('cms-skill-row-')));
        [$codes[$offset - 1], $codes[$offset]] = [$codes[$offset], $codes[$offset - 1]];
        self::assertSame($codes, $after, 'The skill took the place of the one above it.');
        self::assertSame($offset, $this->row()['position'], 'Positions are numbered from 1.');
        self::assertStringContainsString('order', $list->filter('[role="alert"]')->text(''));
    }

    public function testACodeNobodyShippedAnswers404(): void
    {
        self::assertSame(404, $this->send('GET', self::SCREEN.'/nope', [], []));

        $token = $this->tokenOfTheScripts($this->screen(self::SCREEN));
        self::assertSame(404, $this->send('POST', self::SCREEN.'/nope/restore', [], [...self::XHR, 'HTTP_X_CSRF_TOKEN' => $token]));
        self::assertSame(404, $this->send('POST', self::SCREEN.'/nope/move/down', [], [...self::XHR, 'HTTP_X_CSRF_TOKEN' => $token]));
    }

    private function editForm(): Form
    {
        return $this->screen(self::SCREEN.'/'.self::CODE)->filter('form[name="cms_skill"]')->form();
    }

    /**
     * @return array{body: string, enabled: int, position: int}
     */
    private function row(): array
    {
        // The rows are sown by the first read; a test that reads the table
        // before opening a screen would find it empty.
        $repository = $this->getService(SkillRepository::class);
        \assert($repository instanceof SkillRepository);
        $repository->sow();

        CmsSkillTableMap::clearInstancePool();
        $model = CmsSkillQuery::create()->findOneByCode(self::CODE);

        self::assertNotNull($model, self::CODE.' is sown.');

        return ['body' => (string) $model->getBody(), 'enabled' => (int) $model->getEnabled(), 'position' => (int) $model->getPosition()];
    }
}
