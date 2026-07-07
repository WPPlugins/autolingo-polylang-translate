<?php
/**
 * Freemius — initialisation du SDK et gestion des licences.
 *
 * MODE DEV LOCAL (automatique) :
 *   Si le SDK Freemius est absent, le plugin fonctionne sans licence.
 *   Ou via wp-config.php : define( 'AUTOLINGO_DEV_MODE', true );
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'AUTOLINGO_FS_PLUGIN_ID',  '29910' );
define( 'AUTOLINGO_FS_PUBLIC_KEY', 'pk_baee914ff200e10acc72556ea9d8c' );

function autolingo_is_dev_mode(): bool {
    if ( defined( 'AUTOLINGO_DEV_MODE' ) && AUTOLINGO_DEV_MODE )            return true;
    if ( ! file_exists( AUTOLINGO_PLUGIN_DIR . 'freemius/start.php' ) ) return true;
    return false;
}

function autolingo_fs() {
    if ( autolingo_is_dev_mode() ) return null;
    global $autolingo_fs;
    if ( ! isset( $autolingo_fs ) ) {
        require_once AUTOLINGO_PLUGIN_DIR . 'freemius/start.php';
        $autolingo_fs = fs_dynamic_init( [
            'id'                             => AUTOLINGO_FS_PLUGIN_ID,
            'slug'                           => 'autolingo-polylang-translate',
            'type'                           => 'plugin',
            'public_key'                     => AUTOLINGO_FS_PUBLIC_KEY,
            'is_premium'                     => true,
            'has_addons'                     => false,
            'has_paid_plans'                 => true,
            'is_org_compliant'               => false,
            'is_premium_only'                => false,
            'bundle_license_auto_activation' => false,
            'menu' => [
                'slug'           => 'autolingo-polylang-translate',
                'override_exact' => true,
                'contact'        => true,
                'support'        => false,
                'parent'         => [ 'slug' => 'options-general.php' ],
            ],
            'is_live' => true,
        ] );
    }
    return $autolingo_fs;
}

if ( ! autolingo_is_dev_mode() ) {
    autolingo_fs();
    do_action( 'autolingo_fs_loaded' );
}

function autolingo_is_licensed(): bool {
    if ( autolingo_is_dev_mode() ) return true;
    $fs = autolingo_fs();
    return $fs ? $fs->can_use_premium_code() : false;
}

function autolingo_is_premium(): bool {
    if ( autolingo_is_dev_mode() ) return true;
    return autolingo_is_licensed();
}

function autolingo_check_license(): bool {
    if ( autolingo_is_dev_mode() ) return true;
    try {
        return autolingo_is_licensed();
    } catch ( \Exception $e ) {
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            error_log( '[PDT] Erreur licence : ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- gated on WP_DEBUG_LOG.
        }
        return false;
    }
}

function autolingo_license_notice(): void {
    if ( autolingo_is_dev_mode() ) return;
    $fs = autolingo_fs();
    if ( $fs && $fs->is_activation_mode() ) {
        echo '<div class="notice notice-warning"><p>';
        echo '🔑 <strong>AutoLingo</strong> — ';
        echo '<a href="' . esc_url( $fs->get_activation_url() ) . '">'
            . esc_html__( 'Activate your licence', 'autolingo-polylang-translate' )
            . '</a>.';
        echo '</p></div>';
    }
}
add_action( 'admin_notices', 'autolingo_license_notice' );
