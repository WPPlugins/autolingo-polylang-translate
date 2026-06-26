<?php
/**
 * WP-CLI — commandes pdat.
 *
 * wp pdat audit                        → audit des traductions manquantes
 * wp pdat audit --format=csv           → export CSV
 * wp pdat translate-missing            → traduit uniquement les manquantes
 * wp pdat translate-missing --dry-run  → simulation
 * wp pdat translate-all                → traduit ou met à jour tout
 */

if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) return;

// ── Helpers CLI ────────────────────────────────────────────

function autolingo_cli_get_all_source_posts(): array {
    return get_posts( [
        'post_type'      => autolingo_post_types(),
        'post_status'    => 'publish',
        'lang'           => autolingo_source_lang(),
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ] );
}

// ── wp pdat audit ──────────────────────────────────────────

WP_CLI::add_command( 'pdat audit', function( array $args, array $assoc_args ): void {

    $posts   = autolingo_cli_get_all_source_posts();
    $format  = $assoc_args['format'] ?? 'table';
    $missing = array_fill_keys( autolingo_target_langs(), 0 );
    $rows    = [];

    foreach ( $posts as $post_id ) {
        $post   = get_post( $post_id );
        $status = autolingo_translation_status( $post_id );
        $row    = [
            'ID'    => $post_id,
            'Type'  => $post->post_type,
            'Titre' => mb_substr( $post->post_title, 0, 50 ),
        ];
        foreach ( $status as $lang => $exists ) {
            $row[ strtoupper( $lang ) ] = $exists ? '✓' : '✗';
            if ( ! $exists ) $missing[ $lang ]++;
        }
        $rows[] = $row;
    }

    if ( empty( $rows ) ) {
        WP_CLI::warning( 'Aucun post trouvé en ' . strtoupper( autolingo_source_lang() ) . '.' );
        return;
    }

    WP_CLI\Utils\format_items( $format, $rows, array_keys( $rows[0] ) );

    WP_CLI::line( '' );
    WP_CLI::line( '── Résumé ────────────────────────────────────────' );
    WP_CLI::line( count( $posts ) . ' posts/pages en ' . strtoupper( autolingo_source_lang() ) );
    foreach ( $missing as $lang => $count ) {
        $color = $count > 0 ? '%R' : '%G';
        WP_CLI::line( WP_CLI::colorize( $color . strtoupper( $lang ) . '%n' ) . ' : ' . $count . ' traduction(s) manquante(s)' );
    }
    WP_CLI::line( '──────────────────────────────────────────────────' );

}, [
    'shortdesc' => 'Audit des traductions manquantes pour tous les posts/pages dans la langue source.',
    'synopsis'  => [
        [
            'type'        => 'assoc',
            'name'        => 'format',
            'optional'    => true,
            'description' => 'Format : table (défaut), csv, json',
            'default'     => 'table',
        ],
    ],
] );

// ── wp pdat translate-missing ──────────────────────────────

WP_CLI::add_command( 'pdat translate-missing', function( array $args, array $assoc_args ): void {

    $dry_run = isset( $assoc_args['dry-run'] );
    $posts   = autolingo_cli_get_all_source_posts();
    $total   = 0;
    $done    = 0;
    $skipped = 0;
    $errors  = 0;

    if ( $dry_run ) {
        WP_CLI::line( WP_CLI::colorize( '%Y[dry-run]%n Simulation — aucune traduction ne sera créée.' ) );
        WP_CLI::line( '' );
    }

    foreach ( $posts as $post_id ) {
        $post   = get_post( $post_id );
        $status = autolingo_translation_status( $post_id );
        $needs  = array_filter( $status, fn( bool $exists ) => ! $exists );

        if ( empty( $needs ) ) {
            $skipped++;
            continue;
        }

        $total++;
        $title = mb_substr( $post->post_title, 0, 45 );
        $langs = implode( ', ', array_map( 'strtoupper', array_keys( $needs ) ) );

        if ( $dry_run ) {
            WP_CLI::line( "  → #{$post_id} [{$post->post_type}] \"{$title}\" — manque : {$langs}" );
            continue;
        }

        if ( ! autolingo_check_usage() ) {
            WP_CLI::error( 'Crédit DeepL épuisé. Arrêt.', false );
            break;
        }

        $success = true;
        foreach ( array_keys( $needs ) as $lang_slug ) {
            $target_lang = strtoupper( $lang_slug );
            $post_obj    = get_post( $post_id ); // objet frais
            $new_id      = autolingo_create_translation( $post_obj, $target_lang, $lang_slug );

            if ( ! $new_id ) {
                $success = false;
                WP_CLI::warning( "  #{$post_id} \"{$title}\" → {$target_lang} : échec." );
            } else {
                WP_CLI::line( WP_CLI::colorize( "  %G✓%n #{$post_id} \"{$title}\" → {$target_lang} (nouveau #{$new_id})" ) );
            }
            sleep( 1 );
        }

        if ( $success ) $done++; else $errors++;
    }

    WP_CLI::line( '' );
    WP_CLI::line( '── Résumé ────────────────────────────────────────────' );
    WP_CLI::line( "Posts déjà complets (ignorés) : {$skipped}" );

    if ( $dry_run ) {
        WP_CLI::line( "Posts qui seraient traduits   : {$total}" );
    } else {
        WP_CLI::line( WP_CLI::colorize( "%GTraductions créées%n : {$done}" ) );
        if ( $errors ) {
            WP_CLI::line( WP_CLI::colorize( "%RErreurs%n             : {$errors}" ) );
        }
    }
    WP_CLI::line( '─────────────────────────────────────────────────────' );

}, [
    'shortdesc' => 'Traduit uniquement les posts/pages sans traduction dans les langues cibles.',
    'synopsis'  => [
        [
            'type'        => 'flag',
            'name'        => 'dry-run',
            'optional'    => true,
            'description' => 'Affiche ce qui serait traduit sans rien créer.',
        ],
    ],
] );

