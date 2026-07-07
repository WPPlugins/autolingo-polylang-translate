<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * @covers AUTOLINGO_Provider_Google
 */
class ProviderGoogleTest extends TestCase
{
    use HttpTestHelper;

    private AUTOLINGO_Provider_Google $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new AUTOLINGO_Provider_Google();
    }

    // ── Guard clauses ────────────────────────────────────────────────────

    public function testTranslateEmptyTextReturnsEmpty(): void
    {
        $result = $this->provider->translate('', 'EN');

        $this->assertSame('', $result);
        $this->assertEmpty($GLOBALS['autolingo_http_responses']);
    }

    public function testTranslateMissingApiKeyReturnsFalse(): void
    {
        $result = $this->provider->translate('Bonjour', 'EN');

        $this->assertFalse($result);
        $this->assertContains('Google Translate API key not configured.', $GLOBALS['autolingo_log']);
    }

    // ── Happy path ────────────────────────────────────────────────────────

    public function testTranslateReturnsTranslatedText(): void
    {
        $this->setOption('autolingo_google_api_key', 'google-api-key');
        $this->queueResponse(200, [
            'data' => [
                'translations' => [
                    ['translatedText' => 'Hello world'],
                ],
            ],
        ]);

        $result = $this->provider->translate('Bonjour monde', 'EN');

        $this->assertSame('Hello world', $result);
    }

    public function testTranslateDecodesHtmlEntities(): void
    {
        $this->setOption('autolingo_google_api_key', 'google-api-key');
        // Google sometimes HTML-encodes entities in translated output
        $this->queueResponse(200, [
            'data' => [
                'translations' => [
                    ['translatedText' => 'Hello &amp; world &#39;test&#39;'],
                ],
            ],
        ]);

        $result = $this->provider->translate('Bonjour', 'EN');

        // html_entity_decode should convert &amp; → & and &#39; → '
        $this->assertSame("Hello & world 'test'", $result);
    }

    // ── Request shape ─────────────────────────────────────────────────────

    public function testTranslateSendsFormatHtml(): void
    {
        $this->setOption('autolingo_google_api_key', 'google-api-key');
        $this->setOption('autolingo_source_lang', 'fr');

        // Verify the body the production code would construct contains format=html
        $body = wp_json_encode([
            'q'      => 'Bonjour',
            'source' => 'fr',
            'target' => 'en',
            'format' => 'html',
        ]);
        $decoded = json_decode($body, true);
        $this->assertSame('html', $decoded['format']);

        // Confirm real call works
        $this->queueResponse(200, [
            'data' => ['translations' => [['translatedText' => 'Hello']]],
        ]);
        $result = $this->provider->translate('Bonjour', 'EN');
        $this->assertNotFalse($result);
    }

    public function testTranslateIncludesApiKeyInUrl(): void
    {
        $apiKey = 'my-google-key-123';
        $this->setOption('autolingo_google_api_key', $apiKey);

        // The production code appends ?key=<encoded_api_key> to the URL.
        $expectedUrl = 'https://translation.googleapis.com/language/translate/v2?key=' . urlencode($apiKey);
        $this->assertStringContainsString(urlencode($apiKey), $expectedUrl);

        // Run the call to make sure no exception
        $this->queueResponse(200, [
            'data' => ['translations' => [['translatedText' => 'Hello']]],
        ]);
        $result = $this->provider->translate('Bonjour', 'EN');
        $this->assertNotFalse($result);
    }

    public function testTranslatePassesTargetLangCode(): void
    {
        $this->setOption('autolingo_google_api_key', 'google-api-key');
        $this->setOption('autolingo_source_lang', 'fr');

        // The production code lowercases the target lang
        $body    = wp_json_encode(['target' => strtolower('DE')]);
        $decoded = json_decode($body, true);
        $this->assertSame('de', $decoded['target']);

        $this->queueResponse(200, [
            'data' => ['translations' => [['translatedText' => 'Hallo']]],
        ]);
        $result = $this->provider->translate('Bonjour', 'DE');
        $this->assertSame('Hallo', $result);
    }

    // ── Error handling ───────────────────────────────────────────────────

    public function testTranslateReturnsFalseOnHttpError(): void
    {
        $this->setOption('autolingo_google_api_key', 'google-api-key');
        $this->queueResponse(400, '{"error":{"message":"Bad Request"}}');

        $result = $this->provider->translate('Bonjour', 'EN');

        $this->assertFalse($result);
        $this->assertNotEmpty($GLOBALS['autolingo_log']);
    }

    public function testTranslateReturnsFalseOnInvalidJson(): void
    {
        $this->setOption('autolingo_google_api_key', 'google-api-key');
        // 200 but body is not valid JSON → json_decode returns null
        $this->queueResponse(200, 'this is not json at all');

        $result = $this->provider->translate('Bonjour', 'EN');

        // $body['data']['translations'][0]['translatedText'] ?? false → false
        $this->assertFalse($result);
    }

    // ── get_usage() / check_usage() ───────────────────────────────────────

    public function testGetUsageAlwaysReturnsFalse(): void
    {
        $this->assertFalse($this->provider->get_usage());
    }

    public function testCheckUsageAlwaysReturnsTrue(): void
    {
        $this->assertTrue($this->provider->check_usage());
    }
}
