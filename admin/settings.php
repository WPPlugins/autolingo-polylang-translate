<?php
/**
 * Admin — settings page.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu',             'autolingo_add_settings_page' );
add_action( 'admin_init',             'autolingo_register_settings' );
add_action( 'admin_init',             'autolingo_handle_pro_banner_dismiss' );
add_action( 'admin_post_autolingo_translate_menus', 'autolingo_handle_translate_menus' );
add_action( 'admin_notices',          'autolingo_pro_banner' );
add_action( 'admin_enqueue_scripts',  'autolingo_settings_enqueue' );

// ── Menu translation handler ───────────────────────────────

function autolingo_handle_translate_menus(): void {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Permission denied.', 'autolingo-polylang-translate' ) );
    check_admin_referer( 'autolingo_translate_menus' );

    $results = autolingo_translate_all_menus();
    $done    = count( array_filter( $results, fn( $r ) => $r['items'] > 0 ) );
    $errors  = count( array_filter( $results, fn( $r ) => $r['error'] !== '' ) );

    set_transient(
        'autolingo_menu_notice_' . get_current_user_id(),
        [ 'done' => $done, 'errors' => $errors, 'total' => count( $results ) ],
        60
    );

    wp_safe_redirect( admin_url( 'options-general.php?page=autolingo-polylang-translate' ) );
    exit;
}

function autolingo_settings_enqueue( string $hook ): void {
    if ( 'settings_page_autolingo-polylang-translate' !== $hook ) return;

    // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- version set via constant.
    wp_register_script( 'autolingo-settings', false, [], AUTOLINGO_VERSION, true );
    wp_enqueue_script( 'autolingo-settings' );

    // Provider key row toggle.
    wp_add_inline_script( 'autolingo-settings', '(function(){var sel=document.getElementById("autolingo_provider");function toggle(){document.querySelectorAll(".autolingo-key-row").forEach(function(r){r.style.display="none";});var row=document.getElementById("autolingo-key-"+sel.value);if(row)row.style.display="";}sel.addEventListener("change",toggle);toggle();})();' );

    // Async enable/disable toggle.
    wp_add_inline_script( 'autolingo-settings', '(function(){var cb=document.getElementById("autolingo_async_enabled");var wrap=document.getElementById("autolingo-async-threshold-wrap");if(cb&&wrap){cb.addEventListener("change",function(){wrap.style.display=cb.checked?"":"none";});}})();' );

    // Glossary sync button.
    wp_add_inline_script(
        'autolingo-settings',
        sprintf(
            '(function(){
                var btn = document.getElementById("autolingo-sync-glossary");
                if (!btn) return;
                btn.addEventListener("click", function() {
                    var status = document.getElementById("autolingo-glossary-status");
                    btn.disabled = true;
                    status.textContent = %s;
                    fetch(ajaxurl, {
                        method: "POST",
                        headers: {"Content-Type": "application/x-www-form-urlencoded"},
                        body: "action=autolingo_sync_glossary&_wpnonce=%s&terms=" + encodeURIComponent(document.querySelector("[name=autolingo_glossary_terms]").value)
                    })
                    .then(function(r){return r.json();})
                    .then(function(data){
                        status.textContent = data.success ? data.data.message : data.data;
                        status.style.color = data.success ? "green" : "red";
                        btn.disabled = false;
                    });
                });
            })();',
            wp_json_encode( __( 'Syncing…', 'autolingo-polylang-translate' ) ),
            esc_js( wp_create_nonce( 'autolingo_sync_glossary' ) )
        )
    );
}

// ── Pro banner (free build only) ───────────────────────────

function autolingo_handle_pro_banner_dismiss(): void {
    if ( ! isset( $_GET['autolingo_dismiss_banner'] ) ) return;
    if ( ! check_admin_referer( 'autolingo_dismiss_banner' ) ) return;
    if ( ! current_user_can( 'manage_options' ) ) return;
    update_option( 'autolingo_banner_dismissed', 1 );
    wp_safe_redirect( remove_query_arg( [ 'autolingo_dismiss_banner', '_wpnonce' ] ) );
    exit;
}

function autolingo_pro_banner(): void {
    if ( function_exists( 'autolingo_run_premium_modules' ) ) return;

    $screen = get_current_screen();
    if ( ! $screen || 'settings_page_autolingo-polylang-translate' !== $screen->id ) return;

    if ( get_option( 'autolingo_banner_dismissed' ) ) return;

    $dismiss_url = wp_nonce_url( add_query_arg( 'autolingo_dismiss_banner', '1' ), 'autolingo_dismiss_banner' );
    ?>
    <div style="background:linear-gradient(135deg,#1a5276,#2471a3);color:#fff;border-radius:6px;padding:18px 24px;margin:16px 0 0;position:relative;max-width:900px;">
        <a href="<?php echo esc_url( $dismiss_url ); ?>"
           style="position:absolute;top:10px;right:14px;color:rgba(255,255,255,0.65);text-decoration:none;font-size:22px;line-height:1;font-weight:300;"
           title="<?php esc_attr_e( 'Dismiss', 'autolingo-polylang-translate' ); ?>">×</a>

        <div style="display:flex;align-items:center;gap:20px;flex-wrap:wrap;">
            <div style="flex:1;min-width:200px;">
                <strong style="font-size:15px;">🚀 <?php esc_html_e( 'AutoLingo Pro', 'autolingo-polylang-translate' ); ?></strong>
                <p style="margin:5px 0 0;opacity:.88;font-size:13px;">
                    <?php esc_html_e( 'Translate page builders, SEO meta, ACF fields and WooCommerce products.', 'autolingo-polylang-translate' ); ?>
                </p>
            </div>
            <ul style="flex:2;min-width:260px;margin:0;padding:0;list-style:none;display:grid;grid-template-columns:1fr 1fr;gap:3px 20px;font-size:12px;opacity:.92;">
                <li>✓ <?php esc_html_e( 'Elementor, Beaver Builder, Bricks', 'autolingo-polylang-translate' ); ?></li>
                <li>✓ <?php esc_html_e( 'Yoast, RankMath, SEOPress', 'autolingo-polylang-translate' ); ?></li>
                <li>✓ <?php esc_html_e( 'ACF fields, repeater, flex content', 'autolingo-polylang-translate' ); ?></li>
                <li>✓ <?php esc_html_e( 'WooCommerce product attributes', 'autolingo-polylang-translate' ); ?></li>
            </ul>
            <div>
                <a href="https://auto-lingo.com" target="_blank" rel="noopener"
                   style="display:inline-block;background:#fff;color:#1a5276;font-weight:700;padding:10px 22px;border-radius:4px;text-decoration:none;font-size:13px;white-space:nowrap;">
                    <?php esc_html_e( 'Get AutoLingo Pro →', 'autolingo-polylang-translate' ); ?>
                </a>
            </div>
        </div>
    </div>
    <?php
}

function autolingo_add_settings_page(): void {
    add_options_page(
        __( 'AutoLingo — Translation for Polylang', 'autolingo-polylang-translate' ),
        __( 'AutoLingo', 'autolingo-polylang-translate' ),
        'manage_options',
        'autolingo-polylang-translate',
        'autolingo_settings_page_html'
    );
}

function autolingo_register_settings(): void {
    register_setting( 'autolingo_settings_group', 'autolingo_provider',        [ 'sanitize_callback' => 'sanitize_text_field' ] );
    register_setting( 'autolingo_settings_group', 'autolingo_api_key',         [ 'sanitize_callback' => 'sanitize_text_field' ] );
    register_setting( 'autolingo_settings_group', 'autolingo_openai_api_key',  [ 'sanitize_callback' => 'sanitize_text_field' ] );
    register_setting( 'autolingo_settings_group', 'autolingo_google_api_key',  [ 'sanitize_callback' => 'sanitize_text_field' ] );
    register_setting( 'autolingo_settings_group', 'autolingo_deepseek_api_key', [ 'sanitize_callback' => 'sanitize_text_field' ] );
    register_setting( 'autolingo_settings_group', 'autolingo_source_lang',     [ 'sanitize_callback' => 'sanitize_text_field' ] );
    register_setting( 'autolingo_settings_group', 'autolingo_target_langs',    [ 'sanitize_callback' => 'autolingo_sanitize_array' ] );
    register_setting( 'autolingo_settings_group', 'autolingo_post_types',      [ 'sanitize_callback' => 'autolingo_sanitize_array' ] );
    register_setting( 'autolingo_settings_group', 'autolingo_formality',            [ 'sanitize_callback' => 'sanitize_text_field' ] );
    register_setting( 'autolingo_settings_group', 'autolingo_auto_translate',       [ 'sanitize_callback' => 'sanitize_text_field' ] );
    register_setting( 'autolingo_settings_group', 'autolingo_alert_threshold',      [ 'sanitize_callback' => 'absint' ] );
    register_setting( 'autolingo_settings_group', 'autolingo_async_enabled',        [ 'sanitize_callback' => 'sanitize_text_field' ] );
    register_setting( 'autolingo_settings_group', 'autolingo_async_threshold',      [ 'sanitize_callback' => 'absint' ] );
    add_settings_section( 'autolingo_section_langs',    '🌍 ' . __( 'Languages', 'autolingo-polylang-translate' ),  '__return_false', 'autolingo-polylang-translate' );
    add_settings_section( 'autolingo_section_behavior', '⚙️ ' . __( 'Behavior', 'autolingo-polylang-translate' ),   '__return_false', 'autolingo-polylang-translate' );

    if ( function_exists( 'autolingo_run_premium_modules' ) ) {
        register_setting( 'autolingo_settings_group', 'autolingo_module_seo',           [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'autolingo_settings_group', 'autolingo_module_acf',           [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'autolingo_settings_group', 'autolingo_module_woocommerce',   [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'autolingo_settings_group', 'autolingo_module_page_builders', [ 'sanitize_callback' => 'sanitize_text_field' ] );
        add_settings_section( 'autolingo_section_modules', '🧩 ' . __( 'Modules', 'autolingo-polylang-translate' ), '__return_false', 'autolingo-polylang-translate' );
        add_settings_field( 'autolingo_modules', __( 'Fields to translate', 'autolingo-polylang-translate' ), 'autolingo_field_modules', 'autolingo-polylang-translate', 'autolingo_section_modules' );
    }

    add_settings_field( 'autolingo_source_lang',     __( 'Source Language', 'autolingo-polylang-translate' ),        'autolingo_field_source_lang',     'autolingo-polylang-translate', 'autolingo_section_langs' );
    add_settings_field( 'autolingo_target_langs',    __( 'Target Languages', 'autolingo-polylang-translate' ),       'autolingo_field_target_langs',    'autolingo-polylang-translate', 'autolingo_section_langs' );
    add_settings_field( 'autolingo_post_types',      __( 'Content Types', 'autolingo-polylang-translate' ),          'autolingo_field_post_types',      'autolingo-polylang-translate', 'autolingo_section_behavior' );
    add_settings_field( 'autolingo_auto_translate',  __( 'Auto-translate', 'autolingo-polylang-translate' ),         'autolingo_field_auto_translate',  'autolingo-polylang-translate', 'autolingo_section_behavior' );
    add_settings_field( 'autolingo_async_threshold', __( 'Background translation', 'autolingo-polylang-translate' ),  'autolingo_field_async_threshold', 'autolingo-polylang-translate', 'autolingo_section_behavior' );
    register_setting( 'autolingo_settings_group', 'autolingo_glossary_terms', [ 'sanitize_callback' => 'sanitize_textarea_field' ] );
    add_settings_section( 'autolingo_section_glossary', '📖 ' . __( 'DeepL Glossary', 'autolingo-polylang-translate' ), '__return_false', 'autolingo-polylang-translate' );
    add_settings_field( 'autolingo_glossary_terms', __( 'Glossary Terms', 'autolingo-polylang-translate' ), 'autolingo_field_glossary', 'autolingo-polylang-translate', 'autolingo_section_glossary' );
    add_settings_section( 'autolingo_section_tm', '♻️ ' . __( 'Translation Memory', 'autolingo-polylang-translate' ), '__return_false', 'autolingo-polylang-translate' );
    add_settings_field( 'autolingo_tm', __( 'Cached Segments', 'autolingo-polylang-translate' ), 'autolingo_field_tm', 'autolingo-polylang-translate', 'autolingo_section_tm' );
    add_settings_field( 'autolingo_formality',       __( 'DeepL Formality', 'autolingo-polylang-translate' ),        'autolingo_field_formality',       'autolingo-polylang-translate', 'autolingo_section_behavior' );
    add_settings_field( 'autolingo_alert_threshold', __( 'Credit Alert Threshold', 'autolingo-polylang-translate' ), 'autolingo_field_alert_threshold', 'autolingo-polylang-translate', 'autolingo_section_behavior' );
}

function autolingo_sanitize_array( $value ): array {
    if ( ! is_array( $value ) ) return [];
    return array_map( 'sanitize_text_field', $value );
}

// ── Field renderers ────────────────────────────────────────

function autolingo_field_source_lang(): void {
    $val   = autolingo_source_lang();
    $langs = autolingo_available_languages();
    echo '<select name="autolingo_source_lang">';
    foreach ( $langs as $code => $label ) {
        echo '<option value="' . esc_attr( $code ) . '"' . selected( $val, $code, false ) . '>' . esc_html( $label ) . '</option>';
    }
    echo '</select>';
    echo '<p class="description">' . esc_html__( 'Language in which you write your content.', 'autolingo-polylang-translate' ) . '</p>';
}

function autolingo_field_target_langs(): void {
    $selected = autolingo_target_langs();
    $langs    = autolingo_available_languages();
    foreach ( $langs as $code => $label ) {
        echo '<label style="display:inline-block;margin-right:16px;margin-bottom:6px;">';
        echo '<input type="checkbox" name="autolingo_target_langs[]" value="' . esc_attr( $code ) . '" ' . checked( in_array( $code, $selected, true ), true, false ) . '> ';
        echo esc_html( $label );
        echo '</label>';
    }
    echo '<p class="description">' . esc_html__( 'Languages to automatically translate into.', 'autolingo-polylang-translate' ) . '</p>';
}

function autolingo_field_modules(): void {
    $modules = [
        'page_builders' => [
            'label' => __( 'Page builders', 'autolingo-polylang-translate' ),
            'desc'  => __( 'Elementor, Beaver Builder, Bricks Builder', 'autolingo-polylang-translate' ),
        ],
        'seo'           => [
            'label' => __( 'SEO plugins', 'autolingo-polylang-translate' ),
            'desc'  => __( 'Yoast SEO, RankMath, SEOPress, The SEO Framework', 'autolingo-polylang-translate' ),
        ],
        'acf'           => [
            'label' => __( 'ACF fields', 'autolingo-polylang-translate' ),
            'desc'  => __( 'Text, textarea, wysiwyg, link title, repeater, flexible content', 'autolingo-polylang-translate' ),
        ],
        'woocommerce'   => [
            'label' => __( 'WooCommerce', 'autolingo-polylang-translate' ),
            'desc'  => __( 'Custom product attributes, purchase note. Price, stock and SKU are never modified.', 'autolingo-polylang-translate' ),
        ],
    ];

    $premium_loaded = function_exists( 'autolingo_run_premium_modules' );
    foreach ( $modules as $key => $info ) {
        $enabled = autolingo_module_enabled( $key );
        $locked  = ! $premium_loaded;
        echo '<label style="display:block;margin-bottom:8px;">';
        echo '<input type="checkbox" name="autolingo_module_' . esc_attr( $key ) . '" value="1" ' . checked( $enabled, true, false ) . ( $locked ? ' disabled' : '' ) . '> ';
        echo '<strong>' . esc_html( $info['label'] ) . '</strong>';
        if ( $locked ) {
            echo ' <span style="display:inline-block;font-size:10px;font-weight:600;color:#fff;background:#135e96;border-radius:3px;padding:1px 5px;vertical-align:middle;margin-left:4px">PRO</span>';
        }
        echo ' <span style="color:#666;font-size:12px">— ' . esc_html( $info['desc'] ) . '</span>';
        echo '</label>';
    }
    if ( ! $premium_loaded ) {
        echo '<p class="description">' . wp_kses(
            sprintf(
                /* translators: %s: link to AutoLingo website */
                __( 'These modules are included in <a href="%s" target="_blank">AutoLingo Pro</a>. Install the Pro version to enable them.', 'autolingo-polylang-translate' ),
                'https://auto-lingo.com'
            ),
            [ 'a' => [ 'href' => [], 'target' => [] ] ]
        ) . '</p>';
    } else {
        echo '<p class="description">' . esc_html__( 'Uncheck a module to skip it during translation and reduce API usage.', 'autolingo-polylang-translate' ) . '</p>';
    }
}

