<?php
/**
 * Hooks — actions et filtres WordPress.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── 1. Traduction automatique à la publication ─────────────

// Cron hook for async translation (scheduled by autolingo_maybe_translate).
add_action( 'autolingo_async_translate', 'autolingo_translate_post' );

add_action( 'admin_init', 'autolingo_setup_languages_from_site' );
add_action( 'save_post', 'autolingo_on_save_post', 20, 3 );
add_action( 'save_post', 'autolingo_on_save_post_late', 101, 3 );
add_action( 'load-post.php', 'autolingo_keep_new_translation_links', 5 );

/**
 * The block editor saves the meta boxes in a second request, after the REST save that published
 * the post, with the forms as they were when the editor opened. Polylang's Languages box then
 * re-saves the links it showed (load-post.php, priority 10): none for the translation AutoLingo
 * has just created, so Polylang unlinked it and save_post translated the post a second time.
 * Put the links created since then back into that form first.
 */
function autolingo_keep_new_translation_links(): void {
    // phpcs:disable WordPress.Security.NonceVerification -- Polylang's own form fields, its handler checks its nonce.
    if ( empty( $_GET['meta-box-loader'] ) || ! isset( $_POST['post_ID'], $_POST['post_tr_lang'] ) || ! is_array( $_POST['post_tr_lang'] ) ) return;

    $new_links = get_transient( 'autolingo_new_translations_' . absint( $_POST['post_ID'] ) );
    if ( ! is_array( $new_links ) ) return;

    foreach ( $new_links as $lang_slug => $tr_id ) {
        if ( empty( $_POST['post_tr_lang'][ $lang_slug ] ) && get_post( $tr_id ) ) {
            $_POST['post_tr_lang'][ $lang_slug ] = (int) $tr_id;
        }
    }
    // phpcs:enable
}

/**
 * Posts whose language was not assigned yet when autolingo_on_save_post ran.
 * WPML assigns a new post's language in save_post at priority 100, so a post created
 * and published in one request (REST API, WP-CLI, importers) has none at priority 20.
 */
function autolingo_pending_language( ?int $post_id = null, bool $remove = false ): bool {
    static $pending = [];
    if ( $post_id === null ) return false;
    if ( $remove ) {
        $was = isset( $pending[ $post_id ] );
        unset( $pending[ $post_id ] );
        return $was;
    }
    $pending[ $post_id ] = true;
    return true;
}

function autolingo_on_save_post_late( int $post_id, WP_Post $post, bool $update ): void {
    if ( autolingo_pending_language( $post_id, true ) ) {
        autolingo_on_save_post( $post_id, get_post( $post_id ) ?: $post, $update, true );
    }
}

function autolingo_on_save_post( int $post_id, WP_Post $post, bool $update, bool $late = false ): void {
    if ( ! autolingo_auto_translate_enabled() )                          return;
    if ( wp_is_post_revision( $post_id ) )                         return;
    if ( wp_is_post_autosave( $post_id ) )                         return;
    if ( ! in_array( $post->post_type, autolingo_post_types(), true ) )  return;
    if ( $post->post_status !== 'publish' )                        return;
    $ml = autolingo_multilingual();
    if ( ! $ml ) return;
    $post_lang = $ml->get_post_language( $post_id );
    if ( $post_lang === '' && ! $late ) {
        autolingo_pending_language( $post_id ); // language not assigned yet: retry once it is (priority 101)
        return;
    }
    if ( $post_lang !== autolingo_source_lang() ) return;

    // Elementor saves _elementor_data in a separate request after save_post.
    // Let elementor/document/after_save handle those posts instead.
    if ( function_exists( 'autolingo_is_elementor_post' ) && autolingo_is_elementor_post( $post_id ) ) return;

    // Verrou statique — bloque les appels récursifs dans la même requête PHP
    static $in_progress = [];
    if ( isset( $in_progress[ $post_id ] ) ) return;
    $in_progress[ $post_id ] = true;

    // Verrou transient — bloque les doublons inter-requêtes (Gutenberg REST)
    $lock_key = 'autolingo_lock_' . $post_id;
    if ( get_transient( $lock_key ) ) {
        unset( $in_progress[ $post_id ] );
        return;
    }
    set_transient( $lock_key, 1, 30 );

    autolingo_maybe_translate( $post_id );

    delete_transient( $lock_key );
    unset( $in_progress[ $post_id ] );
}

// Elementor saves _elementor_data after save_post via its own pipeline.
// This hook fires once Elementor has written all its data, so we translate fresh content.
add_action( 'elementor/document/after_save', 'autolingo_on_elementor_document_save', 20, 2 );

