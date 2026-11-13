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

use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\Ai\AiToolCall;
use Thelia\Domain\Webhook\WebhookHttpClientFactory;
use Thelia\Model\ModuleConfigQuery;
use TheliaCMS\Content\DocumentSchema;
use TheliaCMS\Content\DocumentValidator;
use TheliaCMS\Generation\Ai\GatewayFailures;
use TheliaCMS\Generation\Ai\GeneratorToolDefinitions;
use TheliaCMS\Generation\Brief;
use TheliaCMS\Generation\DocumentChecker;
use TheliaCMS\Generation\EditorValues;
use TheliaCMS\Generation\GenerationLog;
use TheliaCMS\Generation\GenerationRequest;
use TheliaCMS\Generation\GenerationTarget;
use TheliaCMS\Generation\GeneratorSettings;
use TheliaCMS\Generation\PageGenerator;
use TheliaCMS\Generation\PromptBuilder;
use TheliaCMS\Generation\Tool\GeneratorTools;
use TheliaCMS\Generation\Tool\ImportImageTool;
use TheliaCMS\Generation\Tool\MakeComponentTool;
use TheliaCMS\Generation\Tool\ReadUrlTool;
use TheliaCMS\Generation\TranslationGraft;
use TheliaCMS\Media\Admin\CmsMediaType;
use TheliaCMS\Media\Admin\CmsMediaWriter;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\Tests\Support\FakeAiGateway;
use TheliaLibrary\Model\LibraryImage;
use TheliaLibrary\Model\LibraryImageQuery;

/**
 * `import_image` copies a picture the brief cites into the media library:
 * nothing is requested for an address the brief does not cite, and what comes
 * back is checked on its bytes.
 */
final class ImportImageToolTest extends CmsIntegrationTestCase
{
    private const string URL = 'https://example.com/media/banner.png';

    public function testAPictureOfTheBriefIsCopiedIntoTheLibraryAndTaggedLikeAnUpload(): void
    {
        $tool = $this->tool(static fn (): MockResponse => self::png());

        $answer = $this->decode($tool->import(['url' => self::URL, 'title' => 'Banner of the shop', 'alt' => 'A wooden shelf', 'credit' => 'Photo: Jane Doe'], [self::URL], 'en_US'));

        self::assertArrayNotHasKey('error', $answer, json_encode($answer));
        $image = LibraryImageQuery::create()->findPk($answer['id']);
        self::assertInstanceOf(LibraryImage::class, $image);
        self::assertStringStartsWith('/image-library/'.$answer['id'].'/', $answer['editor_url']);
        self::assertContains($image->getId(), array_map(static fn (LibraryImage $stored): ?int => $stored->getId(), $this->getService(CmsMediaLibrary::class)->images(null, 500)), 'The image carries the tag of the CMS library.');

        $image->setLocale('en_US');
        self::assertSame('Banner of the shop', $image->getTitle());
        self::assertSame('A wooden shelf', $image->getAlt());
        self::assertSame('Photo: Jane Doe', $image->getCaption());
        self::assertSame(1, $image->getWidth());
    }

    public function testAnAddressTheBriefDoesNotCiteIsRefusedWithoutARequest(): void
    {
        $tool = $this->tool(static fn () => throw new \LogicException('Nothing may be requested.'));

        self::assertSame('url_not_cited', $this->decode($tool->import(['url' => 'https://example.com/other.png', 'title' => 'x', 'alt' => '', 'credit' => ''], [self::URL], 'en_US'))['error']);
    }

    public function testAnHttpAddressIsRefusedWithoutARequest(): void
    {
        $url = 'http://example.com/banner.png';
        $tool = $this->tool(static fn () => throw new \LogicException('Nothing may be requested.'));

        self::assertSame('url_not_allowed', $this->decode($tool->import(['url' => $url, 'title' => 'x', 'alt' => '', 'credit' => ''], [$url], 'en_US'))['error']);
    }

