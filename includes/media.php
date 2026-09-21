<?php
/**
 * Image alt text translation.
 *
 * Translates _wp_attachment_image_alt for images used by a translated post
 * (featured image + inline images) whenever a distinct translated media item
 * exists for the target language. Media is normally shared across languages
 * unless the multilingual plugin's own media-translation feature is enabled
 * for the attachment — in that case it is safe to write to the translated
 * attachment's own meta without touching the shared source.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Collect attachment IDs referenced by a post: the featured image plus any
 * inline image carrying WordPress's standard `wp-image-{ID}` class (added
 * by the core Image block and classic media inserts).
 *
 * @return int[]
 */
function autolingo_get_post_image_ids( WP_Post $post ): array {
    $ids = [];

    $thumbnail_id = get_post_thumbnail_id( $post );
    if ( $thumbnail_id ) $ids[] = (int) $thumbnail_id;

    if ( preg_match_all( '/wp-image-(\d+)/', $post->post_content, $matches ) ) {
        foreach ( $matches[1] as $id ) $ids[] = (int) $id;
    }

    return array_values( array_unique( $ids ) );
}

/**
 * Translate the alt text of every image used by $source_post onto its
 * translated counterpart attachment, when one exists. Skips images whose
 * media item is shared across languages (no distinct translated attachment)
 * rather than overwriting the shared source alt text.
 */
function autolingo_translate_image_alt_text( WP_Post $source_post, int $target_id, string $target_lang, string $lang_slug ): void {
    if ( ! autolingo_module_enabled( 'media_alt' ) ) return;

    $ml = autolingo_multilingual();
    if ( ! $ml ) return;

    $image_ids = autolingo_get_post_image_ids( $source_post );
    if ( empty( $image_ids ) ) return;

    $source_hash_key = '_autolingo_alt_hash_' . $lang_slug;
    $target_hash_key = '_autolingo_alt_target_hash_' . $lang_slug;

    // Map source attachment ID → [ target_id, source_alt ] for images that
    // actually need (re)translating.
    $jobs = [];
    foreach ( $image_ids as $attachment_id ) {
        $source_alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
        if ( trim( (string) $source_alt ) === '' ) continue;

        $translated_attachment_id = $ml->get_translated_post( $attachment_id, $lang_slug );
        if ( ! $translated_attachment_id || $translated_attachment_id === $attachment_id ) continue;

        $source_changed = get_post_meta( $attachment_id, $source_hash_key, true ) !== md5( $source_alt );
        if ( ! $source_changed ) continue; // already translated, alt text unchanged since

        $stored_target_hash = get_post_meta( $attachment_id, $target_hash_key, true );
        if ( $stored_target_hash ) {
            $current_target_alt = (string) get_post_meta( $translated_attachment_id, '_wp_attachment_image_alt', true );
            if ( md5( $current_target_alt ) !== $stored_target_hash ) {
                continue; // manually edited on the translated attachment — leave it alone
            }
        }

        $jobs[ $attachment_id ] = [ 'target_id' => $translated_attachment_id, 'source_alt' => $source_alt ];
    }

    if ( empty( $jobs ) ) return;

    $attachment_ids = array_keys( $jobs );
    $texts          = array_column( $jobs, 'source_alt' );

    $translated = autolingo_call_deepl_batch( $texts, $target_lang );
    if ( $translated === false ) {
        autolingo_log_error(
            "Erreur lors de la traduction du texte alternatif des images vers {$target_lang}.",
            [ 'target_id' => $target_id ],
            $source_post->ID,
            $target_lang
        );
        return;
    }

    $translated_count = 0;
    foreach ( $attachment_ids as $i => $attachment_id ) {
        $translated_text = $translated[ $i ] ?? false;
        if ( $translated_text === false ) continue;

        $job = $jobs[ $attachment_id ];
        update_post_meta( $job['target_id'], '_wp_attachment_image_alt', $translated_text );
        update_post_meta( $attachment_id, $source_hash_key, md5( $job['source_alt'] ) );
        update_post_meta( $attachment_id, $target_hash_key, md5( $translated_text ) );
        $translated_count++;
    }

    if ( $translated_count ) {
        autolingo_log_success(
            "Texte alternatif traduit pour {$translated_count} image(s) vers {$target_lang}.",
            [ 'target_id' => $target_id ],
            $source_post->ID,
            $target_lang
        );
    }
}
