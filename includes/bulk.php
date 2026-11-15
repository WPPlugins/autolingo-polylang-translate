<?php
/**
 * Bulk work from the admin, without WP-CLI: translating what is missing, and
 * repairing existing translations (SEO fields and addresses left in the source
 * language, duplicates linked to no original).
 *
 * Many hosts give no shell access, or kill long processes: every request here
 * works for about AUTOLINGO_BULK_BUDGET seconds and the page asks again until
 * nothing is left, like the strings translation.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** Seconds of work in one admin request: well under the usual host and cache time limits. */
const AUTOLINGO_BULK_BUDGET = 12;

/** Meta on a translation: an address it had before AutoLingo translated it (one row per address). */
const AUTOLINGO_OLD_SLUG = '_autolingo_old_slug';

// ── Missing translations ──────────────────────────────────────────────────────

/**
 * Source posts and the target languages they have no translation in.
 *
 * @return array<int, string[]> [ post ID => [ lang, … ] ]
 */
function autolingo_bulk_missing(): array {
    $out = [];
    foreach ( autolingo_get_all_source_posts() as $post_id ) {
        $needs = array_keys( array_filter( autolingo_translation_status( (int) $post_id ), static fn( bool $exists ): bool => ! $exists ) );
        if ( $needs ) $out[ (int) $post_id ] = $needs;
    }
    return $out;
}

/**
 * Creates missing translations for about $budget seconds.
 *
 * @param string[] $exclude "postID:lang" pairs to leave aside (failed earlier in this run).
 * @return array{done:int, remaining:int, failed:string[], error:string}
 */
function autolingo_bulk_translate_missing( float $budget, array $exclude = [] ): array {
    $start  = microtime( true );
    $result = [ 'done' => 0, 'remaining' => 0, 'failed' => [], 'error' => '' ];
    $skip   = array_flip( $exclude );

    $pairs = [];
    foreach ( autolingo_bulk_missing() as $post_id => $langs ) {
        foreach ( $langs as $lang ) {
            if ( ! isset( $skip[ "$post_id:$lang" ] ) ) $pairs[] = [ $post_id, $lang ];
        }
    }
    $result['remaining'] = count( $pairs );

    foreach ( $pairs as $n => [ $post_id, $lang ] ) {
        if ( $n > 0 && $budget > 0 && microtime( true ) - $start >= $budget ) break;
        if ( ! autolingo_check_usage() ) {
            $result['error'] = __( 'The translation credit is used up.', 'autolingo-polylang-translate' );
            break;
        }
        $post = get_post( $post_id );
        $new  = 0;
        if ( $post ) {
            $previous = autolingo_translation_context( autolingo_post_context( $post ) );
            try {
                $new = autolingo_create_translation( $post, strtoupper( $lang ), $lang );
            } finally {
                autolingo_translation_context( $previous );
            }
        }
        if ( $new ) {
            $result['done']++;
        } else {
            $result['failed'][] = "$post_id:$lang";
            $cause = autolingo_last_error();
            if ( $cause !== '' ) $result['error'] = $cause;
        }
        $result['remaining']--;
    }
    return $result;
}

// ── Existing translations left in the source language ─────────────────────────

/**
 * Every [ source ID, lang, translation ID ] of the site's source posts.
 *
 * @return array<int, array{0:int, 1:string, 2:int}>
 */
function autolingo_bulk_translation_pairs(): array {
    $ml    = autolingo_multilingual();
    $pairs = [];
    if ( ! $ml ) return $pairs;
    foreach ( autolingo_get_all_source_posts() as $source_id ) {
        foreach ( $ml->get_post_translations( (int) $source_id ) as $lang => $id ) {
            if ( (int) $id !== (int) $source_id && in_array( $lang, autolingo_target_langs(), true ) ) {
                $pairs[] = [ (int) $source_id, (string) $lang, (int) $id ];
            }
        }
    }
    return $pairs;
}

/** True when $value has words (not just a variable such as %%title%% or a number). */
function autolingo_bulk_has_words( string $value ): bool {
    return (bool) preg_match( '/\p{L}{2,}/u', (string) preg_replace( '/%%[a-z_]+%%|%[a-z_]+%/i', '', $value ) );
}

