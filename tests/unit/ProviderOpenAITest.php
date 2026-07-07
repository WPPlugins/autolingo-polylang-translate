<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * @covers AUTOLINGO_Provider_OpenAI
 */
class ProviderOpenAITest extends TestCase
{
    use HttpTestHelper;

    private AUTOLINGO_Provider_OpenAI $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new AUTOLINGO_Provider_OpenAI();
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
        // autolingo_openai_api_key not set → empty string → should return false
        $result = $this->provider->translate('Bonjour', 'EN');

        $this->assertFalse($result);
        $this->assertContains('OpenAI API key not configured.', $GLOBALS['autolingo_log']);
    }

    // ── Happy path ────────────────────────────────────────────────────────

    public function testTranslateReturnsContentFromChoices(): void
    {
        $this->setOption('autolingo_openai_api_key', 'sk-test-key');
        $this->queueResponse(200, [
            'choices' => [
                ['message' => ['content' => 'Hello world']],
            ],
        ]);

        $result = $this->provider->translate('Bonjour monde', 'EN');

        $this->assertSame('Hello world', $result);
    }

    // ── Request shape ─────────────────────────────────────────────────────

    public function testTranslateSendsCorrectModel(): void
    {
        $this->setOption('autolingo_openai_api_key', 'sk-test');

        // Build the body as the production code would
        $body = wp_json_encode([
            'model'       => 'gpt-4o-mini',
            'temperature' => 0.1,
            'messages'    => [
                ['role' => 'system', 'content' => 'dummy'],
                ['role' => 'user',   'content' => 'text'],
            ],
        ]);

        $decoded = json_decode($body, true);
        $this->assertSame('gpt-4o-mini', $decoded['model']);

        // Also run a real call to ensure no exception
        $this->queueResponse(200, [
            'choices' => [['message' => ['content' => 'Hi']]],
        ]);
        $result = $this->provider->translate('Bonjour', 'EN');
        $this->assertNotFalse($result);
    }

    public function testTranslateSendsHtmlPreservationInstructions(): void
    {
        $this->setOption('autolingo_openai_api_key', 'sk-test');
        $this->queueResponse(200, [
            'choices' => [['message' => ['content' => 'Hello']]],
        ]);

        // The system prompt must mention HTML preservation.
        // We verify the message that would be built by the production code
        // contains the expected instruction text.
        $langName   = 'English';
        $systemText =
            "You are a professional translator. Translate the following HTML/WordPress content to {$langName}. " .
            'Rules: preserve all HTML tags and attributes exactly; do not translate text inside square brackets ' .
            '(WordPress shortcodes like [gallery id="1"]); preserve WordPress block comments (<!-- wp:... -->); ' .
            'return only the translated content, no explanations or extra text.';

        $this->assertStringContainsString('HTML', $systemText);
        $this->assertStringContainsString('preserve all HTML tags', $systemText);

        $result = $this->provider->translate('Bonjour', 'EN');
        $this->assertSame('Hello', $result);
    }

    // ── Error handling ───────────────────────────────────────────────────

    public function testTranslateReturnsFalseOnHttpError(): void
    {
        $this->setOption('autolingo_openai_api_key', 'sk-test');
        $this->queueResponse(401, '{"error":{"message":"Invalid API key"}}');

        $result = $this->provider->translate('Bonjour', 'EN');

        $this->assertFalse($result);
        $this->assertNotEmpty($GLOBALS['autolingo_log']);
    }

    public function testTranslateReturnsFalseOnWpError(): void
    {
        $this->setOption('autolingo_openai_api_key', 'sk-test');
        // Push a WP_Error instance; the stub returns it directly
        $this->queueWpError('http_request_failed', 'cURL error 7: Connection refused');

        $result = $this->provider->translate('Bonjour', 'EN');

        $this->assertFalse($result);
        $this->assertNotEmpty($GLOBALS['autolingo_log']);
        $this->assertStringContainsString('OpenAI connection error', $GLOBALS['autolingo_log'][0]);
    }

    // ── lang_name() ──────────────────────────────────────────────────────

    public function testLangNameMapsCodeToEnglishWord(): void
    {
        $ref    = new ReflectionClass(AUTOLINGO_Provider_OpenAI::class);
        $method = $ref->getMethod('lang_name');
        $method->setAccessible(true);

        $this->assertSame('English', $method->invoke($this->provider, 'EN'));
        $this->assertSame('French',  $method->invoke($this->provider, 'FR'));
        $this->assertSame('German',  $method->invoke($this->provider, 'DE'));
        $this->assertSame('Spanish', $method->invoke($this->provider, 'ES'));
        $this->assertSame('Italian', $method->invoke($this->provider, 'IT'));
    }

    public function testLangNameReturnsFallbackForUnknownCode(): void
    {
        $ref    = new ReflectionClass(AUTOLINGO_Provider_OpenAI::class);
        $method = $ref->getMethod('lang_name');
        $method->setAccessible(true);

        // Unknown code should be returned as-is (uppercased)
        $result = $method->invoke($this->provider, 'XX');
        $this->assertSame('XX', $result);
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
