<?php
/**
 * Translate — logique de création et mise à jour des traductions Polylang.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Insert or update a translated post without KSES sanitization.
 *
 * In cron / async context there is no logged-in user, so WordPress adds
 * wp_filter_post_kses to content_save_pre and strips tags like <input>,
 * <style>, <script> that are present inside wp:html blocks. Bypass it for the
 * duration of the save — the source content was already trusted by an admin.
 *
 * @param array $postarr Post data.
 * @param bool  $update  True → wp_update_post, false → wp_insert_post.
 * @return int|WP_Error
 */
function autolingo_save_post( array $postarr, bool $update = false ): int|WP_Error {
    $hooks = [
        'content_save_pre'          => 'wp_filter_post_kses',
        'content_filtered_save_pre' => 'wp_filter_post_kses',
        'excerpt_save_pre'          => 'wp_filter_post_kses',
    ];
    $had = [];
    foreach ( $hooks as $hook => $cb ) {
        $had[ $hook ] = has_filter( $hook, $cb );
        if ( $had[ $hook ] ) remove_filter( $hook, $cb );
    }
    $result = $update ? wp_update_post( $postarr ) : wp_insert_post( $postarr );
    foreach ( $hooks as $hook => $cb ) {
        if ( $had[ $hook ] ) add_filter( $hook, $cb );
    }
    return $result;
}

/**
 * Fingerprint of the translatable basic fields (title, content, excerpt).
 * Stored as post meta after each successful translation so subsequent saves
 * with identical content skip the API entirely.
 */
function autolingo_source_content_hash( WP_Post $post ): string {
    return md5( $post->post_title . "\x00" . $post->post_content . "\x00" . $post->post_excerpt );
}

/**
 * Traduit un post vers toutes les langues cibles configurées.
 * Crée la traduction si elle n'existe pas, la met à jour sinon.
 *
 * @param  int  $post_id  ID du post source (en langue FR).
 * @return bool           true si toutes les traductions ont réussi.
 */
function autolingo_translate_post( int $post_id ): bool {
    $post = get_post( $post_id );
    if ( ! $post ) return false;

    if ( ! function_exists( 'pll_get_post_translations' ) ) {
        autolingo_log_error( 'Traduction annulée : Polylang non chargé.', [], $post_id );
        return false;
    }

    if ( ! autolingo_check_usage() ) {
        autolingo_log_error( 'Traduction annulée : crédit DeepL épuisé.', [], $post_id );
        return false;
    }

    $success = true;

    foreach ( autolingo_target_langs() as $lang_slug ) {
        $target_lang  = strtoupper( $lang_slug );
        $translations = pll_get_post_translations( $post_id );

        if ( ! empty( $translations[ $lang_slug ] ) ) {
            // Traduction existante → mise à jour
            $ok = autolingo_update_translation( $post, $translations[ $lang_slug ], $target_lang, $lang_slug );
        } else {
            // Nouvelle traduction
            $ok = (bool) autolingo_create_translation( $post, $target_lang, $lang_slug );
        }

        if ( ! $ok ) $success = false;
    }

    if ( $success ) {
        autolingo_invalidate_progress_cache();
    }

    return $success;
}

/**
 * Crée un nouveau post traduit en utilisant le mécanisme natif de Polylang.
 * Reproduit exactement ce que fait le lien :
 *   post-new.php?post_type=page&from_post=64&new_lang=en
 *
 * @param  WP_Post $source_post  Post source.
 * @param  string  $target_lang  Code langue majuscules (ex: 'EN').
 * @param  string  $lang_slug    Code langue minuscules (ex: 'en').
 * @return int|false             ID du nouveau post ou false.
 */
