<?php
/**
 * Premium module dispatch.
 *
 * This file is the single entry-point for all premium translation features.
 * It is EXCLUDED from the WP.org free build — the free zip never contains it.
 *
 * translate.php calls autolingo_run_premium_modules() only when this file is loaded
 * (guarded by function_exists), so the free build runs with zero premium code.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Run all premium module translations for a given post pair.
 * Called by autolingo_create_translation() and autolingo_update_translation().
 *
 * @param WP_Post $source_post  Source post.
 * @param int     $target_id    ID of the translated post.
 * @param string  $target_lang  Uppercase language code (e.g. 'EN').
 * @param string  $lang_slug    Lowercase language code (e.g. 'en').
 */
function autolingo_run_premium_modules( WP_Post $source_post, int $target_id, string $target_lang, string $lang_slug ): void {
	if ( ! autolingo_is_premium() ) return;

	if ( autolingo_module_enabled( 'page_builders' ) ) {
		if ( autolingo_is_elementor_post( $source_post->ID ) ) {
			autolingo_elementor_translate( $source_post->ID, $target_id, $target_lang );
		}
		if ( autolingo_is_beaver_builder_post( $source_post->ID ) ) {
			autolingo_beaver_builder_translate( $source_post->ID, $target_id, $target_lang );
		}
		if ( autolingo_is_bricks_post( $source_post->ID ) ) {
			autolingo_bricks_translate( $source_post->ID, $target_id, $target_lang );
		}
	}

	if ( autolingo_module_enabled( 'woocommerce' ) && autolingo_is_woocommerce_product( $source_post->ID ) ) {
		autolingo_woocommerce_translate( $source_post->ID, $target_id, $target_lang );
	}

	if ( autolingo_module_enabled( 'seo' ) ) {
		autolingo_seo_translate( $source_post->ID, $target_id, $target_lang );
	}

	if ( autolingo_module_enabled( 'acf' ) && function_exists( 'get_field' ) ) {
		autolingo_acf_translate_post_fields( $source_post->ID, $target_id, $target_lang );
	}
}
