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
            'post_status'    => autolingo_new_translation_status( $source->post_type ),
            'post_type'      => $source->post_type,
            'post_title'     => $data['title']   ?? $source->post_title,
            'post_content'   => $data['content'] ?? $source->post_content,
            'post_excerpt'   => $data['excerpt'] ?? $source->post_excerpt,
            'comment_status' => $source->comment_status,
            'ping_status'    => $source->ping_status,
            'menu_order'     => $source->menu_order,
            'post_parent'    => $source->post_parent,
        ] + autolingo_source_dates( $source );

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

    /** Polylang keeps no translation status. */
    public function mark_translation_current( int $source_id, int $translated_id ): void {}

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

    // ── Menus ─────────────────────────────────────────────────────────────────

    /**
     * Polylang shows one menu per language in each theme location (Appearance → Menus → Manage
     * Locations, its setting nav_menus[theme][location][language]). The translated menu takes the
     * locations of its source menu; a location that already has a menu for that language keeps it.
     */
    public function link_menu_translation( int $source_menu_id, int $menu_id, string $lang_slug, string $source_lang_slug ): void {
        if ( ! function_exists( 'PLL' ) || ! PLL() || ! $menu_id ) return;
        $options = PLL()->options ?? null;
        $menus   = (array) ( $options ? ( $options['nav_menus'] ?? [] ) : ( (array) get_option( 'polylang', [] ) )['nav_menus'] ?? [] );
        $theme   = get_stylesheet();
        $current = (array) ( $menus[ $theme ] ?? [] );
        $raw     = (array) get_theme_mod( 'nav_menu_locations', [] );
        $changed = false;

        foreach ( array_keys( get_registered_nav_menus() ) as $location ) {
            $source_here = (int) ( $current[ $location ][ $source_lang_slug ] ?? $raw[ $location ] ?? 0 );
            if ( $source_here !== $source_menu_id || ! empty( $current[ $location ][ $lang_slug ] ) ) continue;
            $current[ $location ][ $source_lang_slug ] = $source_menu_id;
            $current[ $location ][ $lang_slug ]        = $menu_id;
            $changed = true;
        }
        if ( ! $changed ) return;

        $menus[ $theme ] = $current;
        if ( $options && method_exists( $options, 'set' ) ) {
            $options->set( 'nav_menus', $menus );
            if ( method_exists( $options, 'save' ) ) $options->save();
        } else {
            $all              = (array) get_option( 'polylang', [] );
            $all['nav_menus'] = $menus;
            update_option( 'polylang', $all );
        }
    }

    public function switch_language( string $lang_slug ): string {
        return ''; // Polylang does not remap IDs to a "current language" when saving.
    }

    public function get_default_language(): string {
        return function_exists( 'pll_default_language' ) ? (string) pll_default_language( 'slug' ) : '';
    }

    public function get_languages(): array {
        return function_exists( 'pll_languages_list' ) ? array_map( 'strval', (array) pll_languages_list( [ 'fields' => 'slug' ] ) ) : [];
    }
}
