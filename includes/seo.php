<?php
/**
 * SEO — traduction automatique des metas SEO vers la langue cible.
 *
 * Plugins supportés :
 *   Yoast SEO, RankMath, SEOPress, The SEO Framework
 *
 * Appelé lors de la CRÉATION et de la MISE À JOUR d'une traduction,
 * contrairement à autolingo_copy_post_meta() qui ne s'exécute qu'à la création.
 *
 * Les variables de template SEO (%%title%%, %sitename%, etc.) sont protégées
 * automatiquement dans autolingo_call_deepl() et ne sont pas traduites.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Traduit les metas SEO du post source vers le post cible.
 *
 * @param  int    $source_id   ID post source.
 * @param  int    $target_id   ID post traduit.
 * @param  string $target_lang Code langue majuscules (ex: 'EN').
 */
function autolingo_seo_translate( int $source_id, int $target_id, string $target_lang ): void {
    foreach ( autolingo_seo_translatable_fields() as $meta_key ) {
        $value = get_post_meta( $source_id, $meta_key, true );
        if ( empty( $value ) || ! is_string( $value ) ) continue;

        $translated = autolingo_call_deepl( $value, $target_lang );
        if ( $translated !== false ) {
            update_post_meta( $target_id, $meta_key, $translated );
        }
    }
}

/**
 * Champs SEO à traduire par plugin.
 * Extensible via le filtre autolingo_seo_translatable_fields.
 *
 * @return string[]
 */
function autolingo_seo_translatable_fields(): array {
    return apply_filters( 'autolingo_seo_translatable_fields', [
        // Yoast SEO
        '_yoast_wpseo_title',
        '_yoast_wpseo_metadesc',
        '_yoast_wpseo_focuskw',
        // RankMath
        '_rankmath_title',
        '_rankmath_description',
        '_rankmath_focus_keyword',
        // SEOPress
        '_seopress_titles_title',
        '_seopress_titles_desc',
        // The SEO Framework
        '_genesis_title',
        '_genesis_description',
    ] );
}
