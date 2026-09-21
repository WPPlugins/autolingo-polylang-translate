<?php
/**
 * autolingo_multilingual() — returns the active multilingual adapter singleton.
 *
 * Detection order: Polylang → WPML → null.
 * Returns null when no supported multilingual plugin is active so callers can
 * bail early instead of hitting a fatal error.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function autolingo_multilingual(): ?AutoLingo_Multilingual_Adapter {
    static $adapter = false; // false = not yet resolved

    if ( $adapter !== false ) {
        return $adapter; // may be null if no plugin detected
    }

    if ( AutoLingo_Polylang_Adapter::is_active() ) {
        $adapter = new AutoLingo_Polylang_Adapter();
    } elseif ( AutoLingo_WPML_Adapter::is_active() ) {
        $adapter = new AutoLingo_WPML_Adapter();
    } else {
        $adapter = null;
    }

    return $adapter;
}
