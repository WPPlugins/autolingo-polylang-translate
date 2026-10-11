<?php
/**
 * Provider factory + universal translation wrapper.
 *
 * All code calls autolingo_call_deepl() (kept for backward compat) or autolingo_get_provider() directly.
 * Shortcode and SEO-variable protection is applied here for every provider,
 * followed by a Translation Memory lookup (see includes/tm.php) so repeated
 * segments are never sent to the API twice for the same language pair.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Returns the active translation provider instance.
 */
function autolingo_get_provider(): AUTOLINGO_Provider {
    static $instance = null;
    if ( $instance !== null ) return $instance;

    $instance = autolingo_make_provider( autolingo_provider_slug() );
    // Premium: a provider without a key gives way to AutoLingo AI, which needs none.
    if ( class_exists( 'AUTOLINGO_Provider_AI' ) && ! ( $instance instanceof AUTOLINGO_Provider_AI )
        && method_exists( $instance, 'api_key' ) && $instance->api_key() === '' ) {
        $ai = new AUTOLINGO_Provider_AI();
        if ( $ai->api_key() !== '' ) $instance = $ai;
    }

    return $instance;
}

function autolingo_make_provider( string $slug ): AUTOLINGO_Provider {
    switch ( $slug ) {
        case 'openai':    return new AUTOLINGO_Provider_OpenAI();
        case 'google':    return new AUTOLINGO_Provider_Google();
        case 'deepseek':  return new AUTOLINGO_Provider_DeepSeek();
        case 'microsoft': return new AUTOLINGO_Provider_Microsoft();
        case 'autolingo': return class_exists( 'AUTOLINGO_Provider_AI' ) ? new AUTOLINGO_Provider_AI() : new AUTOLINGO_Provider_DeepL();
        default:          return new AUTOLINGO_Provider_DeepL();
    }
}

// ── Fallback when a quota runs out ────────────────────────────────────────────

/** Fallback order after the selected provider: free tiers first (DeepL, Microsoft, Google), then paid ones. */
const AUTOLINGO_FALLBACK_ORDER = [ 'deepl', 'microsoft', 'google', 'openai', 'deepseek' ];

/** On unless switched off in the settings. */
function autolingo_fallback_enabled(): bool {
    return (bool) get_option( 'autolingo_fallback', '1' );
}

/**
 * The providers translations go through: the selected one, followed — when fallback is
 * on — by every other provider that has a key, used when the previous one runs out of quota.
 *
 * @return AUTOLINGO_Provider[]
 */
function autolingo_provider_chain(): array {
    $selected = autolingo_get_provider();
    if ( ! autolingo_fallback_enabled() ) return [ $selected ];

    $chain = [ $selected ];
    // Premium: AutoLingo AI, included, comes last.
    $order = class_exists( 'AUTOLINGO_Provider_AI' ) ? [ ...AUTOLINGO_FALLBACK_ORDER, 'autolingo' ] : AUTOLINGO_FALLBACK_ORDER;
    foreach ( $order as $slug ) {
        if ( $slug === $selected->get_slug() ) continue;
        $provider = autolingo_make_provider( $slug );
        if ( method_exists( $provider, 'api_key' ) && $provider->api_key() !== '' ) {
            $chain[] = $provider;
        }
    }
    return $chain;
}

/**
 * Translate $batch with the first provider of the chain that is not out of quota. When a
 * provider fails because its quota or balance is used up, it is skipped for an hour and
 * the next one translates the same texts — so free DeepL, Microsoft and Google accounts
 * add up (about 3,000,000 characters a month). Any other failure stops here (no silent switch).
 *
 * @param  string[] $batch Prepared texts.
 * @return string[]|false
 */
function autolingo_translate_with_fallback( array $batch, string $target_lang ): array|false {
    $chain     = autolingo_provider_chain();
    $exhausted = array_filter(
        (array) get_transient( 'autolingo_quota_exhausted' ),
        fn( $time ) => time() - (int) $time < HOUR_IN_SECONDS
    );
    $skipped   = [];

    foreach ( $chain as $i => $provider ) {
        $name = $provider->get_name();
        // Skip a provider known to be out of quota — unless it is the last one left.
        if ( isset( $exhausted[ $provider->get_slug() ] ) && $i < count( $chain ) - 1 ) {
            $skipped[] = $name;
            continue;
        }

        $result = autolingo_provider_translate( $provider, $batch, $target_lang );
        if ( $result !== false ) {
            if ( $skipped ) {
                autolingo_log_info(
                    sprintf( 'Translated with %s (%s quota used up).', $name, implode( ', ', $skipped ) ),
                    [], null, $target_lang
                );
            }
            if ( isset( $exhausted[ $provider->get_slug() ] ) ) {
                unset( $exhausted[ $provider->get_slug() ] );
                set_transient( 'autolingo_quota_exhausted', $exhausted, HOUR_IN_SECONDS );
            }
            return $result;
        }
        if ( ! $provider->quota_exceeded() ) {
            return false; // a real error, already logged by the provider
        }
        $exhausted[ $provider->get_slug() ] = time();
        set_transient( 'autolingo_quota_exhausted', $exhausted, HOUR_IN_SECONDS );
        $skipped[] = $name;
    }

    return false;
}

