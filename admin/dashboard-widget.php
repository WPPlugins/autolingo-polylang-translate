<?php
/**
 * Admin — widget tableau de bord WordPress.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'wp_dashboard_setup', 'autolingo_register_dashboard_widget' );

function autolingo_register_dashboard_widget(): void {
    wp_add_dashboard_widget(
        'autolingo_usage_widget',
        '🌍 ' . __( 'AutoLingo — Credit', 'autolingo-polylang-translate' ),
        'autolingo_dashboard_widget_html'
    );
}

function autolingo_dashboard_widget_html(): void {
    $usage    = autolingo_get_usage();
    $progress = autolingo_get_translation_progress();

    // ── Translation coverage ──────────────────────────────
    if ( ! empty( $progress['langs'] ) ) {
        $total = $progress['total'];

        echo '<p style="font-weight:600;font-size:12px;text-transform:uppercase;color:#1d2327;letter-spacing:.5px;margin:0 0 8px">';
        echo esc_html__( 'Translation Coverage', 'autolingo-polylang-translate' );
        echo '</p>';

        foreach ( $progress['langs'] as $lang => $data ) {
            $done  = $data['done'];
            $pct   = $data['pct'];
            $color = $pct === 100 ? '#00a32a' : ( $pct >= 50 ? '#996800' : '#d63638' );
            ?>
            <div style="margin-bottom:10px">
                <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:3px">
                    <span style="font-size:12px;font-weight:600;color:#1d2327"><?php echo esc_html( strtoupper( $lang ) ); ?></span>
                    <span style="font-size:11px;color:<?php echo esc_attr( $color ); ?>;font-weight:600">
                        <?php echo sprintf(
                            /* translators: 1: translated count, 2: total count, 3: percentage */
                            esc_html__( '%1$s / %2$s (%3$s%%)', 'autolingo-polylang-translate' ),
                            (int) $done, (int) $total, (int) $pct
                        ); ?>
                    </span>
                </div>
                <div style="background:#e0e0e0;border-radius:4px;height:8px">
                    <div style="background:<?php echo esc_attr( $color ); ?>;width:<?php echo (int) $pct; ?>%;height:8px;border-radius:4px;transition:width .3s"></div>
                </div>
            </div>
            <?php
        }

        echo '<p style="color:#888;font-size:11px;margin:0 0 16px">';
        echo sprintf(
            /* translators: %d: total number of source posts */
            esc_html( _n( '%d source post', '%d source posts', $total, 'autolingo-polylang-translate' ) ),
            (int) $total
        );
        echo ' · <a href="' . esc_url( admin_url( 'edit.php?lang=' . autolingo_source_lang() ) ) . '">'
            . esc_html__( 'View all', 'autolingo-polylang-translate' )
            . '</a>';
        echo '</p>';
    }

    // ── ROI & economies ────────────────────────────────────
    // Estimate only — no per-translation character count is logged, so this is
    // derived from the number of successful translations × configurable
    // assumptions (filterable so a site owner can correct them for their content).
    $success_count = autolingo_log_get( [ 'level' => 'success', 'limit' => 1 ] )['total'] ?? 0;

    if ( $success_count > 0 ) {
        $words_per_post   = (int) apply_filters( 'autolingo_roi_words_per_post', 500 );
        $minutes_per_post = (int) apply_filters( 'autolingo_roi_minutes_per_post', 15 );
        $rate_per_word    = (float) apply_filters( 'autolingo_roi_rate_per_word', 0.10 );

        $hours_saved = round( ( $success_count * $minutes_per_post ) / 60, 1 );
        $money_saved = round( $success_count * $words_per_post * $rate_per_word );

        echo '<p style="font-weight:600;font-size:12px;text-transform:uppercase;color:#1d2327;letter-spacing:.5px;margin:0 0 8px">';
        echo esc_html__( 'Estimated Savings', 'autolingo-polylang-translate' );
        echo '</p>';

        echo '<table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:6px">';
        echo '<tr><td style="color:#666">⏱️ ' . esc_html__( 'Time saved', 'autolingo-polylang-translate' ) . '</td><td style="text-align:right;font-weight:600">'
            . sprintf(
                /* translators: %s: number of hours, formatted */
                esc_html__( '~%s h', 'autolingo-polylang-translate' ),
                number_format( $hours_saved, 1, ',', "\u{202F}" )
            ) . '</td></tr>';
        echo '<tr><td style="color:#666">💰 ' . esc_html__( 'vs. a translation agency', 'autolingo-polylang-translate' ) . '</td><td style="text-align:right;font-weight:600;color:#00a32a">'
            . sprintf(
                /* translators: %s: amount saved, formatted */
                esc_html__( '~$%s', 'autolingo-polylang-translate' ),
                number_format( $money_saved, 0, ',', "\u{202F}" )
            ) . '</td></tr>';
        echo '</table>';

        echo '<p style="color:#888;font-size:11px;margin:0 0 16px">';
        echo sprintf(
            /* translators: 1: number of translations, 2: assumed words per post */
            esc_html__( 'Based on %1$d translations, assuming ~%2$d words/post. Adjustable via filters.', 'autolingo-polylang-translate' ),
            (int) $success_count,
            (int) $words_per_post
        );
        echo '</p>';
    }

    // ── Provider credit ───────────────────────────────────
    $provider = autolingo_get_provider();
    $settings_url = admin_url( 'options-general.php?page=autolingo-polylang-translate' );

    if ( ! $usage ) {
        echo '<p style="font-weight:600;font-size:12px;text-transform:uppercase;color:#1d2327;letter-spacing:.5px;margin:0 0 8px">';
        echo esc_html( $provider->get_name() );
        echo '</p>';

        if ( $provider->get_usage() === false && autolingo_provider_slug() !== 'deepl' ) {
            echo '<p style="color:#888;font-size:12px;margin:0">';
            echo esc_html__( 'This provider does not expose a usage balance.', 'autolingo-polylang-translate' );
            echo '</p>';
        } else {
            echo '<p>' . esc_html__( 'Unable to retrieve balance. Check your API key.', 'autolingo-polylang-translate' ) . '</p>';
        }

        echo '<p style="margin-top:8px"><a href="' . esc_url( $settings_url ) . '">'
            . esc_html__( 'Settings →', 'autolingo-polylang-translate' )
            . '</a></p>';
        return;
    }

    echo '<p style="font-weight:600;font-size:12px;text-transform:uppercase;color:#1d2327;letter-spacing:.5px;margin:0 0 8px">';
    $is_ai = $provider->get_slug() === 'autolingo'; // the usage shown is AutoLingo AI's, not DeepL's
    echo $is_ai ? esc_html__( 'AutoLingo AI — this month', 'autolingo-polylang-translate' ) : esc_html__( 'DeepL Credit', 'autolingo-polylang-translate' );
    echo '</p>';

    $pct   = round( ( $usage['count'] / max( $usage['limit'], 1 ) ) * 100, 1 );
    $color = $usage['remaining'] < autolingo_alert_threshold() ? '#d63638' : '#00a32a';
    $plan  = $is_ai
        ? esc_html__( 'Included with Premium · monthly', 'autolingo-polylang-translate' )
        : ( str_ends_with( trim( get_option( 'autolingo_api_key', '' ) ), ':fx' )
            ? esc_html__( 'Free — one-time credit', 'autolingo-polylang-translate' )
            : esc_html__( 'Pro — monthly', 'autolingo-polylang-translate' ) );

    echo '<table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:10px">';
    echo '<tr><td style="color:#666">' . esc_html__( 'Plan',      'autolingo-polylang-translate' ) . '</td><td style="text-align:right">' . esc_html( $plan ) . '</td></tr>';
    echo '<tr><td style="color:#666">' . esc_html__( 'Used',      'autolingo-polylang-translate' ) . '</td><td style="text-align:right">' . number_format( $usage['count'],     0, ',', "\u{202F}" ) . '</td></tr>';
    echo '<tr><td style="color:#666">' . esc_html__( 'Remaining', 'autolingo-polylang-translate' ) . '</td><td style="text-align:right;color:' . esc_attr( $color ) . ';font-weight:600">' . number_format( $usage['remaining'], 0, ',', "\u{202F}" ) . '</td></tr>';
    echo '<tr><td style="color:#666">' . esc_html__( 'Total',     'autolingo-polylang-translate' ) . '</td><td style="text-align:right">' . number_format( $usage['limit'],     0, ',', "\u{202F}" ) . '</td></tr>';
    echo '</table>';

    echo '<div style="background:#e0e0e0;border-radius:4px;height:8px;margin-bottom:10px">';
    echo '<div style="background:' . esc_attr( $color ) . ';width:' . (float) $pct . '%;height:8px;border-radius:4px"></div>';
    echo '</div>';

    echo '<p style="color:#888;font-size:11px;margin:0">';
    echo esc_html__( 'Updated every hour', 'autolingo-polylang-translate' ) . ' · ';
    echo '<a href="' . esc_url( $settings_url ) . '">'
        . esc_html__( 'Settings', 'autolingo-polylang-translate' )
        . '</a>';
    echo '</p>';
}
