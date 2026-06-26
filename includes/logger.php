<?php
/**
 * Logger — stockage des événements de traduction en base de données.
 *
 * Table : {prefix}autolingo_logs
 * Colonnes : id, created_at, level, post_id, post_title, lang, message, context
 *
 * Niveaux : success | error | warning | info
 * Rétention : 30 jours (nettoyage automatique via WP Cron)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ══════════════════════════════════════════════════════════
//  1. INSTALLATION / DÉSINSTALLATION DE LA TABLE
// ══════════════════════════════════════════════════════════

function autolingo_log_install(): void {
    global $wpdb;

    $table   = $wpdb->prefix . 'autolingo_logs';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        level       VARCHAR(10)     NOT NULL DEFAULT 'info',
        post_id     BIGINT UNSIGNED          DEFAULT NULL,
        post_title  VARCHAR(255)             DEFAULT NULL,
        lang        VARCHAR(10)              DEFAULT NULL,
        message     TEXT            NOT NULL,
        context     TEXT                     DEFAULT NULL,
        PRIMARY KEY (id),
        KEY level      (level),
        KEY post_id    (post_id),
        KEY created_at (created_at)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    update_option( 'autolingo_log_db_version', '1.0' );
}

function autolingo_log_uninstall(): void {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- uninstall cleanup, table name is always {prefix}autolingo_logs.
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}autolingo_logs" );
    delete_option( 'autolingo_log_db_version' );
    wp_clear_scheduled_hook( 'autolingo_log_cleanup' );
}


// ══════════════════════════════════════════════════════════
//  2. ÉCRITURE D'UN LOG
// ══════════════════════════════════════════════════════════

/**
 * Enregistre un événement dans la table de logs.
 *
 * @param string     $level     'success' | 'error' | 'warning' | 'info'
 * @param string     $message   Message lisible
 * @param array      $context   Données supplémentaires (optionnel)
 * @param int|null   $post_id   ID du post concerné (optionnel)
 * @param string|null $lang     Langue cible (optionnel)
 */
function autolingo_log( string $level, string $message, array $context = [], ?int $post_id = null, ?string $lang = null ): void {
    global $wpdb;

    $post_title = null;
    if ( $post_id ) {
        $post       = get_post( $post_id );
        $post_title = $post ? mb_substr( $post->post_title, 0, 200 ) : null;
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- log writes, caching not applicable for a write-only log table.
    $wpdb->insert(
        $wpdb->prefix . 'autolingo_logs',
        [
            'level'      => $level,
            'post_id'    => $post_id,
            'post_title' => $post_title,
            'lang'       => $lang ? strtoupper( $lang ) : null,
            'message'    => $message,
            'context'    => ! empty( $context ) ? wp_json_encode( $context ) : null,
        ],
        [ '%s', '%d', '%s', '%s', '%s', '%s' ]
    );

    if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
        error_log( "[PDT/{$level}] {$message}" ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- gated on WP_DEBUG_LOG.
    }
}

// Raccourcis
function autolingo_log_success( string $msg, array $ctx = [], ?int $post_id = null, ?string $lang = null ): void {
    autolingo_log( 'success', $msg, $ctx, $post_id, $lang );
}
function autolingo_log_error( string $msg, array $ctx = [], ?int $post_id = null, ?string $lang = null ): void {
    autolingo_log( 'error', $msg, $ctx, $post_id, $lang );
}
function autolingo_log_warning( string $msg, array $ctx = [], ?int $post_id = null, ?string $lang = null ): void {
    autolingo_log( 'warning', $msg, $ctx, $post_id, $lang );
}
function autolingo_log_info( string $msg, array $ctx = [], ?int $post_id = null, ?string $lang = null ): void {
    autolingo_log( 'info', $msg, $ctx, $post_id, $lang );
}


// ══════════════════════════════════════════════════════════
//  3. LECTURE DES LOGS
// ══════════════════════════════════════════════════════════

/**
 * Récupère les entrées de log avec filtres et pagination.
 *
 * @param array $args {
 *   @type string   $level    Filtrer par niveau ('' = tous)
 *   @type int      $post_id  Filtrer par post
 *   @type int      $limit    Nombre de résultats (défaut: 50)
 *   @type int      $offset   Offset pour pagination
 * }
 * @return array { rows: array, total: int }
 */
function autolingo_log_get( array $args = [] ): array {
    global $wpdb;

    $level   = sanitize_text_field( $args['level']   ?? '' );
    $post_id = intval( $args['post_id'] ?? 0 );
    $limit   = min( intval( $args['limit'] ?? 50 ), 200 );
    $offset  = intval( $args['offset'] ?? 0 );

    $table  = $wpdb->prefix . 'autolingo_logs';
    $where  = [ '1=1' ];
    $params = [];

    if ( $level ) {
        $where[]  = 'level = %s';
        $params[] = $level;
    }
    if ( $post_id ) {
        $where[]  = 'post_id = %d';
        $params[] = $post_id;
    }

    $where_sql = implode( ' AND ', $where );

    // $table is always {prefix}autolingo_logs (never user input). $where_sql is built from hardcoded strings only.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $total = (int) $wpdb->get_var(
        $params
            ? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", ...$params )
            : "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}"
    );

    $params[] = $limit;
    $params[] = $offset;

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d",
            ...$params
        ),
        ARRAY_A
    );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

    return [ 'rows' => $rows ?: [], 'total' => $total ];
}

