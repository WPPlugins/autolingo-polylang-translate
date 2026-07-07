<?php
/**
 * Beaver Builder — traduction des modules de page builder.
 *
 * Beaver Builder stocke son contenu en PHP sérialisé dans _fl_builder_data.
 * Chaque entrée est un "node" (row, column, module) avec un objet settings.
 *
 * Particularités gérées :
 *   - Deep copy avant modification (évite de corrompre le cache WP des metas source)
 *   - Nodes globaux ignorés (ils sont partagés entre pages, les modifier ici
 *     affecterait tout le site)
 *   - Live data ET draft data mises à jour (_fl_builder_data + _fl_builder_draft)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Vérifie si un post est édité avec Beaver Builder.
 */
function autolingo_is_beaver_builder_post( int $post_id ): bool {
    return '1' === get_post_meta( $post_id, '_fl_builder_enabled', true );
}

/**
 * Noms de champs Beaver Builder contenant du texte traduisible.
 * Couvre les modules natifs les plus courants.
 * Extensible via le filtre autolingo_beaver_builder_translatable_keys.
 *
 * @return string[]
 */
function autolingo_beaver_builder_translatable_keys(): array {
    return apply_filters( 'autolingo_beaver_builder_translatable_keys', [
        // Rich Text, Heading, Text
        'text', 'title', 'heading',
        // Button, CTA
        'label', 'button_text', 'cta_text',
        // Callout, Icon
        'content', 'description', 'sub_title',
        // Photo, Video
        'caption',
        // Tabs, Accordion
        'tab_title', 'tab_content',
        // Testimonials, Pricing
        'testimonial', 'name', 'company', 'price_label', 'feature',
    ] );
}

/**
 * Traduit les modules Beaver Builder du post source vers le post cible.
 *
 * @param  int    $source_id   ID post source.
 * @param  int    $target_id   ID post traduit.
 * @param  string $target_lang Code langue majuscules (ex: 'EN').
 * @return bool
 */
function autolingo_beaver_builder_translate( int $source_id, int $target_id, string $target_lang ): bool {
    $original = get_post_meta( $source_id, '_fl_builder_data', true );
    if ( empty( $original ) || ! is_array( $original ) ) return true;

    // Deep copy via serialize/unserialize pour ne pas modifier le cache WP
    // des metas du post source (les objets PHP sont passés par référence).
    $data = unserialize( serialize( $original ) );

    $data = autolingo_beaver_builder_translate_nodes( $data, $target_lang );

    // Copier les metas BB nécessaires au fonctionnement de l'éditeur
    foreach ( [ '_fl_builder_enabled', '_fl_builder_version', '_fl_builder_template_id', '_fl_builder_template_node_id' ] as $key ) {
        $val = get_post_meta( $source_id, $key, true );
        if ( $val !== '' && $val !== false ) {
            update_post_meta( $target_id, $key, $val );
        }
    }

    // Sauvegarder live ET draft (BB utilise les deux)
    update_post_meta( $target_id, '_fl_builder_data',  $data );
    update_post_meta( $target_id, '_fl_builder_draft', $data );

    autolingo_log_info(
        "Beaver Builder : modules traduits vers {$target_lang}.",
        [ 'source_id' => $source_id, 'target_id' => $target_id ],
        $source_id,
        $target_lang
    );

    return true;
}

/**
 * Parcourt le tableau de nodes BB et traduit les settings des modules.
 *
 * @param  array  $nodes       Tableau de nodes (array de stdClass/FLBuilderNode).
 * @param  string $target_lang Code langue majuscules.
 * @return array
 */
function autolingo_beaver_builder_translate_nodes( array $nodes, string $target_lang ): array {
    $keys = autolingo_beaver_builder_translatable_keys();

    foreach ( $nodes as &$node ) {
        if ( ! is_object( $node ) ) continue;
        if ( ( $node->type ?? '' ) !== 'module' ) continue;
        if ( ! isset( $node->settings ) || ! is_object( $node->settings ) ) continue;

        // Ignorer les nodes globaux : partagés entre pages, les modifier ici
        // affecterait toutes les pages qui utilisent ce module global.
        if ( ! empty( $node->global ) ) continue;

        foreach ( $keys as $field ) {
            if ( ! isset( $node->settings->$field ) ) continue;

            $value = $node->settings->$field;
            if ( ! is_string( $value ) || '' === trim( $value ) ) continue;

            $translated = autolingo_call_deepl( $value, $target_lang );
            if ( $translated !== false ) {
                $node->settings->$field = $translated;
            }
        }
    }
    unset( $node );

    return $nodes;
}