function autolingo_on_elementor_document_save( $document, array $data ): void {
    if ( ! autolingo_is_premium() ) return;
    if ( ! autolingo_auto_translate_enabled() ) return;

    $post_id = $document->get_post()->ID;
    $post    = get_post( $post_id );

    if ( ! $post ) return;
    if ( wp_is_post_revision( $post_id ) )                         return;
    if ( ! in_array( $post->post_type, autolingo_post_types(), true ) )  return;
    if ( $post->post_status !== 'publish' )                        return;
    $ml = autolingo_multilingual();
    if ( ! $ml || $ml->get_post_language( $post_id ) !== autolingo_source_lang() ) return;

    static $in_progress = [];
    if ( isset( $in_progress[ $post_id ] ) ) return;
    $in_progress[ $post_id ] = true;

    $lock_key = 'autolingo_lock_elementor_' . $post_id;
    if ( get_transient( $lock_key ) ) {
        unset( $in_progress[ $post_id ] );
        return;
    }
    set_transient( $lock_key, 1, 30 );

    autolingo_maybe_translate( $post_id );

    delete_transient( $lock_key );
    unset( $in_progress[ $post_id ] );
}

/**
 * Translate synchronously or schedule an async WP-Cron job, depending on
 * the configured threshold and whether WP-Cron is available.
 *
 * Async is skipped when:
 *   - threshold is 0 (always sync)
 *   - post_content is shorter than the threshold
 *   - DISABLE_WP_CRON is true (server-side cron, no loopback available)
 */
function autolingo_maybe_translate( int $post_id ): void {
    $threshold = autolingo_async_threshold();
    $post      = get_post( $post_id );

    $use_async = autolingo_async_enabled()
        && $threshold > 0
        && $post
        && strlen( $post->post_content ) >= $threshold
        && ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON );

    if ( $use_async ) {
        wp_schedule_single_event( time(), 'autolingo_async_translate', [ $post_id ] );
        spawn_cron(); // fire pending cron jobs immediately (best-effort)
    } else {
        autolingo_translate_post( $post_id );
    }
}

// ── 2. Bouton dans la liste des posts/pages ────────────────

add_filter( 'post_row_actions', 'autolingo_add_row_action', 10, 2 );
add_filter( 'page_row_actions', 'autolingo_add_row_action', 10, 2 );

function autolingo_add_row_action( array $actions, WP_Post $post ): array {
    if ( ! in_array( $post->post_type, autolingo_post_types(), true ) ) return $actions;
    $ml = autolingo_multilingual();
    if ( ! $ml || $ml->get_post_language( $post->ID ) !== autolingo_source_lang() ) return $actions;

    $langs = implode( '/', array_map( 'strtoupper', autolingo_target_langs() ) );
    $url   = wp_nonce_url(
        admin_url( 'admin-post.php?action=autolingo_translate&post_id=' . $post->ID . '&post_type=' . $post->post_type ),
        'autolingo_translate_' . $post->ID
    );
    $actions['autolingo_translate'] = '<a href="' . esc_url( $url ) . '" style="color:#2271b1;">🌍 '
        . sprintf(
            /* translators: %s: target language codes, e.g. "EN/DE" */
            esc_html__( 'Translate %s', 'autolingo-polylang-translate' ),
            esc_html( $langs )
        )
        . '</a>';
    return $actions;
}

// ── 3. Handler du bouton manuel ────────────────────────────

add_action( 'admin_post_autolingo_translate', 'autolingo_handle_manual_translate' );

function autolingo_handle_manual_translate(): void {
    $post_id   = intval( $_GET['post_id']   ?? 0 );
    $post_type = sanitize_key( $_GET['post_type'] ?? 'post' );
    if ( ! $post_id ) wp_die( esc_html__( 'Missing post ID.', 'autolingo-polylang-translate' ) );

    check_admin_referer( 'autolingo_translate_' . $post_id );
    if ( ! current_user_can( 'edit_post', $post_id ) ) wp_die( esc_html__( 'Permission denied.', 'autolingo-polylang-translate' ) );

    $ml = autolingo_multilingual();
    if ( ! $ml ) {
        wp_die( esc_html__( 'No multilingual plugin detected (Polylang or WPML required).', 'autolingo-polylang-translate' ) );
    }

    $result = autolingo_translate_post( $post_id );

    if ( $post_type === 'page' ) {
        $redirect = admin_url( 'edit.php?post_type=page' );
    } else {
        $redirect = admin_url( 'edit.php' );
    }

    $lang = $ml->get_post_language( $post_id );
    if ( $lang ) {
        $redirect = add_query_arg( 'lang', $lang, $redirect );
    }

    set_transient( 'autolingo_admin_notice_' . get_current_user_id(), $result ? 'success' : 'error', 60 );


    wp_safe_redirect( $redirect );
    exit;
}

// ── 4. Synchronisation SEO après mise à jour d'un champ meta SEO ──────────
// Yoast, RankMath et SEOPress écrivent leur meta via update_post_meta
// APRÈS que save_post et elementor/document/after_save se déclenchent.
// Ce hook corrige les traductions dès qu'une clé SEO surveillée est modifiée.

add_action( 'updated_post_meta', 'autolingo_on_seo_meta_updated', 20, 3 );