function autolingo_create_translation( WP_Post $source_post, string $target_lang, string $lang_slug ) {

    // ── Traduire le contenu via une seule requête batch ───
    $batch_input = [ $source_post->post_title, $source_post->post_content ];
    if ( $source_post->post_excerpt ) {
        $batch_input[] = $source_post->post_excerpt;
    }

    $batch = autolingo_call_deepl_batch( $batch_input, $target_lang );

    $translated_title   = $batch !== false ? ( $batch[0] ?? false ) : false;
    $translated_content = $batch !== false ? ( $batch[1] ?? false ) : false;
    $translated_excerpt = ( $batch !== false && $source_post->post_excerpt ) ? ( $batch[2] ?? $source_post->post_excerpt ) : '';

    if ( $translated_title === false || $translated_content === false ) {
        autolingo_log_error(
            "Erreur DeepL lors de la traduction vers {$target_lang}.",
            [ 'source_post_id' => $source_post->ID ],
            $source_post->ID,
            $target_lang
        );
        return false;
    }

    $translated_content = autolingo_replace_internal_urls( $translated_content, $lang_slug );
    $translated_excerpt = autolingo_replace_internal_urls( $translated_excerpt, $lang_slug );

    $translated_data = [
        'title'   => $translated_title,
        'content' => $translated_content,
        'excerpt' => $translated_excerpt,
    ];

    // ── Créer via le modèle natif Polylang ────────────────
    // Passer le contenu traduit directement évite un wp_update_post() ultérieur
    // qui déclencherait le hook save_post de Polylang et réinitialiserait la langue.
    remove_action( 'save_post', 'autolingo_on_save_post', 20 );

    $new_post_id = autolingo_polylang_create_post( $source_post, $lang_slug, $translated_data );

    if ( ! $new_post_id ) {
        // Fallback : création manuelle si le modèle Polylang n'est pas accessible.
        // On crée directement avec le contenu traduit et le statut final pour éviter
        // tout wp_update_post() supplémentaire qui écraserait la langue assignée.
        $new_post_id = autolingo_save_post( [
            'post_title'   => $translated_title,
            'post_content' => $translated_content,
            'post_excerpt' => $translated_excerpt,
            'post_status'  => 'publish',
            'post_type'    => $source_post->post_type,
            'post_author'  => $source_post->post_author,
        ] );

        if ( is_wp_error( $new_post_id ) ) {
            add_action( 'save_post', 'autolingo_on_save_post', 20, 3 );
            autolingo_log_error(
                'Impossible de créer le post traduit : ' . $new_post_id->get_error_message(),
                [ 'target_lang' => $target_lang ],
                $source_post->ID,
                $target_lang
            );
            return false;
        }

        // Fallback : assigner langue + lier manuellement
        pll_set_post_language( $new_post_id, $lang_slug );
        $translations = pll_get_post_translations( $source_post->ID );
        $translations[ $lang_slug ]                                 = $new_post_id;
        $translations[ pll_get_post_language( $source_post->ID ) ] = $source_post->ID;
        pll_save_post_translations( $translations );
    }

    add_action( 'save_post', 'autolingo_on_save_post', 20, 3 );

    // ── Copier les metas du source (thème, SEO, layout…) ──
    // Doit se faire AVANT les modules de traduction qui écrasent
    // les champs traduits (Elementor, ACF).
    autolingo_copy_post_meta( $source_post->ID, $new_post_id );

    // ── Synchroniser termes + extensions ──────────────────
    autolingo_sync_terms( $source_post->ID, $new_post_id, $lang_slug );

    if ( function_exists( 'autolingo_run_premium_modules' ) ) {
        autolingo_run_premium_modules( $source_post, $new_post_id, $target_lang, $lang_slug );
    }

    update_post_meta( $source_post->ID, '_autolingo_hash_' . $lang_slug, autolingo_source_content_hash( $source_post ) );

    autolingo_log_success(
        "Post traduit vers {$target_lang} (nouveau post #{$new_post_id}).",
        [ 'source_id' => $source_post->ID, 'target_id' => $new_post_id ],
        $source_post->ID,
        $target_lang
    );
    return $new_post_id;
}

/**
 * Crée un post via l'API interne de Polylang 3.8.4.
 * Utilise les méthodes : insert, set_language, save_translations
 *
 * @param  WP_Post $source_post    Post source.
 * @param  string  $lang_slug      Code langue minuscules (ex: 'en').
 * @param  array   $translated_data Contenu traduit ['title', 'content', 'excerpt'].
 * @return int|false               ID du nouveau post ou false.
 */
function autolingo_polylang_create_post( WP_Post $source_post, string $lang_slug, array $translated_data = [] ) {

    $pll_model_post = PLL()->model->post ?? null;
    if ( ! $pll_model_post ) return false;

    // Créer le post directement avec le contenu traduit et le bon statut.
    // Ne pas créer en draft puis mettre à jour : le second wp_update_post()
    // déclencherait le hook save_post de Polylang qui réinitialiserait la langue.
    $postarr = [
        'post_author'    => $source_post->post_author,
        'post_status'    => 'publish',
        'post_type'      => $source_post->post_type,
        'post_title'     => $translated_data['title']   ?? $source_post->post_title,
        'post_content'   => $translated_data['content'] ?? $source_post->post_content,
        'post_excerpt'   => $translated_data['excerpt'] ?? $source_post->post_excerpt,
        'comment_status' => $source_post->comment_status,
        'ping_status'    => $source_post->ping_status,
        'menu_order'     => $source_post->menu_order,
        'post_parent'    => $source_post->post_parent,
    ];

    $new_id = autolingo_save_post( $postarr );
    if ( is_wp_error( $new_id ) || ! $new_id ) return false;

    // Récupérer l'objet langue Polylang
    $language = PLL()->model->get_language( $lang_slug );
    if ( ! $language ) {
        wp_delete_post( $new_id, true );
        return false;
    }

    // Assigner la langue via la méthode native set_language
    $pll_model_post->set_language( $new_id, $language );

    // Lier source et traduction via save_translations
    $translations = $pll_model_post->get_translations( $source_post->ID );
    $translations[ $lang_slug ]                                 = $new_id;
    $translations[ pll_get_post_language( $source_post->ID ) ] = $source_post->ID;
    $pll_model_post->save_translations( $new_id, $translations );

    return $new_id;
}

