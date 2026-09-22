<?php
/**
 * Translation Memory — reuse of previously translated segments.
 *
 * Table : {prefix}autolingo_tm
 * Colonnes : id, source_lang, target_lang, segment_hash, source_text, translated_text, hits, updated_at
 *
 * A "segment" is one top-level HTML block (typically a <p>, <li>, <h1-6>…) or,
 * when the text has no such structure, the whole text. Segments are looked up
 * by an exact hash of (source_lang, trimmed segment text) before any text is
 * sent to the translation API, so a paragraph repeated across many posts (e.g.
 * boilerplate terms & conditions) is only ever translated once per language pair.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ══════════════════════════════════════════════════════════
//  1. INSTALLATION / DÉSINSTALLATION DE LA TABLE
// ══════════════════════════════════════════════════════════

function autolingo_tm_install(): void {
    global $wpdb;

    $table   = $wpdb->prefix . 'autolingo_tm';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        source_lang      VARCHAR(10)     NOT NULL,
        target_lang      VARCHAR(10)     NOT NULL,
        segment_hash     CHAR(32)        NOT NULL,
        source_text      MEDIUMTEXT      NOT NULL,
        translated_text  MEDIUMTEXT      NOT NULL,
        hits             BIGINT UNSIGNED NOT NULL DEFAULT 0,
        updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_segment (target_lang, segment_hash)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    update_option( 'autolingo_tm_db_version', '1.0' );
}

function autolingo_tm_uninstall(): void {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- uninstall cleanup, table name is always {prefix}autolingo_tm.
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}autolingo_tm" );
    delete_option( 'autolingo_tm_db_version' );
}

// ══════════════════════════════════════════════════════════
//  2. LOOKUP / STORE
// ══════════════════════════════════════════════════════════

function autolingo_tm_hash( string $source_lang, string $segment ): string {
    return md5( strtolower( $source_lang ) . "\x1f" . trim( $segment ) );
}

/**
 * Return the cached translation for this exact segment, or null on a miss.
 * A hit increments the segment's reuse counter (used for the "characters
 * saved" stat shown in the admin).
 */
function autolingo_tm_lookup( string $source_lang, string $target_lang, string $segment ): ?string {
    global $wpdb;

    $hash = autolingo_tm_hash( $source_lang, $segment );

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- prepared below.
    $found = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id, translated_text FROM {$wpdb->prefix}autolingo_tm WHERE target_lang = %s AND segment_hash = %s",
            strtolower( $target_lang ),
            $hash
        )
    );

    if ( ! $found ) return null;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- counter bump, not cacheable.
    $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}autolingo_tm SET hits = hits + 1 WHERE id = %d", $found->id ) );

    return $found->translated_text;
}

function autolingo_tm_store( string $source_lang, string $target_lang, string $segment, string $translated ): void {
    global $wpdb;

    $hash = autolingo_tm_hash( $source_lang, $segment );

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- upsert on the unique (target_lang, segment_hash) key.
    $wpdb->query(
        $wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}autolingo_tm
                (source_lang, target_lang, segment_hash, source_text, translated_text, hits, updated_at)
             VALUES (%s, %s, %s, %s, %s, 0, %s)
             ON DUPLICATE KEY UPDATE
                translated_text = VALUES(translated_text),
                source_text     = VALUES(source_text),
                updated_at      = VALUES(updated_at)",
            strtolower( $source_lang ),
            strtolower( $target_lang ),
            $hash,
            $segment,
            $translated,
            current_time( 'mysql' )
        )
    );
}

/**
 * Delete every cached segment. Called from Settings when the glossary is
 * edited (terminology changed → stale cached translations must not survive)
 * or manually via the "Clear translation memory" button.
 *
 * @return int Number of segments removed.
 */
function autolingo_tm_clear(): int {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- explicit cache purge.
    $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}autolingo_tm" );
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- explicit cache purge.
    $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}autolingo_tm" );
    return $count;
}

/**
 * @return array{segments: int, chars_saved: int} Cache size and an estimate
 *         of characters that did NOT need to be sent to the translation API
 *         thanks to reused segments.
 */
function autolingo_tm_stats(): array {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only stats query.
    $row = $wpdb->get_row(
        "SELECT COUNT(*) AS segments, COALESCE(SUM(hits * CHAR_LENGTH(source_text)), 0) AS chars_saved
         FROM {$wpdb->prefix}autolingo_tm"
    );

    return [
        'segments'    => (int) ( $row->segments ?? 0 ),
        'chars_saved' => (int) ( $row->chars_saved ?? 0 ),
    ];
}

/**
 * Terminology changed — any previously cached segment may now translate a
 * glossary term incorrectly, so purge the whole cache rather than risk
 * serving a stale translation. Covers both the Settings-page save and the
 * "Sync to DeepL" AJAX handler, since both call update_option() underneath.
 */
add_action( 'update_option_autolingo_glossary_terms', 'autolingo_tm_on_glossary_changed', 10, 2 );
function autolingo_tm_on_glossary_changed( $old_value, $new_value ): void {
    if ( $old_value === $new_value ) return;
    autolingo_tm_clear();
}

// ══════════════════════════════════════════════════════════
//  3. SEGMENTATION
// ══════════════════════════════════════════════════════════

