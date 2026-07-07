<?php
/**
 * ACF — traduction automatique des champs Advanced Custom Fields.
 *
 * Supporte :
 *   - Champs sur posts / pages / CPT
 *   - ACF Options Pages (acf_get_options_page)
 *   - Détection automatique des champs traduisibles
 *   - Types traduits : text, textarea, wysiwyg, url, email, link (title)
 *   - Types ignorés  : image, file, gallery, repeater complexe, relationship, taxonomy, etc.
 *
 * Requiert : ACF gratuit ou Pro, Polylang, clé DeepL configurée.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ══════════════════════════════════════════════════════════
//  1. TYPES DE CHAMPS TRADUISIBLES
// ══════════════════════════════════════════════════════════

/**
 * Types de champs ACF contenant du texte traduisible.
 * Modifiable via filtre pour les projets custom.
 *
 * @return string[]
 */
function autolingo_acf_translatable_types(): array {
    return apply_filters( 'autolingo_acf_translatable_types', [
        'text',
        'textarea',
        'wysiwyg',
    ] );
}

/**
 * Types de champs ACF contenant du texte dans une sous-clé.
 * Ex: 'link' → traduit uniquement le sous-champ 'title'.
 *
 * @return array<string, string[]>  [ type => [sous-clés à traduire] ]
 */
function autolingo_acf_subkey_types(): array {
    return apply_filters( 'autolingo_acf_subkey_types', [
        'link' => [ 'title' ],
    ] );
}


// ══════════════════════════════════════════════════════════
//  2. DÉTECTION AUTOMATIQUE DES CHAMPS D'UN POST
// ══════════════════════════════════════════════════════════

/**
 * Retourne tous les champs ACF traduisibles d'un post.
 * Parcourt récursivement les groupes et répéteurs.
 *
 * @param  int    $post_id
 * @return array  [ ['key' => string, 'type' => string, 'name' => string], ... ]
 */
function autolingo_acf_get_translatable_fields( int $post_id ): array {
    if ( ! function_exists( 'get_field_objects' ) ) return [];

    $field_objects = get_field_objects( $post_id );
    if ( empty( $field_objects ) ) return [];

    return autolingo_acf_extract_translatable( $field_objects );
}

/**
 * Extraction récursive des champs traduisibles (gère group, repeater, flexible_content).
 *
 * @param  array  $fields  Résultat de get_field_objects() ou sous-champs.
 * @param  string $prefix  Préfixe de clé pour les champs imbriqués.
 * @return array
 */
function autolingo_acf_extract_translatable( array $fields, string $prefix = '' ): array {
    $translatable    = [];
    $simple_types    = autolingo_acf_translatable_types();
    $subkey_types    = autolingo_acf_subkey_types();

    foreach ( $fields as $field ) {
        $key  = $prefix ? $prefix . '.' . $field['name'] : $field['name'];
        $type = $field['type'] ?? '';

        if ( in_array( $type, $simple_types, true ) ) {
            $translatable[] = [
                'key'  => $key,
                'type' => $type,
                'name' => $field['label'] ?? $field['name'],
            ];

        } elseif ( isset( $subkey_types[ $type ] ) ) {
            foreach ( $subkey_types[ $type ] as $subkey ) {
                $translatable[] = [
                    'key'    => $key,
                    'type'   => $type,
                    'name'   => ( $field['label'] ?? $field['name'] ) . ' (' . $subkey . ')',
                    'subkey' => $subkey,
                ];
            }

        } elseif ( $type === 'group' && ! empty( $field['sub_fields'] ) ) {
            // Groupe → descendre récursivement
            $sub = autolingo_acf_extract_translatable(
                autolingo_acf_index_by_name( $field['sub_fields'] ),
                $key
            );
            $translatable = array_merge( $translatable, $sub );

        } elseif ( $type === 'repeater' && ! empty( $field['sub_fields'] ) ) {
            // Repeater → on traduit chaque ligne au moment de la traduction
            $translatable[] = [
                'key'        => $key,
                'type'       => 'repeater',
                'name'       => $field['label'] ?? $field['name'],
                'sub_fields' => autolingo_acf_extract_translatable(
                    autolingo_acf_index_by_name( $field['sub_fields'] )
                ),
            ];

        } elseif ( $type === 'flexible_content' && ! empty( $field['layouts'] ) ) {
            // Flexible content → parcourir chaque layout
            $translatable[] = [
                'key'     => $key,
                'type'    => 'flexible_content',
                'name'    => $field['label'] ?? $field['name'],
                'layouts' => autolingo_acf_flexible_layouts( $field['layouts'] ),
            ];
        }
    }

    return $translatable;
}

