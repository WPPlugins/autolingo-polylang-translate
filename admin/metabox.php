<?php
/**
 * Admin — metabox dans l'éditeur classique / Gutenberg.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'add_meta_boxes',        'autolingo_add_meta_box' );
add_action( 'admin_enqueue_scripts', 'autolingo_metabox_enqueue' );
add_action( 'admin_head',            'autolingo_list_overlay_html' );

function autolingo_add_meta_box(): void {
	add_meta_box(
		'autolingo_translate_box',
		'🌍 ' . __( 'AutoLingo Translation', 'autolingo-polylang-translate' ),
		'autolingo_meta_box_html',
		autolingo_post_types(),
		'side',
		'default'
	);
}

function autolingo_meta_box_html( WP_Post $post ): void {
	$lang = pll_get_post_language( $post->ID );

	if ( $lang !== autolingo_source_lang() ) {
		echo '<p style="color:#666;font-size:12px;">'
			. sprintf(
				/* translators: %s: uppercase language code, e.g. "FR" */
				esc_html__( 'Only available on %s content.', 'autolingo-polylang-translate' ),
				'<strong>' . esc_html( strtoupper( autolingo_source_lang() ) ) . '</strong>'
			)
			. '</p>';
		return;
	}

	$url         = wp_nonce_url(
		admin_url( 'admin-post.php?action=autolingo_translate&post_id=' . $post->ID ),
		'autolingo_translate_' . $post->ID
	);
	$langs_label = implode( ', ', array_map( 'strtoupper', autolingo_target_langs() ) );
	$status      = autolingo_translation_status( $post->ID );
	$usage       = autolingo_get_usage();

	// Statut des traductions existantes
	echo '<p style="margin-bottom:8px">';
	foreach ( $status as $lang_code => $exists ) {
		$icon  = $exists ? '✅' : '⬜';
		$label = strtoupper( $lang_code );
		echo '<span style="margin-right:8px">' . esc_html( $icon ) . ' ' . esc_html( $label ) . '</span>';
	}
	echo '</p>';

	// Crédit restant
	if ( $usage ) {
		$color = $usage['remaining'] < autolingo_alert_threshold() ? '#d63638' : '#2271b1';
		echo '<p style="font-size:11px;color:' . esc_attr( $color ) . ';margin-bottom:10px">';
		echo '💳 ' . sprintf(
			/* translators: %s: formatted number of characters */
			esc_html__( '%s characters remaining', 'autolingo-polylang-translate' ),
			number_format( $usage['remaining'], 0, ',', "\u{202F}" )
		);
		echo '</p>';
	}

	// Bouton avec loader
	?>
	<div id="pdt-translate-wrap">
		<a href="<?php echo esc_url( $url ); ?>"
		   id="pdt-translate-btn"
		   class="button button-primary"
		   style="width:100%;text-align:center;display:block;margin-bottom:6px;box-sizing:border-box;">
			🌍 <?php
			echo sprintf(
				/* translators: %s: target language codes, e.g. "EN, DE" */
				esc_html__( 'Translate → %s', 'autolingo-polylang-translate' ),
				esc_html( $langs_label )
			); ?>
		</a>

		<!-- Loader (caché par défaut) -->
		<div id="pdt-translate-loader" style="display:none;text-align:center;padding:10px 0;">
			<span class="pdt-spinner"></span>
			<span style="font-size:12px;color:#555;margin-left:6px;vertical-align:middle;">
				<?php esc_html_e( 'Translating…', 'autolingo-polylang-translate' ); ?>
			</span>
		</div>

		<p style="color:#888;font-size:11px;margin:0">
			<?php esc_html_e( 'Translations will be published automatically.', 'autolingo-polylang-translate' ); ?>
		</p>
	</div>
	<?php
}

// ── Enqueue scripts + styles ───────────────────────────────

function autolingo_metabox_enqueue( string $hook ): void {
	$screen = get_current_screen();
	if ( ! $screen ) return;

	$is_editor = in_array( $screen->post_type, autolingo_post_types(), true );
	$is_list   = 'edit' === $screen->base;

	if ( ! $is_editor && ! $is_list ) return;

	$css = '
.pdt-spinner{display:inline-block;width:14px;height:14px;border:2px solid #c3c4c7;border-top-color:#2271b1;border-radius:50%;animation:pdt-spin 0.7s linear infinite;vertical-align:middle;}
@keyframes pdt-spin{to{transform:rotate(360deg);}}
#pdt-list-overlay{display:none;position:fixed;inset:0;background:rgba(255,255,255,0.75);z-index:9999;align-items:center;justify-content:center;flex-direction:column;gap:12px;}
#pdt-list-overlay.active{display:flex;}
#pdt-list-overlay .pdt-spinner{width:28px;height:28px;border-width:3px;}
#pdt-list-overlay p{font-size:14px;color:#1d2327;margin:0;font-weight:500;}
';

	wp_register_style( 'pdt-metabox', false, [], AUTOLINGO_VERSION ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- version set via AUTOLINGO_VERSION constant.
	wp_enqueue_style( 'pdt-metabox' );
	wp_add_inline_style( 'pdt-metabox', $css );

	if ( $is_editor ) {
		$js = '(function(){var btn=document.getElementById("pdt-translate-btn");if(!btn)return;btn.addEventListener("click",function(){var loader=document.getElementById("pdt-translate-loader");btn.style.pointerEvents="none";btn.style.opacity="0.6";btn.style.display="none";loader.style.display="block";});})();';
		wp_register_script( 'pdt-metabox', false, [], AUTOLINGO_VERSION, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- version set via AUTOLINGO_VERSION constant.
		wp_enqueue_script( 'pdt-metabox' );
		wp_add_inline_script( 'pdt-metabox', $js );
	}

	if ( $is_list ) {
		$js = '(function(){document.querySelectorAll("a[href*=\'action=autolingo_translate\']").forEach(function(link){link.addEventListener("click",function(){document.querySelectorAll("a[href*=\'action=autolingo_translate\']").forEach(function(l){l.style.pointerEvents="none";l.style.opacity="0.4";});document.getElementById("pdt-list-overlay").classList.add("active");});});})();';
		wp_register_script( 'pdt-list', false, [], AUTOLINGO_VERSION, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- version set via AUTOLINGO_VERSION constant.
		wp_enqueue_script( 'pdt-list' );
		wp_add_inline_script( 'pdt-list', $js );
	}
}

// ── Overlay HTML (post list page only) ────────────────────

function autolingo_list_overlay_html(): void {
	$screen = get_current_screen();
	if ( ! $screen || 'edit' !== $screen->base ) return;
	?>
	<div id="pdt-list-overlay" role="status" aria-live="polite">
		<span class="pdt-spinner"></span>
		<p>⏳ <?php esc_html_e( 'Translation in progress, please wait…', 'autolingo-polylang-translate' ); ?></p>
		<p style="font-size:12px;color:#666;font-weight:400">
			<?php esc_html_e( 'The page will reload automatically when done.', 'autolingo-polylang-translate' ); ?>
		</p>
	</div>
	<?php
}