/** One provider: its batch method when it has one, else text by text. */
function autolingo_provider_translate( AUTOLINGO_Provider $provider, array $batch, string $target_lang ): array|false {
    if ( method_exists( $provider, 'translate_batch' ) ) {
        return $provider->translate_batch( $batch, $target_lang );
    }
    $out = [];
    foreach ( $batch as $t ) {
        $r = $provider->translate( $t, $target_lang );
        if ( $r === false ) return false;
        $out[] = $r;
    }
    return $out;
}

// ── Universal wrapper ─────────────────────────────────────────────────────────

/**
 * Translate $text to $target_lang using the active provider.
 * Shortcodes and SEO variables are protected for every provider.
 */
function autolingo_call_deepl( string $text, string $target_lang ): string|false {
    if ( empty( trim( $text ) ) ) return $text;

    $results = autolingo_call_deepl_batch( [ $text ], $target_lang );
    return $results === false ? false : ( $results[0] ?? false );
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

    $notranslate_maps = [];
    $opaque_maps      = [];
    $block_maps       = [];
    $placeholder_maps = [];
    $attribute_maps   = [];
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
        $attrs  = [];
        $t      = autolingo_extract_html_attributes( $t, $attrs );

        $prepared[]           = $t;
        $attribute_maps[]     = $attrs;
        $notranslate_maps[]   = $nt;
        $opaque_maps[]        = $opaque;
        $block_maps[]         = $blocks;
        $placeholder_maps[]   = $ph;
    }

    $api_translate = static function ( array $batch ) use ( $target_lang ): array|false {
        return autolingo_translate_with_fallback( $batch, $target_lang );
    };

    // alt/title values travel as extra texts of the same call, after the texts themselves.
    $segments = array_merge( [], ...array_map( 'array_values', $attribute_maps ) );
    $results  = autolingo_tm_translate_batch( array_merge( $prepared, $segments ), autolingo_source_lang(), $target_lang, $api_translate );

    if ( $results === false ) return false;

    $all_results = $results;
    $offset      = count( $prepared );
    $results     = array_slice( $results, 0, $offset );

    // An AI provider sometimes hands the comment placeholders back altered ("<!--PDT3--" without
    // its ">", "<!--PDT3--->") or drops one, and the exact restore below then misses them: the post
    // loses its block structure. Normalize them; if one is still missing or doubled, translate the
    // text again piece by piece between its placeholders, so that none of them goes to the provider.
    $tm_translate = static function ( array $batch ) use ( $target_lang, $api_translate ): array|false {
        return autolingo_tm_translate_batch( $batch, autolingo_source_lang(), $target_lang, $api_translate );
    };
    foreach ( $results as $i => &$res ) {
        if ( ! is_string( $res ) ) continue;
        $keys = array_values( array_filter(
            array_merge( array_keys( $notranslate_maps[ $i ] ), array_keys( $opaque_maps[ $i ] ), array_keys( $block_maps[ $i ] ) ),
            static fn( string $key ): bool => str_contains( $prepared[ $i ], $key )
        ) );
        if ( ! $keys ) continue;

        $res = autolingo_normalize_placeholders( $res );
        if ( autolingo_placeholders_intact( $res, $keys ) ) continue;

        autolingo_log( 'warning', 'Placeholders lost by the translation provider: text translated again piece by piece.', [], null, $target_lang );
        $piecewise = autolingo_translate_between_placeholders( $prepared[ $i ], $tm_translate );
        if ( $piecewise === false ) return false;
        $res = $piecewise;
    }
    unset( $res );
    foreach ( $attribute_maps as $i => $attrs ) {
        if ( empty( $attrs ) ) continue;
        $values = [];
        foreach ( array_keys( $attrs ) as $token ) {
            $values[ $token ] = is_string( $all_results[ $offset ] ?? null ) ? $all_results[ $offset ] : $attrs[ $token ];
            $offset++;
        }
        if ( is_string( $results[ $i ] ?? null ) ) {
            $results[ $i ] = autolingo_restore_html_attributes( $results[ $i ], $values );
        }
    }

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

