<?php
/**
 * Navigation menu translation.
 *
 * For each registered nav menu, creates (or updates) a translated copy named
 * "{Original Name} [LANG]" per configured target language.
 *
 * Menu items are handled as follows:
 *  - post_type items → re-pointed to the translated post via the multilingual adapter
 *  - taxonomy items  → re-pointed to the translated term via the multilingual adapter
 *  - custom URL items → URL kept as-is
 *  - Labels          → translated via the active provider
 * Parent/child relationships and menu_order are preserved exactly.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Translate all registered nav menus to every configured target language.
 *
 * Skips menus whose name already contains "[XX]" (they are translated copies).
 *
 * @return array[] One result array per [menu × lang] pair.
 */
/**
 * Menus to translate: every nav menu except the translated copies ("… [FR]").
 * Unique by ID — under WPML wp_get_nav_menus() lists a menu once per language,
 * each mapped to the current language, so the same menu came back twice.
 *
 * @return WP_Term[]
 */
function autolingo_source_menus(): array {
    $menus = [];
    foreach ( wp_get_nav_menus() as $menu ) {
        if ( ! preg_match( '/\[[A-Z]{2,5}\]$/', $menu->name ) ) {
            $menus[ $menu->term_id ] = $menu;
        }
    }
    return array_values( $menus );
}

function autolingo_translate_all_menus(): array {
    $results = [];

    foreach ( autolingo_source_menus() as $menu ) {
        foreach ( autolingo_target_langs() as $lang_slug ) {
            $results[] = autolingo_translate_menu_to_lang( $menu, $lang_slug );
        }
    }

    return $results;
}

/**
 * Translate a single nav menu to one target language.
 *
 * Creates a new menu named "{Name} [LANG]" (or updates it if it already
 * exists), translates every item label, and re-points internal links to
 * their translated post/term equivalent.
 *
 * @param  WP_Term $source_menu  The source nav menu term.
 * @param  string  $lang_slug    Target language slug, e.g. 'en'.
 * @return array{menu:string, lang:string, menu_id:int, items:int, error:string}
 */
function autolingo_translate_menu_to_lang( WP_Term $source_menu, string $lang_slug ): array {
    autolingo_extend_time_limit();
    $result = [
        'menu'    => $source_menu->name,
        'lang'    => $lang_slug,
        'menu_id' => 0,
        'items'   => 0,
        'error'   => '',
    ];

    // Read the source items in the source language, build the translation in the
    // target one: WPML maps menu and term IDs to its current language.
    $ml       = autolingo_multilingual();
    $previous = $ml ? $ml->switch_language( autolingo_source_lang() ) : '';

    // Fetch source items ordered by menu position.
    $items = wp_get_nav_menu_items( $source_menu->term_id, [ 'orderby' => 'menu_order' ] );
    if ( empty( $items ) ) {
        if ( $previous !== '' ) $ml->switch_language( $previous );
        $result['error'] = 'empty menu';
        return $result;
    }

    if ( $ml ) $ml->switch_language( $lang_slug );
    try {
        return autolingo_build_menu_translation( $source_menu, $items, $lang_slug, $result );
    } finally {
        if ( $previous !== '' ) $ml->switch_language( $previous );
    }
}

/**
 * Create (or rebuild) the $lang_slug copy of a menu from its source items.
 * Runs with $lang_slug as the multilingual plugin's current language.
 *
 * @param WP_Post[] $items Source menu items, in menu order.
 */
