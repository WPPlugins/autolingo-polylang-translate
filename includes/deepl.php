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

    // Protect [notranslate]...[/notranslate] spans — inner content preserved verbatim.
    $notranslate = [];
    $text        = autolingo_protect_notranslate( $text, $notranslate );

    // Protect entire blocks whose inner content must never be sent to the API
    // (custom HTML, embeds, code…). Must run before strip_block_comments so the
    // opaque block's opening/closing comments are included in the placeholder.
    $opaque   = [];
    $prepared = autolingo_protect_opaque_blocks( $text, $opaque );

    // Strip Gutenberg block comments before sending to the API.
    // Block comments can contain huge JSON attributes (e.g. UAGB info-box with full
    // media library objects) that push content over the provider's request size limit.
    // They are never translated anyway — only the rendered HTML between tags matters.
    $block_comments = [];
    $prepared       = autolingo_strip_block_comments( $prepared, $block_comments );

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

    // Restore opaque blocks
    if ( ! empty( $opaque ) ) {
        $result = str_replace( array_keys( $opaque ), array_values( $opaque ), $result );
    }

    // Restore [notranslate] inner content
    if ( ! empty( $notranslate ) ) {
        $result = str_replace( array_keys( $notranslate ), array_values( $notranslate ), $result );
    }

    // Strip any residual translate="no" spans added by DeepL
    if ( str_contains( $result, 'translate="no"' ) ) {
        $result = preg_replace( '/<span translate="no">(.*?)<\/span>/s', '$1', $result );
    }

    return $result;
}

/**
 * Translate multiple texts in a single provider call, applying all standard
 * protections (opaque blocks, block comments, shortcodes, SEO vars) to each
 * text individually before sending and restoring them after.
 *
 * Falls back to sequential single-text calls for providers that do not
 * implement translate_batch().
 *
 * @param  string[] $texts        Raw texts to translate.
 * @param  string   $target_lang  Target language code.
 * @return string[]|false         Translated strings in the same order, or false on error.
 */
function autolingo_call_deepl_batch( array $texts, string $target_lang ): array|false {
    if ( empty( $texts ) ) return [];

    $provider = autolingo_get_provider();

    $notranslate_maps = [];
    $opaque_maps      = [];
    $block_maps       = [];
    $placeholder_maps = [];
    $prepared         = [];

    foreach ( $texts as $text ) {
        $nt = [];
        $t  = autolingo_protect_notranslate( $text, $nt );
        $opaque = [];
        $t      = autolingo_protect_opaque_blocks( $t, $opaque );
        $blocks = [];
        $t      = autolingo_strip_block_comments( $t, $blocks );
        $ph     = [];
        $t      = autolingo_protect_shortcodes( $t, $ph );
        $t      = autolingo_protect_seo_vars( $t );

        $prepared[]           = $t;
        $notranslate_maps[]   = $nt;
        $opaque_maps[]        = $opaque;
        $block_maps[]         = $blocks;
        $placeholder_maps[]   = $ph;
    }

    if ( method_exists( $provider, 'translate_batch' ) ) {
        $results = $provider->translate_batch( $prepared, $target_lang );
    } else {
        $results = [];
        foreach ( $prepared as $t ) {
            $results[] = $provider->translate( $t, $target_lang );
        }
    }

    if ( $results === false ) return false;

    foreach ( $results as $i => &$result ) {
        if ( ! is_string( $result ) ) continue;
        if ( ! empty( $placeholder_maps[ $i ] ) ) {
            $result = str_replace( array_keys( $placeholder_maps[ $i ] ), array_values( $placeholder_maps[ $i ] ), $result );
        }
        if ( ! empty( $block_maps[ $i ] ) ) {
            $result = str_replace( array_keys( $block_maps[ $i ] ), array_values( $block_maps[ $i ] ), $result );
        }
        if ( ! empty( $opaque_maps[ $i ] ) ) {
            $result = str_replace( array_keys( $opaque_maps[ $i ] ), array_values( $opaque_maps[ $i ] ), $result );
        }
        if ( ! empty( $notranslate_maps[ $i ] ) ) {
            $result = str_replace( array_keys( $notranslate_maps[ $i ] ), array_values( $notranslate_maps[ $i ] ), $result );
        }
        if ( str_contains( $result, 'translate="no"' ) ) {
            $result = preg_replace( '/<span translate="no">(.*?)<\/span>/s', '$1', $result );
        }
    }
    unset( $result );

    return $results;
}