function autolingo_field_post_types(): void {
    $selected   = autolingo_post_types();
    $post_types = get_post_types( [ 'public' => true ], 'objects' );
    foreach ( $post_types as $pt ) {
        echo '<label style="display:inline-block;margin-right:16px;margin-bottom:6px;">';
        echo '<input type="checkbox" name="autolingo_post_types[]" value="' . esc_attr( $pt->name ) . '" ' . checked( in_array( $pt->name, $selected, true ), true, false ) . '> ';
        echo esc_html( $pt->labels->name );
        echo '</label>';
    }
    echo '<p class="description">' . esc_html__( 'Content types to include in automatic translation.', 'autolingo-polylang-translate' ) . '</p>';
}

function autolingo_field_auto_translate(): void {
    $val = autolingo_auto_translate_enabled();
    echo '<label><input type="checkbox" name="autolingo_auto_translate" value="1" ' . checked( $val, true, false ) . '> '
        . esc_html__( 'Automatically translate on each publish', 'autolingo-polylang-translate' ) . '</label>';
    echo '<p class="description">' . esc_html__( 'If disabled, translation only happens via the manual button or WP-CLI.', 'autolingo-polylang-translate' ) . '</p>';
}

function autolingo_field_formality(): void {
    $val = autolingo_option( 'formality', 'default' );
    $options = [
        'default'      => __( 'Default', 'autolingo-polylang-translate' ),
        'more'         => __( 'More formal', 'autolingo-polylang-translate' ),
        'less'         => __( 'Less formal', 'autolingo-polylang-translate' ),
        'prefer_more'  => __( 'Prefer formal (fallback to default)', 'autolingo-polylang-translate' ),
        'prefer_less'  => __( 'Prefer informal (fallback to default)', 'autolingo-polylang-translate' ),
    ];
    echo '<select name="autolingo_formality">';
    foreach ( $options as $key => $label ) {
        echo '<option value="' . esc_attr( $key ) . '" ' . selected( $val, $key, false ) . '>' . esc_html( $label ) . '</option>';
    }
    echo '</select>';
    echo '<p class="description">' . esc_html__( 'DeepL only. Applies to: DE, FR, IT, ES, NL, PL, PT, RU, JA. Ignored for other languages.', 'autolingo-polylang-translate' ) . '</p>';
}