/**
 * Vide tous les logs (ou uniquement ceux de plus de N jours).
 */
function autolingo_log_clear( int $days = 0 ): int {
    global $wpdb;
    $table = $wpdb->prefix . 'autolingo_logs';

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- log table management, $table is always {prefix}autolingo_logs.
    if ( $days > 0 ) {
        $deleted = $wpdb->query(
            $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)", $days )
        );
    } else {
        $deleted = $wpdb->query( "DELETE FROM {$table}" );
    }
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

    return (int) $deleted;
}


// ══════════════════════════════════════════════════════════
//  4. NETTOYAGE AUTOMATIQUE (CRON)
// ══════════════════════════════════════════════════════════

add_action( 'autolingo_log_cleanup', 'autolingo_log_auto_cleanup' );

function autolingo_log_auto_cleanup(): void {
    $deleted = autolingo_log_clear( 30 );
    if ( $deleted > 0 ) {
        autolingo_log_info( "Nettoyage automatique : {$deleted} entrée(s) supprimée(s) (> 30 jours)." );
    }
}

function autolingo_log_schedule_cron(): void {
    if ( ! wp_next_scheduled( 'autolingo_log_cleanup' ) ) {
        wp_schedule_event( time(), 'daily', 'autolingo_log_cleanup' );
    }
}


// ══════════════════════════════════════════════════════════
//  5. PAGE ADMIN — JOURNAL DES TRADUCTIONS
// ══════════════════════════════════════════════════════════

add_action( 'admin_menu', 'autolingo_log_add_menu_page' );

function autolingo_log_add_menu_page(): void {
    add_submenu_page(
        'options-general.php',
        __( 'Translation Log — AutoLingo', 'autolingo-polylang-translate' ),
        __( 'DeepL Log', 'autolingo-polylang-translate' ),
        'manage_options',
        'autolingo-logs',
        'autolingo_log_page_html'
    );
}

