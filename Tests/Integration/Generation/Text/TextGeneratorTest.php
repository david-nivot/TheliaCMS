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

namespace TheliaCMS\Tests\Integration\Generation\Text;

use Psr\Log\NullLogger;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\Ai\AiFinishReason;
use Thelia\Domain\Ai\AiMessageRole;
use Thelia\Domain\Ai\AiToolCall;
use Thelia\Domain\Ai\Exception\AiBudgetExceededException;
use TheliaCMS\Generation\Ai\GatewayFailures;
use TheliaCMS\Generation\Ai\GeneratorToolDefinitions;
use TheliaCMS\Generation\Brief;
use TheliaCMS\Generation\GenerationException;
use TheliaCMS\Generation\GenerationLog;
use TheliaCMS\Generation\GenerationRefusedException;
use TheliaCMS\Generation\GenerationRequest;
use TheliaCMS\Generation\GenerationTarget;
use TheliaCMS\Generation\GeneratorSettings;
use TheliaCMS\Generation\InvalidGenerationException;
use TheliaCMS\Generation\ShopContext;
use TheliaCMS\Generation\Text\TextField;
use TheliaCMS\Generation\Text\TextGenerator;
use TheliaCMS\Generation\Text\TextPrompt;
use TheliaCMS\Generation\Text\TextRequest;
use TheliaCMS\Generation\Text\TextSanitizer;
use TheliaCMS\Generation\Tool\GeneratorTools;
use TheliaCMS\Model\CmsPageGenerationQuery;
use TheliaCMS\Skill\SkillRepository;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\Tests\Support\FakeAiGateway;

/**
 * The text of one rich-text field, written from a brief: formatted text only,
 * with the tools of the catalogue and the pages, and the settings of the texts
 * rather than the ones of the pages. Nothing is saved but the row of the
 * ledger.
 */
final class TextGeneratorTest extends CmsIntegrationTestCase
{
    private FakeAiGateway $api;

    protected function setUp(): void
    {
        parent::setUp();

        $this->api = new FakeAiGateway();
    }

    public function testABriefBecomesFormattedTextAndTheCallIsLogged(): void
    {
        $this->api->answer(self::answer('<p>Un <strong>bol</strong> en grès, tourné à la main.</p><ul><li>Passe au four</li></ul>'));

        $result = $this->generator()->generate($this->request('Présente le bol en deux phrases.', TextField::Summary, '<p>Ancien résumé.</p>'));

        self::assertSame('<p>Un <strong>bol</strong> en grès, tourné à la main.</p><ul><li>Passe au four</li></ul>', $result->html);

        $sent = $this->api->requests[0];
        $settings = $this->getService(GeneratorSettings::class);
        self::assertSame(TextGenerator::USAGE, $sent->usageId);
        self::assertSame('fake-model', $result->model, 'The model is the one the gateway used.');
        self::assertSame($settings->textMaxTokens(), $sent->maxOutputTokens);
        self::assertSame(['anthropic' => ['effort' => $settings->textEffort()]], $sent->providerOptions);
        self::assertSame(TextPrompt::schema(), $sent->outputSchema?->schema, 'The answer is constrained to the envelope of a text.');
        self::assertSame(TextGenerator::TOOLS, array_column($sent->tools, 'name'), 'The catalogue, the pages and the skills: no picture, no section.');
        self::assertNotContains('web_fetch', array_column($sent->tools, 'name'), 'No page of the web is read for a text.');

        $user = $sent->messages[0]->content;
        self::assertStringContainsString('Bol en grès', $user, 'The object is named.');
        self::assertStringContainsString('The summary', $user, 'The field is named.');
        self::assertStringContainsString('<p>Ancien résumé.</p>', $user, 'What the field holds is sent, to rewrite or complete.');
        self::assertStringContainsString('Présente le bol', $user);
        self::assertStringContainsString('# Skills', (string) $sent->system);

        $row = CmsPageGenerationQuery::create()->findPk($result->logId);
        self::assertNotNull($row);
        self::assertSame(['product', 42, GenerationRequest::KIND_TEXT, GenerationLog::STATUS_DONE], [$row->getTargetType(), (int) $row->getTargetId(), $row->getKind(), $row->getStatus()]);
        self::assertSame($result->html, $row->getDocument());
        self::assertSame('fake-model', $row->getModel(), 'The ledger keeps the model of the answer.');
        self::assertSame(AiFinishReason::Completed->value, $row->getStopReason());
        self::assertSame(10, (int) $row->getInputTokens());
        self::assertSame(5, (int) $row->getOutputTokens());
        self::assertEqualsWithDelta(0.001, $result->costUsd, 0.0000001, 'The cost is the one the gateway computed.');
    }