/**
 * Met à jour une traduction existante.
 *
 * @param  WP_Post $source_post    Post source.
 * @param  int     $translated_id  ID du post traduit existant.
 * @param  string  $target_lang    Code langue majuscules.
 * @param  string  $lang_slug      Code langue minuscules.
 * @return bool
 */
function autolingo_update_translation( WP_Post $source_post, int $translated_id, string $target_lang, string $lang_slug ): bool {

    $hash_key     = '_autolingo_hash_' . $lang_slug;
    $current_hash = autolingo_source_content_hash( $source_post );
    $content_changed = get_post_meta( $source_post->ID, $hash_key, true ) !== $current_hash;

    if ( $content_changed ) {
        $batch_input = [ $source_post->post_title, $source_post->post_content ];
        if ( $source_post->post_excerpt ) {
            $batch_input[] = $source_post->post_excerpt;
        }

        $batch = autolingo_call_deepl_batch( $batch_input, $target_lang );

        $translated_title   = $batch !== false ? ( $batch[0] ?? false ) : false;
        $translated_content = $batch !== false ? ( $batch[1] ?? false ) : false;
        $translated_excerpt = ( $batch !== false && $source_post->post_excerpt ) ? ( $batch[2] ?? $source_post->post_excerpt ) : '';

        if ( $translated_title === false || $translated_content === false ) {
            autolingo_log_error(
                "Erreur DeepL lors de la mise à jour vers {$target_lang}.",
                [ 'translated_id' => $translated_id ],
                $source_post->ID,
                $target_lang
            );
            return false;
        }

        $translated_content = autolingo_replace_internal_urls( $translated_content, $lang_slug );
        $translated_excerpt = autolingo_replace_internal_urls( $translated_excerpt, $lang_slug );

        remove_action( 'save_post', 'autolingo_on_save_post', 20 );

        // S'assurer que la langue est correctement assignée (correction si manquante)
        $current_lang = pll_get_post_language( $translated_id );
        if ( $current_lang !== $lang_slug ) {
            pll_set_post_language( $translated_id, $lang_slug );
        }

        autolingo_save_post( [
            'ID'           => $translated_id,
            'post_title'   => $translated_title,
            'post_content' => $translated_content,
            'post_excerpt' => $translated_excerpt,
            'post_status'  => 'publish',
        ], true );

        // Réaffirmer la langue après wp_update_post (Polylang peut la réinitialiser)
        pll_set_post_language( $translated_id, $lang_slug );

        add_action( 'save_post', 'autolingo_on_save_post', 20, 3 );

        update_post_meta( $source_post->ID, $hash_key, $current_hash );

        autolingo_log_success(
            "Traduction mise à jour vers {$target_lang} (post #{$translated_id}).",
            [ 'source_id' => $source_post->ID, 'target_id' => $translated_id ],
            $source_post->ID,
            $target_lang
        );
    } else {
        autolingo_log_info(
            "Traduction {$target_lang} ignorée : contenu principal inchangé depuis la dernière traduction.",
            [ 'source_id' => $source_post->ID, 'target_id' => $translated_id ],
            $source_post->ID,
            $target_lang
        );
    }

    // Always sync terms and run premium modules — taxonomy assignments and
    // Elementor / ACF / WooCommerce data can change independently of the
    // basic content fields and must not be skipped.
    autolingo_sync_terms( $source_post->ID, $translated_id, $lang_slug );

    if ( function_exists( 'autolingo_run_premium_modules' ) ) {
        autolingo_run_premium_modules( $source_post, $translated_id, $target_lang, $lang_slug );
    }

    return true;
}

/**
 * Synchronise les termes (catégories, tags) vers le post traduit.
 * Utilise l'équivalent traduit dans Polylang si disponible.
 *
 * @param  int    $source_id  ID du post source.
 * @param  int    $target_id  ID du post traduit.
 * @param  string $lang_slug  Code langue cible minuscules.
 */
function autolingo_sync_terms( int $source_id, int $target_id, string $lang_slug ): void {
    foreach ( get_post_taxonomies( $source_id ) as $taxonomy ) {
        // Ignorer les taxonomies internes de Polylang
        if ( str_starts_with( $taxonomy, 'language' ) || str_starts_with( $taxonomy, 'post_translations' ) ) {
            continue;
        }

        $source_terms = wp_get_post_terms( $source_id, $taxonomy, [ 'fields' => 'ids' ] );
        if ( is_wp_error( $source_terms ) || empty( $source_terms ) ) continue;

        $target_terms = [];
        foreach ( $source_terms as $term_id ) {
            $translated_term = pll_get_term( $term_id, $lang_slug );
            $target_terms[]  = $translated_term ?: $term_id;
        }

        wp_set_post_terms( $target_id, $target_terms, $taxonomy );
    }
}
