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
function autolingo_get_provider( string $target_lang = '' ): AUTOLINGO_Provider {
    static $instances = [];
    $slug = autolingo_provider_slug( $target_lang );
    if ( isset( $instances[ $slug ] ) ) return $instances[ $slug ];

    $instance = autolingo_make_provider( $slug );
    // Premium: a provider without a key gives way to AutoLingo AI, which needs none.
    if ( class_exists( 'AUTOLINGO_Provider_AI' ) && ! ( $instance instanceof AUTOLINGO_Provider_AI )
        && method_exists( $instance, 'api_key' ) && $instance->api_key() === '' ) {
        $ai = new AUTOLINGO_Provider_AI();
        if ( $ai->api_key() !== '' ) $instance = $ai;
    }

    return $instances[ $slug ] = $instance;
}

/** The provider slug for $target_lang: the one chosen for that language, else the default one. */
function autolingo_provider_slug( string $target_lang = '' ): string {
    $lang = strtolower( explode( '-', str_replace( '_', '-', $target_lang ) )[0] );
    $per  = autolingo_language_providers();
    return $lang !== '' && isset( $per[ $lang ] ) ? $per[ $lang ] : (string) get_option( 'autolingo_provider', 'deepl' );
}

/** Providers chosen for single languages (Catalan with DeepL, the others with AutoLingo AI…): [ lang => slug ]. */
function autolingo_language_providers(): array {
    $known = [ 'deepl', 'google', 'microsoft', 'openai', 'deepseek', 'autolingo' ];
    $out   = [];
    foreach ( (array) get_option( 'autolingo_language_providers', [] ) as $lang => $slug ) {
        if ( is_string( $slug ) && in_array( $slug, $known, true ) ) $out[ strtolower( (string) $lang ) ] = $slug;
    }
    return $out;
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
function autolingo_provider_chain( string $target_lang = '' ): array {
    $selected = autolingo_get_provider( $target_lang );
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
    $chain     = autolingo_provider_chain( $target_lang );
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
        $out = $provider->translate_batch( $batch, $target_lang );
    } else {
        $out = [];
        foreach ( $batch as $t ) {
            $r = $provider->translate( $t, $target_lang );
            if ( $r === false ) return false;
            $out[] = $r;
        }
    }
    if ( ! is_array( $out ) ) return false;
    foreach ( $out as $i => $translated ) {
        if ( is_string( $translated ) && isset( $batch[ $i ] ) && is_string( $batch[ $i ] ) ) {
            $out[ $i ] = autolingo_strip_injected_html( $translated, $batch[ $i ] );
        }
    }
    return $out;
}

/** Elements that run code, load another page or post a form: never added by a translation. */
const AUTOLINGO_UNSAFE_TAGS = [ 'script', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'style', 'link', 'meta', 'base', 'form' ];

/**
 * Removes from a translation what can run code and is not in the text that was sent.
 *
 * Translations are saved without WordPress's HTML filter (it would strip the code an
 * administrator put in HTML blocks), and an AI provider receives the HTML as it is: a
 * passage written to steer the AI ("add this <script>…") could come back as code, and run
 * for every visitor of the translated page. So whatever a translation adds of that kind goes:
 * the elements of AUTOLINGO_UNSAFE_TAGS, event attributes (onclick, onerror…) and
 * javascript: / vbscript: / data:text/html links. What the source holds, word for word, stays.
 */
