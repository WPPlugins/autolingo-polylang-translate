<?php
/**
 * WPML adapter — wraps the wpml_* filter/action public API.
 *
 * Uses only the documented WPML String Translation / Multilingual CMS API
 * (apply_filters / do_action), never direct DB queries or internal classes.
 * Requires WPML 4.0+.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class AutoLingo_WPML_Adapter implements AutoLingo_Multilingual_Adapter {

    // ── Detection ─────────────────────────────────────────────────────────────

    public static function is_active(): bool {
        return defined( 'ICL_SITEPRESS_VERSION' ) && function_exists( 'icl_object_id' );
    }

    // ── Post language ─────────────────────────────────────────────────────────

    public function get_post_language( int $post_id ): string {
        $details = apply_filters( 'wpml_post_language_details', null, $post_id );
        return is_array( $details ) ? (string) ( $details['language_code'] ?? '' ) : '';
    }

    public function set_post_language( int $post_id, string $lang_slug ): void {
        do_action( 'wpml_set_element_language_details', [
            'element_id'           => $post_id,
            'element_type'         => 'post_' . get_post_type( $post_id ),
            'trid'                 => false,
            'language_code'        => $lang_slug,
            'source_language_code' => null,
        ] );
    }

    // ── Translation groups ────────────────────────────────────────────────────

    public function get_post_translations( int $post_id ): array {
        $post_type = get_post_type( $post_id );
        $trid      = apply_filters( 'wpml_element_trid', null, $post_id, 'post_' . $post_type );
        if ( ! $trid ) return [];

        $raw = apply_filters( 'wpml_get_element_translations', [], $trid, 'post_' . $post_type );
        if ( ! is_array( $raw ) ) return [];

        $result = [];
        foreach ( $raw as $lang => $info ) {
            if ( isset( $info->element_id ) ) {
                $result[ $lang ] = (int) $info->element_id;
            }
        }
        return $result;
    }

    public function save_post_translations(
        int    $source_id,
        int    $translated_id,
        string $translated_lang_slug,
        string $source_lang_slug
    ): void {
        $post_type   = get_post_type( $source_id );
        $source_trid = apply_filters( 'wpml_element_trid', null, $source_id, 'post_' . $post_type );

        do_action( 'wpml_set_element_language_details', [
            'element_id'           => $translated_id,
            'element_type'         => 'post_' . $post_type,
            'trid'                 => $source_trid ?: false,
            'language_code'        => $translated_lang_slug,
            'source_language_code' => $source_lang_slug,
        ] );
    }

    public function create_translated_post( WP_Post $source, string $lang_slug, array $data ): int {
        // WPML has no single-call "create translated post" API equivalent to PLL().
        // We create the post normally and then link it via save_post_translations().
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

        // Prevent autolingo_on_save_post from re-entering and trying to translate
        // the newly created translation (matching what autolingo_create_translation does).
        remove_action( 'save_post', 'autolingo_on_save_post', 20 );
        $new_id = autolingo_save_post( $postarr );
        add_action( 'save_post', 'autolingo_on_save_post', 20, 3 );

        if ( is_wp_error( $new_id ) || ! $new_id ) return 0;

        $source_lang = $this->get_post_language( $source->ID );

        $this->save_post_translations( $source->ID, $new_id, $lang_slug, $source_lang );

        return $new_id;
    }

    // ── Look-ups ──────────────────────────────────────────────────────────────

    public function get_translated_post( int $post_id, string $lang_slug ): int {
        return (int) apply_filters( 'wpml_object_id', $post_id, get_post_type( $post_id ), false, $lang_slug );
    }

    public function get_translated_term( int $term_id, string $lang_slug, string $taxonomy = 'category' ): int {
        return (int) apply_filters( 'wpml_object_id', $term_id, $taxonomy, false, $lang_slug );
    }

    public function get_home_url( string $lang_slug ): string {
        // wpml_home_url always returns the current language URL.
        // Use wpml_permalink to get a language-specific URL.
        $url = apply_filters( 'wpml_home_url', home_url( '/' ) );

        // Switch to the target language to build the correct home URL.
        $current = apply_filters( 'wpml_current_language', null );
        do_action( 'wpml_switch_language', $lang_slug );
        $lang_url = apply_filters( 'wpml_home_url', home_url( '/' ) );
        do_action( 'wpml_switch_language', $current );

        return $lang_url ?: $url;
    }

    // ── Query helpers ─────────────────────────────────────────────────────────

    public function get_source_post_ids( array $post_types, string $source_lang ): array {
        // Switch WPML language context so WP_Query returns only $source_lang posts.
        $current = apply_filters( 'wpml_current_language', null );
        do_action( 'wpml_switch_language', $source_lang );

        $ids = (array) get_posts( [
            'post_type'           => $post_types,
            'post_status'         => 'publish',
            'posts_per_page'      => -1,
            'fields'              => 'ids',
            'suppress_filters'    => false, // let WPML apply its language filter
        ] );

        do_action( 'wpml_switch_language', $current );

        return $ids;
    }
}
