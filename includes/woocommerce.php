<?php
/**
 * WooCommerce — traduction des champs produit non couverts par le flux principal.
 *
 * Ce qui est déjà traduit par le flux principal :
 *   - Titre produit          (post_title)
 *   - Description longue     (post_content)
 *   - Description courte     (post_excerpt)
 *
 * Ce module complète :
 *   - Attributs personnalisés (non-taxonomie) : libellé + valeurs pipe-séparées
 *   - Note d'achat (_purchase_note) : texte affiché au client après commande
 *
 * Attributs basés sur une taxonomie globale (pa_color, pa_size…) :
 *   Leurs noms et valeurs sont des termes WooCommerce → traduits via
 *   l'interface Polylang, pas par DeepL.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Vérifie si un post est un produit WooCommerce.
 */
function autolingo_is_woocommerce_product( int $post_id ): bool {
    return 'product' === get_post_type( $post_id );
}

/**
 * Traduit les champs WooCommerce du post source vers le post cible.
 *
 * @param  int    $source_id   ID post source.
 * @param  int    $target_id   ID post traduit.
 * @param  string $target_lang Code langue majuscules (ex: 'EN').
 * @return bool
 */
function autolingo_woocommerce_translate( int $source_id, int $target_id, string $target_lang ): bool {

    // ── Attributs personnalisés ──────────────────────────────
    $attributes = get_post_meta( $source_id, '_product_attributes', true );
    if ( ! empty( $attributes ) && is_array( $attributes ) ) {
        $translated_attrs = autolingo_woocommerce_translate_attributes( $attributes, $target_lang );
        update_post_meta( $target_id, '_product_attributes', $translated_attrs );
    }

    // ── Note d'achat ─────────────────────────────────────────
    $note = get_post_meta( $source_id, '_purchase_note', true );
    if ( ! empty( $note ) && is_string( $note ) ) {
        $translated_note = autolingo_call_deepl( $note, $target_lang );
        if ( $translated_note !== false ) {
            update_post_meta( $target_id, '_purchase_note', $translated_note );
        }
    }

    autolingo_log_info(
        "WooCommerce : champs produit traduits vers {$target_lang}.",
        [ 'source_id' => $source_id, 'target_id' => $target_id ],
        $source_id,
        $target_lang
    );

    return true;
}

/**
 * Traduit les attributs personnalisés (non-taxonomie) d'un produit.
 *
 * Structure _product_attributes :
 *   [ 'slug' => [ 'name' => 'Matière', 'value' => 'Coton | Polyester',
 *                 'is_taxonomy' => 0, ... ] ]
 *
 * Les attributs taxonomie (is_taxonomy = 1) ont name = slug WC (pa_color…)
 * et value = '' → rien à traduire ici.
 *
 * @param  array  $attributes  Tableau _product_attributes du post source.
 * @param  string $target_lang Code langue majuscules.
 * @return array
 */
function autolingo_woocommerce_translate_attributes( array $attributes, string $target_lang ): array {

    foreach ( $attributes as &$attr ) {
        if ( ! is_array( $attr ) ) continue;
        if ( ! empty( $attr['is_taxonomy'] ) ) continue; // attribut global WC, pas de traduction ici

        // Libellé de l'attribut (ex: "Matière", "Taille")
        if ( ! empty( $attr['name'] ) && is_string( $attr['name'] ) ) {
            $t = autolingo_call_deepl( $attr['name'], $target_lang );
            if ( $t !== false ) {
                $attr['name'] = $t;
            }
        }

        // Valeurs pipe-séparées (ex: "Coton | Polyester | Laine")
        // Chaque valeur est traduite individuellement pour éviter que DeepL
        // ne modifie le séparateur ou réordonne les options.
        if ( ! empty( $attr['value'] ) && is_string( $attr['value'] ) ) {
            $values            = array_map( 'trim', explode( '|', $attr['value'] ) );
            $translated_values = [];

            foreach ( $values as $val ) {
                if ( '' === $val ) {
                    $translated_values[] = '';
                    continue;
                }
                $t                   = autolingo_call_deepl( $val, $target_lang );
                $translated_values[] = $t !== false ? $t : $val;
            }

            $attr['value'] = implode( ' | ', $translated_values );
        }
    }
    unset( $attr );

    return $attributes;
}
