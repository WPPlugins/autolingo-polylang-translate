<?php
/**
 * WPML adapter — wraps the wpml_* filter/action public API.
 *
 * Uses only the documented WPML String Translation / Multilingual CMS API
 * (apply_filters / do_action), never direct DB queries or internal classes.
 * Requires WPML 4.0+.
 *
 * Language codes: WPML codes can carry a region or script (en-us, pt-br,
 * zh-hans — WPML 5 names English "en-us" by default), while AutoLingo's
 * settings use base codes (en, pt, zh). The adapter speaks AutoLingo codes to
 * the rest of the plugin and WPML codes to WPML: to_wpml() / from_wpml().
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class AutoLingo_WPML_Adapter implements AutoLingo_Multilingual_Adapter {

    // ── Detection ─────────────────────────────────────────────────────────────

    public static function is_active(): bool {
        return defined( 'ICL_SITEPRESS_VERSION' ) && function_exists( 'icl_object_id' );
    }

    // ── Language codes ────────────────────────────────────────────────────────

    /** WPML code → AutoLingo code: "en-us" → "en", "pt-br" → "pt", "fr" → "fr". */
    public function from_wpml( string $code ): string {
        return strtolower( explode( '-', str_replace( '_', '-', $code ) )[0] );
    }

    /** AutoLingo code → the active WPML language it stands for ("en" → "en-us"). */
    public function to_wpml( string $code ): string {
        $code   = strtolower( $code );
        $active = array_keys( (array) apply_filters( 'wpml_active_languages', null, [ 'skip_missing' => 0 ] ) );
        if ( in_array( $code, $active, true ) ) return $code;
        foreach ( $active as $wpml_code ) {
            if ( $this->from_wpml( (string) $wpml_code ) === $code ) return (string) $wpml_code;
        }
        return $code;
    }

    /**
     * WPML identifies a term by its term_taxonomy_id in its element API
     * (wpml_set_element_language_details, wpml_element_trid…), but wpml_object_id
     * takes and returns term_id. Both are equal on a fresh install only.
     */
    private function term_element_id( int $term_id, string $taxonomy ): int {
        $term = get_term( $term_id, $taxonomy );
        return ( $term && ! is_wp_error( $term ) ) ? (int) $term->term_taxonomy_id : $term_id;
    }

    /**
     * Set an element's language without moving it out of its translation group:
     * WPML reads 'trid' => false as "start a new group", which detached a
     * translation from its source every time its language was re-asserted.
     */
    private function set_element_language( int $element_id, string $element_type, string $lang_slug ): void {
        // Read group and source from the element's own record. wpml_element_trid can
        // answer for another element type sharing the ID when this one has no record
        // yet (a term just created), which filed it in an unrelated group.
        $details = apply_filters( 'wpml_element_language_details', null, [ 'element_id' => $element_id, 'element_type' => $element_type ] );
        $trid    = ( $details && (int) ( $details->element_id ?? $element_id ) === $element_id ) ? ( $details->trid ?? false ) : false;

        do_action( 'wpml_set_element_language_details', [
            'element_id'           => $element_id,
            'element_type'         => $element_type,
            'trid'                 => $trid ?: false,
            'language_code'        => $this->to_wpml( $lang_slug ),
            'source_language_code' => $details->source_language_code ?? null,
        ] );
    }

    // ── Post language ─────────────────────────────────────────────────────────

    public function get_post_language( int $post_id ): string {
        $details = apply_filters( 'wpml_post_language_details', null, $post_id );
        return is_array( $details ) ? $this->from_wpml( (string) ( $details['language_code'] ?? '' ) ) : '';
    }

    public function set_post_language( int $post_id, string $lang_slug ): void {
        $this->set_element_language( $post_id, 'post_' . get_post_type( $post_id ), $lang_slug );
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
                $result[ $this->from_wpml( (string) $lang ) ] = (int) $info->element_id;
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
            'language_code'        => $this->to_wpml( $translated_lang_slug ),
            'source_language_code' => $this->to_wpml( $source_lang_slug ),
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
        return (int) apply_filters( 'wpml_object_id', $post_id, get_post_type( $post_id ), false, $this->to_wpml( $lang_slug ) );
    }

    public function get_translated_term( int $term_id, string $lang_slug, string $taxonomy = 'category' ): int {
        return (int) apply_filters( 'wpml_object_id', $term_id, $taxonomy, false, $this->to_wpml( $lang_slug ) );
    }

    public function set_term_language( int $term_id, string $taxonomy, string $lang_slug ): void {
        $this->set_element_language( $this->term_element_id( $term_id, $taxonomy ), 'tax_' . $taxonomy, $lang_slug );
    }

    public function save_term_translations(
        int    $source_term_id,
        int    $translated_term_id,
        string $taxonomy,
        string $translated_lang_slug,
        string $source_lang_slug
    ): void {
        $source_trid = apply_filters( 'wpml_element_trid', null, $this->term_element_id( $source_term_id, $taxonomy ), 'tax_' . $taxonomy );

        do_action( 'wpml_set_element_language_details', [
            'element_id'           => $this->term_element_id( $translated_term_id, $taxonomy ),
            'element_type'         => 'tax_' . $taxonomy,
            'trid'                 => $source_trid ?: false,
            'language_code'        => $this->to_wpml( $translated_lang_slug ),
            'source_language_code' => $this->to_wpml( $source_lang_slug ),
        ] );
    }

    public function get_home_url( string $lang_slug ): string {
        // wpml_home_url always returns the current language URL.
        // Use wpml_permalink to get a language-specific URL.
        $url = apply_filters( 'wpml_home_url', home_url( '/' ) );

        // Switch to the target language to build the correct home URL.
        $current = apply_filters( 'wpml_current_language', null );
        do_action( 'wpml_switch_language', $this->to_wpml( $lang_slug ) );
        $lang_url = apply_filters( 'wpml_home_url', home_url( '/' ) );
        do_action( 'wpml_switch_language', $current );

        return $lang_url ?: $url;
    }

    // ── Query helpers ─────────────────────────────────────────────────────────

    public function get_source_post_ids( array $post_types, string $source_lang ): array {
        // Switch WPML language context so WP_Query returns only $source_lang posts.
        $current = apply_filters( 'wpml_current_language', null );
        do_action( 'wpml_switch_language', $this->to_wpml( $source_lang ) );

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

    // ── Menus ─────────────────────────────────────────────────────────────────

    public function link_menu_translation( int $source_menu_id, int $menu_id, string $lang_slug, string $source_lang_slug ): void {
        $this->set_term_language( $menu_id, 'nav_menu', $lang_slug );
        $this->save_term_translations( $source_menu_id, $menu_id, 'nav_menu', $lang_slug, $source_lang_slug );
    }

    public function switch_language( string $lang_slug ): string {
        $previous = (string) apply_filters( 'wpml_current_language', null );
        if ( $lang_slug !== '' ) {
            do_action( 'wpml_switch_language', $this->to_wpml( $lang_slug ) );
        }
        return $previous;
    }
}