/**
 * Split HTML into an ordered list of segments. Each segment is
 * ['text' => string, 'translate' => bool] — 'translate' is false for
 * whitespace-only text nodes between block elements, which must be kept
 * (for output fidelity) but never sent to the API or looked up in memory.
 *
 * Falls back to a single whole-text segment when the content has no
 * top-level HTML structure to split on, or when parsing fails — behaviour
 * is then identical to translating the field as one block, exactly like
 * before Translation Memory existed.
 *
 * @return array<int, array{text: string, translate: bool}>
 */
function autolingo_tm_segment( string $html ): array {
    $trimmed = trim( $html );
    if ( $trimmed === '' ) return [];

    if ( ! preg_match( '/<[a-z][^>]*>/i', $trimmed ) ) {
        return [ [ 'text' => $html, 'translate' => true ] ];
    }

    $prev_errors = libxml_use_internal_errors( true );
    $doc         = new DOMDocument();
    $loaded      = $doc->loadHTML(
        '<?xml encoding="utf-8"?><div id="__autolingo_tm_root__">' . $html . '</div>',
        LIBXML_NOERROR | LIBXML_NOWARNING
    );
    libxml_clear_errors();
    libxml_use_internal_errors( $prev_errors );

    if ( ! $loaded ) return [ [ 'text' => $html, 'translate' => true ] ];

    $root = $doc->getElementById( '__autolingo_tm_root__' );
    if ( ! $root || ! $root->childNodes || $root->childNodes->length === 0 ) {
        return [ [ 'text' => $html, 'translate' => true ] ];
    }

    // Only split when EVERY non-whitespace top-level child is a recognized
    // block-level element. A title or excerpt containing inline formatting
    // (e.g. "Hello <em>world</em>!") has no such structure — splitting it at
    // the <em> boundary would send "Hello " and "!" to the API in isolation,
    // losing sentence context and producing worse translations. In that case
    // fall back to translating the whole text as one segment, exactly as
    // before Translation Memory existed.
    static $block_tags = [
        'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'blockquote', 'pre',
        'table', 'ul', 'ol', 'div', 'section', 'article', 'figure',
        'figcaption', 'dl', 'dt', 'dd', 'header', 'footer', 'aside', 'nav',
        'address', 'hr',
    ];

    foreach ( $root->childNodes as $node ) {
        if ( $node->nodeType === XML_TEXT_NODE && trim( $node->textContent ) === '' ) {
            continue; // whitespace between blocks — never disqualifies a split
        }
        $is_block = $node->nodeType === XML_ELEMENT_NODE
            && in_array( strtolower( $node->nodeName ), $block_tags, true );
        if ( ! $is_block ) {
            return [ [ 'text' => $html, 'translate' => true ] ];
        }
    }

    $segments = [];
    foreach ( $root->childNodes as $node ) {
        $serialized = $doc->saveHTML( $node );
        if ( $serialized === false || $serialized === '' ) continue;

        $is_whitespace_only = $node->nodeType === XML_TEXT_NODE && trim( $serialized ) === '';
        $segments[] = [ 'text' => $serialized, 'translate' => ! $is_whitespace_only ];
    }

    // Nothing usable came out of parsing (e.g. a single self-closing tag with
    // no useful split) — fall back to the safe, pre-TM behaviour.
    if ( empty( array_filter( $segments, static fn( $s ) => $s['translate'] ) ) ) {
        return [ [ 'text' => $html, 'translate' => true ] ];
    }

    return $segments;
}

// ══════════════════════════════════════════════════════════
//  4. TM-AWARE BATCH TRANSLATION
// ══════════════════════════════════════════════════════════

/**
 * Translate a batch of texts, reusing any segment already in Translation
 * Memory and only sending genuinely new segments to $api_translate.
 *
 * @param  string[]  $texts         Prepared texts (shortcodes/blocks already
 *                                   protected by the caller).
 * @param  string    $source_lang
 * @param  string    $target_lang
 * @param  callable  $api_translate function(string[] $batch): string[]|false
 * @return string[]|false Same order/count as $texts, or false on API failure.
 */
function autolingo_tm_translate_batch( array $texts, string $source_lang, string $target_lang, callable $api_translate ): array|false {
    if ( empty( $texts ) ) return [];

    $per_text = [];   // text index => segments (each augmented with 'result' once known)
    $to_send  = [];   // flat list of segment texts still needing translation
    $to_refs  = [];   // parallel: [ text_index, segment_index ]

    foreach ( $texts as $i => $text ) {
        $segments = autolingo_tm_segment( $text );

        foreach ( $segments as $j => $segment ) {
            if ( ! $segment['translate'] ) {
                $segments[ $j ]['result'] = $segment['text']; // passthrough
                continue;
            }

            $cached = autolingo_tm_lookup( $source_lang, $target_lang, $segment['text'] );
            if ( $cached !== null ) {
                $segments[ $j ]['result'] = $cached;
            } else {
                $to_send[] = $segment['text'];
                $to_refs[] = [ $i, $j ];
            }
        }

        $per_text[ $i ] = $segments;
    }

    if ( ! empty( $to_send ) ) {
        $results = $api_translate( $to_send );
        if ( $results === false ) return false;

        foreach ( $to_refs as $k => [ $i, $j ] ) {
            $translated = $results[ $k ] ?? false;
            if ( $translated === false || ! is_string( $translated ) ) return false;

            $per_text[ $i ][ $j ]['result'] = $translated;
            autolingo_tm_store( $source_lang, $target_lang, $per_text[ $i ][ $j ]['text'], $translated );
        }
    }

    $out = [];
    foreach ( $per_text as $i => $segments ) {
        $out[ $i ] = implode( '', array_column( $segments, 'result' ) );
    }

    return $out;
}