function autolingo_field_async_threshold(): void {
    $enabled = autolingo_async_enabled();
    $val     = autolingo_async_threshold();

    // Enable / disable toggle.
    echo '<label><input type="checkbox" id="autolingo_async_enabled" name="autolingo_async_enabled" value="1" '
        . checked( $enabled, true, false ) . '> '
        . esc_html__( 'Translate long posts in the background via WP-Cron', 'autolingo-polylang-translate' )
        . '</label>';

    // Threshold (hidden when async is off).
    echo '<div id="autolingo-async-threshold-wrap" style="margin-top:10px;' . ( $enabled ? '' : 'display:none;' ) . '">';
    echo '<input type="number" name="autolingo_async_threshold" id="autolingo_async_threshold" value="' . esc_attr( (string) $val ) . '" class="small-text" min="0" step="500"> '
        . esc_html__( 'characters', 'autolingo-polylang-translate' );
    echo '<p class="description">'
        . esc_html__( 'Posts whose content is longer than this are queued in WP-Cron instead of blocking the editor save.', 'autolingo-polylang-translate' )
        . '</p>';
    echo '</div>';

    // WP-Cron environment status.
    echo '<div style="margin-top:10px;">';
    if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
        echo '<p class="description" style="color:#d63638;font-weight:600;">'
            . esc_html__( '⚠ DISABLE_WP_CRON is set — background translation is disabled and all posts will be translated synchronously. If a server-side cron job calls wp-cron.php, remove this constant to allow async translation.', 'autolingo-polylang-translate' )
            . '</p>';
    } else {
        echo '<p class="description" style="color:#00a32a;">✓ '
            . esc_html__( 'WP-Cron is active. Note: spawn_cron() requires loopback HTTP requests to be allowed by your host. If they are blocked, jobs will run on the next visitor page load instead of immediately.', 'autolingo-polylang-translate' )
            . '</p>';
    }

    // Pending async jobs.
    $pending = autolingo_count_pending_async_jobs();
    if ( $pending > 0 ) {
        echo '<p class="description">⏳ '
            . esc_html( sprintf(
                /* translators: %d: number of pending translation jobs */
                _n( '%d translation job pending in the queue.', '%d translation jobs pending in the queue.', $pending, 'autolingo-polylang-translate' ),
                $pending
            ) )
            . '</p>';
    }
    echo '</div>';
}