/**
 * Indexe un tableau de champs ACF par leur 'name'.
 */
function autolingo_acf_index_by_name( array $sub_fields ): array {
    $indexed = [];
    foreach ( $sub_fields as $sf ) {
        $indexed[ $sf['name'] ] = $sf;
    }
    return $indexed;
}

/**
 * Extrait les champs traduisibles de chaque layout flexible_content.
 */
function autolingo_acf_flexible_layouts( array $layouts ): array {
    $result = [];
    foreach ( $layouts as $layout ) {
        if ( empty( $layout['sub_fields'] ) ) continue;
        $result[ $layout['name'] ] = autolingo_acf_extract_translatable(
            autolingo_acf_index_by_name( $layout['sub_fields'] )
        );
    }
    return $result;
}


// ══════════════════════════════════════════════════════════
//  3. TRADUCTION DES CHAMPS ACF D'UN POST
// ══════════════════════════════════════════════════════════

/**
 * Traduit tous les champs ACF d'un post source vers le post traduit.
 *
 * @param  int    $source_id   ID post source (FR).
 * @param  int    $target_id   ID post traduit (EN, DE…).
 * @param  string $target_lang Code langue majuscules.
 * @return bool
 */
function autolingo_acf_translate_post_fields( int $source_id, int $target_id, string $target_lang ): bool {
    if ( ! function_exists( 'get_field' ) ) return true; // ACF non installé → pas d'erreur

    $fields = autolingo_acf_get_translatable_fields( $source_id );
    if ( empty( $fields ) ) return true;

    $success = true;

    foreach ( $fields as $field_def ) {
        $ok = autolingo_acf_translate_field( $source_id, $target_id, $field_def, $target_lang );
        if ( ! $ok ) $success = false;
    }

    if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
        error_log( "[PDT ACF] Post #{$source_id} → #{$target_id} ({$target_lang}) : " . count( $fields ) . " champ(s) traité(s)." ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- gated on WP_DEBUG_LOG.
    }
    return $success;
}

/**
 * Traduit un champ ACF individuel (dispatch selon le type).
 */
function autolingo_acf_translate_field( int $source_id, int $target_id, array $field_def, string $target_lang ): bool {
    $key  = $field_def['key'];
    $type = $field_def['type'];

    switch ( $type ) {
        case 'text':
        case 'textarea':
        case 'wysiwyg':
            $value = get_field( $key, $source_id );
            if ( empty( $value ) ) return true;
            $translated = autolingo_call_deepl( $value, $target_lang );
            if ( $translated === false ) return false;
            update_field( $key, $translated, $target_id );
            return true;

        case 'link':
            $value = get_field( $key, $source_id );
            if ( empty( $value ) || ! isset( $field_def['subkey'] ) ) return true;
            $subkey = $field_def['subkey'];
            if ( empty( $value[ $subkey ] ) ) return true;
            $translated = autolingo_call_deepl( $value[ $subkey ], $target_lang );
            if ( $translated === false ) return false;
            // Copier le champ entier puis remplacer la sous-clé
            $new_value = get_field( $key, $target_id ) ?: $value;
            $new_value[ $subkey ] = $translated;
            update_field( $key, $new_value, $target_id );
            return true;

        case 'repeater':
            return autolingo_acf_translate_repeater( $source_id, $target_id, $key, $field_def['sub_fields'] ?? [], $target_lang );

        case 'flexible_content':
            return autolingo_acf_translate_flexible( $source_id, $target_id, $key, $field_def['layouts'] ?? [], $target_lang );

        default:
            return true;
    }
}