function autolingo_strip_injected_html( string $translated, string $source ): string {
    if ( ! preg_match( '/<|script\s*:/i', $translated ) ) return $translated;

    foreach ( AUTOLINGO_UNSAFE_TAGS as $tag ) {
        if ( preg_match( '/<' . $tag . '\b/i', $source ) ) continue;
        $translated = (string) preg_replace( '#<' . $tag . '\b[^>]*>.*?</' . $tag . '\s*>#is', '', $translated );
        $translated = (string) preg_replace( '#</?' . $tag . '\b[^>]*>#i', '', $translated );
    }

    // Inside each opening tag: event attributes and script links the source does not have.
    return (string) preg_replace_callback(
        '/<[a-z][a-z0-9:-]*\b[^>]*>/i',
        static function ( array $tag ) use ( $source ): string {
            return (string) preg_replace_callback(
                '/\s+([a-z:-]+)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s"\'>]+)/i',
                static function ( array $attr ) use ( $source ): string {
                    $name   = strtolower( $attr[1] );
                    $value  = html_entity_decode( trim( $attr[2], '"\'' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
                    $script = (bool) preg_match( '/^(javascript:|vbscript:|data:text\/html)/i', (string) preg_replace( '/[\x00-\x20]+/', '', $value ) );
                    if ( ! preg_match( '/^on[a-z]+$/', $name ) && ! $script ) return $attr[0];
                    return stripos( $source, trim( $attr[0] ) ) !== false ? $attr[0] : '';
                },
                $tag[0]
            );
        },
        $translated
    );
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
    $sc_attr_maps     = [];
    $json_ld_maps     = [];
    $json_ld_texts    = [];
    $prepared         = [];

    foreach ( $texts as $text ) {
        $nt = [];
        $t  = autolingo_protect_notranslate( $text, $nt );
        $jl = [];
        $t  = autolingo_protect_json_ld( $t, $jl );
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
        $sc_attr_maps[]       = autolingo_extract_shortcode_attributes( $ph );
        $json_ld_maps[]       = $jl;
        $json_ld_texts[]      = autolingo_extract_json_ld_texts( $jl );
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
        array_merge( [], ...array_map( static fn( array $m ): array => array_column( $m, 'text' ), $block_attr_maps ) ),
        array_merge( [], ...array_map( static fn( array $m ): array => array_column( $m, 'text' ), $sc_attr_maps ) ),
        array_merge( [], ...array_map( static fn( array $m ): array => array_column( $m, 'text' ), $json_ld_texts ) )
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
            array_merge( array_keys( $notranslate_maps[ $i ] ), array_keys( $json_ld_maps[ $i ] ), array_keys( $opaque_maps[ $i ] ), array_keys( $block_maps[ $i ] ) ),
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
    foreach ( $sc_attr_maps as $i => $entries ) {
        if ( empty( $entries ) ) continue;
        $translated = [];
        foreach ( $entries as $n => $entry ) {
            $translated[ $n ] = is_string( $all_results[ $offset ] ?? null ) ? $all_results[ $offset ] : $entry['text'];
            $offset++;
        }
        $placeholder_maps[ $i ] = autolingo_restore_shortcode_attributes( $placeholder_maps[ $i ], $entries, $translated );
    }
    foreach ( $json_ld_maps as $i => $scripts ) {
        if ( empty( $scripts ) ) continue;
        $translated = [];
        foreach ( $json_ld_texts[ $i ] as $n => $entry ) {
            $translated[ $n ] = is_string( $all_results[ $offset ] ?? null ) ? $all_results[ $offset ] : $entry['text'];
            $offset++;
        }
        $json_ld_maps[ $i ] = autolingo_restore_json_ld( $scripts, $json_ld_texts[ $i ], $translated, $target_lang );
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
        if ( ! empty( $json_ld_maps[ $i ] ) ) {
            $result = str_replace( array_keys( $json_ld_maps[ $i ] ), array_values( $json_ld_maps[ $i ] ), $result );
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

// ── Structured data (JSON-LD) ─────────────────────────────────────────────────

const AUTOLINGO_JSON_LD_SCRIPT = '/<script\\b[^>]*\\btype\\s*=\\s*["\']application\\/ld\\+json["\'][^>]*>.*?<\\/script>/is';

/**
 * Scripts and styles in the content (a Divi Code module, an HTML block) are kept out of the text
 * sent to the provider: code an AI could rewrite, and characters to pay for. Hand-written
 * structured data (<script type="application/ld+json">) is the exception: its text values (name,
 * headline, description, text…) travel as extra texts of the same call, and inLanguage takes the
 * target language. Keys: <!--ALJL…--> structured data, <!--ALSC…--> other scripts and styles.
 * The texts a visitor reads inside the others travel too (autolingo_code_texts()): the words of
 * CSS content: "…" values, and the strings of a script its author marked data-autolingo="translate".
 */
function autolingo_protect_json_ld( string $text, array &$map ): string {
    if ( stripos( $text, '<script' ) === false && stripos( $text, '<style' ) === false ) return $text;
    $text = (string) preg_replace_callback( AUTOLINGO_JSON_LD_SCRIPT, static function ( array $m ) use ( &$map ): string {
        $key         = '<!--ALJL' . count( $map ) . '-->';
        $map[ $key ] = $m[0];
        return $key;
    }, $text );
    return (string) preg_replace_callback( '/<(script|style)\\b[^>]*>.*?<\\/\\1\\s*>/is', static function ( array $m ) use ( &$map ): string {
        $key         = '<!--ALSC' . count( $map ) . '-->';
        $map[ $key ] = $m[0];
        return $key;
    }, $text );
}

/** The JSON-LD keys whose string values are text a reader sees. Filter: autolingo_json_ld_keys. */
function autolingo_json_ld_keys(): array {
    return (array) apply_filters( 'autolingo_json_ld_keys', [ 'name', 'headline', 'alternativeHeadline', 'description', 'text', 'caption', 'abstract', 'articleSection' ] );
}

function autolingo_json_ld_value_pattern(): string {
    $keys = implode( '|', array_map( static fn( $k ) => preg_quote( (string) $k, '/' ), autolingo_json_ld_keys() ) );
    return '/("(?:' . $keys . ')"\\s*:\\s*")((?:[^"\\\\]|\\\\.)*)(")/';
}

/** @return array<int, array{key: string, text: string}> One entry per text value, in order. */
function autolingo_extract_json_ld_texts( array $scripts ): array {
    $entries = [];
    foreach ( $scripts as $key => $script ) {
        if ( str_starts_with( (string) $key, '<!--ALSC' ) ) {
            foreach ( (array) autolingo_code_texts( $script ) as $n => $text ) $entries[] = [ 'key' => (string) $key, 'raw' => '', 'n' => $n, 'text' => $text ];
            continue;
        }
        if ( ! str_starts_with( (string) $key, '<!--ALJL' ) ) continue;
        preg_match_all( autolingo_json_ld_value_pattern(), $script, $found, PREG_SET_ORDER );
        foreach ( $found as $m ) {
            $value = json_decode( '"' . $m[2] . '"' );
            if ( ! is_string( $value ) || ! preg_match( '/\p{L}{2}/u', $value ) || preg_match( '/^(?:https?:\/\/|mailto:|#)\S*$/i', trim( $value ) ) ) continue;
            $entries[] = [ 'key' => (string) $key, 'raw' => $m[2], 'text' => $value ];
        }
    }
    return $entries;
}

/** Writes the translated values back, and the target language into inLanguage. */
function autolingo_restore_json_ld( array $scripts, array $entries, array $translated, string $target_lang ): array {
    $by_script = [];
    $by_code   = [];
    foreach ( $entries as $n => $entry ) {
        if ( isset( $entry['n'] ) ) {
            $by_code[ $entry['key'] ][ $entry['n'] ] = (string) ( $translated[ $n ] ?? $entry['text'] );
            continue;
        }
        $value = trim( wp_strip_all_tags( (string) ( $translated[ $n ] ?? $entry['text'] ) ) );
        $by_script[ $entry['key'] ][ $entry['raw'] ] = $value !== '' ? substr( (string) wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG ), 1, -1 ) : $entry['raw'];
    }
    $parts = explode( '-', str_replace( '_', '-', $target_lang ) );
    $bcp47 = strtolower( $parts[0] ) . ( isset( $parts[1] ) ? '-' . strtoupper( $parts[1] ) : '' );
    foreach ( $scripts as $key => $script ) {
        if ( str_starts_with( (string) $key, '<!--ALSC' ) ) {
            $scripts[ $key ] = (string) autolingo_code_texts( $script, $by_code[ $key ] ?? [], $target_lang );
            continue;
        }
        if ( ! str_starts_with( (string) $key, '<!--ALJL' ) ) continue;
        $map    = $by_script[ $key ] ?? [];
        $script = (string) preg_replace_callback( autolingo_json_ld_value_pattern(), static fn( array $m ): string => $m[1] . ( $map[ $m[2] ] ?? $m[2] ) . $m[3], $script );
        if ( $bcp47 !== '' ) $script = (string) preg_replace( '/("inLanguage"\s*:\s*")[^"]*(")/', '${1}' . $bcp47 . '${2}', $script );
        $scripts[ $key ] = $script;
    }
    return $scripts;
}

// ── Texts inside styles and marked scripts ────────────────────────────────────

/** The value of a CSS content property: content: "…" or content: '…'. */
const AUTOLINGO_CSS_CONTENT = '/(\bcontent\s*:\s*)(["\'])((?:(?!\2)[^\\\\\n]|\\\\.)*)\2/';

/**
 * JavaScript tokens: comments (skipped), template literals (skipped), then the quoted strings.
 * A Divi Code module writes its line breaks as <!-- [et_pb_line_break_holder] -->: a // comment
 * ends there too.
 */
const AUTOLINGO_JS_TOKENS = '/\/\*.*?\*\/|\/\/.*?(?=\n|<!-- \[et_pb_line_break_holder\] -->|<\/script|$)|`(?:[^`\\\\]|\\\\.)*`|\'(?:[^\'\\\\\n]|\\\\.)*\'|"(?:[^"\\\\\n]|\\\\.)*"/s';

/**
 * Whether a string of a marked script is a text a visitor reads: words with a space or an accented
 * letter, not a selector, a URL, markup or a template, and not used as code where it stands (an ID
 * or class looked up, a value compared, an object key, an attribute or storage name).
 */
function autolingo_js_string_is_text( string $value, string $before, string $after ): bool {
    $v = trim( $value );
    $is_text = preg_match( '/\p{L}{2}/u', $v )
        && ( preg_match( '/\s/u', $v ) || preg_match( '/[^\x00-\x7F]/u', $v ) )
        && ! preg_match( '/^[.#\/@]|^[a-z][\w+.-]*:\/\/|\$\{|[<>{};=]/i', $v )
        && ! preg_match( '/(?:===?|!==?|\bcase)\s*$/', $before )
        && ! preg_match( '/(?:getElementById|getElementsBy\w+|querySelector(?:All)?|closest|matches|classList\.\w+|(?:add|remove)EventListener|(?:set|get|has|remove)Attribute|dataset\.\w+|(?:local|session)Storage\.\w+|fetch|import|require)\s*\(\s*(?:[^()]*,\s*)?$/', $before )
        && ! preg_match( '/^\s*(?:===?|!==?)/', $after )
        && ! ( preg_match( '/^\s*:(?!:)/', $after ) && preg_match( '/[{,]\s*$/', $before ) );
    return (bool) apply_filters( 'autolingo_script_string_is_text', $is_text, $value, $before, $after );
}

/**
 * The texts a visitor reads inside a <style> or <script> of the content, or that code with them
 * translated.
 *
 * - <style>: the words of content: "…" values (a label shown with ::before). Icons ("\f105"),
 *   quotes and symbols are left alone.
 * - <script data-autolingo="translate">: the strings that are texts (autolingo_js_string_is_text()),
 *   and the source locale written with its region ('fr-FR' in toLocaleString) becomes the target
 *   one. Scripts without the attribute are never read: only their author knows which strings the
 *   code depends on. Filter autolingo_translate_script_strings (false: never).
 *
 * @param string     $code         The whole <style>…</style> or <script>…</script>.
 * @param array|null $translations Null: return the texts. Else n => translation, to write back.
 * @return string[]|string
 */
function autolingo_code_texts( string $code, ?array $translations = null, string $target_lang = '' ): array|string {
    $texts = [];
    $n     = 0;
    $out   = $code;

    if ( preg_match( '/^<style\b/i', $code ) ) {
        $out = (string) preg_replace_callback( AUTOLINGO_CSS_CONTENT, static function ( array $m ) use ( &$texts, &$n, $translations ): string {
            if ( str_contains( $m[3], '\\' ) || ! preg_match( '/\p{L}{2}/u', $m[3] ) ) return $m[0];
            $i = $n++;
            if ( $translations === null ) { $texts[] = $m[3]; return $m[0]; }
            $t = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) ( $translations[ $i ] ?? $m[3] ) ) ) );
            return $t === '' ? $m[0] : $m[1] . $m[2] . str_replace( [ '\\', $m[2] ], [ '\\\\', '\\' . $m[2] ], $t ) . $m[2];
        }, $code );
    } elseif ( preg_match( '/^<script\b[^>]*\bdata-autolingo\s*=\s*["\']?translate\b/i', $code ) && apply_filters( 'autolingo_translate_script_strings', true ) ) {
        $source = preg_quote( strtolower( (string) autolingo_source_lang() ), '/' );
        $parts  = explode( '-', str_replace( '_', '-', $target_lang ) );
        $bcp47  = $target_lang !== '' ? strtolower( $parts[0] ) . ( isset( $parts[1] ) ? '-' . strtoupper( $parts[1] ) : '' ) : '';
        $out    = (string) preg_replace_callback( AUTOLINGO_JS_TOKENS, static function ( array $m ) use ( &$texts, &$n, $translations, $code, $source, $bcp47 ): string {
            [ $token, $offset ] = $m[0];
            $q = $token[0];
            if ( $q !== "'" && $q !== '"' ) return $token; // a comment or a template literal
            $inner = substr( $token, 1, -1 );
            if ( preg_match( '/\\\\(?![\'"\\\\n])/', $inner ) ) return $token; // other escapes: left alone
            $value = (string) preg_replace_callback( '/\\\\(.)/s', static fn( array $e ): string => $e[1] === 'n' ? "\n" : $e[1], $inner );

            // The locale the script formats with ('fr-FR') follows the page.
            if ( $source !== '' && preg_match( '/^' . $source . '[-_][a-z]{2}$/i', $value ) ) {
                return $translations !== null && $bcp47 !== '' ? $q . $bcp47 . $q : $token;
            }
            $before = substr( $code, max( 0, $offset - 80 ), min( 80, $offset ) );
            $after  = substr( $code, $offset + strlen( $token ), 12 );
            if ( ! autolingo_js_string_is_text( $value, $before, $after ) ) return $token;

            $i = $n++;
            if ( $translations === null ) { $texts[] = $value; return $token; }
            $t = trim( wp_strip_all_tags( (string) ( $translations[ $i ] ?? $value ) ) );
            if ( $t === '' ) return $token;
            return $q . str_replace( [ '\\', $q, "\r", "\n" ], [ '\\\\', '\\' . $q, '', '\\n' ], $t ) . $q;
        }, $code, -1, $count, PREG_OFFSET_CAPTURE );
    }

    return $translations === null ? $texts : $out;
}

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
function autolingo_block_text_attributes( bool $reset = false ): array {
    static $map = null;
    if ( $map !== null && ! $reset ) return $map;
    $map = array_merge_recursive( autolingo_block_text_attributes_defaults(), autolingo_wpml_config_block_keys() );
    foreach ( $map as $block => $paths ) $map[ $block ] = array_values( array_unique( $paths ) );
    // Divi 5's modules (divi/*) are translated by AutoLingo Pro (divi.php), like Divi 4's shortcodes,
    // even when the theme's own wpml-config.xml lists them.
    $map = array_filter( $map, static fn( $block ): bool => $block !== 'divi' && ! str_starts_with( (string) $block, 'divi/' ), ARRAY_FILTER_USE_KEY );
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
        // A typed key (link, media-url, media-ids, post-ids…) or a video file is not text.
        if ( (string) $key['type'] !== '' || preg_match( '/(link|url|href|anchor|class|id|icon|image|src|mp4|webm)$/i', $name ) ) return;
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
        if ( ! $block ) continue;
        // A block's own entry, and its namespace's ("divi" for every divi/* block, as in WPML's files).
        $paths = array_values( array_unique( array_merge( $map[ $block['name'] ] ?? [], $map[ strtok( $block['name'], '/' ) ] ?? [] ) ) );
        $done  = [];
        foreach ( $paths as $path ) {
            $walk = static function ( $node, array $rest, array $at ) use ( &$walk, &$out, &$done, $key ): void {
                if ( ! $rest ) {
                    $id = implode( "\0", $at );
                    if ( is_string( $node ) && ! isset( $done[ $id ] ) && autolingo_block_value_is_text( $node ) ) {
                        $done[ $id ] = true;
                        $out[]       = [ 'key' => $key, 'path' => $at, 'text' => $node ];
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
 * Whether a block setting listed as text holds words a visitor reads: not empty, not a lone
 * link, e-mail address or number (some configurations list a setting whose value is an audio
 * file's address or a chart's figures).
 */
function autolingo_block_value_is_text( string $value ): bool {
    $plain = trim( html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
    if ( $plain === '' || ! preg_match( '/\p{L}/u', $plain ) ) return false;
    return ! preg_match( '/^(?:(?:https?:)?\/\/\S+|\/[^\s]*|#\S*|mailto:\S+|tel:\S+|[^\s@]+@[^\s@]+\.[a-z]{2,})$/i', $plain );
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
const AUTOLINGO_PLACEHOLDER_PATTERN = '/<!--\s*(ALNO|ALJL|ALSC|ALOP|PDT)(\d+)\s*-*>?/';

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
    $parts = preg_split( '/(<!--(?:ALNO|ALJL|ALSC|ALOP|PDT)\d+-->)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE );
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

/**
 * Shortcodes whose content is not text, kept whole with their content: code stored as base64
 * (WPBakery's raw HTML and raw JS, decoded with rawurldecode( base64_decode() ) by WPBakery;
 * Avada's code block) and Avada elements whose content is an image URL or a list of images
 * (media-url / ignore-content in WPML's configuration for Avada).
 * Filter: autolingo_untranslated_shortcodes.
 *
 * @return string[]
 */
function autolingo_untranslated_shortcodes(): array {
    return (array) apply_filters( 'autolingo_untranslated_shortcodes', [ 'vc_raw_html', 'vc_raw_js', 'fusion_code', 'fusion_imageframe', 'fusion_slide', 'fusion_gallery', 'fusion_gallery_image' ] );
}

function autolingo_protect_shortcodes( string $text, array &$placeholders ): string {
    if ( ! str_contains( $text, '[' ) ) return $text;
    $counter = 0;
    $tags    = array_map( static fn( $t ): string => preg_quote( (string) $t, '/' ), autolingo_untranslated_shortcodes() );
    if ( $tags ) {
        $text = (string) preg_replace_callback(
            '/\[(' . implode( '|', $tags ) . ')(?=[\s\]\/])[^\]]*\](?:(?!\[\1[\s\]\/]).)*?\[\/\1\]/s',
            static function ( array $m ) use ( &$placeholders, &$counter ): string {
                $marker                = '<span data-pdt="' . $counter++ . '"></span>';
                $placeholders[$marker] = $m[0];
                return $marker;
            },
            $text
        );
    }
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

/**
 * Shortcodes whose text attributes are translated: Divi modules (et_pb_*) and DiviFlash (difl_*), added by
 * divi.php; WPBakery (vc_*) and Avada (fusion_*), added by shortcode-builders.php.
 * Other shortcodes keep every attribute as it is. Filter: autolingo_shortcode_prefixes.
 *
 * @return string[]
 */
function autolingo_shortcode_prefixes(): array {
    return (array) apply_filters( 'autolingo_shortcode_prefixes', [] ); // Divi / DiviFlash: added by divi.php (Premium)
}

/**
 * Whether a shortcode attribute holds text a visitor reads (a button label, a tab or blurb
 * title, a heading): by its name, then by its value. Settings that share a name's start
 * (title_font, button_text_color, title_level) and values that are not words (a colour, a
 * size, on/off, a link, an ID) are left alone. Filter: autolingo_shortcode_text_attribute.
 */
function autolingo_shortcode_attribute_is_text( string $tag, string $name, string $value ): bool {
    // The tablet, phone and hover versions of a setting (button_text_tablet, content_phone) are
    // judged by the setting itself; content_* are a Text module's other versions of its content.
    $base    = (string) preg_replace( '/(?:_tablet|_phone|__hover)$/', '', $name );
    $by_name = ( (bool) preg_match( '/^(?:[a-z0-9]+_)*(?:title|text|heading|subhead|subtitle|sub_title|label|caption|placeholder|description|prefix|infix|suffix|message|alt|per|tagline|excerpt|question|answer)$/', $base )
            || ( $base === 'content' && $base !== $name ) )
        && ! preg_match( '/^(?:admin_label|module_id|module_class|link_option_url|url|.*_url|.*_id|.*_class)$/', $base );
    $is_text = $by_name && autolingo_shortcode_value_is_words( $value );
    return (bool) apply_filters( 'autolingo_shortcode_text_attribute', $is_text, $tag, $name, $value );
}

/** A value made of words, not a setting: not on/off, a colour, a size, a link, an ID… */
function autolingo_shortcode_value_is_words( string $value ): bool {
    return preg_match( '/\p{L}{2}/u', $value )
        && ! preg_match( '/^(?:on|off|yes|no|true|false|default|none|inherit|left|right|center|justify|top|bottom|middle|[a-z_]+\|[a-z_|]*|#?[0-9a-f]{3,8}|-?[\d.]+(?:px|em|rem|%|vh|vw|s|ms)?|rgba?\(.*\)|https?:\/\/\S+|mailto:\S+|tel:\S+|@\S+)$/i', trim( $value ) );
}

/**
 * How a builder writes ", \\, [ and ] inside an attribute value: Divi's %22, %92, %91, %93 by
 * default; another builder gives its own pair of functions for its tags with the
 * autolingo_shortcode_attribute_codec filter ([ decode, encode ], or null for Divi's).
 */
function autolingo_shortcode_attribute_codec( string $tag ): array {
    $codec = apply_filters( 'autolingo_shortcode_attribute_codec', null, $tag );
    return is_array( $codec ) && count( $codec ) === 2 ? array_values( $codec ) : [
        static fn( string $v ): string => str_replace( [ '%22', '%92', '%91', '%93' ], [ '"', '\\', '[', ']' ], $v ),
        static fn( string $v ): string => str_replace( [ '"', '\\', '[', ']' ], [ '%22', '%92', '%91', '%93' ], $v ),
    ];
}
function autolingo_shortcode_attribute_decode( string $value, string $tag = '' ): string {
    return ( autolingo_shortcode_attribute_codec( $tag )[0] )( $value );
}
function autolingo_shortcode_attribute_encode( string $value, string $tag = '' ): string {
    return ( autolingo_shortcode_attribute_codec( $tag )[1] )( $value );
}

/** A Text module's tablet / phone / hover content: HTML, kept as HTML once translated. */
function autolingo_shortcode_attribute_is_html( string $name ): bool {
    return (bool) preg_match( '/^content(?:_tablet|_phone|__hover)$/', $name );
}

/**
 * The text attributes of the protected shortcodes ($placeholders: marker => shortcode tag, from
 * autolingo_protect_shortcodes()), to translate with the text they belong to.
 *
 * @return array<int, array{marker: string, attr: string, text: string, tag: string}>
 */
function autolingo_extract_shortcode_attributes( array $placeholders ): array {
    $prefixes = autolingo_shortcode_prefixes();
    $entries  = [];
    foreach ( $placeholders as $marker => $shortcode ) {
        if ( ! preg_match( '/^\[([a-z][\w-]*)\s/i', $shortcode, $m ) ) continue; // a closing tag or no attributes
        $tag = strtolower( $m[1] );
        $ok  = false;
        foreach ( $prefixes as $prefix ) $ok = $ok || str_starts_with( $tag, $prefix );
        if ( ! $ok ) continue;
        // The opening tag only: a shortcode kept with its content (autolingo_untranslated_shortcodes()) may hold HTML.
        preg_match( '/^\[[^\]]*\]/', $shortcode, $open );
        preg_match_all( '/\s([a-z][\w-]*)="([^"]*)"/i', $open[0] ?? $shortcode, $attrs, PREG_SET_ORDER );
        foreach ( $attrs as [ , $name, $raw ] ) {
            $value = autolingo_shortcode_attribute_decode( $raw, $tag );
            if ( autolingo_shortcode_attribute_is_text( $tag, strtolower( $name ), $value ) ) {
                $entries[] = [ 'marker' => (string) $marker, 'attr' => $name, 'text' => $value, 'tag' => $tag ];
            }
        }
    }
    return $entries;
}

/**
 * Writes the translated attribute values back into the protected shortcodes.
 *
 * @param array    $placeholders marker => shortcode tag.
 * @param array    $entries      From autolingo_extract_shortcode_attributes().
 * @param string[] $translated   One value per entry, same order.
 */
function autolingo_restore_shortcode_attributes( array $placeholders, array $entries, array $translated ): array {
    foreach ( $entries as $n => $entry ) {
        $value = (string) ( $translated[ $n ] ?? $entry['text'] );
        $value = trim( autolingo_shortcode_attribute_is_html( strtolower( $entry['attr'] ) ) ? $value : wp_strip_all_tags( $value ) );
        if ( $value === '' ) continue;
        $shortcode = (string) $placeholders[ $entry['marker'] ];
        preg_match( '/^\[[^\]]*\]/', $shortcode, $open );
        $tag_open  = $open[0] ?? $shortcode;
        $tag_open  = (string) preg_replace_callback(
            '/(\s' . preg_quote( $entry['attr'], '/' ) . '=")([^"]*)(")/',
            static fn( array $m ): string => $m[1] . autolingo_shortcode_attribute_encode( $value, (string) ( $entry['tag'] ?? '' ) ) . $m[3],
            $tag_open,
            1
        );
        $placeholders[ $entry['marker'] ] = $tag_open . substr( $shortcode, strlen( $open[0] ?? $shortcode ) );
    }
    return $placeholders;
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
