<?php
/**
 * Helpers — fonctions utilitaires partagées dans tout le plugin.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Retourne la valeur d'une option du plugin.
 */
function autolingo_option( string $key, $default = null ) {
    return get_option( 'autolingo_' . $key, $default );
}

/**
 * Retourne la clé API DeepL.
 */
function autolingo_api_key(): string {
    return trim( autolingo_option( 'api_key', '' ) );
}

/**
 * Retourne la langue source (ex: 'fr').
 */
function autolingo_source_lang(): string {
    return strtolower( autolingo_option( 'source_lang', 'fr' ) );
}

/**
 * Retourne les langues cibles (ex: ['en', 'de']).
 */
function autolingo_target_langs(): array {
    $langs = autolingo_option( 'target_langs', [ 'en' ] );
    return array_map( 'strtolower', (array) $langs );
}

/**
 * New install: take the source and target languages from Polylang / WPML once they
 * have at least two languages (default language → source, the others → targets).
 * The 'fr' → 'en' defaults would otherwise translate nothing on most sites.
 * Existing installs are never changed: the flag is only set on first activation.
 */
function autolingo_setup_languages_from_site(): bool {
    if ( ! get_option( 'autolingo_language_setup_pending' ) ) return false;
    $ml = autolingo_multilingual();
    if ( ! $ml ) return false;

    $default = $ml->get_default_language();
    $targets = array_values( array_diff( $ml->get_languages(), [ $default ] ) );
    if ( $default === '' || ! $targets ) return false;

    update_option( 'autolingo_source_lang', $default );
    update_option( 'autolingo_target_langs', $targets );
    delete_option( 'autolingo_language_setup_pending' );
    return true;
}
/**
 * Parse le textarea de glossaire (format "source | cible" par ligne).
 * Retourne un tableau de [ 'source' => '\', 'target' => '\'] ].
 */
function autolingo_parse_glossary_terms( string $raw ): array {
    $entries = [];
    foreach ( explode( "\n", $raw ) as $line ) {
        $line = trim( $line );
        if ( $line === '' || str_starts_with( $line, '#' ) ) continue;
        $parts = array_map( 'trim', explode( '|', $line, 2 ) );
        if ( count( $parts ) === 2 && $parts[0] !== '' && $parts[1] !== '' ) {
            $entries[] = [ 'source' => $parts[0], 'target' => $parts[1] ];
        }
    }
    return $entries;
}


/**
 * Retourne la valeur de formalité DeepL pour une langue cible, ou null
 * si la langue ne supporte pas la formalité ou si le réglage est 'default'.
 * Langues supportées : DE, FR, IT, ES, NL, PL, PT, RU, JA.
 */
function autolingo_deepl_formality( string $target_lang ): ?string {
    static $supported = [ 'DE', 'FR', 'IT', 'ES', 'NL', 'PL', 'PT', 'RU', 'JA' ];
    $formality = autolingo_option( 'formality', 'default' );
    if ( ! $formality || $formality === 'default' ) return null;
    $base = strtoupper( explode( '-', $target_lang )[0] );
    return in_array( $base, $supported, true ) ? $formality : null;
}

/**
 * Retourne les types de post à traiter.
 */
function autolingo_post_types(): array {
    /** Integrations add their own content types (Contact Form 7 forms, Divi Theme Builder layouts). */
    return array_values( array_unique( (array) apply_filters( 'autolingo_post_types', (array) autolingo_option( 'post_types', [ 'post', 'page' ] ) ) ) );
}

