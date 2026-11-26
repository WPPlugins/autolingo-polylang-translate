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
 * WPBakery does the same on its own (wpb_remove_custom_html() on content_save_pre,
 * WPBakery 6.3+): without unfiltered_html it deletes the raw HTML, raw JS and Google
 * Maps elements and the buttons with a custom onclick, which AutoLingo copies unchanged.
 *
 * @param array $postarr Post data.
 * @param bool  $update  True → wp_update_post, false → wp_insert_post.
 * @return int|WP_Error
 */
function autolingo_save_post( array $postarr, bool $update = false ): int|WP_Error {
    $hooks = [
        [ 'content_save_pre', 'wp_filter_post_kses' ],
        [ 'content_filtered_save_pre', 'wp_filter_post_kses' ],
        [ 'excerpt_save_pre', 'wp_filter_post_kses' ],
        [ 'content_save_pre', 'wpb_remove_custom_html' ],
    ];
    $had = [];
    foreach ( $hooks as $n => [ $hook, $cb ] ) {
        $had[ $n ] = has_filter( $hook, $cb );
        if ( false !== $had[ $n ] ) remove_filter( $hook, $cb, (int) $had[ $n ] );
    }
    // wp_insert_post() / wp_update_post() strip one level of backslashes (they expect slashed data,
    // as from a form): unslashed, the "\u003c" WordPress writes in block settings for "<" (a
    // button text in bold) came out as "u003c" on the translated page.
    $postarr = wp_slash( $postarr );
    $result  = $update ? wp_update_post( $postarr ) : wp_insert_post( $postarr );
    foreach ( $hooks as $n => [ $hook, $cb ] ) {
        if ( false !== $had[ $n ] ) add_filter( $hook, $cb, (int) $had[ $n ] );
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
    autolingo_extend_time_limit();

    if ( ! autolingo_multilingual() ) {
        autolingo_log_error( 'Traduction annulée : aucun plugin multilingue détecté (Polylang ou WPML requis).', [], $post_id );
        return false;
    }

    if ( ! autolingo_check_usage() ) {
        autolingo_log_error( 'Traduction annulée : crédit DeepL épuisé.', [], $post_id );
        return false;
    }

    $success = true;

    $ml = autolingo_multilingual();

    $previous_context = autolingo_translation_context( autolingo_post_context( $post ) );
    try {
        foreach ( autolingo_target_langs() as $lang_slug ) {
            $target_lang  = strtoupper( $lang_slug );
            $translations = $ml->get_post_translations( $post_id );

            if ( ! empty( $translations[ $lang_slug ] ) ) {
                // Traduction existante → mise à jour
                $ok = autolingo_update_translation( $post, $translations[ $lang_slug ], $target_lang, $lang_slug );
            } else {
                // Nouvelle traduction
                $ok = (bool) autolingo_create_translation( $post, $target_lang, $lang_slug );
            }

            if ( ! $ok ) $success = false;
        }
    } finally {
        autolingo_translation_context( $previous_context );
    }

    if ( $success ) {
        autolingo_invalidate_progress_cache();
    }

    return $success;
}

/**
 * Message d'échec d'une traduction : la cause réelle quand un fournisseur l'a signalée (quota
 * épuisé, erreur de connexion…), et non plus « Erreur DeepL » quel que soit le fournisseur.
 *
 * @param string $action      'traduction' ou 'mise à jour'.
 * @param string $target_lang Code langue majuscules.
 */
function autolingo_translation_failure_message( string $action, string $target_lang ): string {
    $cause = autolingo_last_error();
    return $cause !== ''
        ? "Échec de la {$action} vers {$target_lang} : {$cause}"
        : "Échec de la {$action} vers {$target_lang} : le service de traduction n'a renvoyé aucun texte.";
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
/**
 * The publication date of the original, for its new translation: without it the translation is dated the day
 * it is created, and a blog listed by date shows old articles first in that language. Nothing for a draft that
 * has no date of its own yet.
 *
 * @return array{post_date?: string, post_date_gmt?: string}
 */
function autolingo_source_dates( WP_Post $source ): array {
    if ( $source->post_date_gmt === '0000-00-00 00:00:00' || $source->post_date_gmt === '' ) return [];
    return [ 'post_date' => $source->post_date, 'post_date_gmt' => $source->post_date_gmt ];
}

function autolingo_create_translation( WP_Post $source_post, string $target_lang, string $lang_slug ) {

    // ── Traduire le contenu via une seule requête batch ───
    autolingo_last_error( '' );
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
            autolingo_translation_failure_message( 'traduction', $target_lang ),
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

    $ml = autolingo_multilingual();

    $new_post_id = $ml->create_translated_post( $source_post, $lang_slug, $translated_data );

    if ( ! $new_post_id ) {
        // Fallback générique : création manuelle + liaison via l'adaptateur.
        $new_post_id = autolingo_save_post( [
            'post_title'   => $translated_title,
            'post_content' => $translated_content,
            'post_excerpt' => $translated_excerpt,
            'post_status'  => autolingo_new_translation_status( $source_post->post_type ),
            'post_type'    => $source_post->post_type,
            'post_author'  => $source_post->post_author,
        ] + autolingo_source_dates( $source_post ) );

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

        $ml->set_post_language( $new_post_id, $lang_slug );
        $ml->save_post_translations(
            $source_post->ID,
            $new_post_id,
            $lang_slug,
            $ml->get_post_language( $source_post->ID )
        );
    }

    add_action( 'save_post', 'autolingo_on_save_post', 20, 3 );

    // ── Copier les metas du source (thème, SEO, layout…) ──
    // Doit se faire AVANT les modules de traduction qui écrasent
    // les champs traduits (Elementor, ACF).
    autolingo_copy_post_meta( $source_post->ID, $new_post_id );

    // ── Synchroniser termes + extensions ──────────────────
    autolingo_sync_terms( $source_post->ID, $new_post_id, $lang_slug );
    autolingo_translate_image_alt_text( $source_post, $new_post_id, $target_lang, $lang_slug );

    if ( function_exists( 'autolingo_run_premium_modules' ) ) {
        autolingo_run_premium_modules( $source_post, $new_post_id, $target_lang, $lang_slug );
    }
    /** After a translation is written: integrations (Contact Form 7, Divi Theme Builder) finish their own data. */
    do_action( 'autolingo_translated', $source_post, (int) $new_post_id, $target_lang, $lang_slug );

    update_post_meta( $source_post->ID, '_autolingo_hash_' . $lang_slug, autolingo_source_content_hash( $source_post ) );
    // The translation as saved, after the integrations above (a Divi menu module pointed at the translated menu):
    // the next update compares with it to tell a manual edit, and must not take AutoLingo's own change for one.
    $saved_translation = get_post( (int) $new_post_id );
    update_post_meta( $source_post->ID, '_autolingo_target_hash_' . $lang_slug,
        $saved_translation ? autolingo_source_content_hash( $saved_translation ) : md5( $translated_title . "\x00" . $translated_content . "\x00" . $translated_excerpt )
    );
    $ml->mark_translation_current( $source_post->ID, $new_post_id );
    // Created as a draft or pending review: the review agent (Premium) may publish it once it finds nothing.
    if ( get_post_status( $new_post_id ) !== 'publish' ) update_post_meta( $new_post_id, '_autolingo_awaiting_review', 1 );

    // The block editor's meta box request may still post the links as they were before (hooks.php).
    $new_links               = get_transient( 'autolingo_new_translations_' . $source_post->ID ) ?: [];
    $new_links[ $lang_slug ] = $new_post_id;
    set_transient( 'autolingo_new_translations_' . $source_post->ID, $new_links, 10 * MINUTE_IN_SECONDS );

    autolingo_log_success(
        "Post traduit vers {$target_lang} (nouveau post #{$new_post_id}).",
        [ 'source_id' => $source_post->ID, 'target_id' => $new_post_id ],
        $source_post->ID,
        $target_lang
    );
    return $new_post_id;
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

    $hash_key        = '_autolingo_hash_' . $lang_slug;
    $target_hash_key = '_autolingo_target_hash_' . $lang_slug;
    $current_hash    = autolingo_source_content_hash( $source_post );
    $content_changed = get_post_meta( $source_post->ID, $hash_key, true ) !== $current_hash;

    if ( $content_changed ) {
        // Skip overwriting content that was manually edited on the translated post.
        $stored_target_hash = get_post_meta( $source_post->ID, $target_hash_key, true );
        if ( $stored_target_hash ) {
            $translated_post = get_post( $translated_id );
            /**
             * Whether a translation that differs from AutoLingo's last one is still untouched: an integration may
             * have changed it itself (Divi: before 1.13.8, the menu module's ID swapped after the fingerprint).
             */
            $untouched = $translated_post && (bool) apply_filters( 'autolingo_translation_untouched', false, $translated_post, $stored_target_hash, $lang_slug );
            if ( $translated_post && ! $untouched && autolingo_source_content_hash( $translated_post ) !== $stored_target_hash ) {
                autolingo_log_info(
                    "Traduction {$target_lang} ignorée : le post traduit (#{$translated_id}) a été modifié manuellement depuis la dernière traduction automatique.",
                    [ 'source_id' => $source_post->ID, 'target_id' => $translated_id ],
                    $source_post->ID,
                    $target_lang
                );
                autolingo_sync_terms( $source_post->ID, $translated_id, $lang_slug );
                autolingo_translate_image_alt_text( $source_post, $translated_id, $target_lang, $lang_slug );
                if ( function_exists( 'autolingo_run_premium_modules' ) ) {
                    autolingo_run_premium_modules( $source_post, $translated_id, $target_lang, $lang_slug );
                }
                return true;
            }
        }

        autolingo_last_error( '' );
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
                autolingo_translation_failure_message( 'mise à jour', $target_lang ),
                [ 'translated_id' => $translated_id ],
                $source_post->ID,
                $target_lang
            );
            return false;
        }

        $translated_content = autolingo_replace_internal_urls( $translated_content, $lang_slug );
        $translated_excerpt = autolingo_replace_internal_urls( $translated_excerpt, $lang_slug );

        remove_action( 'save_post', 'autolingo_on_save_post', 20 );

        $ml = autolingo_multilingual();

        // S'assurer que la langue est correctement assignée (correction si manquante)
        if ( $ml && $ml->get_post_language( $translated_id ) !== $lang_slug ) {
            $ml->set_post_language( $translated_id, $lang_slug );
        }

        autolingo_save_post( [
            'ID'           => $translated_id,
            'post_title'   => $translated_title,
            'post_content' => $translated_content,
            'post_excerpt' => $translated_excerpt,
            // No post_status: a translation keeps its status (a draft under review, one
            // the editor took offline). It used to be published again on every update.
        ], true );

        // Réaffirmer la langue après la sauvegarde (certains plugins peuvent la réinitialiser)
        if ( $ml ) {
            $ml->set_post_language( $translated_id, $lang_slug );
        }

        add_action( 'save_post', 'autolingo_on_save_post', 20, 3 );

        update_post_meta( $source_post->ID, $hash_key, $current_hash );
        update_post_meta( $source_post->ID, $target_hash_key,
            md5( $translated_title . "\x00" . $translated_content . "\x00" . $translated_excerpt )
        );

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
    autolingo_translate_image_alt_text( $source_post, $translated_id, $target_lang, $lang_slug );

    if ( function_exists( 'autolingo_run_premium_modules' ) ) {
        autolingo_run_premium_modules( $source_post, $translated_id, $target_lang, $lang_slug );
    }
    // A translation nobody edited by hand stays AutoLingo's after the integrations change it (a Divi menu module
    // pointed at the translated menu): its fingerprint follows, else the next update would skip it as a manual edit.
    $before          = get_post( $translated_id );
    $ours            = $before && get_post_meta( $source_post->ID, $target_hash_key, true ) === autolingo_source_content_hash( $before );
    do_action( 'autolingo_translated', $source_post, $translated_id, $target_lang, $lang_slug );
    $after = $ours ? get_post( $translated_id ) : null;
    if ( $after ) {
        update_post_meta( $source_post->ID, $target_hash_key, autolingo_source_content_hash( $after ) );
    }

    $ml = autolingo_multilingual();
    if ( $ml ) {
        $ml->mark_translation_current( $source_post->ID, $translated_id );
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
    $ml = autolingo_multilingual();
    $target_lang       = strtoupper( $lang_slug );
    $source_lang_slug  = $ml ? $ml->get_post_language( $source_id ) : '';

    foreach ( get_post_taxonomies( $source_id ) as $taxonomy ) {
        // Ignorer les taxonomies internes des plugins multilingues
        if ( str_starts_with( $taxonomy, 'language' ) || str_starts_with( $taxonomy, 'post_translations' ) ) {
            continue;
        }

        $source_terms = wp_get_post_terms( $source_id, $taxonomy, [ 'fields' => 'ids' ] );
        if ( is_wp_error( $source_terms ) || empty( $source_terms ) ) continue;

        $target_terms = [];
        foreach ( $source_terms as $term_id ) {
            $term_id    = (int) $term_id;
            $translated = $ml ? $ml->get_translated_term( $term_id, $lang_slug, $taxonomy ) : 0;
            if ( ! $translated && $ml ) {
                $translated = autolingo_translate_term( $term_id, $taxonomy, $target_lang, $lang_slug, $source_lang_slug );
            }
            $target_terms[] = $translated ?: $term_id;
        }

        wp_set_post_terms( $target_id, $target_terms, $taxonomy );
    }
}

// Structured data: the translation's own address, once it has one.
add_action( 'autolingo_translated', static function ( WP_Post $source, int $target_id ): void {
    autolingo_json_ld_own_url( $source, $target_id );
}, 20, 2 );
