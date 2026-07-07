<?php
/**
 * Tests d'intégration — Polylang DeepL Translate
 *
 * Exécuter avec :
 *   docker compose run --rm wpcli eval-file /var/www/html/wp-content/plugins/autolingo-polylang-translate/tests/integration.php
 *
 * Variables d'environnement :
 *   AUTOLINGO_TEST_KEEP=1  Conserver les posts créés pour inspection après les tests.
 */

if ( ! defined( 'ABSPATH' ) ) {
    echo "Ce script doit être exécuté via WP-CLI.\n";
    exit( 1 );
}

// ── Compteurs dans $GLOBALS pour traverser la barrière eval-file/fonctions ──
// WP-CLI eval-file exécute le code dans un scope local (pas global).
// Les fonctions définies ici accèdent à $GLOBALS via `global`, qui est différent
// du scope local du fichier. On utilise donc $GLOBALS directement pour les compteurs.
$GLOBALS['autolingo_passed']  = 0;
$GLOBALS['autolingo_failed']  = 0;
$GLOBALS['autolingo_cleanup'] = [];

// ── Helpers ────────────────────────────────────────────────────────────────

function autolingo_test( string $name, callable $fn ): void {
    // Petit délai entre les tests pour éviter les 429 sur l'API DeepL.
    static $first = true;
    if ( $first ) { $first = false; } else { sleep( 1 ); }

    $GLOBALS['autolingo_cleanup'] = [];

    try {
        $fn();
        echo "  ✅  $name\n";
        $GLOBALS['autolingo_passed']++;
    } catch ( \RuntimeException $e ) {
        echo "  ❌  $name\n     → " . $e->getMessage() . "\n";
        $GLOBALS['autolingo_failed']++;
    } finally {
        if ( empty( getenv( 'AUTOLINGO_TEST_KEEP' ) ) ) {
            foreach ( $GLOBALS['autolingo_cleanup'] as $id ) {
                wp_delete_post( $id, true );
            }
        }
    }
}

function autolingo_assert( bool $cond, string $msg ): void {
    if ( ! $cond ) throw new \RuntimeException( $msg );
}

/** Crée un post FR source et l'enregistre pour nettoyage. */
function autolingo_make_fr_post( array $args = [] ): int {
    $id = wp_insert_post( array_merge( [
        'post_title'   => 'Test PDT source',
        'post_content' => 'Contenu de test en français.',
        'post_excerpt' => 'Résumé de test.',
        'post_status'  => 'publish',
        'post_type'    => 'post',
    ], $args ) );
    pll_set_post_language( $id, 'fr' );
    $GLOBALS['autolingo_cleanup'][] = $id;
    return $id;
}

/** Traduit un post et vérifie que ça a réussi. Lève une exception si ça échoue. */
function autolingo_translate( int $src_id, string $context = '' ): int {
    $ok = autolingo_translate_post( $src_id );
    autolingo_assert( $ok === true, ( $context ? "$context : " : '' ) . 'autolingo_translate_post() a retourné false' );
    $t     = pll_get_post_translations( $src_id );
    $en_id = $t['en'] ?? 0;
    autolingo_assert( $en_id > 0, ( $context ? "$context : " : '' ) . 'Aucun post EN trouvé dans les traductions Polylang' );
    $GLOBALS['autolingo_cleanup'][] = $en_id;
    return $en_id;
}

// ── Suite de tests ──────────────────────────────────────────────────────────

// Ensure the plugin is configured as the tests expect: source=fr, target=en.
$_autolingo_saved_source  = get_option( 'autolingo_source_lang' );
$_autolingo_saved_targets = get_option( 'autolingo_target_langs' );
update_option( 'autolingo_source_lang',  'fr' );
update_option( 'autolingo_target_langs', [ 'en' ] );

register_shutdown_function( function () use ( $_autolingo_saved_source, $_autolingo_saved_targets ) {
    update_option( 'autolingo_source_lang',  $_autolingo_saved_source );
    update_option( 'autolingo_target_langs', $_autolingo_saved_targets );
} );

echo "\n======================================\n";
echo "  Polylang DeepL — Tests d'intégration\n";
echo "======================================\n\n";

// ──────────────────────────────────────────────────────────────────────────
echo "── 1. Flux principal ─────────────────────────────────────────────────\n";

