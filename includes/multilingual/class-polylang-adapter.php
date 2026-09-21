<?php
/**
 * Polylang adapter — wraps the pll_* public API and PLL() internal model.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class AutoLingo_Polylang_Adapter implements AutoLingo_Multilingual_Adapter {

    // ── Detection ─────────────────────────────────────────────────────────────

    public static function is_active(): bool {
        return function_exists( 'pll_get_post_language' );
    }

    // ── Post language ─────────────────────────────────────────────────────────

    public function get_post_language( int $post_id ): string {
        return (string) ( pll_get_post_language( $post_id ) ?: '' );
    }

    public function set_post_language( int $post_id, string $lang_slug ): void {
        pll_set_post_language( $post_id, $lang_slug );
    }

    // ── Translation groups ────────────────────────────────────────────────────

    public function get_post_translations( int $post_id ): array {
        return (array) pll_get_post_translations( $post_id );
    }

    public function save_post_translations(
        int    $source_id,
        int    $translated_id,
        string $translated_lang_slug,
        string $source_lang_slug
    ): void {
        $group = pll_get_post_translations( $source_id );
        $group[ $translated_lang_slug ] = $translated_id;
        $group[ $source_lang_slug ]     = $source_id;
        pll_save_post_translations( $group );
    }

    public function create_translated_post( WP_Post $source, string $lang_slug, array $data ): int {
        $pll_post = PLL()->model->post ?? null;
        if ( ! $pll_post ) return 0;

        $postarr = [
            'post_author'    => $source->post_author,
            'post_status'    => 'publish',
            'post_type'      => $source->post_type,
            'post_title'     => $data['title']   ?? $source->post_title,
            'post_content'   => $data['content'] ?? $source->post_content,
            'post_excerpt'   => $data['excerpt'] ?? $source->post_excerpt,
            'comment_status' => $source->comment_status,
            'ping_status'    => $source->ping_status,
            'menu_order'     => $source->menu_order,
            'post_parent'    => $source->post_parent,
        ];

        $new_id = autolingo_save_post( $postarr );
        if ( is_wp_error( $new_id ) || ! $new_id ) return 0;

        $language = PLL()->model->get_language( $lang_slug );
        if ( ! $language ) {
            wp_delete_post( $new_id, true );
            return 0;
        }

        $pll_post->set_language( $new_id, $language );

        $group = $pll_post->get_translations( $source->ID );
        $group[ $lang_slug ]                    = $new_id;
        $group[ pll_get_post_language( $source->ID ) ] = $source->ID;
        $pll_post->save_translations( $new_id, $group );

        return $new_id;
    }

    // ── Look-ups ──────────────────────────────────────────────────────────────

    public function get_translated_post( int $post_id, string $lang_slug ): int {
        return (int) ( function_exists( 'pll_get_post' ) ? ( pll_get_post( $post_id, $lang_slug ) ?: 0 ) : 0 );
    }

    public function get_translated_term( int $term_id, string $lang_slug, string $taxonomy = 'category' ): int {
        return (int) ( function_exists( 'pll_get_term' ) ? ( pll_get_term( $term_id, $lang_slug ) ?: 0 ) : 0 );
    }

    public function set_term_language( int $term_id, string $taxonomy, string $lang_slug ): void {
        $pll_term = PLL()->model->term ?? null;
        if ( ! $pll_term ) return;

        $language = PLL()->model->get_language( $lang_slug );
        if ( ! $language ) return;

        $pll_term->set_language( $term_id, $language );
    }

    public function save_term_translations(
        int    $source_term_id,
        int    $translated_term_id,
        string $taxonomy,
        string $translated_lang_slug,
        string $source_lang_slug
    ): void {
        $pll_term = PLL()->model->term ?? null;
        if ( ! $pll_term ) return;

        $group = $pll_term->get_translations( $source_term_id );
        $group[ $translated_lang_slug ] = $translated_term_id;
        $group[ $source_lang_slug ]     = $source_term_id;
        $pll_term->save_translations( $translated_term_id, $group );
    }

    public function get_home_url( string $lang_slug ): string {
        return function_exists( 'pll_home_url' ) ? (string) pll_home_url( $lang_slug ) : home_url();
    }

    // ── Query helpers ─────────────────────────────────────────────────────────

    public function get_source_post_ids( array $post_types, string $source_lang ): array {
        return (array) get_posts( [
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'lang'           => $source_lang,   // Polylang WP_Query arg
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ] );
    }
}
