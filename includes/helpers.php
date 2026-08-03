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
 * Retourne le slug du provider actif (ex: 'deepl', 'openai', 'google').
 */
function autolingo_provider_slug(): string {
    return autolingo_option( 'provider', 'deepl' );
}

/**
 * Retourne le statut de traduction d'un post pour chaque langue cible.
 * Ex: [ 'en' => true, 'de' => false ]
 */
function autolingo_translation_status( int $post_id ): array {
    $translations = pll_get_post_translations( $post_id );
    $status       = [];
    foreach ( autolingo_target_langs() as $lang ) {
        $status[ $lang ] = ! empty( $translations[ $lang ] );
    }
    return $status;
}

/**
 * Retourne tous les posts publiés dans la langue source.
 */
function autolingo_get_all_source_posts(): array {
    return get_posts( [
        'post_type'      => autolingo_post_types(),
        'post_status'    => 'publish',
        'lang'           => autolingo_source_lang(),
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ] );
}

/**
 * Returns translation coverage per target language (cached 1 hour).
 *
 * @return array { total: int, langs: [ 'en' => [ 'done' => int, 'pct' => float ], ... ] }
 */
function autolingo_get_translation_progress(): array {
    $cached = get_transient( 'autolingo_translation_progress' );
    if ( $cached !== false ) return $cached;

    $source_posts = autolingo_get_all_source_posts();
    $total        = count( $source_posts );
    $langs        = autolingo_target_langs();
    $done         = array_fill_keys( $langs, 0 );

    foreach ( $source_posts as $post_id ) {
        $translations = pll_get_post_translations( $post_id );
        foreach ( $langs as $lang ) {
            if ( ! empty( $translations[ $lang ] ) ) {
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
    if ( ! function_exists( 'pll_home_url' ) || ! function_exists( 'pll_get_post' ) ) return $content;
    if ( empty( $content ) ) return $content;

    $source_home  = untrailingslashit( pll_home_url( autolingo_source_lang() ) );
    $target_home  = untrailingslashit( pll_home_url( $target_lang_slug ) );

    if ( empty( $source_home ) ) return $content;

    $escaped = preg_quote( $source_home, '/' );

    return preg_replace_callback(
        '/(?<=href=["\']|src=["\'])(' . $escaped . '[^"\']*)/i',
        function ( array $m ) use ( $source_home, $target_home, $target_lang_slug ): string {
            $url     = $m[1];
            $post_id = url_to_postid( $url );

            if ( $post_id ) {
                $translated_id = pll_get_post( $post_id, $target_lang_slug );
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