autolingo_test( 'Création d\'une traduction EN', function () {
    $src = autolingo_make_fr_post( [ 'post_title' => 'Bonjour le monde', 'post_excerpt' => 'Un extrait simple.' ] );

    $en_id = autolingo_translate( $src );
    $en    = get_post( $en_id );

    autolingo_assert( pll_get_post_language( $en_id ) === 'en',  'Langue incorrecte : ' . pll_get_post_language( $en_id ) );
    autolingo_assert( $en->post_status === 'publish',             'Statut incorrect : ' . $en->post_status );
    autolingo_assert( ! empty( $en->post_title ),                 'Titre EN vide' );
    autolingo_assert( $en->post_title !== 'Bonjour le monde',     'Titre non traduit' );
    autolingo_assert( ! empty( $en->post_excerpt ),               'Extrait EN vide' );
} );

autolingo_test( 'Mise à jour d\'une traduction existante', function () {
    $src = autolingo_make_fr_post( [ 'post_title' => 'Titre original en français' ] );
    $en_id = autolingo_translate( $src );
    $title_v1 = get_post( $en_id )->post_title;

    wp_update_post( [ 'ID' => $src, 'post_title' => 'Titre mis à jour en français' ] );
    $ok = autolingo_translate_post( $src );
    autolingo_assert( $ok === true, 'autolingo_translate_post() retourne false lors de la mise à jour' );

    autolingo_assert( get_post( $en_id )->post_title !== $title_v1,   'Titre non mis à jour' );
    autolingo_assert( pll_get_post_language( $en_id ) === 'en',       'Langue réinitialisée après mise à jour' );
    autolingo_assert( get_post( $en_id )->post_status === 'publish',  'Statut incorrect après mise à jour' );
} );

autolingo_test( 'Texte vide → retour immédiat sans appel DeepL', function () {
    $result = autolingo_call_deepl( '', 'EN' );
    autolingo_assert( $result === '', 'Texte vide aurait dû retourner "" (reçu : ' . var_export( $result, true ) . ')' );
} );

// ──────────────────────────────────────────────────────────────────────────
echo "\n── 2. SEO metas ──────────────────────────────────────────────────────\n";

autolingo_test( 'Traduction des metas Yoast SEO', function () {
    $src = autolingo_make_fr_post();
    update_post_meta( $src, '_yoast_wpseo_title',    'Formation française' );
    update_post_meta( $src, '_yoast_wpseo_metadesc', 'Apprenez le français efficacement.' );
    update_post_meta( $src, '_yoast_wpseo_focuskw',  'formation français' );

    $en_id = autolingo_translate( $src );
    $t = get_post_meta( $en_id, '_yoast_wpseo_title', true );
    autolingo_assert( ! empty( $t ) && $t !== 'Formation française', "Titre Yoast non traduit : $t" );
    $d = get_post_meta( $en_id, '_yoast_wpseo_metadesc', true );
    autolingo_assert( ! empty( $d ), 'Metadesc Yoast vide' );
} );

autolingo_test( 'Traduction des metas RankMath', function () {
    $src = autolingo_make_fr_post();
    update_post_meta( $src, '_rankmath_title',       'Cours de français' );
    update_post_meta( $src, '_rankmath_description', 'Devenez bilingue rapidement.' );

    $en_id = autolingo_translate( $src );
    $t = get_post_meta( $en_id, '_rankmath_title', true );
    autolingo_assert( ! empty( $t ) && $t !== 'Cours de français', "Titre RankMath non traduit : $t" );
} );

autolingo_test( 'Variables SEO %%var%% (Yoast) préservées', function () {
    $src = autolingo_make_fr_post();
    update_post_meta( $src, '_yoast_wpseo_title', '%%title%% %%sep%% Mon site' );

    $en_id = autolingo_translate( $src );
    $t = get_post_meta( $en_id, '_yoast_wpseo_title', true );
    autolingo_assert( str_contains( $t, '%%title%%' ), "%%title%% perdu dans : $t" );
    autolingo_assert( str_contains( $t, '%%sep%%' ),   "%%sep%% perdu dans : $t" );
} );

