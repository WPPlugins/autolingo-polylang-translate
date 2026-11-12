<?php
/**
 * Plugin Name:       AutoLingo — Automatic Translation for Polylang & WPML
 * Plugin URI:        https://auto-lingo.com
 * Description:       Automatic translation via DeepL, OpenAI or Google Translate. Works with Polylang and WPML. Supports Elementor, Beaver Builder, Bricks, WooCommerce, ACF, and all major SEO plugins.
 * Version:           1.11.3
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            AutoLingo
 * Author Email:      contact@auto-lingo.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       autolingo-polylang-translate
 * Domain Path:       /languages
 * WP HealthKit:      whk_verify_52082ec2f373259648ec8044fdb6a8c3
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Constantes ────────────────────────────────────────────
define( 'AUTOLINGO_VERSION',     '1.11.3' );
define( 'AUTOLINGO_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'AUTOLINGO_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'AUTOLINGO_PLUGIN_FILE', __FILE__ );

// ── Chargement des modules ─────────────────────────────────
// Ordre important : logger → licensing → multilingual → helpers → providers → reste
require_once AUTOLINGO_PLUGIN_DIR . 'includes/logger.php';
require_once AUTOLINGO_PLUGIN_DIR . 'includes/tm.php';
require_once AUTOLINGO_PLUGIN_DIR . 'includes/licensing.php';
require_once AUTOLINGO_PLUGIN_DIR . 'includes/multilingual/interface-adapter.php';
require_once AUTOLINGO_PLUGIN_DIR . 'includes/multilingual/class-polylang-adapter.php';
require_once AUTOLINGO_PLUGIN_DIR . 'includes/multilingual/class-wpml-adapter.php';
require_once AUTOLINGO_PLUGIN_DIR . 'includes/multilingual/functions.php';
require_once AUTOLINGO_PLUGIN_DIR . 'includes/helpers.php';
require_once AUTOLINGO_PLUGIN_DIR . 'includes/providers/interface-provider.php';
require_once AUTOLINGO_PLUGIN_DIR . 'includes/providers/class-deepl.php';
require_once AUTOLINGO_PLUGIN_DIR . 'includes/providers/trait-chat-translation.php';
require_once AUTOLINGO_PLUGIN_DIR . 'includes/providers/class-openai.php';
require_once AUTOLINGO_PLUGIN_DIR . 'includes/providers/class-google.php';
require_once AUTOLINGO_PLUGIN_DIR . 'includes/providers/class-deepseek.php';
require_once AUTOLINGO_PLUGIN_DIR . 'includes/providers/class-microsoft.php';
// Premium only: AutoLingo AI, included (not in the WordPress.org build).
if ( file_exists( AUTOLINGO_PLUGIN_DIR . 'includes/providers/class-autolingo-ai.php' ) ) {
    require_once AUTOLINGO_PLUGIN_DIR . 'includes/providers/class-autolingo-ai.php';
}
require_once AUTOLINGO_PLUGIN_DIR . 'includes/deepl.php';
require_once AUTOLINGO_PLUGIN_DIR . 'includes/meta-copy.php';
require_once AUTOLINGO_PLUGIN_DIR . 'includes/media.php';
require_once AUTOLINGO_PLUGIN_DIR . 'includes/taxonomy.php';
require_once AUTOLINGO_PLUGIN_DIR . 'includes/translate.php';
require_once AUTOLINGO_PLUGIN_DIR . 'includes/menu.php';
require_once AUTOLINGO_PLUGIN_DIR . 'includes/bulk.php';

// Premium module files — absent in the free (WP.org) build.
foreach ( [
    'includes/elementor.php',
    'includes/beaver-builder.php',
    'includes/bricks.php',
    'includes/woocommerce.php',
    'includes/seo.php',
    'includes/acf.php',
    'includes/strings.php',
    'includes/review.php',
    'includes/assistant.php',
    'includes/premium-modules.php', // must load last (calls functions defined above)
] as $autolingo_file ) {
    if ( file_exists( AUTOLINGO_PLUGIN_DIR . $autolingo_file ) ) {
        require_once AUTOLINGO_PLUGIN_DIR . $autolingo_file;
    }
}
unset( $autolingo_file );

require_once AUTOLINGO_PLUGIN_DIR . 'includes/hooks.php';
require_once AUTOLINGO_PLUGIN_DIR . 'admin/settings.php';
require_once AUTOLINGO_PLUGIN_DIR . 'admin/notices.php';
require_once AUTOLINGO_PLUGIN_DIR . 'admin/metabox.php';
require_once AUTOLINGO_PLUGIN_DIR . 'admin/dashboard-widget.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once AUTOLINGO_PLUGIN_DIR . 'includes/cli.php';
}

// ── Activation / Désactivation ─────────────────────────────
register_activation_hook( __FILE__, 'autolingo_activate' );
function autolingo_activate() {
    autolingo_log_install();
    autolingo_log_schedule_cron();
    autolingo_tm_install();
    add_option( 'autolingo_provider',        'deepl' );
    add_option( 'autolingo_api_key',         '' );
    add_option( 'autolingo_openai_api_key',  '' );
    add_option( 'autolingo_google_api_key',  '' );
    add_option( 'autolingo_deepseek_api_key', '' );
    if ( false === get_option( 'autolingo_source_lang' ) ) {
        add_option( 'autolingo_language_setup_pending', '1' );
    }
    add_option( 'autolingo_source_lang',     'fr' );
    add_option( 'autolingo_target_langs',    [ 'en' ] );
    $default_types = [ 'post', 'page' ];
    if ( class_exists( 'WooCommerce' ) ) {
        $default_types[] = 'product';
    }
    add_option( 'autolingo_post_types', $default_types );
    add_option( 'autolingo_formality',            'default' );
    add_option( 'autolingo_glossary_terms',       '' );
    add_option( 'autolingo_glossary_ids',          '{}' );
    add_option( 'autolingo_auto_translate',       '1' );
    add_option( 'autolingo_async_enabled',        '1' );
    add_option( 'autolingo_async_threshold',      5000 );
    add_option( 'autolingo_alert_threshold',      100000 );
    add_option( 'autolingo_module_seo',           '1' );
    add_option( 'autolingo_module_acf',           '1' );
    add_option( 'autolingo_module_woocommerce',   '1' );
    add_option( 'autolingo_module_page_builders', '1' );
}

register_deactivation_hook( __FILE__, 'autolingo_deactivate' );
function autolingo_deactivate() {
    delete_transient( 'autolingo_deepl_usage' );
    wp_clear_scheduled_hook( 'autolingo_log_cleanup' );
}

register_uninstall_hook( __FILE__, 'autolingo_uninstall' );
function autolingo_uninstall() {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- uninstall cleanup, table name is always {prefix}autolingo_logs.
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}autolingo_logs" );
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- uninstall cleanup, table name is always {prefix}autolingo_tm.
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}autolingo_tm" );

    foreach ( [
        'autolingo_provider', 'autolingo_api_key', 'autolingo_openai_api_key', 'autolingo_google_api_key', 'autolingo_deepseek_api_key',
        'autolingo_source_lang', 'autolingo_target_langs', 'autolingo_post_types', 'autolingo_auto_translate',
        'autolingo_alert_threshold', 'autolingo_module_seo', 'autolingo_module_acf',
        'autolingo_module_woocommerce', 'autolingo_module_page_builders',
        'autolingo_log_db_version', 'autolingo_low_credit_alert', 'autolingo_banner_dismissed',
        'autolingo_tm_db_version', 'autolingo_language_setup_pending',
    ] as $option ) {
        delete_option( $option );
    }

    delete_transient( 'autolingo_deepl_usage' );
    delete_transient( 'autolingo_translation_progress' );
    wp_clear_scheduled_hook( 'autolingo_log_cleanup' );
}
