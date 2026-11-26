<?php
/**
 * Image texts translation: alt text, caption, description and title.
 *
 * Translates them for images used by a translated post
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
 * The texts of an image AutoLingo translates onto its translated copy: the alt text (meta), the caption
 * (post_excerpt), the description (post_content) and the title (post_title). The alt text keeps the meta keys
 * of earlier versions, so images already translated are not translated again.
 *
 * @return array<string, array{read: callable, write: callable, hash: string, target_hash: string}>
 */
function autolingo_media_fields( string $lang_slug ): array {
    $meta = static fn( string $key ) => [
        'read'  => static fn( int $id ): string => (string) get_post_meta( $id, $key, true ),
        'write' => static function ( int $id, string $value ) use ( $key ): void { update_post_meta( $id, $key, $value ); },
    ];
    $field = static fn( string $column ) => [
        'read'  => static fn( int $id ): string => (string) get_post_field( $column, $id, 'raw' ),
        'write' => static function ( int $id, string $value ) use ( $column ): void {
            global $wpdb;
            $wpdb->update( $wpdb->posts, [ $column => $value ], [ 'ID' => $id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- no save_post: the copy is not a post to translate again
            clean_post_cache( $id );
        },
    ];
    return [
        'alt'         => $meta( '_wp_attachment_image_alt' ) + [ 'hash' => '_autolingo_alt_hash_' . $lang_slug, 'target_hash' => '_autolingo_alt_target_hash_' . $lang_slug ],
        'caption'     => $field( 'post_excerpt' ) + [ 'hash' => '_autolingo_caption_hash_' . $lang_slug, 'target_hash' => '_autolingo_caption_target_hash_' . $lang_slug ],
        'description' => $field( 'post_content' ) + [ 'hash' => '_autolingo_description_hash_' . $lang_slug, 'target_hash' => '_autolingo_description_target_hash_' . $lang_slug ],
        'title'       => $field( 'post_title' ) + [ 'hash' => '_autolingo_title_hash_' . $lang_slug, 'target_hash' => '_autolingo_title_target_hash_' . $lang_slug ],
    ];
}

/**
 * Translate the alt text, caption, description and title of every image used by $source_post onto its
 * translated counterpart, when one exists. Skips images whose media item is shared across languages (no
 * distinct translated attachment) rather than overwriting the shared source. A text edited by hand on the
 * copy is kept; an unchanged source is not translated again.
 */
function autolingo_translate_image_alt_text( WP_Post $source_post, int $target_id, string $target_lang, string $lang_slug ): void {
    if ( ! autolingo_module_enabled( 'media_alt' ) ) return;

    $ml = autolingo_multilingual();
    if ( ! $ml ) return;

    $image_ids = autolingo_get_post_image_ids( $source_post );
    if ( empty( $image_ids ) ) return;

    $fields = autolingo_media_fields( $lang_slug );
    $jobs   = []; // [ attachment, copy, field, source text ]
    foreach ( $image_ids as $attachment_id ) {
        $copy_id = $ml->get_translated_post( $attachment_id, $lang_slug );
        if ( ! $copy_id || $copy_id === $attachment_id ) continue;
        foreach ( $fields as $name => $field ) {
            $source = ( $field['read'] )( $attachment_id );
            if ( trim( $source ) === '' ) continue;
            if ( get_post_meta( $attachment_id, $field['hash'], true ) === md5( $source ) ) continue; // translated, unchanged since
            $stored = get_post_meta( $attachment_id, $field['target_hash'], true );
            if ( $stored && md5( ( $field['read'] )( $copy_id ) ) !== $stored ) continue; // edited by hand on the copy
            $jobs[] = [ $attachment_id, $copy_id, $name, $source ];
        }
    }
    if ( empty( $jobs ) ) return;

    $previous_context = autolingo_translation_context( 'Texts of images in a website media library: alternative text (describes the image), caption, description and title.' );
    $translated       = autolingo_call_deepl_batch( array_column( $jobs, 3 ), $target_lang );
    autolingo_translation_context( $previous_context );
    if ( $translated === false ) {
        autolingo_log_error(
            "Erreur lors de la traduction des textes des images vers {$target_lang}.",
            [ 'target_id' => $target_id ],
            $source_post->ID,
            $target_lang
        );
        return;
    }

    $count = 0;
    foreach ( $jobs as $i => [ $attachment_id, $copy_id, $name, $source ] ) {
        $text = $translated[ $i ] ?? false;
        if ( $text === false ) continue;
        $field = $fields[ $name ];
        ( $field['write'] )( $copy_id, (string) $text );
        update_post_meta( $attachment_id, $field['hash'], md5( $source ) );
        update_post_meta( $attachment_id, $field['target_hash'], md5( ( $field['read'] )( $copy_id ) ) );
        $count++;
    }

    if ( $count ) {
        autolingo_log_success(
            "Textes d'images traduits ({$count}) vers {$target_lang}.",
            [ 'target_id' => $target_id ],
            $source_post->ID,
            $target_lang
        );
    }
}