function autolingo_log_page_html(): void {
    if ( ! current_user_can( 'manage_options' ) ) return;

    // Action : vider les logs
    if ( isset( $_POST['autolingo_clear_logs'] ) && check_admin_referer( 'autolingo_clear_logs' ) ) {
        $deleted = autolingo_log_clear();
        echo '<div class="notice notice-success is-dismissible"><p>'
            . sprintf(
                /* translators: %d: number of deleted log entries */
                esc_html( _n( '%d entry deleted.', '%d entries deleted.', $deleted, 'autolingo-polylang-translate' ) ),
                (int) $deleted
            )
            . '</p></div>';
    }

    // Filtres GET
    $filter_level   = sanitize_text_field( wp_unslash( $_GET['level'] ?? '' ) );
    $filter_post_id = intval( $_GET['post_id'] ?? 0 );
    $page_num       = max( 1, intval( $_GET['autolingo_page'] ?? 1 ) );
    $per_page       = 50;
    $offset         = ( $page_num - 1 ) * $per_page;

    $data  = autolingo_log_get( [
        'level'   => $filter_level,
        'post_id' => $filter_post_id,
        'limit'   => $per_page,
        'offset'  => $offset,
    ] );
    $rows  = $data['rows'];
    $total = $data['total'];
    $pages = (int) ceil( $total / $per_page );

    $level_labels = [
        'success' => [ 'label' => __( 'Success',     'autolingo-polylang-translate' ), 'color' => '#00a32a', 'bg' => '#edfaef' ],
        'error'   => [ 'label' => __( 'Error',       'autolingo-polylang-translate' ), 'color' => '#d63638', 'bg' => '#fceaea' ],
        'warning' => [ 'label' => __( 'Warning',     'autolingo-polylang-translate' ), 'color' => '#996800', 'bg' => '#fcf9e8' ],
        'info'    => [ 'label' => __( 'Info',        'autolingo-polylang-translate' ), 'color' => '#2271b1', 'bg' => '#eef6fb' ],
    ];

    $base_url = admin_url( 'options-general.php?page=autolingo-logs' );
    ?>
    <div class="wrap">
        <h1>📋 <?php esc_html_e( 'Translation Log — AutoLingo', 'autolingo-polylang-translate' ); ?></h1>

        <div style="display:flex;align-items:center;gap:16px;margin:16px 0;flex-wrap:wrap;">

            <?php foreach ( $level_labels as $lvl => $info ) :
                $count_data = autolingo_log_get( [ 'level' => $lvl, 'limit' => 1 ] );
                $count = $count_data['total'];
            ?>
            <a href="<?php echo esc_url( add_query_arg( 'level', $lvl, $base_url ) ); ?>"
               style="padding:6px 14px;border-radius:4px;font-size:13px;text-decoration:none;
                      background:<?php echo esc_attr( $info['bg'] ); ?>;color:<?php echo esc_attr( $info['color'] ); ?>;
                      border:1px solid <?php echo esc_attr( $info['color'] ); ?>20;
                      <?php echo $filter_level === $lvl ? 'font-weight:600;' : ''; ?>">
                <?php echo esc_html( $info['label'] ); ?>
                <span style="font-weight:600;margin-left:4px"><?php echo (int) $count; ?></span>
            </a>
            <?php endforeach; ?>

            <?php if ( $filter_level || $filter_post_id ) : ?>
            <a href="<?php echo esc_url( $base_url ); ?>" style="font-size:13px;color:#666;">
                ✕ <?php esc_html_e( 'Reset filters', 'autolingo-polylang-translate' ); ?>
            </a>
            <?php endif; ?>

            <div style="margin-left:auto;">
                <form method="post" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Clear all logs?', 'autolingo-polylang-translate' ) ); ?>')">
                    <?php wp_nonce_field( 'autolingo_clear_logs' ); ?>
                    <button type="submit" name="autolingo_clear_logs" class="button">🗑 <?php esc_html_e( 'Clear logs', 'autolingo-polylang-translate' ); ?></button>
                </form>
            </div>
        </div>

        <p style="color:#666;font-size:13px;margin-bottom:16px">
            <?php
            echo sprintf(
                /* translators: %d: total number of log entries */
                esc_html( _n( '%d entry', '%d entries', $total, 'autolingo-polylang-translate' ) ),
                (int) $total
            );
            if ( $filter_level ) {
                echo ' · ' . sprintf(
                    /* translators: %s: filter level label */
                    esc_html__( 'filter: %s', 'autolingo-polylang-translate' ),
                    esc_html( $level_labels[ $filter_level ]['label'] ?? $filter_level )
                );
            }
            ?>
            · <?php esc_html_e( 'Auto-retention: 30 days', 'autolingo-polylang-translate' ); ?>
        </p>

        <?php if ( empty( $rows ) ) : ?>
            <div style="padding:40px;text-align:center;background:#f9f9f9;border:1px solid #ddd;border-radius:4px;">
                <p style="color:#888;font-size:14px;margin:0">
                    <?php echo $filter_level
                        ? esc_html__( 'No logs found for this filter.', 'autolingo-polylang-translate' )
                        : esc_html__( 'No logs found.', 'autolingo-polylang-translate' ); ?>
                </p>
            </div>
        <?php else : ?>

        <table class="wp-list-table widefat fixed striped" style="font-size:13px;">
            <thead>
                <tr>
                    <th style="width:140px"><?php esc_html_e( 'Date',    'autolingo-polylang-translate' ); ?></th>
                    <th style="width:100px"><?php esc_html_e( 'Level',   'autolingo-polylang-translate' ); ?></th>
                    <th style="width:50px"><?php esc_html_e( 'Language', 'autolingo-polylang-translate' ); ?></th>
                    <th><?php esc_html_e( 'Message', 'autolingo-polylang-translate' ); ?></th>
                    <th style="width:200px"><?php esc_html_e( 'Post',    'autolingo-polylang-translate' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $rows as $row ) :
                $lvl_info = $level_labels[ $row['level'] ] ?? [ 'label' => $row['level'], 'color' => '#666', 'bg' => '#f5f5f5' ];
                $context  = $row['context'] ? json_decode( $row['context'], true ) : [];
            ?>
                <tr>
                    <td style="color:#666;white-space:nowrap">
                        <?php echo esc_html( wp_date( 'd/m/Y H:i:s', strtotime( $row['created_at'] ) ) ); ?>
                    </td>
                    <td>
                        <span style="padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;
                                     background:<?php echo esc_attr( $lvl_info['bg'] ); ?>;
                                     color:<?php echo esc_attr( $lvl_info['color'] ); ?>">
                            <?php echo esc_html( strtoupper( $lvl_info['label'] ) ); ?>
                        </span>
                    </td>
                    <td style="font-weight:600;color:#2271b1">
                        <?php echo esc_html( $row['lang'] ?? '—' ); ?>
                    </td>
                    <td>
                        <?php echo esc_html( $row['message'] ); ?>
                        <?php if ( ! empty( $context ) ) : ?>
                        <details style="margin-top:4px">
                            <summary style="font-size:11px;color:#888;cursor:pointer"><?php esc_html_e( 'Details', 'autolingo-polylang-translate' ); ?></summary>
                            <pre style="font-size:11px;background:#f5f5f5;padding:6px;border-radius:3px;margin-top:4px;overflow:auto"><?php echo esc_html( wp_json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); ?></pre>
                        </details>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ( $row['post_id'] ) : ?>
                            <a href="<?php echo esc_url( add_query_arg( 'post_id', $row['post_id'], $base_url ) ); ?>"
                               style="color:#2271b1;text-decoration:none;" title="<?php esc_attr_e( 'Filter by this post', 'autolingo-polylang-translate' ); ?>">
                                #<?php echo intval( $row['post_id'] ); ?>
                            </a>
                            <?php if ( $row['post_title'] ) : ?>
                                <br><span style="color:#666;font-size:11px"><?php echo esc_html( mb_substr( $row['post_title'], 0, 35 ) ); ?></span>
                            <?php endif; ?>
                            <a href="<?php echo esc_url( get_edit_post_link( $row['post_id'] ) ); ?>"
                               style="display:block;font-size:11px;color:#888;margin-top:2px"><?php esc_html_e( 'Edit →', 'autolingo-polylang-translate' ); ?></a>
                        <?php else : ?>
                            <span style="color:#aaa">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ( $pages > 1 ) : ?>
        <div style="margin-top:16px;display:flex;gap:8px;align-items:center">
            <?php if ( $page_num > 1 ) : ?>
                <a href="<?php echo esc_url( add_query_arg( 'autolingo_page', $page_num - 1, add_query_arg( 'level', $filter_level, $base_url ) ) ); ?>" class="button">← <?php esc_html_e( 'Previous', 'autolingo-polylang-translate' ); ?></a>
            <?php endif; ?>
            <span style="font-size:13px;color:#666"><?php echo sprintf(
                /* translators: 1: current page number, 2: total pages */
                esc_html__( 'Page %1$s / %2$s', 'autolingo-polylang-translate' ),
                (int) $page_num, (int) $pages
            ); ?></span>
            <?php if ( $page_num < $pages ) : ?>
                <a href="<?php echo esc_url( add_query_arg( 'autolingo_page', $page_num + 1, add_query_arg( 'level', $filter_level, $base_url ) ) ); ?>" class="button"><?php esc_html_e( 'Next', 'autolingo-polylang-translate' ); ?> →</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php endif; ?>
    </div>
    <?php
}