function autolingo_count_pending_async_jobs(): int {
    $crons = _get_cron_array();
    if ( ! is_array( $crons ) ) return 0;
    $count = 0;
    foreach ( $crons as $events ) {
        if ( isset( $events['autolingo_async_translate'] ) ) {
            $count += count( $events['autolingo_async_translate'] );
        }
    }
    return $count;
}

function autolingo_field_alert_threshold(): void {
    $val = autolingo_alert_threshold();
    echo '<input type="number" name="autolingo_alert_threshold" value="' . esc_attr( $val ) . '" class="small-text" min="0" step="10000"> '
        . esc_html__( 'characters', 'autolingo-polylang-translate' );
    echo '<p class="description">' . esc_html__( 'Shows an admin alert when remaining credit falls below this threshold (DeepL only).', 'autolingo-polylang-translate' ) . '</p>';
}

function autolingo_available_languages(): array {
    return [
        'ar' => __( 'Arabic',     'autolingo-polylang-translate' ),
        'bg' => __( 'Bulgarian',  'autolingo-polylang-translate' ),
        'cs' => __( 'Czech',      'autolingo-polylang-translate' ),
        'da' => __( 'Danish',     'autolingo-polylang-translate' ),
        'de' => __( 'German',     'autolingo-polylang-translate' ),
        'el' => __( 'Greek',      'autolingo-polylang-translate' ),
        'en' => __( 'English',    'autolingo-polylang-translate' ),
        'es' => __( 'Spanish',    'autolingo-polylang-translate' ),
        'et' => __( 'Estonian',   'autolingo-polylang-translate' ),
        'fi' => __( 'Finnish',    'autolingo-polylang-translate' ),
        'fr' => __( 'French',     'autolingo-polylang-translate' ),
        'hu' => __( 'Hungarian',  'autolingo-polylang-translate' ),
        'id' => __( 'Indonesian', 'autolingo-polylang-translate' ),
        'it' => __( 'Italian',    'autolingo-polylang-translate' ),
        'ja' => __( 'Japanese',   'autolingo-polylang-translate' ),
        'ko' => __( 'Korean',     'autolingo-polylang-translate' ),
        'lt' => __( 'Lithuanian', 'autolingo-polylang-translate' ),
        'lv' => __( 'Latvian',    'autolingo-polylang-translate' ),
        'nb' => __( 'Norwegian',  'autolingo-polylang-translate' ),
        'nl' => __( 'Dutch',      'autolingo-polylang-translate' ),
        'pl' => __( 'Polish',     'autolingo-polylang-translate' ),
        'pt' => __( 'Portuguese', 'autolingo-polylang-translate' ),
        'ro' => __( 'Romanian',   'autolingo-polylang-translate' ),
        'ru' => __( 'Russian',    'autolingo-polylang-translate' ),
        'sk' => __( 'Slovak',     'autolingo-polylang-translate' ),
        'sl' => __( 'Slovenian',  'autolingo-polylang-translate' ),
        'sv' => __( 'Swedish',    'autolingo-polylang-translate' ),
        'tr' => __( 'Turkish',    'autolingo-polylang-translate' ),
        'uk' => __( 'Ukrainian',  'autolingo-polylang-translate' ),
        'zh' => __( 'Chinese',    'autolingo-polylang-translate' ),
    ];
}