function autolingo_build_menu_translation( WP_Term $source_menu, array $items, string $lang_slug, array $result ): array {
    $target_lang = strtoupper( $lang_slug );

    // Find or create the translated menu (slug: original-slug-lang).
    $target_slug = $source_menu->slug . '-' . $lang_slug;
    $target_menu = wp_get_nav_menu_object( $target_slug );

    if ( ! $target_menu ) {
        $menu_id = wp_create_nav_menu( $source_menu->name . ' [' . $target_lang . ']' );
        if ( is_wp_error( $menu_id ) ) {
            $result['error'] = $menu_id->get_error_message();
            return $result;
        }
    } else {
        $menu_id = $target_menu->term_id;
        // Clear existing items so we rebuild with fresh translations.
        foreach ( wp_get_nav_menu_items( $menu_id ) ?: [] as $old_item ) {
            wp_delete_post( $old_item->ID, true );
        }
    }

    $result['menu_id'] = $menu_id;

    // WPML: the translated menu must be in the target language, linked to its source,
    // before items are added (items of one language are only attached to menus of it).
    $ml = autolingo_multilingual();
    if ( $ml ) {
        $ml->link_menu_translation( $source_menu->term_id, (int) $menu_id, $lang_slug, autolingo_source_lang() );
    }

    // Walk items in menu_order; map old IDs → new IDs for parent references.
    $id_map = [];

    foreach ( $items as $item ) {

        // ── Re-point internal links to the translated object ─────────────────
        $object_id     = (int) $item->object_id;
        $source_title  = ''; // the title of what the item links to, in the source language
        $translated_id = 0;

        $ml = autolingo_multilingual();
        if ( $item->type === 'post_type' && $object_id ) {
            $source_title  = (string) get_post_field( 'post_title', $object_id );
            $translated_id = $ml ? (int) $ml->get_translated_post( $object_id, $lang_slug ) : 0;
        } elseif ( $item->type === 'taxonomy' && $object_id ) {
            $taxonomy      = is_string( $item->object ) ? $item->object : 'category';
            $term          = get_term( $object_id, $taxonomy );
            $source_title  = $term instanceof WP_Term ? $term->name : '';
            $translated_id = $ml ? (int) $ml->get_translated_term( $object_id, $lang_slug, $taxonomy ) : 0;
        }
        if ( $translated_id ) $object_id = $translated_id;

        // ── The label ────────────────────────────────────────────────────────
        $label = trim( $item->post_title );
        if ( $label !== '' && $translated_id && $source_title !== '' && mb_strtolower( $label ) === mb_strtolower( trim( $source_title ) ) ) {
            // The label is the linked page's title: left empty, the item shows the translated page's title, as
            // WordPress does for an item without a label (and follows it if the page is renamed).
            $label = '';
        } elseif ( $label !== '' ) {
            // A short label means little alone ("Holding" read as "holding on"): the page it leads to says which
            // sense, and the memory keeps this label's translation apart from the same word elsewhere.
            $context = 'Label of a navigation menu item on a website (a short link text).';
            if ( $source_title !== '' ) $context .= ' It links to the page "' . wp_strip_all_tags( $source_title ) . '".';
            $previous_context = autolingo_translation_context( $context );
            $previous_scope   = function_exists( 'autolingo_tm_scope' ) ? autolingo_tm_scope( 'menu|' . $source_title ) : '';
            $translated       = autolingo_call_deepl( $label, $target_lang );
            autolingo_translation_context( $previous_context );
            if ( function_exists( 'autolingo_tm_scope' ) ) autolingo_tm_scope( $previous_scope );
            $label = $translated !== false ? $translated : $item->post_title;
        }

        // ── Map parent ID to the new translated item ID ──────────────────────
        $parent_id = (int) $item->menu_item_parent;
        if ( $parent_id && isset( $id_map[ $parent_id ] ) ) {
            $parent_id = $id_map[ $parent_id ];
        } elseif ( $parent_id ) {
            $parent_id = 0; // Parent not found — promote to top level.
        }

        // ── Create the translated item ───────────────────────────────────────
        $new_id = wp_update_nav_menu_item( $menu_id, 0, wp_slash( [
            'menu-item-title'      => $label,
            'menu-item-type'       => $item->type,
            'menu-item-object'     => $item->object,
            'menu-item-object-id'  => $object_id,
            'menu-item-url'        => $item->url,
            'menu-item-attr-title' => $item->attr_title,
            'menu-item-target'     => $item->target,
            'menu-item-classes'    => implode( ' ', (array) $item->classes ),
            'menu-item-xfn'        => $item->xfn,
            'menu-item-status'     => 'publish',
            'menu-item-parent-id'  => $parent_id,
            'menu-item-position'   => $item->menu_order,
        ] ) );

        if ( ! is_wp_error( $new_id ) ) {
            $id_map[ $item->ID ] = $new_id;
            $result['items']++;
        }
    }

    return $result;
}

/** The $lang_slug copy of a menu made by AutoLingo (slug: original-slug-lang), 0 when there is none. */
function autolingo_translated_menu_id( int $menu_id, string $lang_slug ): int {
    $menu = wp_get_nav_menu_object( $menu_id );
    if ( ! $menu || $lang_slug === '' ) return 0;
    $copy = wp_get_nav_menu_object( $menu->slug . '-' . $lang_slug );
    return $copy ? (int) $copy->term_id : 0;
}
