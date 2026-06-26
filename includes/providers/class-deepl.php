<?php
function autolingo_deepl_normalize_lang( string $lang ): string {
    $upper = strtoupper( str_replace( "_", "-", $lang ) );
    $base  = explode( "-", $upper )[0];
    if ( $base === "ZH" ) return in_array( $upper, [ "ZH-TW", "ZH-HK", "ZH-HANT" ] ) ? "ZH-HANT" : "ZH";
    if ( $base === "EN" ) return $upper === "EN-GB" ? "EN-GB" : "EN-US";
    if ( $base === "PT" ) return $upper === "PT-BR" ? "PT-BR" : "PT-PT";
    return $base;
}
/**
 * DeepL translation provider.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class AUTOLINGO_Provider_DeepL implements AUTOLINGO_Provider {

    public function get_slug(): string { return 'deepl'; }
    public function get_name(): string { return 'DeepL'; }

    public function api_key(): string {
        return trim( get_option( 'autolingo_api_key', '' ) );
    }

    private function endpoint( string $path = '/v2/translate' ): string {
        $host = str_ends_with( $this->api_key(), ':fx' ) ? 'api-free.deepl.com' : 'api.deepl.com';
        return 'https://' . $host . $path;
    }

    public function translate( string $text, string $target_lang ): string|false {
        if ( empty( trim( $text ) ) ) return $text;

        $api_key = $this->api_key();
        if ( empty( $api_key ) ) {
            autolingo_log_error( 'DeepL API key not configured.' );
            return false;
        }

        // Wrap in <div> so DeepL treats the whole payload as one HTML block
        $payload = '<div>' . $text . '</div>';

        $args = [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'DeepL-Auth-Key ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( array_filter( [
                'text'         => [ $payload ],
                'source_lang'  => strtoupper( explode( '-', str_replace( '_', '-', autolingo_source_lang() ) )[0] ),
                'target_lang'  => autolingo_deepl_normalize_lang( $target_lang ),
                'tag_handling' => 'html',
                'formality'    => autolingo_deepl_formality( $target_lang ),
                'glossary_id'  => $this->get_glossary_id( autolingo_source_lang(), $target_lang ),
            ], fn( $v ) => $v !== null ) ),
        ];

        $response = wp_remote_post( $this->endpoint( '/v2/translate' ), $args );

        if ( is_wp_error( $response ) ) {
            autolingo_log_error( 'DeepL connection error: ' . $response->get_error_message(), [ 'lang' => $target_lang ] );
            return false;
        }

        $code    = wp_remote_retrieve_response_code( $response );
        $retries = 0;
        while ( $code === 429 && $retries < 3 ) {
            sleep( 2 ** ( $retries + 1 ) );
            $retries++;
            $retry = wp_remote_post( $this->endpoint( '/v2/translate' ), $args );
            if ( ! is_wp_error( $retry ) ) {
                $response = $retry;
                $code     = wp_remote_retrieve_response_code( $response );
            }
        }

        if ( $code !== 200 ) {
            autolingo_log_error(
                "DeepL returned HTTP {$code}.",
                [ 'lang' => $target_lang, 'response' => mb_substr( wp_remote_retrieve_body( $response ), 0, 300 ) ]
            );
            return false;
        }

        $body       = json_decode( wp_remote_retrieve_body( $response ), true );
        $translated = $body['translations'][0]['text'] ?? false;

        if ( $translated ) {
            $translated = preg_replace( '/^\s*<div>(.*)<\/div>\s*$/s', '$1', $translated );
            $this->invalidate_usage_cache();
        }

        return $translated;
    }

    public function get_usage(): array|false {
        $cached = get_transient( 'autolingo_deepl_usage' );
        if ( $cached !== false ) return $cached;

        $api_key = $this->api_key();
        if ( empty( $api_key ) ) return false;

        $response = wp_remote_get( $this->endpoint( '/v2/usage' ), [
            'timeout' => 10,
            'headers' => [ 'Authorization' => 'DeepL-Auth-Key ' . $api_key ],
        ] );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $data = [
            'count' => (int) ( $body['character_count']  ?? 0 ),
            'limit' => (int) ( $body['character_limit']  ?? 1000000 ),
        ];
        $data['remaining'] = $data['limit'] - $data['count'];

        set_transient( 'autolingo_deepl_usage', $data, HOUR_IN_SECONDS );
        return $data;
    }

    public function check_usage(): bool {
        $usage = $this->get_usage();
        if ( $usage === false ) return true;

        if ( $usage['remaining'] <= 500 ) {
            autolingo_log_error( 'DeepL credit exhausted: ' . $usage['count'] . ' / ' . $usage['limit'] );
            return false;
        }

        if ( $usage['remaining'] < autolingo_alert_threshold() ) {
            update_option( 'autolingo_low_credit_alert', $usage['remaining'] );
        } else {
            delete_option( 'autolingo_low_credit_alert' );
        }

        return true;
    }

    public function invalidate_usage_cache(): void {
        delete_transient( 'autolingo_deepl_usage' );
    }

    // ── Glossary ──────────────────────────────────────────────────────────────

    private function get_glossary_id( string $source_lang, string $target_lang ): ?string {
        $ids = json_decode( get_option( 'autolingo_glossary_ids', '{}' ), true ) ?: [];
        $key = strtolower( $source_lang ) . '_' . strtolower( $target_lang );
        return $ids[ $key ] ?? null;
    }

    public function sync_glossary(): array {
        $api_key = $this->api_key();
        if ( empty( $api_key ) ) {
            return [ 'error' => __( 'DeepL API key not configured.', 'autolingo-polylang-translate' ) ];
        }

        $raw     = get_option( 'autolingo_glossary_terms', '' );
        $entries = autolingo_parse_glossary_terms( $raw );
        if ( empty( $entries ) ) {
            return [ 'error' => __( 'No glossary terms to sync.', 'autolingo-polylang-translate' ) ];
        }

        $source      = strtoupper( autolingo_source_lang() );
        $targets     = array_map( 'strtoupper', autolingo_target_langs() );
        $existing    = json_decode( get_option( 'autolingo_glossary_ids', '{}' ), true ) ?: [];
        $new_ids     = [];
        $synced      = 0;
        $tsv         = implode( "\n", array_map( fn($e) => $e['source'] . "\t" . $e['target'], $entries ) );

        foreach ( $targets as $target ) {
            // Delete old glossary if it exists.
            $old_key = strtolower( $source ) . '_' . strtolower( $target );
            if ( ! empty( $existing[ $old_key ] ) ) {
                $this->delete_glossary_api( $existing[ $old_key ] );
            }

            $response = wp_remote_post( $this->endpoint( '/v2/glossaries' ), [
                'timeout' => 20,
                'headers' => [
                    'Authorization' => 'DeepL-Auth-Key ' . $api_key,
                    'Content-Type'  => 'application/json',
                ],
                'body' => wp_json_encode( [
                    'name'           => 'AutoLingo',
                    'source_lang'    => $source,
                    'target_lang'    => $target,
                    'entries'        => $tsv,
                    'entries_format' => 'tsv',
                ] ),
            ] );

            if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 201 ) {
                $body = json_decode( wp_remote_retrieve_body( $response ), true );
                $new_ids[ $old_key ] = $body['glossary_id'];
                $synced++;
            }
        }

        update_option( 'autolingo_glossary_ids', wp_json_encode( $new_ids ) );
        return [ 'synced' => $synced, 'total' => count( $targets ) ];
    }

    public function delete_glossary_api( string $glossary_id ): void {
        $api_key = $this->api_key();
        if ( empty( $api_key ) ) return;
        wp_remote_request( $this->endpoint( '/v2/glossaries/' . $glossary_id ), [
            'method'  => 'DELETE',
            'timeout' => 10,
            'headers' => [ 'Authorization' => 'DeepL-Auth-Key ' . $api_key ],
        ] );
    }

}