// ── Settings page HTML ─────────────────────────────────────

function autolingo_settings_page_html(): void {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $provider     = autolingo_get_provider();
    $provider_slug = autolingo_provider_slug();

    // Test connection
    $test_result = '';
    if ( isset( $_GET['autolingo_test'] ) && check_admin_referer( 'autolingo_test_connection' ) ) {
        $provider->invalidate_usage_cache();
        $usage = $provider->get_usage();
        if ( $usage ) {
            $test_result = '<div class="notice notice-success inline"><p>✅ '
                . sprintf(
                    /* translators: 1: remaining characters, 2: total characters */
                    esc_html__( 'Connection OK — %1$s characters remaining out of %2$s.', 'autolingo-polylang-translate' ),
                    number_format( $usage['remaining'], 0, ',', "\u{202F}" ),
                    number_format( $usage['limit'],     0, ',', "\u{202F}" )
                )
                . '</p></div>';
        } else {
            $test_result = '<div class="notice notice-' . ( $provider->get_usage() === false && $provider_slug !== 'deepl' ? 'info' : 'error' ) . ' inline"><p>'
                . ( $provider_slug === 'deepl'
                    ? '❌ ' . esc_html__( 'Unable to reach the DeepL API. Please check your API key.', 'autolingo-polylang-translate' )
                    : 'ℹ️ ' . esc_html__( 'This provider does not expose a usage balance.', 'autolingo-polylang-translate' )
                )
                . '</p></div>';
        }
    }

    $usage    = $provider->get_usage();
    $test_url = wp_nonce_url( admin_url( 'options-general.php?page=autolingo-polylang-translate&autolingo_test=1' ), 'autolingo_test_connection' );

    // Current API keys
    $deepl_key  = trim( get_option( 'autolingo_api_key',        '' ) );
    $openai_key = trim( get_option( 'autolingo_openai_api_key', '' ) );
    $google_key = trim( get_option( 'autolingo_google_api_key', '' ) );
    $deepseek_key = trim( get_option( 'autolingo_deepseek_api_key', '' ) );
    ?>
    <div class="wrap">
        <h1>🌍 <?php esc_html_e( 'AutoLingo — Translation for Polylang', 'autolingo-polylang-translate' ); ?></h1>

        <?php echo $test_result; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML built from esc_html__() and esc_url() calls above. ?>

        <?php
        $menu_notice = get_transient( 'autolingo_menu_notice_' . get_current_user_id() );
        if ( $menu_notice ) {
            delete_transient( 'autolingo_menu_notice_' . get_current_user_id() );
            if ( $menu_notice['errors'] === 0 ) {
                echo '<div class="notice notice-success is-dismissible"><p>✅ <strong>AutoLingo</strong> — '
                    . sprintf(
                        /* translators: %d: number of translated menus */
                        esc_html__( '%d menu(s) translated successfully. Assign them in Appearance → Menus or Polylang settings.', 'autolingo-polylang-translate' ),
                        (int) $menu_notice['done']
                    )
                    . '</p></div>';
            } else {
                echo '<div class="notice notice-warning is-dismissible"><p>⚠️ <strong>AutoLingo</strong> — '
                    . sprintf(
                        /* translators: 1: done count, 2: error count */
                        esc_html__( '%1$d menu(s) translated, %2$d skipped (empty or error).', 'autolingo-polylang-translate' ),
                        (int) $menu_notice['done'],
                        (int) $menu_notice['errors']
                    )
                    . '</p></div>';
            }
        }
        ?>

        <?php if ( $usage ) : ?>
        <div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:12px 16px;margin-bottom:20px;max-width:500px;">
            <strong><?php esc_html_e( 'DeepL Credit', 'autolingo-polylang-translate' ); ?></strong>
            <?php
            $pct   = round( ( $usage['count'] / max( $usage['limit'], 1 ) ) * 100, 1 );
            $color = $usage['remaining'] < autolingo_alert_threshold() ? '#d63638' : '#00a32a';
            $plan  = str_ends_with( $deepl_key, ':fx' )
                ? esc_html__( 'Free plan · one-time credit', 'autolingo-polylang-translate' )
                : esc_html__( 'Pro plan · monthly', 'autolingo-polylang-translate' );
            ?>
            <span style="float:right;color:#666;font-size:12px"><?php echo esc_html( $plan ); ?></span>
            <div style="background:#e0e0e0;border-radius:4px;height:10px;margin:10px 0;">
                <div style="background:<?php echo esc_attr( $color ); ?>;width:<?php echo (float) $pct; ?>%;height:10px;border-radius:4px;"></div>
            </div>
            <span style="color:<?php echo esc_attr( $color ); ?>;font-weight:600"><?php echo number_format( $usage['remaining'], 0, ',', "\u{202F}" ); ?> <?php esc_html_e( 'remaining', 'autolingo-polylang-translate' ); ?></span>
            <span style="color:#666"> / <?php echo number_format( $usage['limit'], 0, ',', "\u{202F}" ); ?> <?php esc_html_e( 'total', 'autolingo-polylang-translate' ); ?></span>
            <span style="float:right"><a href="<?php echo esc_url( $test_url ); ?>">↻ <?php esc_html_e( 'Refresh', 'autolingo-polylang-translate' ); ?></a></span>
        </div>
        <?php else : ?>
        <p><a href="<?php echo esc_url( $test_url ); ?>" class="button"><?php esc_html_e( 'Test connection', 'autolingo-polylang-translate' ); ?></a></p>
        <?php endif; ?>

        <form method="post" action="options.php">
            <?php settings_fields( 'autolingo_settings_group' ); ?>

            <?php /* ── Provider + API key (manual HTML for JS toggle) ── */ ?>
            <h2>🔑 <?php esc_html_e( 'Translation Provider', 'autolingo-polylang-translate' ); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Provider', 'autolingo-polylang-translate' ); ?></th>
                    <td>
                        <select name="autolingo_provider" id="autolingo_provider">
                            <option value="deepl"  <?php selected( $provider_slug, 'deepl' ); ?>>DeepL</option>
                            <option value="openai" <?php selected( $provider_slug, 'openai' ); ?>>OpenAI (GPT-4o)</option>
                            <option value="google" <?php selected( $provider_slug, 'google' ); ?>>Google Translate</option>
                            <option value="deepseek" <?php selected( $provider_slug, 'deepseek' ); ?>>DeepSeek</option>
                        </select>
                    </td>
                </tr>
                <tr class="autolingo-key-row" id="autolingo-key-deepl">
                    <th scope="row"><?php esc_html_e( 'DeepL API Key', 'autolingo-polylang-translate' ); ?></th>
                    <td>
                        <?php
                        $plan_badge = '';
                        if ( ! empty( $deepl_key ) ) {
                            $plan_badge = str_ends_with( $deepl_key, ':fx' )
                                ? ' <span style="color:#00a32a">✓ Free</span>'
                                : ' <span style="color:#2271b1">✓ Pro</span>';
                        }
                        ?>
                        <input type="password" name="autolingo_api_key" value="<?php echo esc_attr( $deepl_key ); ?>" class="regular-text" autocomplete="off"><?php echo $plan_badge; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded HTML spans, no user input. ?>
                        <p class="description">
                            <?php printf(
                                /* translators: %s: code suffix :fx */
                                esc_html__( 'Ends with %s for the Free plan.', 'autolingo-polylang-translate' ),
                                '<code>:fx</code>'
                            ); ?>
                            <a href="https://www.deepl.com/pro#developer" target="_blank"><?php esc_html_e( 'Get a key →', 'autolingo-polylang-translate' ); ?></a>
                        </p>
                    </td>
                </tr>
                <tr class="autolingo-key-row" id="autolingo-key-openai">
                    <th scope="row"><?php esc_html_e( 'OpenAI API Key', 'autolingo-polylang-translate' ); ?></th>
                    <td>
                        <input type="password" name="autolingo_openai_api_key" value="<?php echo esc_attr( $openai_key ); ?>" class="regular-text" autocomplete="off">
                        <p class="description">
                            <?php esc_html_e( 'Uses gpt-4o-mini. Billed per token on your OpenAI account.', 'autolingo-polylang-translate' ); ?>
                            <a href="https://platform.openai.com/api-keys" target="_blank"><?php esc_html_e( 'Get a key →', 'autolingo-polylang-translate' ); ?></a>
                        </p>
                    </td>
                </tr>
                <tr class="autolingo-key-row" id="autolingo-key-google">
                    <th scope="row"><?php esc_html_e( 'Google API Key', 'autolingo-polylang-translate' ); ?></th>
                    <td>
                        <input type="password" name="autolingo_google_api_key" value="<?php echo esc_attr( $google_key ); ?>" class="regular-text" autocomplete="off">
                        <p class="description">
                            <?php esc_html_e( 'Cloud Translation API v2 (Basic). Enable it in your Google Cloud project.', 'autolingo-polylang-translate' ); ?>
                            <a href="https://console.cloud.google.com/apis/library/translate.googleapis.com" target="_blank"><?php esc_html_e( 'Enable API →', 'autolingo-polylang-translate' ); ?></a>
                        </p>
                    </td>
                </tr>
                <tr class="autolingo-key-row" id="autolingo-key-deepseek">
                    <th scope="row"><?php esc_html_e( 'DeepSeek API Key', 'autolingo-polylang-translate' ); ?></th>
                    <td>
                        <input type="password" name="autolingo_deepseek_api_key" value="<?php echo esc_attr( $deepseek_key ); ?>" class="regular-text" autocomplete="off">
                        <p class="description">
                            <?php esc_html_e( 'Uses deepseek-chat. Billed per token on your DeepSeek account.', 'autolingo-polylang-translate' ); ?>
                            <a href="https://platform.deepseek.com/api_keys" target="_blank"><?php esc_html_e( 'Get a key →', 'autolingo-polylang-translate' ); ?></a>
                        </p>
                    </td>
                </tr>
            </table>

            <?php do_settings_sections( 'autolingo-polylang-translate' ); ?>
            <?php submit_button( __( 'Save Settings', 'autolingo-polylang-translate' ) ); ?>
        </form>

        <hr>
        <h2>🧭 <?php esc_html_e( 'Navigation Menus', 'autolingo-polylang-translate' ); ?></h2>
        <?php
        $menus = wp_get_nav_menus();
        $source_menus = array_filter( $menus, fn( $m ) => ! preg_match( '/\[[A-Z]{2,5}\]$/', $m->name ) );
        if ( empty( $source_menus ) ) :
        ?>
        <p><?php esc_html_e( 'No navigation menus found. Create menus in Appearance → Menus first.', 'autolingo-polylang-translate' ); ?></p>
        <?php else : ?>
        <p><?php
            echo esc_html( sprintf(
                /* translators: 1: menu names, 2: language codes */
                __( 'Found %1$d menu(s): %2$s. Clicking the button will create a translated copy per target language, with item labels translated and internal links re-pointed to their translated page.', 'autolingo-polylang-translate' ),
                count( $source_menus ),
                implode( ', ', array_map( fn( $m ) => '"' . $m->name . '"', $source_menus ) )
            ) );
        ?></p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="autolingo_translate_menus">
            <?php wp_nonce_field( 'autolingo_translate_menus' ); ?>
            <?php submit_button( __( '🌍 Translate All Menus', 'autolingo-polylang-translate' ), 'secondary', 'submit', false ); ?>
        </form>
        <p class="description"><?php esc_html_e( 'After translation, assign the new menus to their language locations in Appearance → Menus or the Polylang settings.', 'autolingo-polylang-translate' ); ?></p>
        <?php endif; ?>

        <h2>📋 <?php esc_html_e( 'WP-CLI Commands', 'autolingo-polylang-translate' ); ?></h2>
        <table class="widefat striped" style="max-width:680px">
            <thead><tr>
                <th><?php esc_html_e( 'Command', 'autolingo-polylang-translate' ); ?></th>
                <th><?php esc_html_e( 'Description', 'autolingo-polylang-translate' ); ?></th>
            </tr></thead>
            <tbody>
                <tr><td><code>wp pdat audit</code></td><td><?php esc_html_e( 'Lists all source posts with translation status per language', 'autolingo-polylang-translate' ); ?></td></tr>
                <tr><td><code>wp pdat audit --format=csv</code></td><td><?php esc_html_e( 'Same output in CSV format (export)', 'autolingo-polylang-translate' ); ?></td></tr>
                <tr><td><code>wp pdat translate-missing --dry-run</code></td><td><?php esc_html_e( 'Simulates without creating anything', 'autolingo-polylang-translate' ); ?></td></tr>
                <tr><td><code>wp pdat translate-missing</code></td><td><?php esc_html_e( 'Translates only missing translations', 'autolingo-polylang-translate' ); ?></td></tr>
                <tr><td><code>wp pdat translate-all</code></td><td><?php esc_html_e( 'Translates or updates all source posts', 'autolingo-polylang-translate' ); ?></td></tr>
                <tr><td><code>wp pdat translate-menus</code></td><td><?php esc_html_e( 'Translates all nav menus (same as the button above)', 'autolingo-polylang-translate' ); ?></td></tr>
                <tr><td><code>wp pdat translate-menus --menu=main-menu</code></td><td><?php esc_html_e( 'Translates one specific menu by slug', 'autolingo-polylang-translate' ); ?></td></tr>
            </tbody>
        </table>
    </div>
    <?php
}

