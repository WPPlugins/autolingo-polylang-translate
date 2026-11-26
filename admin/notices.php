<?php
/**
 * Admin — what the free version leaves out, said where it applies: AutoLingo's box
 * in the post editor and its settings page. (The review request is in hooks.php.)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** Pricing page on auto-lingo.com, tagged with where the click came from. */
function autolingo_pro_url( string $medium ): string {
    return 'https://auto-lingo.com/wordpress?utm_source=plugin&utm_medium=' . rawurlencode( $medium ) . '&utm_campaign=upgrade#pricing';
}

/** The 14-day free trial of Pro: auto-lingo.com opens the checkout in trial mode (no card). */
function autolingo_trial_url( string $medium ): string {
    return 'https://auto-lingo.com/wordpress?trial=1&utm_source=plugin&utm_medium=' . rawurlencode( $medium ) . '&utm_campaign=trial#pricing';
}

/** True in the free version (the Pro modules are not in the plugin). */
function autolingo_is_free_build(): bool {
    return ! function_exists( 'autolingo_run_premium_modules' );
}

/** The page builder a post is built with ('' = none), for the free version's notice. */
function autolingo_post_builder( int $post_id ): string {
    if ( 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true ) ) return 'Elementor';
    if ( ! empty( get_post_meta( $post_id, '_bricks_page_content_2', true ) ) ) return 'Bricks';
    if ( '1' === get_post_meta( $post_id, '_fl_builder_enabled', true ) ) return 'Beaver Builder';
    if ( '' !== (string) get_post_meta( $post_id, '_breakdance_data', true ) ) return 'Breakdance';
    if ( '' !== (string) get_post_meta( $post_id, '_oxygen_data', true ) ) return 'Oxygen';
    if ( 'on' === get_post_meta( $post_id, '_et_pb_use_builder', true ) ) return 'Divi';
    if ( str_contains( (string) get_post_field( 'post_content', $post_id ), '<!-- wp:divi/' ) ) return 'Divi 5';
    return '';
}

/** Active plugins whose content only AutoLingo Pro translates. */
function autolingo_active_pro_integrations(): array {
    $active = [];
    if ( defined( 'ELEMENTOR_VERSION' ) )                 $active[] = 'Elementor';
    if ( 'bricks' === get_template() )                    $active[] = 'Bricks';
    if ( class_exists( 'FLBuilder' ) )                    $active[] = 'Beaver Builder';
    if ( defined( '__BREAKDANCE_VERSION' ) )              $active[] = 'Breakdance';
    if ( defined( 'ET_BUILDER_VERSION' ) || 'Divi' === get_template() ) $active[] = 'Divi';
    if ( defined( 'WPSEO_VERSION' ) )                     $active[] = 'Yoast SEO';
    if ( class_exists( 'RankMath' ) )                     $active[] = 'Rank Math';
    if ( defined( 'SEOPRESS_VERSION' ) )                  $active[] = 'SEOPress';
    if ( defined( 'THE_SEO_FRAMEWORK_VERSION' ) )         $active[] = 'The SEO Framework';
    if ( class_exists( 'ACF' ) )                          $active[] = 'ACF';
    if ( class_exists( 'WooCommerce' ) )                  $active[] = 'WooCommerce';
    if ( defined( 'WPCF7_VERSION' ) )                     $active[] = 'Contact Form 7';
    return $active;
}

// ── Free version: one notice when the site uses what Pro translates ─────────
// Shown once AutoLingo has translated a few posts (the user has seen it work), only to
// admins, only on the dashboard, the plugins list, the post lists and AutoLingo's page,
// never at the same time as the review request; "No thanks" hides it for good.

add_action( 'admin_notices', 'autolingo_pro_notice' );

function autolingo_pro_notice(): void {
    if ( ! autolingo_is_free_build() || ! current_user_can( 'manage_options' ) ) return;
    if ( get_option( 'autolingo_pro_notice_dismissed' ) ) return;
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen || ! in_array( $screen->base, [ 'dashboard', 'plugins', 'edit', 'settings_page_autolingo-polylang-translate' ], true ) ) return;

    $active = autolingo_active_pro_integrations();
    if ( ! $active ) return;
    $count = (int) ( autolingo_log_get( [ 'level' => 'success', 'limit' => 1 ] )['total'] ?? 0 );
    if ( $count < (int) apply_filters( 'autolingo_pro_notice_threshold', 3 ) ) return;
    // The review request takes the place first (it shows from 20 translations).
    if ( ! get_option( 'autolingo_review_notice_dismissed' ) && $count >= (int) apply_filters( 'autolingo_review_notice_threshold', 20 ) ) return;

    $dismiss_url = wp_nonce_url( admin_url( 'admin-post.php?action=autolingo_dismiss_pro_notice' ), 'autolingo_dismiss_pro_notice' );
    echo '<div class="notice notice-info"><p>🌍 <strong>AutoLingo</strong> — ' . esc_html( sprintf(
        /* translators: %s: plugin names, e.g. "Elementor, WooCommerce" */
        __( 'This site uses %s: the free version translates the WordPress content but not theirs. AutoLingo Pro translates them too, with the same API key.', 'autolingo-polylang-translate' ),
        implode( ', ', array_slice( $active, 0, 3 ) )
    ) )
        . ' <a href="' . esc_url( autolingo_trial_url( 'notice' ) ) . '" target="_blank" rel="noopener" class="button button-primary" style="margin-left:8px;vertical-align:baseline;">'
        . esc_html__( 'Try Pro free for 14 days', 'autolingo-polylang-translate' ) . '</a>'
        . ' <a href="' . esc_url( $dismiss_url ) . '" style="margin-left:8px;">' . esc_html__( 'No thanks', 'autolingo-polylang-translate' ) . '</a>'
        . '</p></div>';
}

add_action( 'admin_post_autolingo_dismiss_pro_notice', 'autolingo_dismiss_pro_notice' );

function autolingo_dismiss_pro_notice(): void {
    check_admin_referer( 'autolingo_dismiss_pro_notice' );
    if ( current_user_can( 'manage_options' ) ) {
        update_option( 'autolingo_pro_notice_dismissed', 1 );
    }
    wp_safe_redirect( wp_get_referer() ?: admin_url() );
    exit;
}
