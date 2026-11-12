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
    $block_attr_maps  = [];
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
        $block_attr_maps[]    = autolingo_extract_block_attributes( $blocks );
        $attribute_maps[]     = $attrs;
        $notranslate_maps[]   = $nt;
        $opaque_maps[]        = $opaque;
        $block_maps[]         = $blocks;
        $placeholder_maps[]   = $ph;
    }

    // Fences are removed before the translation memory stores the answers.
    $api_translate = static function ( array $batch ) use ( $target_lang ): array|false {
        $out = autolingo_translate_with_fallback( $batch, $target_lang );
        if ( ! is_array( $out ) ) return $out;
        foreach ( $out as $k => $text ) {
            if ( is_string( $text ) ) $out[ $k ] = autolingo_strip_code_fence( $text, (string) ( $batch[ $k ] ?? '' ) );
        }
        return $out;
    };

    // alt/title values, then the texts kept in block settings (Kadence tab titles, buttons…), travel
    // as extra texts of the same call, after the texts themselves.
    $segments = array_merge(
        array_merge( [], ...array_map( 'array_values', $attribute_maps ) ),
        array_merge( [], ...array_map( static fn( array $m ): array => array_column( $m, 'text' ), $block_attr_maps ) )
    );
    $results  = autolingo_tm_translate_batch( array_merge( $prepared, $segments ), autolingo_source_lang(), $target_lang, $api_translate );

    if ( $results === false ) return false;

    // …and once more on what the memory hands back, for answers it stored before this fix.
    $sources = array_merge( $prepared, $segments );
    foreach ( $results as $k => $text ) {
        if ( is_string( $text ) ) $results[ $k ] = autolingo_strip_code_fence( $text, (string) ( $sources[ $k ] ?? '' ) );
    }

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
    foreach ( $block_attr_maps as $i => $entries ) {
        if ( empty( $entries ) ) continue;
        $translated = [];
        foreach ( $entries as $n => $entry ) {
            $translated[ $n ] = is_string( $all_results[ $offset ] ?? null ) ? $all_results[ $offset ] : $entry['text'];
            $offset++;
        }
        $block_maps[ $i ] = autolingo_restore_block_attributes( $block_maps[ $i ], $entries, $translated );
    }

    foreach ( $results as $i => &$result ) {
        if ( ! is_string( $result ) ) continue;
        if ( ! empty( $placeholder_maps[ $i ] ) ) {
            $result = str_replace( array_keys( $placeholder_maps[ $i ] ), array_values( $placeholder_maps[ $i ] ), $result );
        }
        if ( ! empty( $block_maps[ $i ] ) ) {
            $result = str_replace( array_keys( $block_maps[ $i ] ), array_values( $block_maps[ $i ] ), $result );
            if ( str_contains( $result, '<!-- wp:kadence/tabs ' ) ) $result = autolingo_kadence_tabs_sync( $result );
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

    // Blocks whose HTML is their text setting wrapped in a tag (GreenShift elements): the HTML
    // takes the translated setting, so that the two stay identical, as the block requires.
    foreach ( $block_attr_maps as $i => $entries ) {
        if ( $entries && is_string( $results[ $i ] ?? null ) ) $results[ $i ] = autolingo_sync_mirrored_html( (string) $texts[ $i ], $results[ $i ] );
    }

    return $results;
}

/** A value inside nested arrays, by a dotted path ("titles.0.text"), or null. */
function autolingo_array_path( array $data, string $path ): mixed {
    foreach ( explode( '.', $path ) as $k ) {
        if ( ! is_array( $data ) || ! array_key_exists( $k, $data ) ) return null;
        $data = $data[ $k ];
    }
    return $data;
}

/**
 * Some blocks write a text setting into their HTML as well: GreenShift's element saves
 * "textContent" as <h2>…</h2>. Translated separately, the two copies differ and the editor asks to
 * recover the block. Where the source block's HTML is exactly one tag around that setting, the
 * translated HTML gets the translated setting.
 */
function autolingo_sync_mirrored_html( string $source, string $translated ): string {
    $re  = '/(<!--\s*\/?wp:[\s\S]*?-->)/';
    $src = preg_split( $re, $source, -1, PREG_SPLIT_DELIM_CAPTURE );
    $dst = preg_split( $re, $translated, -1, PREG_SPLIT_DELIM_CAPTURE );
    if ( ! $src || ! $dst || count( $src ) !== count( $dst ) ) return $translated;
    $map  = autolingo_block_text_attributes();
    $wrap = '/^(\s*<([a-z][a-z0-9-]*)\b[^>]*>)(.*)(<\/\2>\s*)$/s';
    for ( $k = 1; $k < count( $src ) - 1; $k += 2 ) {
        $from = autolingo_parse_block_comment( $src[ $k ] );
        $to   = autolingo_parse_block_comment( $dst[ $k ] );
        if ( ! $from || ! $to || $from['void'] || empty( $map[ $from['name'] ] ) ) continue;
        $html = $src[ $k + 1 ];
        $one  = preg_match( $wrap, $html, $sm ) === 1;
        if ( ! $one || ! preg_match( $wrap, $dst[ $k + 1 ], $dm ) ) $dm = $sm; // the provider reshaped it: the source's tag around the translated setting
        foreach ( $map[ $from['name'] ] as $path ) {
            if ( str_contains( $path, '*' ) ) continue;
            $was = autolingo_array_path( $from['attrs'], $path );
            $now = autolingo_array_path( $to['attrs'], $path );
            if ( ! is_string( $was ) || ! is_string( $now ) || trim( $was ) === '' ) continue;
            if ( $one && ( $sm[3] === $was || html_entity_decode( $sm[3], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) === html_entity_decode( $was, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) {
                $dst[ $k + 1 ] = $dm[1] . $now . $dm[4];
                break;
            }
            // Text nested deeper (a GreenShift button's label inside its link): when the setting is the
            // block's only text, the source HTML with the translated setting in its place.
            if ( substr_count( $html, $was ) === 1 && trim( wp_strip_all_tags( str_replace( $was, '', $html ) ) ) === '' ) {
                $dst[ $k + 1 ] = str_replace( $was, $now, $html );
                break;
            }
        }
    }
    return implode( '', $dst );
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
 * Blocks that keep text in their settings (the JSON of their block comment) rather than in
 * their HTML, and where: "titles.*.text" is the text of each item of the "titles" list. Kadence
 * Blocks' tabs and buttons are the reason (a tab title left in the source language makes the
 * block invalid in the editor, a button's text is only there). Other plugins add theirs with
 * the "autolingo_block_text_attributes" filter.
 *
 * @return array<string, string[]> Block name => attribute paths.
 */
function autolingo_block_text_attributes(): array {
    static $map = null;
    if ( $map !== null ) return $map;
    $map = array_merge_recursive( autolingo_block_text_attributes_defaults(), autolingo_wpml_config_block_keys() );
    foreach ( $map as $block => $paths ) $map[ $block ] = array_values( array_unique( $paths ) );
    return $map = (array) apply_filters( 'autolingo_block_text_attributes', $map );
}

/** AutoLingo's own list, for when a plugin ships no wpml-config.xml. */
function autolingo_block_text_attributes_defaults(): array {
    return [
        'core/search'              => [ 'label', 'placeholder', 'buttonText' ],
        'core/navigation-link'     => [ 'label', 'title', 'description' ],
        'core/navigation-submenu'  => [ 'label', 'title', 'description' ],
        'kadence/tabs'             => [ 'titles.*.text', 'titles.*.subText' ],
        'kadence/singlebtn'        => [ 'text', 'label', 'tooltip' ],
        'kadence/advancedbtn'      => [ 'btns.*.text' ],
        'kadence/iconlist'         => [ 'items.*.text' ],
        'kadence/testimonial'      => [ 'title', 'content', 'name', 'occupation' ],
        'kadence/countup'          => [ 'title' ],
        'kadence/progress-bar'     => [ 'label' ],
        'kadence/tableofcontents'  => [ 'title' ],
        'kadence/table'            => [ 'caption' ],
        'kadence/single-icon'      => [ 'title' ],
        'kadence/search'           => [ 'label' ],
        'kadence/navigation-link'  => [ 'label', 'title', 'description' ],
        'kadence/lottie'           => [ 'label' ],
        'kadence/videopopup'       => [ 'ariaLabel' ],
        'greenshift-blocks/element'       => [ 'textContent', 'tooltipText', 'alt', 'title' ],
        'greenshift-blocks/text'          => [ 'textContent' ],
        'greenshift-blocks/heading'       => [ 'headingContent', 'subTitle', 'textbefore', 'textafter' ],
        'greenshift-blocks/button'        => [ 'buttonContent', 'label', 'closeLabel', 'openLabel' ],
        'greenshift-blocks/buttonbox'     => [ 'buttonContent', 'label', 'closeLabel', 'openLabel' ],
        'greenshift-blocks/accordionitem' => [ 'title' ],
        'greenshift-blocks/titlebox'      => [ 'title' ],
        'greenshift-blocks/progressbar'   => [ 'title', 'label' ],
        'greenshift-blocks/toggler'       => [ 'openlabel', 'closelabel' ],
        'greenshift-blocks/counter'       => [ 'label' ],
        'greenshift-blocks/countdown'     => [ 'labelDay', 'labelHour', 'labelMin', 'labelSec' ],
        'greenshift-blocks/swipe'         => [ 'caption' ],
        'greenshift-blocks/image'         => [ 'title' ],
    ];
}

/**
 * Block settings that hold text, as declared by the active plugins and theme for WPML in their
 * wpml-config.xml (<gutenberg-block type="…" translate="1"><key name="…"/>): GreenShift,
 * Kadence and many others ship one. Keys that hold a link, an anchor, an id or a class are left
 * as they are. Cached until a file changes.
 *
 * @return array<string, string[]> Block name => attribute paths ("titles.*.text" for nested keys).
 */
function autolingo_wpml_config_block_keys(): array {
    if ( ! function_exists( 'simplexml_load_string' ) ) return [];
    $plugins = array_merge( (array) get_option( 'active_plugins', [] ), array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) );
    $files   = [];
    foreach ( $plugins as $plugin ) $files[] = WP_PLUGIN_DIR . '/' . dirname( (string) $plugin ) . '/wpml-config.xml';
    $files[] = get_stylesheet_directory() . '/wpml-config.xml';
    $files[] = get_template_directory() . '/wpml-config.xml';
    $files   = array_values( array_unique( array_filter( $files, 'is_readable' ) ) );
    $sig     = md5( implode( '|', array_map( static fn( $f ) => $f . ':' . filemtime( $f ), $files ) ) );
    $cached  = get_transient( 'autolingo_wpml_block_keys' );
    if ( is_array( $cached ) && ( $cached['sig'] ?? '' ) === $sig ) return (array) $cached['map'];

    $map  = [];
    $walk = static function ( SimpleXMLElement $key, string $prefix, string $block ) use ( &$walk, &$map ): void {
        $name = (string) $key['name'];
        if ( $name === '' ) return;
        $path = $prefix === '' ? $name : $prefix . '.' . $name;
        if ( count( $key->key ) ) {
            foreach ( $key->key as $child ) $walk( $child, $path, $block );
            return;
        }
        if ( (string) $key['type'] === 'link' || preg_match( '/(link|url|href|anchor|class|id|icon|image|src)$/i', $name ) ) return;
        $map[ $block ][] = $path;
    };
    foreach ( $files as $file ) {
        $xml = simplexml_load_string( (string) file_get_contents( $file ), 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
        if ( ! $xml ) continue;
        foreach ( (array) $xml->xpath( '//gutenberg-blocks/gutenberg-block[@translate="1"]' ) as $block ) {
            $type = (string) $block['type'];
            if ( $type === '' ) continue;
            foreach ( $block->key as $key ) $walk( $key, '', $type );
        }
    }
    set_transient( 'autolingo_wpml_block_keys', [ 'sig' => $sig, 'map' => $map ], WEEK_IN_SECONDS );
    return $map;
}

/** A block comment's parts: name ("kadence/tabs"; "core/…" for unprefixed names), attributes, self-closing. */
function autolingo_parse_block_comment( string $comment ): ?array {
    if ( ! preg_match( '/^<!--\s+wp:([a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?)\s+(\{.*\})\s+(\/)?-->$/s', $comment, $m ) ) return null;
    $attrs = json_decode( $m[2], true );
    if ( ! is_array( $attrs ) ) return null;
    return [ 'raw' => $m[1], 'name' => str_contains( $m[1], '/' ) ? $m[1] : 'core/' . $m[1], 'attrs' => $attrs, 'void' => ! empty( $m[3] ) ];
}

/**
 * The texts kept in the settings of the blocks of autolingo_block_text_attributes().
 *
 * @param  array<string, string> $comments Placeholder => block comment (autolingo_strip_block_comments()).
 * @return array<int, array{key:string, path:array, text:string}>
 */
function autolingo_extract_block_attributes( array $comments ): array {
    $map = autolingo_block_text_attributes();
    $out = [];
    foreach ( $comments as $key => $comment ) {
        $block = autolingo_parse_block_comment( $comment );
        if ( ! $block || empty( $map[ $block['name'] ] ) ) continue;
        foreach ( $map[ $block['name'] ] as $path ) {
            $walk = static function ( $node, array $rest, array $at ) use ( &$walk, &$out, $key ): void {
                if ( ! $rest ) {
                    if ( is_string( $node ) && trim( html_entity_decode( strip_tags( $node ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) !== '' ) {
                        $out[] = [ 'key' => $key, 'path' => $at, 'text' => $node ];
                    }
                    return;
                }
                $step = array_shift( $rest );
                if ( ! is_array( $node ) ) return;
                foreach ( $step === '*' ? array_keys( $node ) : [ $step ] as $k ) {
                    if ( array_key_exists( $k, $node ) ) $walk( $node[ $k ], $rest, array_merge( $at, [ $k ] ) );
                }
            };
            $walk( $block['attrs'], explode( '.', $path ), [] );
        }
    }
    return $out;
}

/**
 * The block comments with their translated texts, written back the way WordPress writes block
 * attributes (serialize_block_attributes()).
 *
 * @param array<string, string> $comments
 * @param array<int, array{key:string, path:array, text:string}> $entries
 * @param array<int, string> $translated By the entries' index.
 * @return array<string, string>
 */
function autolingo_restore_block_attributes( array $comments, array $entries, array $translated ): array {
    $by_key = [];
    foreach ( $entries as $n => $entry ) $by_key[ $entry['key'] ][] = [ $entry['path'], $translated[ $n ] ?? $entry['text'] ];
    foreach ( $by_key as $key => $values ) {
        $block = autolingo_parse_block_comment( $comments[ $key ] );
        if ( ! $block ) continue;
        $attrs = $block['attrs'];
        foreach ( $values as [ $path, $text ] ) {
            $node = &$attrs;
            foreach ( $path as $k ) $node = &$node[ $k ];
            $node = $text;
            unset( $node );
        }
        $comments[ $key ] = '<!-- wp:' . $block['raw'] . ' ' . serialize_block_attributes( $attrs ) . ( $block['void'] ? ' /-->' : ' -->' );
    }
    return $comments;
}

/**
 * Kadence tabs write their titles twice: in the block's settings and in the title list of its
 * HTML, where each tab's anchor (id="tab-…", href="#tab-…") is made from its title. Translated
 * separately, the two can differ, and the editor then calls the block invalid. Rewrites each
 * title list from the (translated) settings, the way Kadence's save() writes it.
 */
function autolingo_kadence_tabs_sync( string $html ): string {
    return (string) preg_replace_callback(
        '/(<!-- wp:kadence\/tabs (\{.*?\}) -->)(.*?)(<ul class="kt-tabs-title-list[^"]*">)(.*?)(<\/ul>)/s',
        static function ( array $m ): string {
            $titles = json_decode( $m[2], true )['titles'] ?? null;
            if ( ! is_array( $titles ) ) return $m[0];
            $n    = 0;
            $list = preg_replace_callback(
                '/<li id="[^"]*"(.*?)<a href="#[^"]*"(.*?)<span class="kt-title-text">.*?<\/span>/s',
                static function ( array $li ) use ( $titles, &$n ): string {
                    $t = $titles[ $n++ ] ?? null;
                    if ( ! is_array( $t ) || ! isset( $t['text'] ) ) return $li[0];
                    $anchor = ! empty( $t['anchor'] ) ? (string) $t['anchor'] : 'tab-' . preg_replace( '/[^0-9a-z-]/', '', mb_strtolower( (string) $t['text'] ) );
                    return '<li id="' . $anchor . '"' . $li[1] . '<a href="#' . $anchor . '"' . $li[2] . '<span class="kt-title-text">' . $t['text'] . '</span>';
                },
                $m[5]
            );
            return $m[1] . $m[3] . $m[4] . $list . $m[6];
        },
        $html
    );
}

/**
 * An AI provider sometimes wraps its answer in a Markdown code fence ("```html … ```"), which then
 * ends up in the post title, the content or an alt text. Removes a fence that wraps the whole
 * answer, unless the source text was itself fenced.
 */
function autolingo_strip_code_fence( string $translated, string $source = '' ): string {
    if ( str_starts_with( ltrim( $source ), '```' ) ) {
        return $translated;
    }
    // A language tag ("```html") is only one when a line break follows it.
    if ( preg_match( '/^\s*```(?:[\w+#.-]*[ \t]*\R)?(.*?)\R?[ \t]*```\s*$/su', $translated, $m ) ) {
        return $m[1];
    }
    return $translated;
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
        if ( trim( html_entity_decode( wp_strip_all_tags( $part ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) === '' ) continue;
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