function autolingo_field_glossary(): void {
    $terms = get_option( 'autolingo_glossary_terms', '' );
    $ids   = json_decode( get_option( 'autolingo_glossary_ids', '{}' ), true ) ?: [];
    echo '<textarea name="autolingo_glossary_terms" rows="8" style="width:100%;font-family:monospace;">' . esc_textarea( $terms ) . '</textarea>';
    echo '<p class="description">' . esc_html__( 'One term per line: Source Term | Target Term. Lines starting with # are ignored.', 'autolingo-polylang-translate' ) . '</p>';
    echo '<p><button type="button" id="autolingo-sync-glossary" class="button">' . esc_html__( 'Sync to DeepL', 'autolingo-polylang-translate' ) . '</button>';
    echo ' <span id="autolingo-glossary-status" style="margin-left:8px;"></span></p>';
    if ( ! empty( $ids ) ) {
        echo '<p class="description">' . esc_html__( 'Synced glossaries:', 'autolingo-polylang-translate' ) . ' ';
        echo wp_kses_post( implode( ', ', array_map( fn($k) => '<code>' . esc_html( $k ) . '</code>', array_keys( $ids ) ) ) ) . '</p>';
    }
    // JS is enqueued via autolingo_enqueue_settings_assets() with wp_add_inline_script().
}

