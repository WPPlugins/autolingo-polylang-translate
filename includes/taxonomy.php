<?php
/**
 * Taxonomy term name translation.
 *
 * When a source taxonomy term has no existing translation on the
 * multilingual plugin's side, translate its name (and description) via the
 * configured provider and create a real linked translation — instead of
 * tagging the translated post with the untranslated source term, which is
 * what autolingo_sync_terms() did before and what every competitor checked
 * still does.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Translate a taxonomy term's name and create it as a linked translation.
 * Returns the new (or reused, if a term with that exact translated name
 * already exists in the taxonomy) term ID, or 0 on failure — callers fall
 * back to the source term ID in that case.
 */
function autolingo_translate_term( int $source_term_id, string $taxonomy, string $target_lang, string $lang_slug, string $source_lang_slug ): int {
    if ( ! autolingo_module_enabled( 'taxonomy_names' ) ) return 0;

    $ml = autolingo_multilingual();
    if ( ! $ml ) return 0;

    $term = get_term( $source_term_id, $taxonomy );
    if ( ! $term || is_wp_error( $term ) ) return 0;

    $hash_key = '_autolingo_term_hash_' . $lang_slug;
    $link_key = '_autolingo_term_' . $lang_slug;
    $current_hash = md5( $term->name . "\x00" . $term->description );

    $cached_id = (int) get_term_meta( $source_term_id, $link_key, true );
    if ( $cached_id
        && get_term_meta( $source_term_id, $hash_key, true ) === $current_hash
        && term_exists( $cached_id, $taxonomy )
    ) {
        return $cached_id;
    }

    $batch_input = [ $term->name ];
    if ( $term->description ) $batch_input[] = $term->description;

    $previous_context = autolingo_translation_context( 'Name and description of a ' . $taxonomy . ' term (category or tag) on a website.' );
    $batch            = autolingo_call_deepl_batch( $batch_input, $target_lang );
    autolingo_translation_context( $previous_context );

    $translated_name = $batch !== false ? ( $batch[0] ?? false ) : false;
    $translated_desc = ( $batch !== false && $term->description ) ? ( $batch[1] ?? '' ) : '';

    if ( $translated_name === false || trim( $translated_name ) === '' ) {
        autolingo_log_error(
            "Erreur lors de la traduction du terme « {$term->name} » ({$taxonomy}) vers {$target_lang}.",
            [ 'term_id' => $source_term_id, 'taxonomy' => $taxonomy ],
            null,
            $target_lang
        );
        return 0;
    }

    $inserted = wp_insert_term( $translated_name, $taxonomy, [ 'description' => $translated_desc ] );

    if ( is_wp_error( $inserted ) ) {
        if ( $inserted->get_error_code() === 'term_exists' ) {
            $new_term_id = (int) $inserted->get_error_data();
        } else {
            autolingo_log_error(
                "Impossible de créer le terme traduit « {$translated_name} » ({$taxonomy}) : " . $inserted->get_error_message(),
                [ 'term_id' => $source_term_id, 'taxonomy' => $taxonomy ],
                null,
                $target_lang
            );
            return 0;
        }
    } else {
        $new_term_id = (int) $inserted['term_id'];
    }

    $ml->set_term_language( $new_term_id, $taxonomy, $lang_slug );
    $ml->save_term_translations( $source_term_id, $new_term_id, $taxonomy, $lang_slug, $source_lang_slug );

    update_term_meta( $source_term_id, $hash_key, $current_hash );
    update_term_meta( $source_term_id, $link_key, $new_term_id );

    autolingo_log_success(
        "Terme « {$term->name} » traduit vers « {$translated_name} » ({$taxonomy}, {$target_lang}).",
        [ 'term_id' => $source_term_id, 'new_term_id' => $new_term_id, 'taxonomy' => $taxonomy ],
        null,
        $target_lang
    );

    return $new_term_id;
}
