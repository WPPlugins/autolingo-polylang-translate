<?php
/**
 * Elementor — traduction des données de page builder.
 *
 * Traduit récursivement les champs texte des widgets Elementor
 * stockés dans le meta _elementor_data (JSON).
 *
 * Architecture :
 *   _elementor_data = JSON array d'éléments (sections → colonnes → widgets)
 *   Chaque widget a un objet settings contenant des champs traduits.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Vérifie si un post est édité avec Elementor.
 */
function autolingo_is_elementor_post( int $post_id ): bool {
    return 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true );
}

/**
 * Clés de settings Elementor contenant du texte traduisible.
 * Extensible via le filtre autolingo_elementor_translatable_keys.
 *
 * @return string[]
 */
function autolingo_elementor_translatable_keys(): array {
    return apply_filters( 'autolingo_elementor_translatable_keys', [
        // Heading / Paragraph (classic + Flexbox Container v4+)
        'title', 'text', 'editor', 'description', 'sub_title', 'heading', 'paragraph',
        // Icon Box / Image Box
        'title_text', 'description_text',
        // Bouton / Read More
        'button_text', 'link_text',
        // Image
        'caption',
        // Alerte / Notice
        'alert_title', 'alert_description',
        // Témoignage
        'testimonial_content', 'testimonial_name', 'testimonial_job',
        // Accordéon / Onglets / Toggle
        'tab_title', 'tab_content', 'toggle_title',
        // Compteur / Barre de progression
        'suffix', 'prefix', 'inner_text',
        // Formulaire
        'label', 'placeholder', 'field_label',
        // Contact Buttons / Floating Bar / Link in Bio
        'top_bar_title', 'top_bar_subtitle', 'message_bubble_body', 'send_button_text',
        'announcement_text', 'cta_text',
        'bio_title', 'bio_heading', 'bio_description',
        // Générique (répéteurs)
        'content', 'list_title', 'item_text',
    ] );
}

/**
 * Traduit les données Elementor d'un post source vers le post cible.
 *
 * @param  int    $source_id   ID post source.
 * @param  int    $target_id   ID post traduit.
 * @param  string $target_lang Code langue majuscules (ex: 'EN').
 * @return bool
 */
function autolingo_elementor_translate( int $source_id, int $target_id, string $target_lang ): bool {
    $raw = get_post_meta( $source_id, '_elementor_data', true );
    if ( empty( $raw ) ) return true;

    $data = json_decode( $raw, true );
    if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
        autolingo_log_error(
            'Elementor : impossible de décoder _elementor_data.',
            [ 'source_id' => $source_id, 'json_error' => json_last_error_msg() ],
            $source_id,
            $target_lang
        );
        return false;
    }

    $translated_data = autolingo_elementor_translate_elements( $data, $target_lang );

    // Copier les metas Elementor nécessaires au fonctionnement de l'éditeur
    $meta_to_copy = [
        '_elementor_edit_mode',
        '_elementor_template_type',
        '_elementor_version',
        '_elementor_pro_version',
        '_elementor_page_settings',
    ];
    foreach ( $meta_to_copy as $key ) {
        $val = get_post_meta( $source_id, $key, true );
        if ( $val !== '' && $val !== false ) {
            update_post_meta( $target_id, $key, $val );
        }
    }

    // Sauvegarder (wp_slash requis, comme Elementor le fait lui-même)
    update_post_meta( $target_id, '_elementor_data', wp_slash( wp_json_encode( $translated_data ) ) );

    autolingo_log_info(
        "Elementor : données traduites vers {$target_lang}.",
        [ 'source_id' => $source_id, 'target_id' => $target_id ],
        $source_id,
        $target_lang
    );

    return true;
}

/**
 * Parcourt récursivement le tableau d'éléments Elementor.
 */
function autolingo_elementor_translate_elements( array $elements, string $target_lang ): array {
    foreach ( $elements as &$element ) {
        if ( ! empty( $element['settings'] ) && is_array( $element['settings'] ) ) {
            $element['settings'] = autolingo_elementor_translate_settings( $element['settings'], $target_lang );
        }
        if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
            $element['elements'] = autolingo_elementor_translate_elements( $element['elements'], $target_lang );
        }
    }
    unset( $element );
    return $elements;
}

/**
 * Traduit les champs texte d'un objet settings Elementor.
 * Gère les champs simples, les répéteurs, et le format atomique $$type (Elementor v4+).
 */
function autolingo_elementor_translate_settings( array $settings, string $target_lang ): array {
    $translatable_keys = autolingo_elementor_translatable_keys();

    foreach ( $settings as $key => &$value ) {
        if ( is_string( $value ) && '' !== trim( $value ) && in_array( $key, $translatable_keys, true ) ) {
            // Format classique : valeur texte simple
            $translated = autolingo_call_deepl( $value, $target_lang );
            if ( $translated !== false ) {
                $value = $translated;
            }
        } elseif ( is_array( $value ) && isset( $value['$$type'] ) && in_array( $key, $translatable_keys, true ) ) {
            // Format atomique Elementor v4+ ($$type: "string" | "html-v3")
            $value = autolingo_elementor_translate_atomic( $value, $target_lang );
        } elseif ( is_array( $value ) && ! empty( $value ) ) {
            // Répéteur Elementor = tableau indexé d'objets settings (ex: listes, accordéons)
            if ( array_keys( $value ) === range( 0, count( $value ) - 1 ) ) {
                $value = array_map(
                    static fn( $item ) => is_array( $item )
                        ? autolingo_elementor_translate_settings( $item, $target_lang )
                        : $item,
                    $value
                );
            }
        }
    }
    unset( $value );

    return $settings;
}

/**
 * Traduit récursivement un nœud atomique Elementor v4+ ($$type).
 * Supporte : "string", "html-v3" (et leurs enfants imbriqués).
 */
function autolingo_elementor_translate_atomic( array $atomic, string $target_lang ): array {
    $type = $atomic['$$type'] ?? '';

    if ( 'string' === $type && isset( $atomic['value'] ) && is_string( $atomic['value'] ) && '' !== trim( $atomic['value'] ) ) {
        $translated = autolingo_call_deepl( $atomic['value'], $target_lang );
        if ( $translated !== false ) {
            $atomic['value'] = $translated;
        }
    } elseif ( 'html-v3' === $type && isset( $atomic['value'] ) && is_array( $atomic['value'] ) ) {
        if ( isset( $atomic['value']['content'] ) && is_array( $atomic['value']['content'] ) ) {
            $atomic['value']['content'] = autolingo_elementor_translate_atomic( $atomic['value']['content'], $target_lang );
        }
        if ( ! empty( $atomic['value']['children'] ) && is_array( $atomic['value']['children'] ) ) {
            $atomic['value']['children'] = array_map(
                static fn( $child ) => is_array( $child )
                    ? autolingo_elementor_translate_atomic( $child, $target_lang )
                    : $child,
                $atomic['value']['children']
            );
        }
    }

    return $atomic;
}
