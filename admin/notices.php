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

/** The page builder a post is built with ('' = none), for the free version's notice. */
function autolingo_post_builder( int $post_id ): string {
    if ( 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true ) ) return 'Elementor';
    if ( ! empty( get_post_meta( $post_id, '_bricks_page_content_2', true ) ) ) return 'Bricks';
    if ( '1' === get_post_meta( $post_id, '_fl_builder_enabled', true ) ) return 'Beaver Builder';
    return '';
}

/** Active plugins whose content only AutoLingo Pro translates. */
function autolingo_active_pro_integrations(): array {
    $active = [];
    if ( defined( 'ELEMENTOR_VERSION' ) )                 $active[] = 'Elementor';
    if ( 'bricks' === get_template() )                    $active[] = 'Bricks';
    if ( class_exists( 'FLBuilder' ) )                    $active[] = 'Beaver Builder';
    if ( defined( 'WPSEO_VERSION' ) )                     $active[] = 'Yoast SEO';
    if ( class_exists( 'RankMath' ) )                     $active[] = 'Rank Math';
    if ( defined( 'SEOPRESS_VERSION' ) )                  $active[] = 'SEOPress';
    if ( defined( 'THE_SEO_FRAMEWORK_VERSION' ) )         $active[] = 'The SEO Framework';
    if ( class_exists( 'ACF' ) )                          $active[] = 'ACF';
    if ( class_exists( 'WooCommerce' ) )                  $active[] = 'WooCommerce';
    return $active;
}
