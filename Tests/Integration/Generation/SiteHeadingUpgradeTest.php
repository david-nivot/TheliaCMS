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

namespace TheliaCMS\Tests\Integration\Generation;

use TheliaCMS\Content\HeadingChoice;
use TheliaCMS\Content\SectionCatalog;
use TheliaCMS\Content\Site\SiteComponent;
use TheliaCMS\Content\Site\SiteComponentRepository;
use TheliaCMS\Content\Site\SiteComponentValidator;
use TheliaCMS\Generation\Component\ComponentCandidates;
use TheliaCMS\Generation\Component\SiteHeadingUpgrade;
use TheliaCMS\Generation\GenerationTarget;
use TheliaCMS\Model\CmsPageGenerationQuery;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;

/**
 * A component of the site that draws its own heading, as the generator wrote
 * it before ADR 17, is offered a version with the heading of the site: a
 * version to keep, no call, no page changed before.
 */
final class SiteHeadingUpgradeTest extends CmsIntegrationTestCase
{
    private const string OWN_HEADING = '<section class="faq">{% if props.title is not empty %}<h2 class="faq__title">{{ props.title }}</h2>{% endif %}{% for item in props.questions %}<h3>{{ item.question }}</h3>{% endfor %}</section>';

    public function testAComponentDrawingItsOwnHeadingIsOfferedTheHeadingOfTheSiteAsAVersionToKeep(): void
    {
        $repository = $this->getService(SiteComponentRepository::class);
        $repository->save(self::component('Faq', self::OWN_HEADING), SiteComponentRepository::STATUS_READY);
        $repository->save(self::component('Shout', '<section>{% if props.title is not empty %}<h2 class="shout">{{ props.title|upper }}</h2>{% endif %}</section>'), SiteComponentRepository::STATUS_READY);
        $upgrade = $this->getService(SiteHeadingUpgrade::class);
        $faqId = $repository->idOf('Faq');

        $listed = $upgrade->propose($this->locale(), false);

        self::assertSame([['code' => 'Faq', 'class' => 'faq__title', 'candidate' => null, 'problem' => null]], $listed, 'Only the heading written as the generator wrote it; listed, not written.');
        self::assertSame(0, $this->rowsOf($faqId));

        [$proposed] = $upgrade->propose($this->locale());
        $candidate = $this->getService(ComponentCandidates::class)->find((int) $proposed['candidate']);

        self::assertNotNull($candidate?->component);
        self::assertTrue($candidate->isChange(), 'A version of the component, kept from its screen.');
        self::assertStringContainsString('faq__title', $candidate->request, 'The editor is told which rule no longer styles the heading.');
        self::assertSame(0.0, $candidate->costUsd);
        self::assertStringContainsString("{% if props.title is not empty %}{{ cms_component('cms:heading', {text: props.title, level: props.title_level, style: props.title_style}) }}{% endif %}{% for item", $candidate->component->template);
        self::assertSame(['title', HeadingChoice::LEVEL_PROP, HeadingChoice::STYLE_PROP, 'questions'], array_column($candidate->component->props, 'name'));
        self::assertSame(HeadingChoice::LEVELS, array_keys($candidate->component->props[1]['choices']));
        self::assertSame([HeadingChoice::LEVEL_STYLE, 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'], array_keys($candidate->component->props[2]['choices']), 'The styles the heading offers under Flexy.');

        self::assertStringContainsString('<h2 class="faq__title">', (string) $repository->find('Faq')?->template, 'No page changes before the editor keeps it.');
        self::assertSame([], $upgrade->propose($this->locale()), 'A version already offered is not offered again.');
        self::assertSame(1, $this->rowsOf($faqId));

        $kept = $this->getService(SiteComponentValidator::class)->validate($candidate->component);
        $repository->save($kept, SiteComponentRepository::STATUS_READY, 'Faq');
        $this->getService(SectionCatalog::class)->reset();
        $section = $this->getService(SectionCatalog::class)->find('Site:Faq');
        self::assertNotNull($section);

        $html = $section->render($section->normalizeProps(['title' => 'Vos questions', 'title_level' => 'h3', 'title_style' => 'h1', 'questions' => [['question' => 'Livrez-vous ?', 'answer' => '<p>Oui.</p>'], ['question' => 'Où ?', 'answer' => '<p>Partout.</p>']]]), $this->locale());

        self::assertStringContainsString('<h3 class="cms-heading__text cms-heading__text--styled h1">Vos questions</h3>', $html, 'Once kept, the editor sets the level and the look.');
    }

    private function rowsOf(int $componentId): int
    {
        return CmsPageGenerationQuery::create()->filterByTargetType(GenerationTarget::TYPE_COMPONENT)->filterByTargetId($componentId)->count();
    }

    private static function component(string $code, string $template): SiteComponent
    {
        return new SiteComponent(
            $code,
            'Questions',
            'Questions and answers under a heading.',
            [
                ['name' => 'title', 'type' => 'text', 'label' => 'Titre', 'max' => 120],
                ['name' => 'questions', 'type' => 'items', 'label' => 'Questions', 'min' => 2, 'max' => 12, 'items' => [
                    ['name' => 'question', 'type' => 'text', 'label' => 'Question', 'required' => true, 'max' => 160],
                    ['name' => 'answer', 'type' => 'html', 'label' => 'Réponse', 'required' => true],
                ]],
            ],
            ['title' => 'Questions fréquentes', 'questions' => [['question' => 'Un ?', 'answer' => '<p>Oui.</p>'], ['question' => 'Deux ?', 'answer' => '<p>Non.</p>']]],
            $template,
            '.faq__title { font-size: 2rem; }',
        );
    }
}