// ── wp pdat translate-all ──────────────────────────────────

WP_CLI::add_command( 'pdat translate-all', function( array $args, array $assoc_args ): void {

    $posts = autolingo_cli_get_all_source_posts();
    WP_CLI::line( count( $posts ) . ' posts/pages trouvés en ' . strtoupper( autolingo_source_lang() ) );
    WP_CLI::line( '' );

    $done = $errors = 0;

    foreach ( $posts as $post_id ) {
        $post  = get_post( $post_id );
        $title = mb_substr( $post->post_title, 0, 45 );

        if ( ! autolingo_check_usage() ) {
            WP_CLI::error( 'Crédit DeepL épuisé. Arrêt.', false );
            break;
        }

        $result = autolingo_translate_post( $post_id );
        if ( $result ) {
            WP_CLI::line( WP_CLI::colorize( "%G✓%n #{$post_id} \"{$title}\"" ) );
            $done++;
        } else {
            WP_CLI::warning( "#{$post_id} \"{$title}\" — erreur." );
            $errors++;
        }
        sleep( 1 );
    }

    WP_CLI::line( '' );
    WP_CLI::success( "{$done} traduits, {$errors} erreurs." );

}, [
    'shortdesc' => 'Traduit (ou met à jour) tous les posts/pages publiés dans la langue source.',
] );

// ── wp pdat translate-menus ────────────────────────────────

WP_CLI::add_command( 'pdat translate-menus', function( array $args, array $assoc_args ): void {

    $menu_slug = $assoc_args['menu'] ?? null;
    $lang_only = isset( $assoc_args['lang'] ) ? sanitize_key( $assoc_args['lang'] ) : null;

    if ( $menu_slug ) {
        $source_menu = wp_get_nav_menu_object( $menu_slug );
        if ( ! $source_menu ) {
            WP_CLI::error( "Menu \"{$menu_slug}\" introuvable." );
        }
        $menus = [ $source_menu ];
    } else {
        $menus = array_filter(
            wp_get_nav_menus(),
            fn( $m ) => ! preg_match( '/\[[A-Z]{2,5}\]$/', $m->name )
        );
    }

    if ( empty( $menus ) ) {
        WP_CLI::warning( 'Aucun menu source trouvé.' );
        return;
    }

    $target_langs = $lang_only ? [ $lang_only ] : autolingo_target_langs();
    $done = $errors = 0;

    foreach ( $menus as $menu ) {
        foreach ( $target_langs as $lang_slug ) {
            $result = autolingo_translate_menu_to_lang( $menu, $lang_slug );
            $label  = "\"{$result['menu']}\" → " . strtoupper( $result['lang'] );
            if ( $result['error'] ) {
                WP_CLI::warning( "  {$label} — ignoré ({$result['error']})" );
                $errors++;
            } else {
                WP_CLI::line( WP_CLI::colorize( "  %G✓%n {$label} — {$result['items']} élément(s) (menu #{$result['menu_id']})" ) );
                $done++;
            }
        }
    }

    WP_CLI::line( '' );
    WP_CLI::success( "{$done} menu(s) traduit(s)" . ( $errors ? ", {$errors} ignoré(s)." : '.' ) );
    WP_CLI::line( WP_CLI::colorize( '%YAssignez les nouveaux menus dans Apparence → Menus ou les réglages Polylang.%n' ) );

}, [
    'shortdesc' => 'Traduit tous les menus de navigation (ou un seul) vers les langues cibles.',
    'synopsis'  => [
        [
            'type'        => 'assoc',
            'name'        => 'menu',
            'optional'    => true,
            'description' => 'Slug du menu à traduire (défaut : tous les menus source).',
        ],
        [
            'type'        => 'assoc',
            'name'        => 'lang',
            'optional'    => true,
            'description' => 'Traduire vers une seule langue (ex: en). Défaut : toutes les langues cibles.',
        ],
    ],
] );
