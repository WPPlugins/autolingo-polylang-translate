<?php
/**
 * Provider interface — every translation provider must implement this.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

interface AUTOLINGO_Provider {

    /** Machine slug, e.g. 'deepl'. */
    public function get_slug(): string;

    /** Human-readable name, e.g. 'DeepL'. */
    public function get_name(): string;

    /**
     * Translate $text into $target_lang (uppercase ISO code, e.g. 'EN').
     * Returns the translated string, or false on failure.
     */
    public function translate( string $text, string $target_lang ): string|false;

    /**
     * Returns credit/usage data, or false if the provider has no quota concept.
     * Shape: [ 'count' => int, 'limit' => int, 'remaining' => int ]
     */
    public function get_usage(): array|false;

    /**
     * Returns false when the quota is exhausted, true otherwise.
     * Providers without a quota always return true.
     */
    public function check_usage(): bool;

    /** Flushes the cached usage data. */
    public function invalidate_usage_cache(): void;

    /**
     * True when the last translate()/translate_batch() call failed because the account's
     * quota or balance is used up (DeepL 456, Microsoft 403001, Google daily limit,
     * OpenAI insufficient_quota, DeepSeek 402): autolingo_translate_with_fallback() then
     * moves on to the next provider that has a key.
     */
    public function quota_exceeded(): bool;
}