autolingo_test( 'Variables SEO %var% (RankMath) préservées', function () {
    $src = autolingo_make_fr_post();
    update_post_meta( $src, '_rankmath_title', '%title% %sep% Mon site' );

    $en_id = autolingo_translate( $src );
    $t = get_post_meta( $en_id, '_rankmath_title', true );
    autolingo_assert( str_contains( $t, '%title%' ), "%title% perdu dans : $t" );
    autolingo_assert( str_contains( $t, '%sep%' ),   "%sep% perdu dans : $t" );
} );

autolingo_test( 'Traduction des metas SEOPress', function () {
    $src = autolingo_make_fr_post();
    update_post_meta( $src, '_seopress_titles_title', 'Formation en référencement naturel' );
    update_post_meta( $src, '_seopress_titles_desc',  'Apprenez le SEO efficacement.' );

    $en_id = autolingo_translate( $src );
    $t = get_post_meta( $en_id, '_seopress_titles_title', true );
    autolingo_assert( ! empty( $t ) && $t !== 'Formation en référencement naturel', "Titre SEOPress non traduit : $t" );
    $d = get_post_meta( $en_id, '_seopress_titles_desc', true );
    autolingo_assert( ! empty( $d ) && $d !== 'Apprenez le SEO efficacement.', "Desc SEOPress non traduite : $d" );
} );

autolingo_test( 'Traduction des metas The SEO Framework', function () {
    $src = autolingo_make_fr_post();
    update_post_meta( $src, '_genesis_title',       'Expert en formation linguistique' );
    update_post_meta( $src, '_genesis_description', 'Des formations certifiées pour tous niveaux.' );

    $en_id = autolingo_translate( $src );
    $t = get_post_meta( $en_id, '_genesis_title', true );
    autolingo_assert( ! empty( $t ) && $t !== 'Expert en formation linguistique', "Titre TSF non traduit : $t" );
    $d = get_post_meta( $en_id, '_genesis_description', true );
    autolingo_assert( ! empty( $d ) && $d !== 'Des formations certifiées pour tous niveaux.', "Desc TSF non traduite : $d" );
} );

autolingo_test( 'Hook updated_post_meta re-traduit les metas SEO après mise à jour Yoast', function () {
    $src = autolingo_make_fr_post();
    update_post_meta( $src, '_yoast_wpseo_metadesc', 'Description initiale en français.' );
    $en_id = autolingo_translate( $src );

    // Simuler la mise à jour du meta Yoast (comme Gutenberg le ferait après save_post)
    update_post_meta( $src, '_yoast_wpseo_metadesc', 'Description mise à jour en français.' );

    // Le hook autolingo_on_seo_meta_updated doit avoir re-traduit automatiquement
    $en_desc = get_post_meta( $en_id, '_yoast_wpseo_metadesc', true );
    autolingo_assert( ! empty( $en_desc ),                                    'Metadesc EN vide après re-traduction' );
    autolingo_assert( $en_desc !== 'Description mise à jour en français.',    'Metadesc EN non re-traduite' );
    autolingo_assert( $en_desc !== 'Description initiale en français.',       'Metadesc EN inchangée depuis la version initiale' );
} );

// ──────────────────────────────────────────────────────────────────────────
echo "\n── 3. Shortcodes ─────────────────────────────────────────────────────\n";

autolingo_test( 'Shortcodes avec attributs préservés', function () {
    $sc  = '[contact-form-7 id="1" title="Contact"] et [gallery ids="10,11" columns="3"]';
    $src = autolingo_make_fr_post( [ 'post_content' => "Bienvenue. $sc Merci." ] );

    $en = get_post( autolingo_translate( $src ) );
    autolingo_assert( str_contains( $en->post_content, '[contact-form-7 id="1" title="Contact"]' ),
        'Shortcode contact-form-7 corrompu : ' . $en->post_content );
    autolingo_assert( str_contains( $en->post_content, '[gallery ids="10,11" columns="3"]' ),
        'Shortcode gallery corrompu : ' . $en->post_content );
} );

autolingo_test( 'Shortcode auto-fermant [vc_row /] préservé', function () {
    $src = autolingo_make_fr_post( [ 'post_content' => 'Texte avant. [vc_row /] Texte après.' ] );
    $en  = get_post( autolingo_translate( $src ) );
    autolingo_assert( str_contains( $en->post_content, '[vc_row /]' ),
        'Shortcode auto-fermant corrompu : ' . $en->post_content );
} );

