<?php
/**
 * Licensing stub for the WP.org free build.
 * The premium build uses Freemius; this stub removes that dependency entirely.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function autolingo_is_dev_mode(): bool   { return false; }
function autolingo_is_licensed(): bool   { return false; }
function autolingo_is_premium(): bool    { return false; }
function autolingo_check_license(): bool { return false; }
function autolingo_fs(): mixed           { return null; }