/**
 * Traduit un champ repeater ligne par ligne.
 */
function autolingo_acf_translate_repeater( int $source_id, int $target_id, string $key, array $sub_field_defs, string $target_lang ): bool {
    $rows = get_field( $key, $source_id );
    if ( empty( $rows ) || ! is_array( $rows ) ) return true;

    $translated_rows = [];
    foreach ( $rows as $row ) {
        $new_row = $row;
        foreach ( $sub_field_defs as $sf ) {
            $sf_key  = $sf['key'];
            $sf_type = $sf['type'];
            if ( ! isset( $row[ $sf_key ] ) || empty( $row[ $sf_key ] ) ) continue;
            if ( ! in_array( $sf_type, autolingo_acf_translatable_types(), true ) ) continue;
            $translated = autolingo_call_deepl( $row[ $sf_key ], $target_lang );
            if ( $translated !== false ) {
                $new_row[ $sf_key ] = $translated;
            }
        }
        $translated_rows[] = $new_row;
    }

    update_field( $key, $translated_rows, $target_id );
    return true;
}

/**
 * Traduit un champ flexible_content bloc par bloc.
 */
function autolingo_acf_translate_flexible( int $source_id, int $target_id, string $key, array $layouts, string $target_lang ): bool {
    $blocks = get_field( $key, $source_id );
    if ( empty( $blocks ) || ! is_array( $blocks ) ) return true;

    $translated_blocks = [];
    foreach ( $blocks as $block ) {
        $layout_name  = $block['acf_fc_layout'] ?? '';
        $layout_defs  = $layouts[ $layout_name ] ?? [];
        $new_block    = $block;

        foreach ( $layout_defs as $sf ) {
            $sf_key  = $sf['key'];
            $sf_type = $sf['type'];
            if ( ! isset( $block[ $sf_key ] ) || empty( $block[ $sf_key ] ) ) continue;
            if ( ! in_array( $sf_type, autolingo_acf_translatable_types(), true ) ) continue;
            $translated = autolingo_call_deepl( $block[ $sf_key ], $target_lang );
            if ( $translated !== false ) {
                $new_block[ $sf_key ] = $translated;
            }
        }
        $translated_blocks[] = $new_block;
    }

    update_field( $key, $translated_blocks, $target_id );
    return true;
}


// ══════════════════════════════════════════════════════════
//  4. ACF OPTIONS PAGES
// ══════════════════════════════════════════════════════════

/**
 * Traduit les champs ACF d'une Options Page vers une langue cible.
 * Les options sont stockées avec le suffixe de langue dans la clé wp_options.
 *
 * Usage WP-CLI : wp pdat translate-options
 *
 * @param  string $target_lang  Code langue majuscules (ex: 'EN').
 * @param  string $lang_slug    Code langue minuscules (ex: 'en').
 * @return bool
 */
function autolingo_acf_translate_options( string $target_lang, string $lang_slug ): bool {
    if ( ! function_exists( 'acf_get_field_groups' ) ) return false;

    // Récupérer les groupes de champs attachés aux options pages
    $groups = acf_get_field_groups( [ 'options_page' => 'options' ] );

    if ( empty( $groups ) ) {
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            error_log( '[PDT ACF] Aucun groupe de champs trouvé sur les options pages.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- gated on WP_DEBUG_LOG.
        }
        return true;
    }

    $success = true;
    foreach ( $groups as $group ) {
        $fields = acf_get_fields( $group['key'] );
        if ( empty( $fields ) ) continue;

        $translatable = autolingo_acf_extract_translatable(
            autolingo_acf_index_by_name( $fields )
        );

        foreach ( $translatable as $field_def ) {
            $key   = $field_def['key'];
            $type  = $field_def['type'];
            $value = get_field( $key, 'option' );

            if ( empty( $value ) ) continue;

            if ( in_array( $type, autolingo_acf_translatable_types(), true ) ) {
                $translated = autolingo_call_deepl( $value, $target_lang );
                if ( $translated !== false ) {
                    // Stockage spécifique à la langue : autolingo_acf_{lang}_{key}
                    update_option( 'autolingo_acf_' . $lang_slug . '_' . $key, $translated );
                    if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                        error_log( "[PDT ACF] Option '{$key}' traduite → {$target_lang}" ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- gated on WP_DEBUG_LOG.
                    }
                } else {
                    $success = false;
                }
            }
        }
    }

    return $success;
}