// ──────────────────────────────────────────────────────────────────────────
echo "\n── 4. Elementor ──────────────────────────────────────────────────────\n";

autolingo_test( 'Widgets Elementor traduits (heading + editor HTML)', function () {
    $src  = autolingo_make_fr_post();
    $json = json_encode( [ [
        'id' => 'e1', 'elType' => 'section', 'settings' => [], 'isInner' => false,
        'elements' => [ [
            'id' => 'e2', 'elType' => 'column', 'settings' => [], 'elements' => [
                [ 'id' => 'e3', 'elType' => 'widget', 'widgetType' => 'heading',
                  'settings' => [ 'title' => 'Nos services', 'sub_title' => 'Qualité garantie' ],
                  'elements' => [] ],
                [ 'id' => 'e4', 'elType' => 'widget', 'widgetType' => 'text-editor',
                  'settings' => [ 'editor' => '<p>Texte avec <strong>HTML</strong>.</p>' ],
                  'elements' => [] ],
            ],
        ] ],
    ] ] );
    update_post_meta( $src, '_elementor_edit_mode', 'builder' );
    update_post_meta( $src, '_elementor_data', wp_slash( $json ) );

    $en_id   = autolingo_translate( $src );
    $raw     = get_post_meta( $en_id, '_elementor_data', true );
    $els     = json_decode( $raw, true );
    autolingo_assert( is_array( $els ), 'EN _elementor_data non décodable en JSON : ' . substr( (string) $raw, 0, 100 ) );

    $w1 = $els[0]['elements'][0]['elements'][0]['settings'] ?? null;
    $w2 = $els[0]['elements'][0]['elements'][1]['settings'] ?? null;
    autolingo_assert( $w1 !== null,                                         'Widget heading introuvable dans les éléments EN' );
    autolingo_assert( ( $w1['title'] ?? '' ) !== 'Nos services',            'heading.title non traduit' );
    autolingo_assert( ( $w1['sub_title'] ?? '' ) !== 'Qualité garantie',    'heading.sub_title non traduit' );
    autolingo_assert( $w2 !== null,                                         'Widget text-editor introuvable' );
    autolingo_assert( str_contains( $w2['editor'] ?? '', '<strong>' ),      'Balise <strong> perdue dans editor' );
} );

// ──────────────────────────────────────────────────────────────────────────
echo "\n── 5. Beaver Builder ─────────────────────────────────────────────────\n";

autolingo_test( 'Modules BB traduits (title + content + button_text)', function () {
    $src  = autolingo_make_fr_post();
    $node = new stdClass();
    $node->node = 'bb1'; $node->type = 'module'; $node->global = false;
    $node->settings = new stdClass();
    $node->settings->title       = 'Notre équipe';
    $node->settings->content     = 'Des experts passionnés.';
    $node->settings->button_text = 'Rejoignez-nous';
    update_post_meta( $src, '_fl_builder_enabled', '1' );
    update_post_meta( $src, '_fl_builder_data',  [ 'bb1' => $node ] );
    update_post_meta( $src, '_fl_builder_draft', [ 'bb1' => $node ] );

    $en_id = autolingo_translate( $src );
    $data  = get_post_meta( $en_id, '_fl_builder_data', true );
    autolingo_assert( is_array( $data ) && isset( $data['bb1'] ),    '_fl_builder_data manquant ou mal formé sur le post EN' );

    $s = $data['bb1']->settings;
    autolingo_assert( $s->title       !== 'Notre équipe',        'title non traduit' );
    autolingo_assert( $s->content     !== 'Des experts passionnés.', 'content non traduit' );
    autolingo_assert( $s->button_text !== 'Rejoignez-nous',      'button_text non traduit' );

    $draft = get_post_meta( $en_id, '_fl_builder_draft', true );
    autolingo_assert( isset( $draft['bb1'] ) && $draft['bb1']->settings->title === $s->title,
        'draft non synchronisé avec live data' );
} );

