<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for autolingo_protect_shortcodes() and autolingo_protect_seo_vars()
 * defined in includes/deepl.php.
 *
 * @covers autolingo_protect_shortcodes
 * @covers autolingo_protect_seo_vars
 */
class ShortcodeTest extends TestCase
{
    // ── Helpers ───────────────────────────────────────────────────────────

    /** Run autolingo_protect_shortcodes and return both the modified text and placeholders. */
    private function protect(string $text): array
    {
        $placeholders = [];
        $result       = autolingo_protect_shortcodes($text, $placeholders);
        return [$result, $placeholders];
    }

    /** Restore placeholders back to original shortcodes (simulates what autolingo_call_deepl does). */
    private function restore(string $text, array $placeholders): string
    {
        return str_replace(array_keys($placeholders), array_values($placeholders), $text);
    }

    // ── autolingo_protect_shortcodes() ─────────────────────────────────────────

    public function testProtectShortcodesReplacesSimpleShortcode(): void
    {
        [$result, $placeholders] = $this->protect('Hello [gallery] World');

        $this->assertStringNotContainsString('[gallery]', $result);
        $this->assertCount(1, $placeholders);
        $this->assertContains('[gallery]', $placeholders);
    }

    public function testProtectShortcodesReplacesShortcodeWithAttributes(): void
    {
        $text               = '[contact-form-7 id="1" title="Contact form"]';
        [$result, $placeholders] = $this->protect($text);

        $this->assertStringNotContainsString('[contact-form-7', $result);
        $this->assertCount(1, $placeholders);
        $this->assertContains($text, $placeholders);
    }

    public function testProtectShortcodesReplacesClosingShortcode(): void
    {
        $text                    = '[/caption]';
        [$result, $placeholders] = $this->protect($text);

        $this->assertStringNotContainsString('[/caption]', $result);
        $this->assertCount(1, $placeholders);
        $this->assertContains('[/caption]', $placeholders);
    }

    public function testProtectShortcodesPreservesOrder(): void
    {
        $text = '[first] some text [second] more text [third]';
        [$result, $placeholders] = $this->protect($text);

        // All three shortcodes replaced, order preserved in placeholder values
        $this->assertCount(3, $placeholders);
        $values = array_values($placeholders);
        $this->assertSame('[first]',  $values[0]);
        $this->assertSame('[second]', $values[1]);
        $this->assertSame('[third]',  $values[2]);
    }

    public function testProtectShortcodesLeavesTextWithoutShortcodesUnchanged(): void
    {
        $text                    = 'Just some plain text without any shortcodes.';
        [$result, $placeholders] = $this->protect($text);

        $this->assertSame($text, $result);
        $this->assertEmpty($placeholders);
    }

    public function testProtectShortcodesMultipleShortcodes(): void
    {
        $text = 'Intro [gallery ids="1,2,3"] Middle [caption id="x"] End.';
        [$result, $placeholders] = $this->protect($text);

        $this->assertStringNotContainsString('[gallery', $result);
        $this->assertStringNotContainsString('[caption', $result);
        $this->assertCount(2, $placeholders);

        // The marker format is <span data-pdt="N"></span>
        foreach (array_keys($placeholders) as $marker) {
            $this->assertStringContainsString('data-pdt=', $marker);
        }
    }

    public function testProtectShortcodesMarkersAreUniquePerShortcode(): void
    {
        $text                    = '[alpha] [beta] [gamma]';
        [$result, $placeholders] = $this->protect($text);

        // Each shortcode gets a distinct numeric index in the marker
        $markers = array_keys($placeholders);
        $this->assertCount(3, array_unique($markers));
    }

    public function testRestoreShortcodesRoundTrip(): void
    {
        $original = 'Before [gallery ids="10,11"] Middle [/gallery] After.';
        [$protected, $placeholders] = $this->protect($original);

        // Simulate what the provider returns (unchanged protected text)
        $restored = $this->restore($protected, $placeholders);

        $this->assertSame($original, $restored);
    }

    public function testProtectShortcodesAutoClosingShortcode(): void
    {
        // Self-closing shortcode with a trailing space before the slash
        $text                    = 'Text [vc_row /] more text';
        [$result, $placeholders] = $this->protect($text);

        $this->assertStringNotContainsString('[vc_row', $result);
        $this->assertCount(1, $placeholders);
        $this->assertContains('[vc_row /]', $placeholders);
    }

    // ── autolingo_protect_seo_vars() ───────────────────────────────────────────

    public function testProtectSeoVarsWrapsDoublePctVar(): void
    {
        $result = autolingo_protect_seo_vars('%%title%% | My Site');

        $this->assertSame('<span translate="no">%%title%%</span> | My Site', $result);
    }

    public function testProtectSeoVarsWrapsSinglePctVar(): void
    {
        $result = autolingo_protect_seo_vars('%sep% My Site');

        $this->assertSame('<span translate="no">%sep%</span> My Site', $result);
    }

    public function testProtectSeoVarsLeavesNormalTextAlone(): void
    {
        $text   = 'Just a normal title with no percent variables.';
        $result = autolingo_protect_seo_vars($text);

        $this->assertSame($text, $result);
    }

    public function testProtectSeoVarsWrapsMultipleVars(): void
    {
        $result = autolingo_protect_seo_vars('%%title%% %%sep%% My Site');

        $this->assertStringContainsString('<span translate="no">%%title%%</span>', $result);
        $this->assertStringContainsString('<span translate="no">%%sep%%</span>', $result);
    }

    public function testProtectSeoVarsCombinedWithShortcodes(): void
    {
        // Test the full pipeline: protect shortcodes, then protect SEO vars
        $text = '[gallery id="1"] %%title%% %sep% My Site';

        $placeholders = [];
        $step1        = autolingo_protect_shortcodes($text, $placeholders);
        $step2        = autolingo_protect_seo_vars($step1);

        // Shortcode should be replaced by a marker
        $this->assertStringNotContainsString('[gallery', $step2);
        // SEO vars should be wrapped
        $this->assertStringContainsString('<span translate="no">%%title%%</span>', $step2);
        $this->assertStringContainsString('<span translate="no">%sep%</span>', $step2);

        // Restoring placeholders should bring back the shortcode
        $restored = str_replace(array_keys($placeholders), array_values($placeholders), $step2);
        $this->assertStringContainsString('[gallery id="1"]', $restored);
        $this->assertStringContainsString('%%title%%', $restored);
    }

    public function testProtectSeoVarsPreservesHyphensAndUnderscores(): void
    {
        // Variables like %post_title% and %site-name% should be matched
        $result = autolingo_protect_seo_vars('%post_title% | %site-name%');

        $this->assertStringContainsString('<span translate="no">%post_title%</span>', $result);
        $this->assertStringContainsString('<span translate="no">%site-name%</span>', $result);
    }
}