// ── No-translate shortcode ────────────────────────────────────────────────────

/**
 * Replace [notranslate]...[/notranslate] spans with a placeholder so their
 * inner content is never sent to the translation API and is restored verbatim.
 * The shortcode tags themselves are discarded; only the inner content survives.
 *
 * Usage in post content:  [notranslate]Brand Name[/notranslate]
 */
function autolingo_protect_notranslate( string $text, array &$map ): string {
    if ( ! str_contains( $text, '[notranslate]' ) ) return $text;
    return preg_replace_callback(
        '/\[notranslate\](.*?)\[\/notranslate\]/s',
        static function ( array $m ) use ( &$map ): string {
            $key      = '<!--ALNO' . count( $map ) . '-->';
            $map[$key] = $m[1];
            return $key;
        },
        $text
    );
}

// Passthrough handler so [notranslate] renders cleanly when no translation runs.
add_shortcode( 'notranslate', static function ( $atts, ?string $content = '' ): string {
    return (string) $content;
} );

// ── Block comment stripping ───────────────────────────────────────────────────

/**
 * Replace entire opaque blocks with a placeholder so their inner content is never
 * sent to the translation API.
 *
 * Covered block types:
 *   html         — Custom HTML block (raw markup, must survive verbatim)
 *   embed        — Embed blocks (YouTube, Twitter, …)
 *   code         — Code block
 *   preformatted — Preformatted text block
 *
 * @param string $content    Post content.
 * @param array  &$protected Placeholder → original block map (passed by reference).
 * @return string Content with opaque blocks replaced by placeholders.
 */
function autolingo_protect_opaque_blocks( string $content, array &$protected ): string {
    if ( ! str_contains( $content, '<!-- wp:' ) ) return $content;

    // strpos-based scan: no PCRE backtrack limits, safe on arbitrarily large blocks.
    foreach ( [ 'html', 'embed', 'code', 'preformatted' ] as $block_name ) {
        $open_prefix = '<!-- wp:' . $block_name;
        $close_open  = '<!-- /wp:' . $block_name;

        while ( ( $start = strpos( $content, $open_prefix ) ) !== false ) {
            // Verify the character immediately after the block name is ' ', '/', or '-'
            // so '<!-- wp:html -->' matches but '<!-- wp:htmlblock -->' does not.
            $char = $content[ $start + strlen( $open_prefix ) ] ?? '';
            if ( $char !== ' ' && $char !== '/' && $char !== '-' ) {
                break; // no valid block of this type remains
            }

            // Find end of opening comment
            $open_end = strpos( $content, '-->', $start );
            if ( $open_end === false ) break;
            $open_end += 3;

            $opening = substr( $content, $start, $open_end - $start );

            // Self-closing block: <!-- wp:embed {"url":"..."} /-->
            if ( str_ends_with( rtrim( $opening ), '/-->' ) ) {
                $block = $opening;
            } else {
                // Find matching closing comment: <!-- /wp:html ... -->
                $close_start = strpos( $content, $close_open, $open_end );
                if ( $close_start === false ) break;
                $close_end = strpos( $content, '-->', $close_start );
                if ( $close_end === false ) break;
                $close_end += 3;
                $block = substr( $content, $start, $close_end - $start );
            }

            $key             = '<!--ALOP' . count( $protected ) . '-->';
            $protected[$key] = $block;
            $content         = substr_replace( $content, $key, $start, strlen( $block ) );
        }
    }

    return $content;
}

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