    public function testAPrivateAddressIsRefusedByTheGuardOfTheCore(): void
    {
        $url = 'https://127.0.0.1/banner.png';
        // The real guard over a client that would answer: it is the guard that refuses, before any answer.
        $inner = new MockHttpClient(static fn (): MockResponse => self::png());
        $downloader = new ReadUrlTool((new NoPrivateNetworkHttpClient($inner, [...IpUtils::PRIVATE_SUBNETS]))->withOptions(WebhookHttpClientFactory::OPTIONS));
        $tool = new ImportImageTool($downloader, $this->getService(CmsMediaWriter::class), $this->getService(CmsMediaLibrary::class));

        self::assertSame('blocked_address', $this->decode($tool->import(['url' => $url, 'title' => 'x', 'alt' => '', 'credit' => ''], [$url], 'en_US'))['error']);
    }

    public function testAPageOrAFakeExtensionIsRefusedOnItsBytes(): void
    {
        $html = $this->tool(static fn (): MockResponse => new MockResponse('<html><body>Not a picture</body></html>', ['response_headers' => ['Content-Type' => 'image/png']]));
        self::assertSame('unsupported_type', $this->decode($html->import(['url' => self::URL, 'title' => 'x', 'alt' => '', 'credit' => ''], [self::URL], 'en_US'))['error'], 'A body that is not a picture is refused, whatever the Content-Type and the extension say.');

        $gif = $this->tool(static fn (): MockResponse => new MockResponse(base64_decode('R0lGODlhAQABAAAAACwAAAAAAQABAAA=', true) ?: '', ['response_headers' => ['Content-Type' => 'image/png']]));
        self::assertSame('unsupported_type', $this->decode($gif->import(['url' => self::URL, 'title' => 'x', 'alt' => '', 'credit' => ''], [self::URL], 'en_US'))['error'], 'A GIF is not a format of the upload routes.');
    }

    public function testAPictureTooHeavyIsRefused(): void
    {
        $tool = $this->tool(static fn (): MockResponse => new MockResponse(str_repeat('a', CmsMediaType::MAX_IMAGE_BYTES + 5), ['response_headers' => ['Content-Type' => 'image/png']]));

        self::assertSame('too_large', $this->decode($tool->import(['url' => self::URL, 'title' => 'x', 'alt' => '', 'credit' => ''], [self::URL], 'en_US'))['error']);
    }

    public function testTheBodyIsCutOneByteAfterTheCeilingNotReadToItsEnd(): void
    {
        $downloaded = (new ReadUrlTool(new MockHttpClient(new MockResponse(str_repeat('a', 2000)))))->download(self::URL, null, 100);

        self::assertSame(101, \strlen((string) $downloaded['bytes']));
    }

    public function testAPictureWithTooManyPixelsIsRefused(): void
    {
        $tool = $this->tool(static fn (): MockResponse => self::png(6000, 6000));

        self::assertSame('too_many_pixels', $this->decode($tool->import(['url' => self::URL, 'title' => 'x', 'alt' => '', 'credit' => ''], [self::URL], 'en_US'))['error']);
    }

    public function testTheSameAddressImportedTwiceInOneCallGivesTheSameIdAndIsRequestedOnce(): void
    {
        $requests = 0;
        $importer = $this->tool(static function () use (&$requests): MockResponse {
            ++$requests;

            return self::png();
        });
        $this->readingOn(true);
        $page = $this->createPage('Banner', published: false);
        $api = new FakeAiGateway();
        $api
            ->answer(FakeAiGateway::response(toolCalls: [
                new AiToolCall('call_1', 'import_image', ['url' => self::URL, 'title' => 'Banner', 'alt' => '', 'credit' => '']),
                new AiToolCall('call_2', 'import_image', ['url' => self::URL, 'title' => 'Banner again', 'alt' => '', 'credit' => '']),
            ]))
            ->answer(FakeAiGateway::response(json_encode(['version' => 1, 'sections' => [['id' => 'content', 'component' => 'cms:richtext', 'props' => '{"html":"<p>Hi</p>"}']]], \JSON_THROW_ON_ERROR)));

        $locale = $this->locale();
        $this->generator($api, $importer)->generate(GenerationRequest::fromBrief(GenerationTarget::page($page, $locale), $locale, new Brief('Import this image: '.self::URL)));

        self::assertContains('import_image', array_column($api->requests[0]->tools, 'name'));
        self::assertStringContainsString('`import_image`', $api->requests[0]->messages[0]->content);
        $first = $this->decode($api->requests[1]->messages[2]->content);
        $second = $this->decode($api->requests[1]->messages[3]->content);
        self::assertArrayHasKey('id', $first);
        self::assertSame($first['id'], $second['id']);
        self::assertSame(1, $requests);
    }

