<?php
/**
 * Provider factory + universal translation wrapper.
 *
 * All code calls autolingo_call_deepl() (kept for backward compat) or autolingo_get_provider() directly.
 * Shortcode and SEO-variable protection is applied here for every provider.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Returns the active translation provider instance.
 */
function autolingo_get_provider(): AUTOLINGO_Provider {
    static $instance = null;
    if ( $instance !== null ) return $instance;

    switch ( autolingo_provider_slug() ) {
        case 'openai':  $instance = new AUTOLINGO_Provider_OpenAI();  break;
        case 'google':  $instance = new AUTOLINGO_Provider_Google();  break;
        default:        $instance = new AUTOLINGO_Provider_DeepL();   break;
    }

    return $instance;
}

// ── Universal wrapper ─────────────────────────────────────────────────────────

/**
 * Translate $text to $target_lang using the active provider.
 * Shortcodes and SEO variables are protected for every provider.
 */
function autolingo_call_deepl( string $text, string $target_lang ): string|false {
    if ( empty( trim( $text ) ) ) return $text;

    // Strip Gutenberg block comments before sending to the API.
    // Block comments can contain huge JSON attributes (e.g. UAGB info-box with full
    // media library objects) that push content over the provider's request size limit.
    // They are never translated anyway — only the rendered HTML between tags matters.
    $block_comments = [];
    $prepared       = autolingo_strip_block_comments( $text, $block_comments );

    $placeholders = [];
    $prepared     = autolingo_protect_shortcodes( $prepared, $placeholders );
    $prepared     = autolingo_protect_seo_vars( $prepared );

    $result = autolingo_get_provider()->translate( $prepared, $target_lang );

    if ( $result === false ) return false;

    // Restore shortcodes
    if ( ! empty( $placeholders ) ) {
        $result = str_replace( array_keys( $placeholders ), array_values( $placeholders ), $result );
    }

    // Restore Gutenberg block comments
    if ( ! empty( $block_comments ) ) {
        $result = str_replace( array_keys( $block_comments ), array_values( $block_comments ), $result );
    }

    // Strip any residual translate="no" spans added by DeepL
    if ( str_contains( $result, 'translate="no"' ) ) {
        $result = preg_replace( '/<span translate="no">(.*?)<\/span>/s', '$1', $result );
    }

    return $result;
}

// ── Block comment stripping ───────────────────────────────────────────────────

/**
 * Replace every Gutenberg block comment with a compact placeholder.
 * Returns the stripped content; $comments maps placeholder → original comment.
 */
function autolingo_strip_block_comments( string $content, array &$comments ): string {
    if ( ! str_contains( $content, '<!-- wp:' ) && ! str_contains( $content, '<!-- /wp:' ) ) {
        return $content;
    }
    $i = 0;
    return preg_replace_callback(
        '/<!--\s*\/?wp:[\s\S]*?-->/',
        static function ( array $m ) use ( &$comments, &$i ): string {
            $key            = '<!--PDT' . $i++ . '-->';
            $comments[$key] = $m[0];
            return $key;
        },
        $content
    );
}

// ── Shortcode / SEO helpers (shared by all providers) ────────────────────────

function autolingo_protect_shortcodes( string $text, array &$placeholders ): string {
    if ( ! str_contains( $text, '[' ) ) return $text;
    $counter = 0;
    return preg_replace_callback(
        '/\[\/?\w[\w-]*[^\]]*?\]/',
        static function ( array $m ) use ( &$placeholders, &$counter ): string {
            $marker                = '<span data-pdt="' . $counter++ . '"></span>';
            $placeholders[$marker] = $m[0];
            return $marker;
        },
        $text
    );
}

function autolingo_protect_seo_vars( string $text ): string {
    if ( ! str_contains( $text, '%' ) ) return $text;
    return preg_replace( '/%{1,2}[a-z][a-z0-9_-]*%{1,2}/i', '<span translate="no">$0</span>', $text );
}

// ── Usage helpers ─────────────────────────────────────────────────────────────

function autolingo_get_usage(): array|false {
    return autolingo_get_provider()->get_usage();
}

function autolingo_check_usage(): bool {
    return autolingo_get_provider()->check_usage();
}

function autolingo_invalidate_usage_cache(): void {
    autolingo_get_provider()->invalidate_usage_cache();
}
