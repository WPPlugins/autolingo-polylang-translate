<?php
/**
 * Bricks Builder — traduction des éléments de page builder.
 *
 * Bricks stocke son contenu dans _bricks_page_content_2 au format JSON.
 * Structure : tableau plat d'éléments (pas imbriqués), chacun ayant :
 *   id, name, parent, children, settings
 *
 * Les items imbriqués (accordéon, onglets, liste) sont des tableaux
 * séquentiels dans settings et sont également traduits.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Vérifie si un post est édité avec Bricks Builder.
 */
function autolingo_is_bricks_post( int $post_id ): bool {
    return ! empty( get_post_meta( $post_id, '_bricks_page_content_2', true ) );
}

/**
 * Champs Bricks Builder contenant du texte traduisible (champs directs).
 * Couvre les éléments natifs les plus courants.
 * Extensible via le filtre autolingo_bricks_translatable_keys.
 *
 * @return string[]
 */
function autolingo_bricks_translatable_keys(): array {
    return apply_filters( 'autolingo_bricks_translatable_keys', [
        // Texte, Titre
        'text', 'heading', 'subheading',
        // Bouton, CTA
        'label', 'buttonText',
        // Contenus généraux
        'content', 'description', 'excerpt',
        // Médias
        'caption',
        // Formulaires
        'placeholder', 'formLabel',
        // Compteur, Séparateur
        'prefix', 'suffix',
        // Icon Box, Alerte
        'title', 'iconBoxHeading', 'alertTitle', 'alertMessage',
        // Divers
        'copyright',
    ] );
}

/**
 * Champs traduisibles dans les items imbriqués (accordéon, onglets, listes…).
 * Extensible via le filtre autolingo_bricks_translatable_item_keys.
 *
 * @return string[]
 */
function autolingo_bricks_translatable_item_keys(): array {
    return apply_filters( 'autolingo_bricks_translatable_item_keys', [
        'title', 'content', 'text', 'description', 'label',
    ] );
}

/**
 * Traduit les éléments Bricks Builder du post source vers le post cible.
 *
 * @param  int    $source_id   ID post source.
 * @param  int    $target_id   ID post traduit.
 * @param  string $target_lang Code langue majuscules (ex: 'EN').
 * @return bool
 */
function autolingo_bricks_translate( int $source_id, int $target_id, string $target_lang ): bool {
    $json = get_post_meta( $source_id, '_bricks_page_content_2', true );
    if ( empty( $json ) ) return true;

    $data = json_decode( $json, true );
    if ( ! is_array( $data ) ) return true;

    $data = autolingo_bricks_translate_elements( $data, $target_lang );

    // Copier les metas Bricks nécessaires au fonctionnement de l'éditeur
    foreach ( [ '_bricks_editor_mode', '_bricks_template_type', '_bricks_template_subtype' ] as $key ) {
        $val = get_post_meta( $source_id, $key, true );
        if ( $val !== '' && $val !== false ) {
            update_post_meta( $target_id, $key, $val );
        }
    }

    update_post_meta( $target_id, '_bricks_page_content_2', wp_slash( wp_json_encode( $data ) ) );

    autolingo_log_info(
        "Bricks Builder : éléments traduits vers {$target_lang}.",
        [ 'source_id' => $source_id, 'target_id' => $target_id ],
        $source_id,
        $target_lang
    );

    return true;
}

/**
 * Parcourt le tableau plat d'éléments Bricks et traduit les settings.
 *
 * Bricks utilise une structure plate : tous les éléments sont au même niveau,
 * reliés par parent/children. Il n'est donc pas nécessaire de récursion
 * sur la structure principale — on itère directement sur le tableau.
 *
 * @param  array  $elements    Tableau plat d'éléments Bricks.
 * @param  string $target_lang Code langue majuscules.
 * @return array
 */
function autolingo_bricks_translate_elements( array $elements, string $target_lang ): array {
    $keys      = autolingo_bricks_translatable_keys();
    $item_keys = autolingo_bricks_translatable_item_keys();

    foreach ( $elements as &$element ) {
        if ( ! is_array( $element ) ) continue;
        if ( empty( $element['settings'] ) || ! is_array( $element['settings'] ) ) continue;

        $settings = &$element['settings'];

        // Champs texte directs
        foreach ( $keys as $field ) {
            if ( ! isset( $settings[ $field ] ) ) continue;
            $value = $settings[ $field ];
            if ( ! is_string( $value ) || '' === trim( $value ) ) continue;

            $translated = autolingo_call_deepl( $value, $target_lang );
            if ( $translated !== false ) {
                $settings[ $field ] = $translated;
            }
        }

        // Items imbriqués : accordéon, onglets, liste d'icônes, etc.
        // Détectés comme tableaux séquentiels de tableaux associatifs dans settings.
        foreach ( $settings as &$setting_value ) {
            if ( ! is_array( $setting_value ) || empty( $setting_value ) ) continue;
            // Tableau séquentiel (indices 0, 1, 2…) = liste d'items
            if ( array_keys( $setting_value ) !== range( 0, count( $setting_value ) - 1 ) ) continue;

            foreach ( $setting_value as &$item ) {
                if ( ! is_array( $item ) ) continue;
                foreach ( $item_keys as $item_field ) {
                    if ( ! isset( $item[ $item_field ] ) ) continue;
                    $value = $item[ $item_field ];
                    if ( ! is_string( $value ) || '' === trim( $value ) ) continue;

                    $translated = autolingo_call_deepl( $value, $target_lang );
                    if ( $translated !== false ) {
                        $item[ $item_field ] = $translated;
                    }
                }
            }
            unset( $item );
        }
        unset( $setting_value );
    }
    unset( $element );

    return $elements;
}