/**
 * SEO fields of a translation still identical to the source's (or empty while
 * the source's is filled): [ meta key => source value ]. Premium (SEO module).
 */
function autolingo_bulk_seo_untranslated( int $source_id, int $target_id ): array {
    if ( ! function_exists( 'autolingo_seo_translatable_fields' ) || ! autolingo_is_premium() || ! autolingo_module_enabled( 'seo' ) ) return [];
    $out = [];
    foreach ( autolingo_seo_translatable_fields() as $key ) {
        $source = get_post_meta( $source_id, $key, true );
        if ( ! is_string( $source ) || trim( $source ) === '' || ! autolingo_bulk_has_words( $source ) ) continue;
        $target = get_post_meta( $target_id, $key, true );
        if ( ! is_string( $target ) || trim( $target ) === '' || trim( $target ) === trim( $source ) ) {
            $out[ $key ] = $source;
        }
    }
    return $out;
}

/** True when the translation's address is the source's (possibly with a -2, -3… suffix). */
function autolingo_bulk_slug_untranslated( int $source_id, int $target_id ): bool {
    $source = (string) get_post_field( 'post_name', $source_id );
    $target = (string) get_post_field( 'post_name', $target_id );
    if ( $source === '' || $target === '' ) return false;
    if ( sanitize_title( (string) get_the_title( $target_id ) ) === $source ) return false; // same title, same address: nothing to translate
    return (bool) preg_match( '/^' . preg_quote( $source, '/' ) . '(-\d+)?$/', $target );
}

/**
 * Translations to repair: SEO fields and addresses left in the source language.
 *
 * @return array<int, array{source:int, lang:string, id:int, seo:array, slug:bool}>
 */
/**
 * Translations made by AutoLingo before 1.12.2 dated the day they were created instead of their original's
 * publication date, so a blog listed by date showed old articles first in that language. Only the types listed
 * by date (not pages), only translations AutoLingo made (the original carries its _autolingo_hash_<lang>), and
 * only when the translation is dated after its published original: a date set on purpose is left alone.
 *
 * @return array<int, array{source: int, lang: string, id: int, date: string}>
 */
function autolingo_bulk_dates(): array {
    $out = [];
    foreach ( autolingo_bulk_translation_pairs() as [ $source_id, $lang, $id ] ) {
        $type = get_post_type( $source_id );
        if ( ! $type || is_post_type_hierarchical( $type ) ) continue;
        if ( (string) get_post_meta( $source_id, '_autolingo_hash_' . $lang, true ) === '' ) continue;
        $source = get_post( $source_id );
        $copy   = get_post( $id );
        if ( ! $source || ! $copy || $source->post_status !== 'publish' || $source->post_date_gmt === '0000-00-00 00:00:00' ) continue;
        if ( $copy->post_date_gmt <= $source->post_date_gmt ) continue;
        $out[] = [ 'source' => $source_id, 'lang' => $lang, 'id' => $id, 'date' => $copy->post_date ];
    }
    return $out;
}