function autolingo_field_tm(): void {
    $stats = autolingo_tm_stats();
    echo '<p>' . sprintf(
        /* translators: 1: number of cached segments, 2: characters saved */
        esc_html__( '%1$s segment(s) cached — an estimated %2$s characters have not needed a second API call.', 'autolingo-polylang-translate' ),
        esc_html( number_format_i18n( $stats['segments'] ) ),
        esc_html( number_format_i18n( $stats['chars_saved'] ) )
    ) . '</p>';
    echo '<p class="description">' . esc_html__( 'Paragraphs repeated across multiple posts or products (e.g. boilerplate terms) are translated once per language pair and reused everywhere else.', 'autolingo-polylang-translate' ) . '</p>';

    $clear_url = wp_nonce_url( admin_url( 'admin-post.php?action=autolingo_clear_tm' ), 'autolingo_clear_tm' );
    echo '<p><a href="' . esc_url( $clear_url ) . '" class="button" onclick="return confirm(\'' . esc_js( __( 'Clear all cached translation segments? Nothing manually translated is affected — only cached API results.', 'autolingo-polylang-translate' ) ) . '\')">' . esc_html__( 'Clear translation memory', 'autolingo-polylang-translate' ) . '</a></p>';
}

add_action( 'admin_post_autolingo_clear_tm', 'autolingo_handle_clear_tm' );
function autolingo_handle_clear_tm(): void {
    check_admin_referer( 'autolingo_clear_tm' );
    if ( current_user_can( 'manage_options' ) ) {
        autolingo_tm_clear();
    }
    wp_safe_redirect( wp_get_referer() ?: admin_url( 'options-general.php?page=autolingo-polylang-translate' ) );
    exit;
}

add_action( 'wp_ajax_autolingo_sync_glossary', 'autolingo_handle_sync_glossary' );
function autolingo_handle_sync_glossary(): void {
    check_ajax_referer( 'autolingo_sync_glossary' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Permission denied.', 'autolingo-polylang-translate' ) );
    }
    // Save terms first.
    $terms = sanitize_textarea_field( wp_unslash( $_POST['terms'] ?? '' ) );
    update_option( 'autolingo_glossary_terms', $terms );

    $provider = autolingo_make_provider();
    if ( ! ( $provider instanceof AUTOLINGO_Provider_DeepL ) ) {
        wp_send_json_error( __( 'Glossary is only available with the DeepL provider.', 'autolingo-polylang-translate' ) );
    }
    $result = $provider->sync_glossary();
    if ( isset( $result['error'] ) ) {
        wp_send_json_error( $result['error'] );
    }
    wp_send_json_success( [
        'message' => sprintf(
            /* translators: %1$d synced, %2$d total */
            __( '✓ %1$d/%2$d glossaries synced.', 'autolingo-polylang-translate' ),
            $result['synced'], $result['total']
        ),
    ] );
}