autolingo_test( 'Nodes BB globaux non traduits', function () {
    $src    = autolingo_make_fr_post();
    $global = new stdClass();
    $global->node = 'bb_g'; $global->type = 'module'; $global->global = true;
    $global->settings = new stdClass();
    $global->settings->title = 'Ne pas traduire';
    update_post_meta( $src, '_fl_builder_enabled', '1' );
    update_post_meta( $src, '_fl_builder_data',  [ 'bb_g' => $global ] );
    update_post_meta( $src, '_fl_builder_draft', [ 'bb_g' => $global ] );

    $en_id = autolingo_translate( $src );
    $data  = get_post_meta( $en_id, '_fl_builder_data', true );
    autolingo_assert( is_array( $data ) && isset( $data['bb_g'] ), '_fl_builder_data manquant sur le post EN' );
    autolingo_assert( $data['bb_g']->settings->title === 'Ne pas traduire',
        'Node global modifié : ' . ( $data['bb_g']->settings->title ?? 'null' ) );
} );

// ──────────────────────────────────────────────────────────────────────────
echo "\n── 6. Bricks Builder ─────────────────────────────────────────────────\n";

autolingo_test( 'Éléments Bricks traduits (heading + text HTML)', function () {
    $src  = autolingo_make_fr_post();
    $data = [
        [ 'id' => 'bk1', 'name' => 'heading', 'parent' => 0, 'children' => [],
          'settings' => [ 'heading' => 'Bienvenue', 'subheading' => 'Votre partenaire' ] ],
        [ 'id' => 'bk2', 'name' => 'text', 'parent' => 0, 'children' => [],
          'settings' => [ 'text' => '<p>Texte avec <em>emphase</em>.</p>' ] ],
    ];
    update_post_meta( $src, '_bricks_page_content_2', wp_slash( wp_json_encode( $data ) ) );

    $en_id = autolingo_translate( $src );
    $raw   = get_post_meta( $en_id, '_bricks_page_content_2', true );
    $out   = json_decode( $raw, true );
    autolingo_assert( is_array( $out ), 'EN _bricks_page_content_2 non décodable : ' . substr( (string) $raw, 0, 100 ) );

    $el0 = $out[0]['settings'] ?? null;
    $el1 = $out[1]['settings'] ?? null;
    autolingo_assert( $el0 !== null,                                  'Élément heading introuvable' );
    autolingo_assert( ( $el0['heading'] ?? '' )    !== 'Bienvenue',    'heading non traduit' );
    autolingo_assert( ( $el0['subheading'] ?? '' ) !== 'Votre partenaire', 'subheading non traduit' );
    autolingo_assert( $el1 !== null,                                  'Élément text introuvable' );
    autolingo_assert( str_contains( $el1['text'] ?? '', '<em>' ),     'Balise <em> perdue dans text' );
} );

autolingo_test( 'Items imbriqués Bricks (accordéon) traduits', function () {
    $src  = autolingo_make_fr_post();
    $data = [ [
        'id' => 'bk3', 'name' => 'accordion', 'parent' => 0, 'children' => [],
        'settings' => [ 'items' => [
            [ 'title' => 'Question fréquente',  'content' => 'Voici la réponse détaillée.' ],
            [ 'title' => 'Autre question',       'content' => 'Une autre réponse.' ],
        ] ],
    ] ];
    update_post_meta( $src, '_bricks_page_content_2', wp_slash( wp_json_encode( $data ) ) );

    $en_id = autolingo_translate( $src );
    $raw   = get_post_meta( $en_id, '_bricks_page_content_2', true );
    $out   = json_decode( $raw, true );
    autolingo_assert( is_array( $out ), 'EN _bricks_page_content_2 non décodable' );

    $items = $out[0]['settings']['items'] ?? null;
    autolingo_assert( is_array( $items ) && count( $items ) === 2, 'Items manquants dans accordéon EN' );
    autolingo_assert( ( $items[0]['title']   ?? '' ) !== 'Question fréquente',         'item[0].title non traduit' );
    autolingo_assert( ( $items[0]['content'] ?? '' ) !== 'Voici la réponse détaillée.', 'item[0].content non traduit' );
    autolingo_assert( ( $items[1]['title']   ?? '' ) !== 'Autre question',             'item[1].title non traduit' );
} );

// ──────────────────────────────────────────────────────────────────────────
echo "\n── 7. WooCommerce ────────────────────────────────────────────────────\n";