// ══════════════════════════════════════════════════════════
//  5. COMMANDE WP-CLI POUR LES OPTIONS
// ══════════════════════════════════════════════════════════

if ( defined( 'WP_CLI' ) && WP_CLI ) {

    WP_CLI::add_command( 'pdat translate-options', function( array $args, array $assoc_args ): void {

        if ( ! function_exists( 'get_field' ) ) {
            WP_CLI::error( 'ACF n\'est pas installé ou activé.' );
            return;
        }

        $dry_run = isset( $assoc_args['dry-run'] );
        if ( $dry_run ) {
            WP_CLI::line( WP_CLI::colorize( '%Y[dry-run]%n Simulation uniquement.' ) );
        }

        foreach ( autolingo_target_langs() as $lang_slug ) {
            $target_lang = strtoupper( $lang_slug );
            WP_CLI::line( "→ Traduction des options vers {$target_lang}…" );

            if ( $dry_run ) {
                // Lister les champs sans traduire
                $groups = function_exists( 'acf_get_field_groups' )
                    ? acf_get_field_groups( [ 'options_page' => 'options' ] )
                    : [];
                foreach ( $groups as $group ) {
                    $fields = acf_get_fields( $group['key'] );
                    if ( empty( $fields ) ) continue;
                    $translatable = autolingo_acf_extract_translatable( autolingo_acf_index_by_name( $fields ) );
                    foreach ( $translatable as $f ) {
                        WP_CLI::line( "  · {$f['name']} ({$f['type']}) [{$f['key']}]" );
                    }
                }
                continue;
            }

            $ok = autolingo_acf_translate_options( $target_lang, $lang_slug );
            if ( $ok ) {
                WP_CLI::success( "Options traduites → {$target_lang}" );
            } else {
                WP_CLI::warning( "Erreurs lors de la traduction des options → {$target_lang}" );
            }
        }

    }, [
        'shortdesc' => 'Traduit les champs ACF des Options Pages vers les langues cibles.',
        'synopsis'  => [
            [
                'type'        => 'flag',
                'name'        => 'dry-run',
                'optional'    => true,
                'description' => 'Liste les champs qui seraient traduits sans rien modifier.',
            ],
        ],
    ] );

    WP_CLI::add_command( 'pdat audit-acf', function( array $args, array $assoc_args ): void {

        if ( ! function_exists( 'get_field_objects' ) ) {
            WP_CLI::error( 'ACF n\'est pas installé ou activé.' );
            return;
        }

        $posts  = autolingo_cli_get_all_source_posts();
        $format = $assoc_args['format'] ?? 'table';
        $rows   = [];

        foreach ( $posts as $post_id ) {
            $post   = get_post( $post_id );
            $fields = autolingo_acf_get_translatable_fields( $post_id );
            if ( empty( $fields ) ) continue;
            foreach ( $fields as $f ) {
                $rows[] = [
                    'Post ID' => $post_id,
                    'Titre'   => mb_substr( $post->post_title, 0, 40 ),
                    'Champ'   => $f['name'],
                    'Clé'     => $f['key'],
                    'Type'    => $f['type'],
                ];
            }
        }

        if ( empty( $rows ) ) {
            WP_CLI::line( 'Aucun champ ACF traduisible trouvé sur les posts en ' . strtoupper( autolingo_source_lang() ) . '.' );
            return;
        }

        WP_CLI\Utils\format_items( $format, $rows, array_keys( $rows[0] ) );
        WP_CLI::line( '' );
        WP_CLI::line( count( $rows ) . ' champ(s) traduisible(s) détecté(s).' );

    }, [
        'shortdesc' => 'Liste tous les champs ACF traduisibles détectés sur les posts FR.',
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
}
