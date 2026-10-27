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
    return (array) autolingo_option( 'post_types', [ 'post', 'page' ] );
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
function autolingo_provider_slug(): string {
    return autolingo_option( 'provider', 'deepl' );
}

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
 * Replaces internal links in translated content with their target-language equivalents.
 *
 * Strategy (per href/src attribute):
 *   1. Resolve URL → post ID via url_to_postid().
 *   2. Find translated post via pll_get_post().
 *   3. Swap URL with get_permalink() of the translated post.
 *   4. Fallback: replace home URL prefix only (handles non-post URLs like archives).
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

    return preg_replace_callback(
        '/(?<=href=["\']|src=["\'])(' . $escaped . '[^"\']*)/i',
        function ( array $m ) use ( $ml, $source_home, $target_home, $target_lang_slug ): string {
            $url     = $m[1];
            $post_id = url_to_postid( $url );

            if ( $post_id ) {
                $translated_id = $ml->get_translated_post( $post_id, $target_lang_slug );
                if ( $translated_id ) {
                    return get_permalink( $translated_id ) ?: $url;
                }
            }

            // Fallback: swap home URL prefix (archives, term pages, etc.)
            return $source_home !== $target_home
                ? str_replace( $source_home, $target_home, $url )
                : $url;
        },
        $content
    ) ?? $content;
}
