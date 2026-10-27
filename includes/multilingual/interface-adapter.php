<?php
/**
 * Multilingual adapter interface.
 *
 * Abstracts every Polylang / WPML call so the rest of the plugin stays
 * agnostic of the active multilingual plugin.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

interface AutoLingo_Multilingual_Adapter {

    // ── Detection ─────────────────────────────────────────────────────────────

    /** Returns true if this plugin is active and its public API is loaded. */
    public static function is_active(): bool;

    // ── Post language ─────────────────────────────────────────────────────────

    /** Language slug of a post (e.g. 'fr'), or '' if unknown. */
    public function get_post_language( int $post_id ): string;

    /** Assign a language to a post. */
    public function set_post_language( int $post_id, string $lang_slug ): void;

    // ── Translation groups ────────────────────────────────────────────────────

    /**
     * Return all posts in the same translation group as $post_id.
     * Format: [ 'fr' => 12, 'en' => 34, ... ]  (may include the source post itself)
     */
    public function get_post_translations( int $post_id ): array;

    /**
     * Link $source_id and $translated_id as translations of each other.
     * $source_lang is the language slug of $source_id.
     */
    public function save_post_translations(
        int    $source_id,
        int    $translated_id,
        string $translated_lang_slug,
        string $source_lang_slug
    ): void;

    /**
     * Create a translated post via the multilingual plugin's native API
     * (so the language assignment + group linking happen atomically).
     *
     * Returns the new post ID, or 0 on failure (falls back to the generic
     * wp_insert_post + set_language + save_translations approach in translate.php).
     */
    public function create_translated_post(
        WP_Post $source,
        string  $lang_slug,
        array   $data      // [ 'title', 'content', 'excerpt' ]
    ): int;

    // ── Look-ups ──────────────────────────────────────────────────────────────

    /** Translated post ID in $lang_slug, or 0 if not found. */
    public function get_translated_post( int $post_id, string $lang_slug ): int;

    /** Translated term ID in $lang_slug, or 0 if not found. */
    public function get_translated_term( int $term_id, string $lang_slug, string $taxonomy = 'category' ): int;

    /** Assign a language to a taxonomy term. */
    public function set_term_language( int $term_id, string $taxonomy, string $lang_slug ): void;

    /**
     * Link $source_term_id and $translated_term_id as translations of each other.
     * $source_lang_slug is the language slug of $source_term_id.
     */
    public function save_term_translations(
        int    $source_term_id,
        int    $translated_term_id,
        string $taxonomy,
        string $translated_lang_slug,
        string $source_lang_slug
    ): void;

    /** Home URL for $lang_slug (e.g. 'https://example.com/en/'). */
    public function get_home_url( string $lang_slug ): string;

    // ── Query helpers ─────────────────────────────────────────────────────────

    /**
     * Return IDs of published posts in $source_lang.
     *
     * @param string[] $post_types
     */
    public function get_source_post_ids( array $post_types, string $source_lang ): array;

    // ── Menus ─────────────────────────────────────────────────────────────────

    /**
     * Declare $menu_id (a nav_menu term) as the $lang_slug translation of
     * $source_menu_id. Call it before adding items: WPML only attaches items to a
     * menu of their own language. No-op where menus are assigned per language in
     * the plugin's own settings (Polylang menu locations).
     */
    public function link_menu_translation( int $source_menu_id, int $menu_id, string $lang_slug, string $source_lang_slug ): void;

    /**
     * Make $lang_slug the plugin's current language and return the previous one, to
     * pass back afterwards. WPML maps term IDs (menus, categories) to the current
     * language, so content must be created in its own language. '' = no-op.
     */
    public function switch_language( string $lang_slug ): string;

    /** The site's default language ("en"), '' when not configured yet. */
    public function get_default_language(): string;

    /** The site's languages ("en", "fr"…), [] when not configured yet. */
    public function get_languages(): array;
}