    public function testWhatIsNotFormattedTextIsTakenOutAndItsTextKept(): void
    {
        $this->api->answer(self::answer('<div class="x"><h1>Le bol</h1><p style="color:red" onclick="alert(1)">Grès <img src="https://example.com/a.png"> clair.</p><script>alert(1)</script><table><tr><td>Taille</td></tr></table><a href="javascript:alert(1)">lien</a></div>'));

        $html = $this->generator()->generate($this->request('Décris le bol.'))->html;

        self::assertStringNotContainsString('<div', $html);
        self::assertStringNotContainsString('<h1', $html);
        self::assertStringContainsString('Le bol', $html, 'The text of a heading outside the set is kept.');
        self::assertStringNotContainsString('style', $html);
        self::assertStringNotContainsString('onclick', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<table', $html);
        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringContainsString('Grès', $html);
    }

    public function testACallOfAToolIsAnsweredAndTheTextFollows(): void
    {
        $this->api
            ->answer(FakeAiGateway::response('', null, [new AiToolCall('toolu_1', 'search_products', ['query' => 'bol'])]))
            ->answer(self::answer('<p>Voir aussi nos bols.</p>'));

        $result = $this->generator()->generate($this->request('Décris le bol.'));

        self::assertSame(2, $result->turns);
        self::assertSame('<p>Voir aussi nos bols.</p>', $result->html);
        $second = $this->api->requests[1]->messages;
        self::assertSame([AiMessageRole::User, AiMessageRole::Assistant, AiMessageRole::Tool], array_column(array_map(static fn ($message): array => ['role' => $message->role], $second), 'role'));
        self::assertSame('toolu_1', $second[2]->toolCallId, 'The call is answered by the site.');
        self::assertEqualsWithDelta(0.002, $result->costUsd, 0.0000001, 'The cost sums the turns.');
        self::assertSame(20, $result->usage->inputTokens, 'The tokens sum the turns.');
    }

    public function testAToolOutsideTheOnesOfferedIsNotRun(): void
    {
        $this->api
            ->answer(FakeAiGateway::response('', null, [new AiToolCall('toolu_1', 'search_media', ['query' => 'bol'])]))
            ->answer(self::answer('<p>Un bol.</p>'));

        $this->generator()->generate($this->request('Décris le bol.'));

        self::assertSame('There is no tool called "search_media".', $this->api->requests[1]->messages[2]->content);
    }

    public function testAnAnswerThatCannotBeUsedGoesBackOnceThenFails(): void
    {
        $this->api
            ->answer(FakeAiGateway::response('Voici le texte.'))
            ->answer(self::answer('<p>  </p>'));

        try {
            $this->generator()->generate($this->request('Décris le bol.'));
            self::fail('A text that cannot be used was handed back.');
        } catch (InvalidGenerationException $exception) {
            self::assertStringContainsString('cannot be used', $this->api->requests[1]->messages[2]->content, 'The first answer went back with its problem.');
            self::assertSame(GenerationLog::STATUS_INVALID, CmsPageGenerationQuery::create()->findPk($exception->logId)?->getStatus());
        }
    }

    public function testARefusalIsLoggedAndThrown(): void
    {
        $this->api->answer(FakeAiGateway::response(finishReason: AiFinishReason::ContentFilter));

        try {
            $this->generator()->generate($this->request('Décris le bol.'));
            self::fail('A refusal was handed back as a text.');
        } catch (GenerationRefusedException $exception) {
            self::assertSame(GenerationLog::STATUS_REFUSED, CmsPageGenerationQuery::create()->findPk($exception->logId)?->getStatus());
        }
    }

    public function testAnAnswerCutByTheOutputLimitIsLoggedAndThrown(): void
    {
        $this->api->answer(FakeAiGateway::response('{"html":"<p>Un bol en gr', finishReason: AiFinishReason::MaxTokens, outputTokens: $this->getService(GeneratorSettings::class)->textMaxTokens()));

        try {
            $this->generator()->generate($this->request('Décris le bol.'));
            self::fail('A cut answer was handed back as a text.');
        } catch (GenerationException $exception) {
            self::assertStringContainsString(\sprintf('cut at %d tokens', $this->getService(GeneratorSettings::class)->textMaxTokens()), $exception->getMessage());
            $row = CmsPageGenerationQuery::create()->findPk($exception->logId);
            self::assertSame(GenerationLog::STATUS_FAILED, $row?->getStatus());
            self::assertSame(AiFinishReason::MaxTokens->value, $row->getStopReason());
        }

        self::assertSame(0, $this->api->remaining());
    }

    public function testACallTheGatewayRefusesIsLoggedAsFailedWithItsReason(): void
    {
        $this->api->answer(new AiBudgetExceededException(TextGenerator::USAGE));

        try {
            $this->generator()->generate($this->request('Décris le bol.'));
            self::fail('A call the gateway refused was handed back as a text.');
        } catch (GenerationException $exception) {
            self::assertStringContainsString('The AI budget of the site is spent for now', $exception->getMessage());
            self::assertSame(GenerationLog::STATUS_FAILED, CmsPageGenerationQuery::create()->findPk($exception->logId)?->getStatus());
        }
    }

    public function testTheModelNeverEndsTheLoopOfToolsEndlessly(): void
    {
        for ($turn = 0; $turn < 9; ++$turn) {
            $this->api->answer(FakeAiGateway::response('', null, [new AiToolCall('toolu_'.$turn, 'search_products', ['query' => 'bol'])]));
        }

        $this->expectException(GenerationException::class);
        $this->generator()->generate($this->request('Décris le bol.'));
    }

    private function request(string $brief, TextField $field = TextField::Description, string $current = ''): TextRequest
    {
        $target = GenerationTarget::field('product', 42, 'Bol en grès', $this->locale());

        return new TextRequest(GenerationRequest::text($target, $this->locale(), new Brief($brief)), $field, $current);
    }

    private static function answer(string $html): \Thelia\Domain\Ai\AiResponse
    {
        return FakeAiGateway::response(json_encode(['html' => $html], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));
    }

    private function generator(): TextGenerator
    {
        return new TextGenerator(
            $this->api,
            $this->getService(GeneratorSettings::class),
            new TextPrompt($this->getService(SkillRepository::class), $this->getService(ShopContext::class)),
            $this->getService(GeneratorTools::class),
            new GeneratorToolDefinitions($this->getService(GeneratorTools::class)),
            new TextSanitizer(),
            $this->getService(GenerationLog::class),
            new NullLogger(),
            new GatewayFailures($this->getService(TranslatorInterface::class)),
        );
    }
}
