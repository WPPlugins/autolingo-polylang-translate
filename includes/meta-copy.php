<?php
/**
 * Meta Copy — copie les post metas du post source vers le post traduit.
 *
 * Problème : lors d'une traduction programmatique, WordPress/Polylang ne copie pas
 * automatiquement les post metas. Le post traduit hérite donc d'aucun réglage
 * de mise en page, ce qui peut provoquer un rendu différent (header/footer manquant,
 * sidebar différente, template de page incorrect, etc.).
 *
 * Ce module copie TOUTES les metas du post source vers le post traduit, en excluant :
 *   - Les metas internes WordPress (edit_lock, etc.)
 *   - Les metas Polylang (gérées par Polylang lui-même)
 *   - Les metas gérées par nos modules dédiés (Elementor, ACF)
 *   - Beaver Builder (objets PHP sérialisés avec le texte source — à gérer manuellement)
 *
 * Les modules de traduction (Elementor, ACF) s'exécutent APRÈS et écrasent
 * les metas copiées avec le contenu traduit.
 *
 * Thèmes couverts automatiquement :
 *   Astra, GeneratePress, OceanWP, Neve, Blocksy, Kadence, Hello Elementor,
 *   Divi (réglages de thème), Flatsome, Avada, The7, Salient, ...
 *
 * SEO couverts automatiquement :
 *   Yoast SEO, RankMath, SEOPress, The SEO Framework
 *   (les titres/descriptions SEO seront en langue source — à affiner manuellement
 *   ou via filtre autolingo_translate_seo_meta)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Copie toutes les post metas du post source vers le post traduit.
 * Appelé lors de la CRÉATION d'une traduction (pas lors des mises à jour).
 *
 * @param  int $source_id  ID du post source.
 * @param  int $target_id  ID du post traduit (nouvellement créé).
 */
function autolingo_copy_post_meta( int $source_id, int $target_id ): void {
    $all_meta = get_post_meta( $source_id );
    if ( empty( $all_meta ) ) return;

    $excluded_keys    = autolingo_copy_meta_excluded_keys();
    $excluded_prefixes = autolingo_copy_meta_excluded_prefixes();

    foreach ( $all_meta as $key => $values ) {
        // Clés à ignorer (liste exacte)
        if ( in_array( $key, $excluded_keys, true ) ) continue;

        // Clés à ignorer (préfixe)
        foreach ( $excluded_prefixes as $prefix ) {
            if ( str_starts_with( $key, $prefix ) ) continue 2;
        }

        // Effacer les éventuelles valeurs existantes sur le post cible
        delete_post_meta( $target_id, $key );

        // Copier chaque valeur (get_post_meta renvoie déjà les valeurs désérialisées)
        foreach ( $values as $value ) {
            add_post_meta( $target_id, $key, $value );
        }
    }
}

/**
 * Clés de post meta à exclure de la copie.
 *
 * @return string[]
 */
function autolingo_copy_meta_excluded_keys(): array {
    return apply_filters( 'autolingo_copy_meta_excluded_keys', [

        // ── WordPress core ─────────────────────────────────
        '_edit_lock',
        '_edit_last',
        '_wp_trash_meta_status',
        '_wp_trash_meta_time',
        '_wp_desired_post_slug',

        // ── Elementor (module dédié autolingo_elementor_translate) ──
        '_elementor_data',
        '_elementor_css',             // CSS généré : sera régénéré par Elementor

        // ── Beaver Builder (objets PHP sérialisés non traduisibles) ──
        '_fl_builder_data',
        '_fl_builder_draft',
        '_fl_builder_enabled',

        // ── Bricks Builder ─────────────────────────────────
        '_bricks_page_content_2',

        // ── WooCommerce (prix, stock — ne pas dupliquer) ───
        '_price',
        '_regular_price',
        '_sale_price',
        '_stock',
        '_stock_status',
        '_sku',
        '_manage_stock',
        '_wc_rating_count',
        '_wc_review_count',
        '_wc_average_rating',
    ] );
}

/**
 * Préfixes de clés meta à exclure de la copie.
 *
 * @return string[]
 */
function autolingo_copy_meta_excluded_prefixes(): array {
    return apply_filters( 'autolingo_copy_meta_excluded_prefixes', [
        '_pll_',          // Polylang internal
        '_oembed_',       // oEmbed cache
    ] );
}