autolingo_test( 'Attributs personnalisés produit traduits (nom + valeurs)', function () {
    $src = autolingo_make_fr_post( [ 'post_type' => 'product', 'post_title' => 'Cours de français' ] );
    update_post_meta( $src, '_product_attributes', [
        'niveau' => [
            'name' => 'Niveau', 'value' => 'Débutant | Intermédiaire | Avancé',
            'position' => 0, 'is_visible' => 1, 'is_variation' => 0, 'is_taxonomy' => 0,
        ],
    ] );

    $en_id = autolingo_translate( $src );
    $attrs = get_post_meta( $en_id, '_product_attributes', true );
    autolingo_assert( is_array( $attrs ) && isset( $attrs['niveau'] ), '_product_attributes manquant sur EN' );
    autolingo_assert( ( $attrs['niveau']['name']  ?? '' ) !== 'Niveau',      'Nom attribut non traduit' );
    autolingo_assert( ! str_contains( $attrs['niveau']['value'] ?? '', 'Débutant' ), 'Valeurs attribut non traduites' );
    autolingo_assert( str_contains( $attrs['niveau']['value'] ?? '', '|' ),  'Séparateur | perdu dans les valeurs' );
} );

autolingo_test( 'Attributs taxonomie WC (is_taxonomy=1) non traduits', function () {
    $src = autolingo_make_fr_post( [ 'post_type' => 'product' ] );
    update_post_meta( $src, '_product_attributes', [
        'pa_langue' => [
            'name' => 'pa_langue', 'value' => '',
            'position' => 0, 'is_visible' => 1, 'is_variation' => 0, 'is_taxonomy' => 1,
        ],
    ] );

    $en_id = autolingo_translate( $src );
    $attrs = get_post_meta( $en_id, '_product_attributes', true );
    autolingo_assert( is_array( $attrs ) && isset( $attrs['pa_langue'] ), '_product_attributes manquant sur EN' );
    autolingo_assert( ( $attrs['pa_langue']['name'] ?? '' ) === 'pa_langue', 'Slug taxonomie modifié' );
} );

autolingo_test( 'Note d\'achat traduite (_purchase_note)', function () {
    $src = autolingo_make_fr_post( [ 'post_type' => 'product' ] );
    update_post_meta( $src, '_purchase_note', 'Merci pour votre achat.' );

    $en_id = autolingo_translate( $src );
    $note  = get_post_meta( $en_id, '_purchase_note', true );
    autolingo_assert( ! empty( $note ),                         'Note d\'achat vide sur EN' );
    autolingo_assert( $note !== 'Merci pour votre achat.',      "Note non traduite : $note" );
} );

autolingo_test( 'Prix et stock exclus de la copie (meta-copy)', function () {
    $src = autolingo_make_fr_post( [ 'post_type' => 'product' ] );
    update_post_meta( $src, '_price',         '99' );
    update_post_meta( $src, '_regular_price', '99' );
    update_post_meta( $src, '_sku',           'SKU-001' );

    $en_id = autolingo_translate( $src );
    autolingo_assert( '' === get_post_meta( $en_id, '_price',         true ), '_price copié alors qu\'il est exclu' );
    autolingo_assert( '' === get_post_meta( $en_id, '_regular_price', true ), '_regular_price copié' );
    autolingo_assert( '' === get_post_meta( $en_id, '_sku',           true ), '_sku copié' );
} );

// ──────────────────────────────────────────────────────────────────────────
echo "\n── 8. ACF ────────────────────────────────────────────────────────────\n";