function autolingo_on_seo_meta_updated( $meta_id, $post_id, $meta_key ): void {
    static $done = [];

    if ( ! function_exists( 'autolingo_seo_translatable_fields' ) ) return;
    if ( ! in_array( $meta_key, autolingo_seo_translatable_fields(), true ) ) return;
    if ( isset( $done[ $post_id ] ) ) return;
    $done[ $post_id ] = true;

    if ( ! autolingo_auto_translate_enabled() ) return;
    if ( ! autolingo_module_enabled( 'seo' ) ) return;
    if ( ! autolingo_is_premium() ) return;

    $ml = autolingo_multilingual();
    if ( ! $ml ) return;

    $post = get_post( $post_id );
    if ( ! $post || $post->post_status !== 'publish' ) return;
    if ( $ml->get_post_language( $post_id ) !== autolingo_source_lang() ) return;

    $translations = $ml->get_post_translations( $post_id );
    foreach ( autolingo_target_langs() as $lang_slug ) {
        if ( empty( $translations[ $lang_slug ] ) ) continue;
        if ( function_exists( 'autolingo_seo_translate' ) ) {
            autolingo_seo_translate( $post_id, $translations[ $lang_slug ], strtoupper( $lang_slug ) );
        }
    }
}

// ── 6. Notices admin ──────────────────────────────────────

add_action( 'admin_notices', 'autolingo_admin_notices' );

function autolingo_admin_notices(): void {
    $uid    = get_current_user_id();
    $notice = get_transient( 'autolingo_admin_notice_' . $uid );
    if ( $notice ) {
        delete_transient( 'autolingo_admin_notice_' . $uid );
        if ( $notice === 'success' ) {
            echo '<div class="notice notice-success is-dismissible"><p>✅ <strong>AutoLingo</strong> — '
                . esc_html__( 'Translations created successfully.', 'autolingo-polylang-translate' )
                . '</p></div>';
        } else {
            echo '<div class="notice notice-error is-dismissible"><p>❌ <strong>AutoLingo</strong> — '
                . sprintf(
                    /* translators: %s: link to settings page */
                    esc_html__( 'Translation error. Check your %s and the logs.', 'autolingo-polylang-translate' ),
                    '<a href="' . esc_url( admin_url( 'options-general.php?page=autolingo-polylang-translate' ) ) . '">'
                        . esc_html__( 'API key', 'autolingo-polylang-translate' )
                        . '</a>'
                )
                . '</p></div>';
        }
    }

    $remaining = get_option( 'autolingo_low_credit_alert' );
    if ( $remaining ) {
        $pct = round( ( $remaining / 1000000 ) * 100, 1 );
        echo '<div class="notice notice-warning is-dismissible"><p>⚠️ <strong>AutoLingo</strong> — '
            . sprintf(
                /* translators: 1: formatted remaining characters, 2: percentage */
                esc_html__( 'Low DeepL credit: %1$s characters remaining (%2$s%%).', 'autolingo-polylang-translate' ),
                '<strong>' . esc_html( number_format( (int) $remaining, 0, ',', "\u{202F}" ) ) . '</strong>',
                esc_html( (string) $pct )
            )
            . '</p></div>';
    }
}

// ── 7. Invite à laisser un avis WP.org ─────────────────────
// Le classement de recherche WP.org dépend davantage de la note moyenne que
// du nombre d'installations actives — voir roadmap "Collecte active d'avis".

add_action( 'admin_notices', 'autolingo_review_notice' );

function autolingo_review_notice(): void {
    if ( ! current_user_can( 'manage_options' ) )      return;
    if ( get_option( 'autolingo_review_notice_dismissed' ) ) return;

    $threshold = (int) apply_filters( 'autolingo_review_notice_threshold', 20 );
    $count     = autolingo_log_get( [ 'level' => 'success', 'limit' => 1 ] )['total'] ?? 0;
    if ( $count < $threshold ) return;

    $dismiss_url = wp_nonce_url(
        admin_url( 'admin-post.php?action=autolingo_dismiss_review_notice' ),
        'autolingo_dismiss_review_notice'
    );

    echo '<div class="notice notice-info is-dismissible"><p>🌍 <strong>AutoLingo</strong> — ';
    printf(
        /* translators: %d: number of posts translated so far */
        esc_html__( 'AutoLingo has translated %d posts for you so far. If it&#8217;s saving you time, a quick review helps other WordPress users find it.', 'autolingo-polylang-translate' ),
        (int) $count
    );
    echo ' <a href="https://wordpress.org/support/plugin/autolingo-polylang-translate/reviews/#new-post" target="_blank" rel="noopener noreferrer" class="button button-primary" style="margin-left:8px;vertical-align:baseline;">'
        . esc_html__( 'Leave a review', 'autolingo-polylang-translate' ) . '</a>'
        . ' <a href="' . esc_url( $dismiss_url ) . '" style="margin-left:8px;">'
        . esc_html__( 'No thanks', 'autolingo-polylang-translate' ) . '</a>';
    echo '</p></div>';
}

add_action( 'admin_post_autolingo_dismiss_review_notice', 'autolingo_dismiss_review_notice' );

function autolingo_dismiss_review_notice(): void {
    check_admin_referer( 'autolingo_dismiss_review_notice' );
    if ( current_user_can( 'manage_options' ) ) {
        update_option( 'autolingo_review_notice_dismissed', 1 );
    }
    wp_safe_redirect( wp_get_referer() ?: admin_url() );
    exit;
}