    public function testTheToolIsNotOfferedWhenTheBriefCitesNoAddressOrTheSiteDisallowsReading(): void
    {
        $page = $this->createPage('Banner', published: false);
        $locale = $this->locale();
        $document = json_encode(['version' => 1, 'sections' => [['id' => 'content', 'component' => 'cms:richtext', 'props' => '{"html":"<p>Hi</p>"}']]], \JSON_THROW_ON_ERROR);
        $importer = $this->tool(static fn () => throw new \LogicException('Nothing may be requested.'));

        $this->readingOn(true);
        $api = (new FakeAiGateway())->answer(FakeAiGateway::response($document));
        $this->generator($api, $importer)->generate(GenerationRequest::fromBrief(GenerationTarget::page($page, $locale), $locale, new Brief('A page about the shop, no address.')));
        self::assertNotContains('import_image', array_column($api->requests[0]->tools, 'name'), 'A brief citing no picture offers no import.');

        $this->readingOn(false);
        $api = (new FakeAiGateway())->answer(FakeAiGateway::response($document));
        $this->generator($api, $importer)->generate(GenerationRequest::fromBrief(GenerationTarget::page($page, $locale), $locale, new Brief('Import this image: '.self::URL)));
        self::assertNotContains('import_image', array_column($api->requests[0]->tools, 'name'), 'A site that did not allow the reading of the web offers no import.');
        self::assertStringNotContainsString('import_image', $api->requests[0]->messages[0]->content);
    }

    private function readingOn(bool $on): void
    {
        $settings = $this->getService(GeneratorSettings::class);
        $settings->save($settings->effort(), $settings->maxTokens(), webFetch: $on);
        ModuleConfigQuery::resetConfigCache();
    }

    private function tool(\Closure $respond): ImportImageTool
    {
        return new ImportImageTool(new ReadUrlTool(new MockHttpClient($respond)), $this->getService(CmsMediaWriter::class), $this->getService(CmsMediaLibrary::class));
    }

    private function generator(FakeAiGateway $api, ImportImageTool $importer): PageGenerator
    {
        return new PageGenerator(
            $api,
            $this->getService(GeneratorSettings::class),
            $this->getService(PromptBuilder::class),
            $this->getService(GeneratorTools::class),
            $this->getService(GeneratorToolDefinitions::class),
            new ReadUrlTool(new MockHttpClient(static fn () => throw new \LogicException('No page may be read here.'))),
            $this->getService(MakeComponentTool::class),
            $this->getService(DocumentSchema::class),
            $this->getService(DocumentValidator::class),
            $this->getService(GenerationLog::class),
            new NullLogger(),
            new GatewayFailures($this->getService(TranslatorInterface::class)),
            $this->getService(TranslationGraft::class),
            $this->getService(DocumentChecker::class),
            $this->getService(EditorValues::class),
            imageImporter: $importer,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        return json_decode($json, true, 8, \JSON_THROW_ON_ERROR);
    }

    /**
     * A real PNG of one pixel, or the header of one that declares a larger size (only the header is read).
     */
    private static function png(int $width = 1, int $height = 1): MockResponse
    {
        $body = 1 === $width && 1 === $height
            ? (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true)
            : "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('NN', $width, $height)."\x08\x06\x00\x00\x00".pack('N', 0);

        return new MockResponse($body, ['response_headers' => ['Content-Type' => 'image/png']]);
    }
}