if ( ! function_exists( 'acf_add_local_field_group' ) ) {
    echo "  ⏭  ACF non installé — section ignorée\n";
} else {
    // Enregistrer un groupe de champs local pour les tests
    acf_add_local_field_group( [
        'key'      => 'group_autolingo_test',
        'title'    => 'PDT Test Fields',
        'fields'   => [
            [ 'key' => 'field_autolingo_text',    'name' => 'autolingo_text',    'label' => 'Text',    'type' => 'text' ],
            [ 'key' => 'field_autolingo_area',    'name' => 'autolingo_area',    'label' => 'Textarea','type' => 'textarea' ],
            [ 'key' => 'field_autolingo_link',    'name' => 'autolingo_link',    'label' => 'Link',    'type' => 'link' ],
            [ 'key' => 'field_autolingo_url',     'name' => 'autolingo_url',     'label' => 'URL',     'type' => 'url' ],
            [ 'key' => 'field_autolingo_rep',     'name' => 'autolingo_rep',     'label' => 'Repeater','type' => 'repeater',
              'sub_fields' => [
                  [ 'key' => 'field_autolingo_rep_title', 'name' => 'rep_title', 'label' => 'Title', 'type' => 'text' ],
                  [ 'key' => 'field_autolingo_rep_body',  'name' => 'rep_body',  'label' => 'Body',  'type' => 'textarea' ],
              ],
            ],
        ],
        'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ] ] ],
        'active'   => true,
    ] );

    autolingo_test( 'Champ ACF text traduit', function () {
        $src = autolingo_make_fr_post();
        update_field( 'field_autolingo_text', 'Bienvenue sur notre site', $src );

        $en_id = autolingo_translate( $src );
        $val   = get_field( 'field_autolingo_text', $en_id );
        autolingo_assert( ! empty( $val ),                          'Champ ACF text vide sur EN' );
        autolingo_assert( $val !== 'Bienvenue sur notre site',      "Champ ACF text non traduit : $val" );
    } );

    autolingo_test( 'Champ ACF textarea traduit', function () {
        $src = autolingo_make_fr_post();
        update_field( 'field_autolingo_area', 'Une description détaillée en français.', $src );

        $en_id = autolingo_translate( $src );
        $val   = get_field( 'field_autolingo_area', $en_id );
        autolingo_assert( ! empty( $val ),                                      'Champ ACF textarea vide sur EN' );
        autolingo_assert( $val !== 'Une description détaillée en français.',    "Champ ACF textarea non traduit : $val" );
    } );

    autolingo_test( 'Champ ACF link — titre traduit, URL préservée', function () {
        $src = autolingo_make_fr_post();
        update_field( 'field_autolingo_link', [ 'title' => 'En savoir plus', 'url' => 'https://example.com', 'target' => '' ], $src );

        $en_id = autolingo_translate( $src );
        $val   = get_field( 'field_autolingo_link', $en_id );
        autolingo_assert( ! empty( $val ),                                      'Champ ACF link vide sur EN' );
        autolingo_assert( ( $val['title'] ?? '' ) !== 'En savoir plus',         "Title du link non traduit : " . ( $val['title'] ?? '' ) );
        autolingo_assert( ( $val['url']   ?? '' ) === 'https://example.com',    'URL du link modifiée' );
    } );

    autolingo_test( 'Champ ACF url non traduit (type exclu)', function () {
        $src = autolingo_make_fr_post();
        update_field( 'field_autolingo_url', 'https://example.com/fr/page', $src );

        $en_id = autolingo_translate( $src );
        $val   = get_field( 'field_autolingo_url', $en_id );
        // url n'est pas dans autolingo_acf_translatable_types() → non traduit
        autolingo_assert(
            empty( $val ) || $val === 'https://example.com/fr/page',
            "Champ url modifié alors qu'il est exclu : $val"
        );
    } );

    autolingo_test( 'Repeater ACF traduit ligne par ligne', function () {
        $src = autolingo_make_fr_post();
        update_field( 'field_autolingo_rep', [
            [ 'rep_title' => 'Premier élément', 'rep_body' => 'Contenu de la première ligne.' ],
            [ 'rep_title' => 'Deuxième élément', 'rep_body' => 'Contenu de la deuxième ligne.' ],
        ], $src );

        $en_id = autolingo_translate( $src );
        $rows  = get_field( 'field_autolingo_rep', $en_id );
        autolingo_assert( is_array( $rows ) && count( $rows ) === 2, 'Repeater manquant ou mauvais nombre de lignes sur EN' );
        autolingo_assert( ( $rows[0]['rep_title'] ?? '' ) !== 'Premier élément',   'Ligne 1 rep_title non traduit' );
        autolingo_assert( ( $rows[1]['rep_title'] ?? '' ) !== 'Deuxième élément',  'Ligne 2 rep_title non traduit' );
        autolingo_assert( ( $rows[0]['rep_body']  ?? '' ) !== 'Contenu de la première ligne.', 'Ligne 1 rep_body non traduit' );
    } );
}

