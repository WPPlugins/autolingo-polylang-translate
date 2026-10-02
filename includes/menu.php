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
function autolingo_translate_all_menus(): array {
    $menus   = wp_get_nav_menus();
    $results = [];

    foreach ( $menus as $menu ) {
        // Skip menus that are already translated copies (named "… [EN]" etc.).
        if ( preg_match( '/\[[A-Z]{2,5}\]$/', $menu->name ) ) {
            continue;
        }
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
    $target_lang = strtoupper( $lang_slug );
    $result      = [
        'menu'    => $source_menu->name,
        'lang'    => $lang_slug,
        'menu_id' => 0,
        'items'   => 0,
        'error'   => '',
    ];

    // Fetch source items ordered by menu position.
    $items = wp_get_nav_menu_items( $source_menu->term_id, [ 'orderby' => 'menu_order' ] );
    if ( empty( $items ) ) {
        $result['error'] = 'empty menu';
        return $result;
    }

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

    // Walk items in menu_order; map old IDs → new IDs for parent references.
    $id_map = [];

    foreach ( $items as $item ) {

        // ── Translate the custom label ───────────────────────────────────────
        $label = trim( $item->post_title );
        if ( $label !== '' ) {
            $previous_context = autolingo_translation_context( 'Label of a navigation menu item on a website (a short link text).' );
            $translated       = autolingo_call_deepl( $label, $target_lang );
            autolingo_translation_context( $previous_context );
            $label = $translated !== false ? $translated : $item->post_title;
        }

        // ── Re-point internal links to the translated object ─────────────────
        $object_id = (int) $item->object_id;

        $ml = autolingo_multilingual();
        if ( $ml && $item->type === 'post_type' && $object_id ) {
            $translated_id = $ml->get_translated_post( $object_id, $lang_slug );
            if ( $translated_id ) {
                $object_id = $translated_id;
            }
        } elseif ( $ml && $item->type === 'taxonomy' && $object_id ) {
            $taxonomy      = is_string( $item->object ) ? $item->object : 'category';
            $translated_id = $ml->get_translated_term( $object_id, $lang_slug, $taxonomy );
            if ( $translated_id ) {
                $object_id = $translated_id;
            }
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