/** The language of the page being shown (Polylang, WPML), '' when unknown. */
function autolingo_current_language(): string {
    if ( function_exists( 'pll_current_language' ) ) return (string) pll_current_language( 'slug' );
    $wpml = apply_filters( 'wpml_current_language', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter.
    return is_string( $wpml ) ? $wpml : '';
}

/**
 * What the texts being translated are, sent with each provider call (DeepL "context",
 * OpenAI / DeepSeek system prompt): short texts are ambiguous on their own. Never
 * translated. Google and Microsoft have no such parameter.
 *
 * @param  string|null $set New context, or null to read it.
 * @return string           The context before this call (to restore it afterwards).
 */
function autolingo_translation_context( ?string $set = null ): string {
    static $context = '';
    $previous = $context;
    if ( $set !== null ) $context = $set;
    return $previous;
}

/** Context for a post, from its type. */
function autolingo_post_context( WP_Post $post ): string {
    switch ( $post->post_type ) {
        case 'post':
            return 'Blog post of a website: title, content, excerpt, image captions and SEO fields.';
        case 'page':
            return 'Page of a website: title, content, excerpt, image captions and SEO fields.';
        case 'product':
            return 'Product page of an online store: product name, descriptions, attributes, image captions and SEO fields.';
    }
    $type  = get_post_type_object( $post->post_type );
    $label = $type ? $type->labels->singular_name : $post->post_type;
    return 'Content of a website (' . $label . '): title, content, excerpt, image captions and SEO fields.';
}

/**
 * Retourne le seuil d'alerte crédit (en caractères).
 */
function autolingo_alert_threshold(): int {
    return (int) autolingo_option( 'alert_threshold', 100000 );
}

/**
 * La traduction automatique est-elle activée ?
 */
function autolingo_auto_translate_enabled(): bool {
    return (bool) autolingo_option( 'auto_translate', '1' );
}

/**
 * Gives a translation time to finish. A long page sends several requests to the provider, each of
 * which may take tens of seconds; on hosts with PHP's default 30-second limit the request died
 * mid-translation ("Maximum execution time of 30 seconds exceeded" in Requests' Curl transport).
 * Raises the limit to $seconds (never lowers it: 0 = unlimited stays unlimited) where the host
 * allows set_time_limit(), and keeps the work going if the browser gives up waiting.
 */
function autolingo_extend_time_limit( int $seconds = 300 ): void {
    $current = (int) ini_get( 'max_execution_time' );
    if ( $current === 0 || $current >= $seconds ) return;
    $disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
    if ( function_exists( 'set_time_limit' ) && ! in_array( 'set_time_limit', $disabled, true ) ) {
        set_time_limit( $seconds ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- a long translation needs more than PHP's default 30 s.
    }
    if ( function_exists( 'ignore_user_abort' ) && ! in_array( 'ignore_user_abort', $disabled, true ) ) {
        ignore_user_abort( true );
    }
}

/**
 * La traduction asynchrone (WP-Cron) est-elle activée ?
 */
function autolingo_async_enabled(): bool {
    return (bool) autolingo_option( 'async_enabled', true );
}

/**
 * Seuil (en caractères de post_content) à partir duquel la traduction
 * est déclenchée en arrière-plan via WP-Cron.
 * 0 = toujours synchrone.
 */
function autolingo_async_threshold(): int {
    return (int) autolingo_option( 'async_threshold', 5000 );
}

/**
 * Un module de traduction est-il activé ?
 * Modules connus : 'seo', 'acf', 'woocommerce', 'page_builders'.
 */
function autolingo_module_enabled( string $module ): bool {
    return (bool) autolingo_option( 'module_' . $module, '1' );
}

/**
 * Retourne le slug du provider actif (ex: 'deepl', 'openai', 'google', 'deepseek').
 */
/**
 * Retourne le statut de traduction d'un post pour chaque langue cible.
 * Ex: [ 'en' => true, 'de' => false ]
 */
function autolingo_translation_status( int $post_id ): array {
    $ml     = autolingo_multilingual();
    $group  = $ml ? $ml->get_post_translations( $post_id ) : [];
    $status = [];
    foreach ( autolingo_target_langs() as $lang ) {
        $status[ $lang ] = ! empty( $group[ $lang ] );
    }
    return $status;
}

/**
 * Status of a translation when AutoLingo creates it: 'publish' (default), or
 * 'draft' / 'pending' to review it before it goes live. An existing translation
 * keeps its status when it is updated.
 */
function autolingo_new_translation_status( string $post_type = '' ): string {
    /**
     * Content types whose translations are always published: no screen shows them as drafts to
     * publish (Divi Theme Builder layouts, Contact Form 7 forms), so a draft would never appear.
     */
    if ( $post_type !== '' && in_array( $post_type, (array) apply_filters( 'autolingo_always_published_types', [] ), true ) ) return 'publish';
    $status = (string) autolingo_option( 'new_status', 'publish' );
    return in_array( $status, [ 'publish', 'draft', 'pending' ], true ) ? $status : 'publish';
}

/**
 * Once (1.13.10): the translations of the always-published types that AutoLingo created as drafts or pending
 * before (the "new translations" setting) are published, or they would never show.
 */
add_action( 'admin_init', static function (): void {
    if ( get_option( 'autolingo_published_fixed' ) === '1.13.10' ) return;
    $types = (array) apply_filters( 'autolingo_always_published_types', [] );
    if ( $types ) {
        $ids = get_posts( [
            'post_type'        => $types,
            'post_status'      => [ 'draft', 'pending' ],
            'numberposts'      => 500,
            'fields'           => 'ids',
            'meta_key'         => '_autolingo_awaiting_review', // phpcs:ignore WordPress.DB.SlowDBQuery -- once.
            'lang'             => '',
            'suppress_filters' => false,
        ] );
        foreach ( $ids as $id ) {
            wp_publish_post( (int) $id );
            delete_post_meta( (int) $id, '_autolingo_awaiting_review' );
        }
    }
    update_option( 'autolingo_published_fixed', '1.13.10', false );
} );

/**
 * Retourne tous les posts publiés dans la langue source.
 */
function autolingo_get_all_source_posts(): array {
    $ml = autolingo_multilingual();
    if ( ! $ml ) return [];
    return $ml->get_source_post_ids( autolingo_post_types(), autolingo_source_lang() );
}

/**
 * Returns translation coverage per target language (cached 1 hour).
 *
 * @return array { total: int, langs: [ 'en' => [ 'done' => int, 'pct' => float ], ... ] }
 */
function autolingo_get_translation_progress(): array {
    $cached = get_transient( 'autolingo_translation_progress' );
    if ( $cached !== false ) return $cached;

    $ml           = autolingo_multilingual();
    $source_posts = autolingo_get_all_source_posts();
    $total        = count( $source_posts );
    $langs        = autolingo_target_langs();
    $done         = array_fill_keys( $langs, 0 );

    foreach ( $source_posts as $post_id ) {
        $group = $ml ? $ml->get_post_translations( $post_id ) : [];
        foreach ( $langs as $lang ) {
            if ( ! empty( $group[ $lang ] ) ) {
                $done[ $lang ]++;
            }
        }
    }

    $result = [ 'total' => $total, 'langs' => [] ];
    foreach ( $langs as $lang ) {
        $result['langs'][ $lang ] = [
            'done' => $done[ $lang ],
            'pct'  => $total > 0 ? round( ( $done[ $lang ] / $total ) * 100 ) : 0,
        ];
    }

    set_transient( 'autolingo_translation_progress', $result, HOUR_IN_SECONDS );
    return $result;
}

/**
 * Invalidates the translation progress cache.
 */
function autolingo_invalidate_progress_cache(): void {
    delete_transient( 'autolingo_translation_progress' );
}

/**
 * Whether a URL points at a file of the site rather than at a page: uploads,
 * themes, plugins, WordPress's own files. These are served from one address
 * whatever the language, so a language prefix would make them answer 404.
 *
 * @param  string $url Absolute URL.
 * @return bool
 */
function autolingo_is_file_url( string $url ): bool {
    $bare = static fn( string $u ): string => untrailingslashit( preg_replace( '#^https?:#i', '', $u ) );

    $uploads = wp_get_upload_dir();
    $bases   = array( content_url(), includes_url(), $uploads['baseurl'] ?? '' );

    foreach ( $bases as $base ) {
        if ( '' !== $base && str_starts_with( $bare( $url ) . '/', $bare( $base ) . '/' ) ) {
            return true;
        }
    }
    return false;
}

/**
 * Replaces internal links in translated content with their target-language equivalents.
 *
 * Strategy (per href/src attribute):
 *   1. Leave the site's files alone (images, PDFs, anything in wp-content).
 *   2. Resolve URL → post ID via url_to_postid().
 *   3. Find translated post via pll_get_post().
 *   4. Swap URL with get_permalink() of the translated post.
 *   5. Fallback: replace home URL prefix only (handles non-post URLs like archives).
 *
 * @param  string $content          Translated post content.
 * @param  string $target_lang_slug Target language slug (e.g. 'en').
 * @return string                   Content with updated internal links.
 */
function autolingo_replace_internal_urls( string $content, string $target_lang_slug ): string {
    $ml = autolingo_multilingual();
    if ( ! $ml || empty( $content ) ) return $content;

    $source_home = untrailingslashit( $ml->get_home_url( autolingo_source_lang() ) );
    $target_home = untrailingslashit( $ml->get_home_url( $target_lang_slug ) );

    if ( empty( $source_home ) ) return $content;

    $escaped = preg_quote( $source_home, '/' );

    $swap = function ( array $m ) use ( $ml, $source_home, $target_home, $target_lang_slug ): string {
            $url = $m[1];

            if ( autolingo_is_file_url( $url ) ) {
                return $url;
            }

            $post_id = url_to_postid( $url );

            if ( $post_id ) {
                $translated_id = $ml->get_translated_post( $post_id, $target_lang_slug );
                if ( $translated_id ) {
                    $permalink = get_permalink( $translated_id );
                    if ( ! $permalink ) return $url;
                    // The anchor of the address (#section, a JSON-LD @id's #article) stays.
                    $hash = strpos( $url, '#' );
                    return $hash !== false ? $permalink . substr( $url, $hash ) : $permalink;
                }
            }

            // Fallback: swap home URL prefix (archives, term pages, etc.)
            return $source_home !== $target_home
                ? str_replace( $source_home, $target_home, $url )
                : $url;
    };

    $content = preg_replace_callback( '/(?<=href=["\']|src=["\'])(' . $escaped . '[^"\']*)/i', $swap, $content ) ?? $content;

    // Structured data (JSON-LD): the page's url and @id, breadcrumb items.
    if ( stripos( $content, 'ld+json' ) !== false ) {
        $content = preg_replace_callback( AUTOLINGO_JSON_LD_SCRIPT, static function ( array $s ) use ( $escaped, $swap ): string {
            return (string) preg_replace_callback(
                '/("(?:url|@id|item|mainEntityOfPage)"\s*:\s*")(' . $escaped . '[^"]*)/i',
                static fn( array $m ): string => $m[1] . $swap( [ 1 => $m[2] ] ),
                $s[0]
            );
        }, $content ) ?? $content;
    }
    return $content;
}

/**
 * Once the translation exists: in its structured data, the original's address becomes its own
 * (when it was created, the translation had no address yet).
 */
function autolingo_json_ld_own_url( WP_Post $source, int $target_id ): void {
    $content = (string) get_post_field( 'post_content', $target_id );
    if ( stripos( $content, 'ld+json' ) === false ) return;
    $from = (string) get_permalink( $source->ID );
    $to   = (string) get_permalink( $target_id );
    if ( $from === '' || $to === '' || $from === $to ) return;
    // While the translation was written, its address was the original's under the language's home.
    $ml    = autolingo_multilingual();
    $lang  = $ml ? $ml->get_post_language( $target_id ) : '';
    $froms = [ $from ];
    if ( $ml && $lang !== '' ) {
        $source_home = untrailingslashit( $ml->get_home_url( autolingo_source_lang() ) );
        $target_home = untrailingslashit( $ml->get_home_url( $lang ) );
        if ( $source_home !== '' && $source_home !== $target_home ) $froms[] = str_replace( $source_home, $target_home, $from );
    }
    $updated = (string) preg_replace_callback( AUTOLINGO_JSON_LD_SCRIPT, static function ( array $s ) use ( $froms, $to ): string {
        foreach ( array_unique( $froms ) as $old ) {
            $s[0] = (string) preg_replace( '/"' . preg_quote( $old, '/' ) . '(#[^"]*)?"/', '"' . $to . '$1"', $s[0] );
        }
        return $s[0];
    }, $content );
    if ( $updated === $content ) return;
    remove_action( 'save_post', 'autolingo_on_save_post', 20 );
    autolingo_save_post( [ 'ID' => $target_id, 'post_content' => $updated ], true );
    add_action( 'save_post', 'autolingo_on_save_post', 20, 3 );
}

// ── Lingo (the reviewer in Pro, the assistant in every edition) ─────────────

/** Lingo's name: AutoLingo's speech bubble with reading glasses (the assistant, and the reviewer in Pro). */
function autolingo_reviewer_name(): string {
    return (string) apply_filters( 'autolingo_reviewer_name', 'Lingo' );
}

/** Lingo's face (assets/lingo-64.png, -128.png for high-density screens), as an <img> tag: safe to echo. */
function autolingo_lingo_img( int $size = 32 ): string {
    $url = static fn( int $px ): string => esc_url( AUTOLINGO_PLUGIN_URL . 'assets/lingo-' . $px . '.png' );
    return '<img src="' . ( $size > 64 ? $url( 128 ) : $url( 64 ) ) . '" srcset="' . $url( 64 ) . ' 64w, ' . $url( 128 ) . ' 128w" sizes="' . $size . 'px" width="' . $size . '" height="' . $size . '" alt="' . esc_attr( autolingo_reviewer_name() ) . '" style="flex:none;vertical-align:middle">';
}