// ──────────────────────────────────────────────────────────────────────────
echo "\n── 9. Modules (toggles) ──────────────────────────────────────────────\n";

autolingo_test( 'Module SEO désactivé — metas non traduites', function () {
    update_option( 'autolingo_module_seo', '' );
    try {
        $src = autolingo_make_fr_post();
        update_post_meta( $src, '_yoast_wpseo_title', 'Titre SEO en français' );

        $en_id = autolingo_translate( $src );
        $t     = get_post_meta( $en_id, '_yoast_wpseo_title', true );
        // meta-copy copies raw value, wpseo_translate() is skipped → French value on target
        autolingo_assert(
            empty( $t ) || $t === 'Titre SEO en français',
            "Meta SEO traduite alors que le module est désactivé : $t"
        );
    } finally {
        update_option( 'autolingo_module_seo', '1' );
    }
} );

autolingo_test( 'Module WooCommerce désactivé — note d\'achat non traduite', function () {
    update_option( 'autolingo_module_woocommerce', '' );
    try {
        $src = autolingo_make_fr_post( [ 'post_type' => 'product' ] );
        // _purchase_note n'est pas affecté par les hooks WooCommerce lors de la création de post.
        update_post_meta( $src, '_purchase_note', 'Merci pour votre achat en français.' );

        $en_id = autolingo_translate( $src );
        $note  = get_post_meta( $en_id, '_purchase_note', true );
        // module désactivé → note copiée telle quelle (français) par autolingo_copy_post_meta
        autolingo_assert(
            empty( $note ) || $note === 'Merci pour votre achat en français.',
            "Note d'achat traduite alors que le module WooCommerce est désactivé : $note"
        );
    } finally {
        update_option( 'autolingo_module_woocommerce', '1' );
    }
} );

autolingo_test( 'Module page builders désactivé — _elementor_data absent sur cible', function () {
    update_option( 'autolingo_module_page_builders', '' );
    try {
        $src  = autolingo_make_fr_post();
        $json = json_encode( [ [
            'id' => 'pbtest', 'elType' => 'widget', 'widgetType' => 'heading',
            'settings' => [ 'title' => 'Titre en français', 'text' => 'Contenu en français' ],
            'elements' => [],
        ] ] );
        update_post_meta( $src, '_elementor_edit_mode', 'builder' );
        update_post_meta( $src, '_elementor_data', wp_slash( $json ) );

        $en_id = autolingo_translate( $src );
        $raw   = get_post_meta( $en_id, '_elementor_data', true );
        // _elementor_data est exclu de autolingo_copy_post_meta (module dédié) :
        // quand le module est désactivé, la clé est absente sur le post cible.
        autolingo_assert( empty( $raw ), "_elementor_data présent sur la cible alors que le module est désactivé" );
    } finally {
        update_option( 'autolingo_module_page_builders', '1' );
    }
} );

autolingo_test( 'Module ACF désactivé — champs non traduits', function () {
    update_option( 'autolingo_module_acf', '' );
    try {
        $src = autolingo_make_fr_post();
        update_post_meta( $src, 'autolingo_text', 'Texte en français non traduit' );
        update_post_meta( $src, '_autolingo_text', 'field_autolingo_text' );

        $en_id = autolingo_translate( $src );
        $val   = get_post_meta( $en_id, 'autolingo_text', true );
        // module désactivé → valeur brute copiée par meta-copy (français) ou absente
        autolingo_assert(
            empty( $val ) || $val === 'Texte en français non traduit',
            "Champ ACF traduit alors que le module est désactivé : $val"
        );
    } finally {
        update_option( 'autolingo_module_acf', '1' );
    }
} );

// ── Résultats ───────────────────────────────────────────────────────────────

$passed = $GLOBALS['autolingo_passed'];
$failed = $GLOBALS['autolingo_failed'];
$total  = $passed + $failed;

echo "\n══════════════════════════════════════\n";
printf( "  Résultats : %d/%d tests réussis\n", $passed, $total );
if ( $failed > 0 ) {
    printf( "  ⚠️  %d test(s) en échec\n", $failed );
} else {
    echo "  Tous les tests sont passés.\n";
}
echo "══════════════════════════════════════\n\n";

if ( $failed > 0 ) exit( 1 );