/** Gives those translations their original's publication date. Returns how many were fixed. */
function autolingo_bulk_fix_dates(): int {
    global $wpdb;
    $done = 0;
    foreach ( autolingo_bulk_dates() as $d ) {
        $source = get_post( $d['source'] );
        // Direct update: wp_update_post() would run save_post and translate the post again.
        $wpdb->update( $wpdb->posts, [ 'post_date' => $source->post_date, 'post_date_gmt' => $source->post_date_gmt ], [ 'ID' => $d['id'] ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a date only, without the save hooks
        clean_post_cache( $d['id'] );
        $done++;
    }
    return $done;
}

function autolingo_bulk_repairs(): array {
    $out = [];
    foreach ( autolingo_bulk_translation_pairs() as [ $source_id, $lang, $id ] ) {
        $seo  = autolingo_bulk_seo_untranslated( $source_id, $id );
        $freed = autolingo_bulk_slug_freed( $id, $lang );
        $slug  = $freed !== '' || autolingo_bulk_slug_untranslated( $source_id, $id );
        if ( $seo || $slug ) {
            $out[] = [ 'source' => $source_id, 'lang' => $lang, 'id' => $id, 'seo' => $seo, 'slug' => $slug, 'freed' => $freed ];
        }
    }
    return $out;
}

/**
 * Gives a translation an address from its translated title. The old address
 * keeps working: it redirects to the new one (autolingo_bulk_old_slug_redirect()).
 */
function autolingo_bulk_fix_slug( int $id, string $wanted = '' ): string {
    $post = get_post( $id );
    if ( ! $post ) return '';
    $old  = $post->post_name;
    $slug = wp_unique_post_slug( $wanted !== '' ? $wanted : sanitize_title( $post->post_title ), $id, $post->post_status, $post->post_type, $post->post_parent );
    if ( $slug === '' || $slug === $old ) return $old;
    autolingo_bulk_set_slug( $id, $slug );
    return $slug;
}

/**
 * Changes a translation's address; the old one redirects to it. A redirect rule
 * of another plugin that sent the new address to the old one would now loop:
 * it is disabled.
 */
function autolingo_bulk_set_slug( int $id, string $slug ): void {
    $old      = (string) get_post_field( 'post_name', $id );
    $old_link = (string) get_permalink( $id );
    remove_action( 'save_post', 'autolingo_on_save_post', 20 );
    wp_update_post( [ 'ID' => $id, 'post_name' => $slug ] );
    add_action( 'save_post', 'autolingo_on_save_post', 20, 3 );
    add_post_meta( $id, AUTOLINGO_OLD_SLUG, $old );
    autolingo_bulk_disable_looping_redirects( (string) get_permalink( $id ), $old_link );
}

/** A URL or path as Redirection compares it: path only, decoded, lower case, no trailing slash. */
function autolingo_bulk_redirect_key( string $url ): string {
    $path = (string) wp_parse_url( $url, PHP_URL_PATH );
    return strtolower( untrailingslashit( rawurldecode( $path ) ) );
}

/**
 * Disables the Redirection rules that send $new_url to $old_url (they were made
 * while the page had the old address, and would now send visitors in a loop).
 *
 * @return int Rules disabled.
 */
function autolingo_bulk_disable_looping_redirects( string $new_url, string $old_url ): int {
    global $wpdb;
    if ( ! class_exists( 'Red_Item' ) || $new_url === '' || $old_url === '' ) return 0;
    $from = autolingo_bulk_redirect_key( $new_url );
    $to   = autolingo_bulk_redirect_key( $old_url );
    if ( $from === '' || $from === $to ) return 0;

    $table = $wpdb->prefix . 'redirection_items';
    $last  = substr( $to, (int) strrpos( '/' . $to, '/' ) ); // basename() is not multibyte safe
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Redirection's own table, admin tool only.
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, url, action_data FROM {$table} WHERE status = 'enabled' AND regex = 0 AND action_type = 'url' AND (action_data LIKE %s OR action_data LIKE %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        '%' . $wpdb->esc_like( $last ) . '%',
        '%' . $wpdb->esc_like( strtolower( rawurlencode( $last ) ) ) . '%'
    ) );
    $done = 0;
    foreach ( (array) $rows as $row ) {
        if ( autolingo_bulk_redirect_key( (string) $row->url ) !== $from || autolingo_bulk_redirect_key( (string) $row->action_data ) !== $to ) continue;
        $item = Red_Item::get_by_id( (int) $row->id );
        if ( $item ) {
            $item->disable();
            $done++;
        }
    }
    return $done;
}

/**
 * The address a translation should have back: it has "S-2" (or -3…) because a
 * duplicate with its title held "S", and that duplicate is now in the trash.
 * '' when there is none, or when "S" is still taken.
 */
function autolingo_bulk_slug_freed( int $id, string $lang ): string {
    $post = get_post( $id );
    if ( ! $post || ! preg_match( '/^(.+)-\d+$/', $post->post_name, $m ) ) return '';
    $ml = autolingo_multilingual();
    foreach ( get_posts( [
        'post_type'        => $post->post_type,
        'post_status'      => 'trash',
        'posts_per_page'   => 10,
        'fields'           => 'ids',
        'title'            => $post->post_title,
        'meta_key'         => '_wp_desired_post_slug', // phpcs:ignore WordPress.DB.SlowDBQuery -- admin tool only.
        'meta_value'       => $m[1],                   // phpcs:ignore WordPress.DB.SlowDBQuery
        'lang'             => '',
        'suppress_filters' => true,
    ] ) as $trashed ) {
        if ( $ml && $ml->get_post_language( (int) $trashed ) !== $lang ) continue;
        $free = wp_unique_post_slug( $m[1], $id, $post->post_status, $post->post_type, $post->post_parent ) === $m[1];
        return $free ? $m[1] : '';
    }
    return '';
}

/** Translates the SEO fields of one translation that are still in the source language. */
function autolingo_bulk_fix_seo( int $id, string $lang, array $fields ): int {
    if ( ! $fields ) return 0;
    $previous = autolingo_translation_context( 'SEO title, meta description and focus keyword of a web page. Keep variables such as %%sep%% or %%sitename%% unchanged.' );
    $out      = autolingo_call_deepl_batch( array_values( $fields ), strtoupper( $lang ) );
    autolingo_translation_context( $previous );
    if ( ! is_array( $out ) ) return 0;
    $done = 0;
    foreach ( array_keys( $fields ) as $i => $key ) {
        $value = is_string( $out[ $i ] ?? null ) ? trim( $out[ $i ] ) : '';
        if ( $value !== '' ) {
            update_post_meta( $id, $key, $value );
            $done++;
        }
    }
    return $done;
}

/**
 * Repairs translations for about $budget seconds.
 *
 * @param string[] $exclude Translation IDs to leave aside (failed earlier in this run).
 * @return array{done:int, remaining:int, failed:string[], error:string}
 */
function autolingo_bulk_repair( float $budget, array $exclude = [] ): array {
    $start  = microtime( true );
    $result = [ 'done' => 0, 'remaining' => 0, 'failed' => [], 'error' => '' ];
    $skip   = array_flip( $exclude );
    $todo   = array_values( array_filter( autolingo_bulk_repairs(), static fn( array $r ): bool => ! isset( $skip[ (string) $r['id'] ] ) ) );
    $result['remaining'] = count( $todo );

    foreach ( $todo as $n => $r ) {
        if ( $n > 0 && $budget > 0 && microtime( true ) - $start >= $budget ) break;
        $ok = true;
        if ( $r['seo'] ) {
            if ( ! autolingo_check_usage() ) {
                $result['error'] = __( 'The translation credit is used up.', 'autolingo-polylang-translate' );
                break;
            }
            $ok = autolingo_bulk_fix_seo( $r['id'], $r['lang'], $r['seo'] ) > 0;
        }
        if ( $r['slug'] ) {
            autolingo_bulk_fix_slug( $r['id'], $r['freed'] ?? '' );
        }
        if ( $ok ) {
            $result['done']++;
        } else {
            $result['failed'][] = (string) $r['id'];
            $cause = autolingo_last_error();
            if ( $cause !== '' ) $result['error'] = $cause;
        }
        $result['remaining']--;
    }
    return $result;
}

// ── Duplicates ────────────────────────────────────────────────────────────────

/**
 * Posts of a target language linked to no source post, with the same title as a
 * post of that language that is a translation: copies made twice, usually after
 * the link to the first one was lost.
 *
 * @return array<int, int> [ duplicate ID => the linked post with that title ]
 */
function autolingo_bulk_duplicates(): array {
    $ml = autolingo_multilingual();
    if ( ! $ml ) return [];
    $source = autolingo_source_lang();

    $linked = []; // lang => [ post_type|title => ID ]
    foreach ( autolingo_bulk_translation_pairs() as [ , $lang, $id ] ) {
        $post = get_post( $id );
        if ( $post ) $linked[ $lang ][ $post->post_type . '|' . $post->post_title ] = $id;
    }

    $dupes = [];
    if ( ! $linked ) return $dupes;
    // Every language at once ('lang' => '' for Polylang, suppress_filters for WPML), sorted out below.
    $ids = get_posts( [
        'post_type'        => autolingo_post_types(),
        'post_status'      => [ 'publish', 'draft', 'pending', 'future', 'private' ],
        'posts_per_page'   => -1,
        'fields'           => 'ids',
        'lang'             => '',
        'suppress_filters' => true,
    ] );
    foreach ( $ids as $id ) {
        $lang = (string) $ml->get_post_language( (int) $id );
        if ( empty( $linked[ $lang ] ) ) continue;
        $group = $ml->get_post_translations( (int) $id );
        if ( ! empty( $group[ $source ] ) ) continue; // linked to an original
        $post = get_post( $id );
        $key  = $post->post_type . '|' . $post->post_title;
        if ( isset( $linked[ $lang ][ $key ] ) && (int) $linked[ $lang ][ $key ] !== (int) $id ) {
            $dupes[ (int) $id ] = (int) $linked[ $lang ][ $key ];
        }
    }
    return $dupes;
}

/**
 * Moves a duplicate to the trash. When the translation kept got the duplicate's
 * address plus -2, -3… (the duplicate held it), it gets the clean address back,
 * and its -N address redirects to it.
 */
function autolingo_bulk_trash_duplicate( int $id, int $kept ): bool {
    $slug = (string) get_post_field( 'post_name', $id );
    if ( ! wp_trash_post( $id ) ) return false;

    $post = get_post( $kept );
    if ( $slug === '' || ! $post || ! preg_match( '/^' . preg_quote( $slug, '/' ) . '-\d+$/', $post->post_name ) ) return true;
    if ( wp_unique_post_slug( $slug, $kept, $post->post_status, $post->post_type, $post->post_parent ) !== $slug ) return true;

    autolingo_bulk_set_slug( $kept, $slug );
    return true;
}

// ── Old addresses keep working ────────────────────────────────────────────────

add_action( 'template_redirect', 'autolingo_bulk_old_slug_redirect', 5 );

/** A 404 on an address a translation had before AutoLingo translated it: redirect to its new one. */
function autolingo_bulk_old_slug_redirect(): void {
    if ( ! is_404() ) return;
    $path = trim( (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '', PHP_URL_PATH ), '/' );
    if ( $path === '' ) return;
    $parts = explode( '/', $path );
    $slug  = sanitize_title( rawurldecode( (string) end( $parts ) ) );
    if ( $slug === '' ) return;

    $ids = get_posts( [
        'post_type'        => 'any',
        'post_status'      => 'publish',
        'posts_per_page'   => 5,
        'fields'           => 'ids',
        'meta_key'         => AUTOLINGO_OLD_SLUG, // phpcs:ignore WordPress.DB.SlowDBQuery -- only on a 404.
        'meta_value'       => $slug,              // phpcs:ignore WordPress.DB.SlowDBQuery
        'lang'             => '',                 // every language (Polylang)
        'suppress_filters' => true,
    ] );
    if ( ! $ids ) return;

    // Several languages may have had the same address: take the one of the requested language.
    $ml     = autolingo_multilingual();
    $target = (int) $ids[0];
    foreach ( $ids as $id ) {
        $lang = $ml ? $ml->get_post_language( (int) $id ) : '';
        if ( $lang !== '' && in_array( $lang, $parts, true ) ) {
            $target = (int) $id;
            break;
        }
    }
    wp_safe_redirect( get_permalink( $target ), 301 );
    exit;
}

// ── Admin ─────────────────────────────────────────────────────────────────────

add_action( 'wp_ajax_autolingo_bulk', 'autolingo_handle_bulk' );

function autolingo_handle_bulk(): void {
    check_ajax_referer( 'autolingo_bulk' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Permission denied.', 'autolingo-polylang-translate' ) );
    }
    $task    = sanitize_key( wp_unslash( $_POST['task'] ?? '' ) );
    $exclude = array_filter(
        explode( ',', sanitize_text_field( wp_unslash( $_POST['exclude'] ?? '' ) ) ),
        static fn( string $k ): bool => (bool) preg_match( '/^\d+(:[a-z_-]{2,12})?$/', $k )
    );
    switch ( $task ) {
        case 'missing':
            wp_send_json_success( autolingo_bulk_translate_missing( AUTOLINGO_BULK_BUDGET, $exclude ) );
            break;
        case 'repair':
            wp_send_json_success( autolingo_bulk_repair( AUTOLINGO_BULK_BUDGET, $exclude ) );
            break;
        case 'dates':
            wp_send_json_success( [ 'done' => autolingo_bulk_fix_dates(), 'remaining' => 0, 'failed' => [], 'error' => '' ] );
            break;
        case 'duplicates':
            $done = 0;
            foreach ( autolingo_bulk_duplicates() as $id => $kept ) {
                if ( autolingo_bulk_trash_duplicate( $id, $kept ) ) $done++;
            }
            wp_send_json_success( [ 'done' => $done, 'remaining' => 0, 'failed' => [], 'error' => '' ] );
            break;
    }
    // Tasks of other modules (Premium review): array{done, remaining, failed, error}.
    $result = apply_filters( 'autolingo_bulk_task', null, $task, $exclude, (float) AUTOLINGO_BULK_BUDGET );
    if ( is_array( $result ) ) wp_send_json_success( $result );
    wp_send_json_error( 'Unknown task.' );
}

/** Settings section (Tools tab): what is missing or to repair, with a button for each. */
function autolingo_bulk_settings_section(): void {
    if ( ! autolingo_multilingual() ) {
        echo '<p class="description">' . esc_html__( 'Needs Polylang or WPML.', 'autolingo-polylang-translate' ) . '</p>';
        return;
    }

    $missing = autolingo_bulk_missing();
    $pairs   = array_sum( array_map( 'count', $missing ) );
    $repairs = autolingo_bulk_repairs();
    $seo     = count( array_filter( $repairs, static fn( array $r ): bool => (bool) $r['seo'] ) );
    $slugs   = count( array_filter( $repairs, static fn( array $r ): bool => $r['slug'] ) );
    $dupes   = autolingo_bulk_duplicates();
    $dates   = count( autolingo_bulk_dates() );

    $rows = [
        'missing'    => [
            sprintf(
                /* translators: 1: translations to create, 2: posts */
                _n( '%1$d translation missing, for %2$d post or page.', '%1$d translations missing, for %2$d posts and pages.', $pairs, 'autolingo-polylang-translate' ),
                $pairs,
                count( $missing )
            ),
            __( 'Translate what is missing', 'autolingo-polylang-translate' ),
            $pairs,
        ],
        'repair'     => [
            sprintf(
                /* translators: 1: translations with SEO fields left in the source language, 2: translations with the source's address */
                __( 'Translations to repair: %1$d with SEO fields left in the source language, %2$d with an address to fix, the original’s or a -2 left by a duplicate (the old address redirects to the new one).', 'autolingo-polylang-translate' ),
                $seo,
                $slugs
            ),
            __( 'Repair them', 'autolingo-polylang-translate' ),
            count( $repairs ),
        ],
        'dates'      => [
            sprintf(
                /* translators: %d: number of translations */
                _n( '%d translation dated the day it was created instead of its original’s publication date (old articles shown first on the blog).', '%d translations dated the day they were created instead of their original’s publication date (old articles shown first on the blog).', $dates, 'autolingo-polylang-translate' ),
                $dates
            ),
            __( 'Give them their original’s date', 'autolingo-polylang-translate' ),
            $dates,
        ],
        'duplicates' => [
            sprintf(
                /* translators: %d: number of posts */
                _n( '%d duplicate linked to no original (same title as a translation of the same language).', '%d duplicates linked to no original (same title as a translation of the same language).', count( $dupes ), 'autolingo-polylang-translate' ),
                count( $dupes )
            ),
            __( 'Move them to the trash', 'autolingo-polylang-translate' ),
            count( $dupes ),
        ],
    ];

    /**
     * Rows of other modules: task => [ text, button label, count, extra HTML (escaped) ].
     */
    $rows = apply_filters( 'autolingo_bulk_rows', $rows );

    echo '<table class="widefat striped" style="max-width:820px;margin-bottom:8px"><tbody>';
    foreach ( $rows as $task => $row ) {
        [ $text, $button, $count ] = $row;
        $unavailable = ( $row[4] ?? '' ) === 'unavailable'; // the module cannot run here: its text says why
        echo '<tr><td><span class="autolingo-bulk-status" data-task="' . esc_attr( $task ) . '"' . ( $count || $unavailable ? '' : ' style="color:#00a32a"' ) . '>' . esc_html( $count || $unavailable ? $text : __( '✓ Nothing to do', 'autolingo-polylang-translate' ) . ' — ' . $text ) . '</span>';
        if ( $task === 'duplicates' && $dupes ) {
            echo '<details style="margin-top:6px"><summary>' . esc_html__( 'See them', 'autolingo-polylang-translate' ) . '</summary><ul style="margin:6px 0 0 18px;list-style:disc">';
            foreach ( array_slice( $dupes, 0, 50, true ) as $id => $kept ) {
                echo '<li><a href="' . esc_url( (string) get_edit_post_link( $id ) ) . '">#' . (int) $id . ' ' . esc_html( get_the_title( $id ) ) . '</a> — ' . esc_html__( 'kept:', 'autolingo-polylang-translate' ) . ' <a href="' . esc_url( (string) get_edit_post_link( $kept ) ) . '">#' . (int) $kept . '</a></li>';
            }
            echo '</ul></details>';
        }
        if ( ! empty( $row[3] ) ) echo $row[3]; // phpcs:ignore WordPress.Security.EscapeOutput -- built escaped by the module.
        echo '</td><td style="width:240px;text-align:right">';
        printf(
            '<button type="button" class="button%s autolingo-bulk-run" data-task="%s" data-total="%d"%s%s>%s</button>',
            $task === 'missing' ? ' button-primary' : '',
            esc_attr( $task ),
            (int) $count,
            $count ? '' : ' disabled',
            $task === 'duplicates' ? ' data-confirm="' . esc_attr__( 'Move these duplicates to the trash? You can restore them from the trash.', 'autolingo-polylang-translate' ) . '"' : '',
            esc_html( $button )
        );
        echo '</td></tr>';
    }
    echo '</tbody></table>';
    echo '<p class="description">' . esc_html__( 'Each step works for a few seconds at a time, so that no host or cache time limit stops it. You can leave this page open and come back.', 'autolingo-polylang-translate' ) . '</p>';

    $labels = [
        /* translators: 1: done, 2: to do */
        'progress' => __( 'Working… %1$d / %2$d', 'autolingo-polylang-translate' ),
        /* translators: %d: number done */
        'done'     => __( '✓ Done: %d', 'autolingo-polylang-translate' ),
        /* translators: %d: number of failures */
        'failed'   => __( '%d could not be done', 'autolingo-polylang-translate' ),
        'cut'      => __( 'The server cut the request short. What was done is saved: click the button again to carry on.', 'autolingo-polylang-translate' ),
    ];
    wp_print_inline_script_tag( 'window.autolingoBulk=' . wp_json_encode( [ 'labels' => $labels, 'nonce' => wp_create_nonce( 'autolingo_bulk' ) ] ) . ';' . <<<'JS'
(function () {
  var C = window.autolingoBulk, L = C.labels;
  function post(body) {
    return fetch(ajaxurl, { method: "POST", credentials: "same-origin", headers: { "Content-Type": "application/x-www-form-urlencoded" }, body: body })
      .then(function (r) { return r.text(); })
      .then(function (t) { try { return JSON.parse(t); } catch (e) { var err = new Error("cut"); err.cut = true; throw err; } });
  }
  [].forEach.call(document.querySelectorAll(".autolingo-bulk-run"), function (b) {
    b.addEventListener("click", function () {
      if (b.dataset.confirm && !window.confirm(b.dataset.confirm)) return;
      var task = b.dataset.task, s = document.querySelector('.autolingo-bulk-status[data-task="' + task + '"]');
      var total = parseInt(b.dataset.total, 10) || 0, done = 0, failed = [], tries = 0;
      b.disabled = true;
      function step() {
        s.textContent = L.progress.replace("%1$d", done).replace("%2$d", total); s.style.color = "";
        var body = "action=autolingo_bulk&_wpnonce=" + encodeURIComponent(C.nonce) + "&task=" + task + "&exclude=" + encodeURIComponent(failed.join(","));
        post(body).then(function (d) {
          tries = 0;
          if (!d.success) { s.textContent = d.data; s.style.color = "#d63638"; b.disabled = false; return; }
          var r = d.data;
          done += r.done; failed = failed.concat(r.failed || []);
          total = Math.max(total, done + failed.length + Math.max(r.remaining, 0));
          if (!r.error && r.remaining > 0 && (r.done > 0 || (r.failed || []).length)) return step();
          var t = L.done.replace("%d", done);
          if (failed.length) t += " — " + L.failed.replace("%d", failed.length);
          if (r.error) t += " — " + r.error;
          s.textContent = t; s.style.color = r.error ? "#996800" : "#00a32a";
        }, function (e) {
          if (e.cut && tries < 2) { tries++; return step(); }
          s.textContent = e.cut ? L.cut : String(e); s.style.color = "#d63638"; b.disabled = false;
        });
      }
      step();
    });
  });
})();
JS
    );
}

// ── WP-CLI ────────────────────────────────────────────────────────────────────

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    WP_CLI::add_command( 'pdat repair', function ( array $args, array $assoc_args ): void {
        $repairs = autolingo_bulk_repairs();
        foreach ( $repairs as $r ) {
            $what = array_merge( $r['seo'] ? [ 'SEO: ' . implode( ', ', array_keys( $r['seo'] ) ) ] : [], $r['slug'] ? [ 'address: ' . urldecode( get_post_field( 'post_name', $r['id'] ) ) . ( $r['freed'] !== '' ? ' → ' . urldecode( $r['freed'] ) : '' ) ] : [] );
            WP_CLI::line( sprintf( '  #%d %s "%s" — %s', $r['id'], strtoupper( $r['lang'] ), mb_substr( get_the_title( $r['id'] ), 0, 40 ), implode( ' · ', $what ) ) );
        }
        if ( isset( $assoc_args['dry-run'] ) ) {
            WP_CLI::success( count( $repairs ) . ' translation(s) to repair. Dry run.' );
            return;
        }
        $r = autolingo_bulk_repair( 0 );
        $r['error'] !== '' ? WP_CLI::warning( $r['error'] ) : null;
        WP_CLI::success( sprintf( '%d translation(s) repaired, %d failed.', $r['done'], count( $r['failed'] ) ) );
    }, [
        'shortdesc' => 'Translates the SEO fields and addresses that translations still have in the source language (the old address redirects).',
        'synopsis'  => [ [ 'type' => 'flag', 'name' => 'dry-run', 'optional' => true, 'description' => 'Lists the translations to repair.' ] ],
    ] );

    WP_CLI::add_command( 'pdat fix-dates', function ( array $args, array $assoc_args ): void {
        $dates = autolingo_bulk_dates();
        foreach ( $dates as $d ) {
            WP_CLI::line( sprintf( '  #%d %s "%s" — %s → %s', $d['id'], strtoupper( $d['lang'] ), mb_substr( get_the_title( $d['id'] ), 0, 40 ), $d['date'], get_post_field( 'post_date', $d['source'] ) ) );
        }
        if ( isset( $assoc_args['dry-run'] ) ) {
            WP_CLI::success( count( $dates ) . ' translation(s) to date. Dry run.' );
            return;
        }
        WP_CLI::success( autolingo_bulk_fix_dates() . ' translation(s) now carry their original’s publication date.' );
    }, [
        'shortdesc' => 'Gives the translations AutoLingo dated the day they were created their original’s publication date.',
        'synopsis'  => [ [ 'type' => 'flag', 'name' => 'dry-run', 'optional' => true, 'description' => 'Lists the translations to date.' ] ],
    ] );

    WP_CLI::add_command( 'pdat duplicates', function ( array $args, array $assoc_args ): void {
        $dupes = autolingo_bulk_duplicates();
        foreach ( $dupes as $id => $kept ) {
            WP_CLI::line( sprintf( '  #%d "%s" (%s) — kept: #%d', $id, mb_substr( get_the_title( $id ), 0, 50 ), get_post_type( $id ), $kept ) );
        }
        if ( ! isset( $assoc_args['trash'] ) ) {
            WP_CLI::success( count( $dupes ) . ' duplicate(s). Add --trash to move them to the trash.' );
            return;
        }
        $done = 0;
        foreach ( $dupes as $id => $kept ) {
            if ( autolingo_bulk_trash_duplicate( $id, $kept ) ) $done++;
        }
        WP_CLI::success( "$done duplicate(s) moved to the trash." );
    }, [
        'shortdesc' => 'Lists the posts of a target language linked to no original that duplicate a translation; --trash moves them to the trash.',
        'synopsis'  => [ [ 'type' => 'flag', 'name' => 'trash', 'optional' => true, 'description' => 'Moves the duplicates to the trash.' ] ],
    ] );
}