// ── Image alt text and titles inside HTML ────────────────────────────────────

/**
 * Providers translate the text between tags, not attribute values: swap each alt/title
 * value for a token and return the values (decoded) in $segments, to translate them as
 * extra texts. Values already tokenized or blank are skipped.
 */
function autolingo_extract_html_attributes( string $html, array &$segments ): string {
    if ( stripos( $html, 'alt' ) === false && stripos( $html, 'title' ) === false ) return $html;
    $pattern = '/(<[a-z][a-z0-9]*\b[^>]*?\s(?:alt|title)\s*=\s*)(["\'])(?!__ALATTR\d+__\2|\s*\2)(.*?)\2/is';
    // A tag can carry both alt and title: repeat until no untokenized value is left.
    do {
        $before = $html;
        $html   = (string) preg_replace_callback( $pattern, static function ( array $m ) use ( &$segments ): string {
            $token              = '__ALATTR' . count( $segments ) . '__';
            $segments[ $token ] = html_entity_decode( $m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            return $m[1] . $m[2] . $token . $m[2];
        }, $html );
    } while ( $html !== $before );
    return $html;
}

/** Put the translated alt/title values back, escaped for an attribute. */
function autolingo_restore_html_attributes( string $html, array $translated ): string {
    $replace = [];
    foreach ( $translated as $token => $text ) {
        $plain             = html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $replace[ $token ] = esc_attr( $plain );
    }
    return $replace ? strtr( $html, $replace ) : $html;
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

/**
 * Comment placeholders that travel through the provider: [notranslate] passages (ALNO),
 * opaque blocks (ALOP) and Gutenberg block comments (PDT), in the forms an AI model may
 * hand them back — "<!--PDT3--" without its ">", "<!--PDT3--->", spaces inside.
 */
const AUTOLINGO_PLACEHOLDER_PATTERN = '/<!--\s*(ALNO|ALOP|PDT)(\d+)\s*-*>?/';

/** Rewrites every placeholder, altered or not, to its exact form. */
function autolingo_normalize_placeholders( string $text ): string {
    return preg_replace( AUTOLINGO_PLACEHOLDER_PATTERN, '<!--$1$2-->', $text ) ?? $text;
}

/**
 * True when each placeholder of $keys is in $text exactly once, and no other one is.
 *
 * @param string[] $keys Placeholders present in the text sent for translation.
 */
function autolingo_placeholders_intact( string $text, array $keys ): bool {
    foreach ( $keys as $key ) {
        if ( substr_count( $text, $key ) !== 1 ) return false;
    }
    return preg_match_all( AUTOLINGO_PLACEHOLDER_PATTERN, $text ) === count( $keys );
}

/**
 * Translates only the pieces of $text between its placeholders, which stay where they are.
 * Pieces without visible text (wrapper tags, whitespace) are not sent.
 *
 * @param callable $translate function(string[] $batch): string[]|false
 * @return string|false The text with its pieces translated, or false if the translation failed.
 */
function autolingo_translate_between_placeholders( string $text, callable $translate ): string|false {
    $parts = preg_split( '/(<!--(?:ALNO|ALOP|PDT)\d+-->)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE );
    $send  = [];
    foreach ( $parts as $k => $part ) {
        if ( $k % 2 === 1 ) continue; // a placeholder
        if ( trim( html_entity_decode( strip_tags( $part ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) === '' ) continue;
        $send[ $k ] = $part;
    }
    if ( ! $send ) return $text;

    $translated = $translate( array_values( $send ) );
    if ( $translated === false ) return false;
    foreach ( array_keys( $send ) as $n => $k ) {
        if ( ! is_string( $translated[ $n ] ?? null ) ) return false;
        $parts[ $k ] = $translated[ $n ];
    }
    return implode( '', $parts );
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

/**
 * False when the selected provider's credit is exhausted and no other provider can take
 * over (fallback off, or no other key).
 */
function autolingo_check_usage(): bool {
    return autolingo_get_provider()->check_usage() || count( autolingo_provider_chain() ) > 1;
}

function autolingo_invalidate_usage_cache(): void {
    autolingo_get_provider()->invalidate_usage_cache();
}
