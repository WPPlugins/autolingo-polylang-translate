<?php
/**
 * Google Cloud Translation provider (v2 — Basic).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class AUTOLINGO_Provider_Google implements AUTOLINGO_Provider {

    public function get_slug(): string { return 'google'; }
    public function get_name(): string { return 'Google Translate'; }

    /** Whether the last call failed because the quota or balance is used up. */
    private bool $quota_exceeded = false;

    public function quota_exceeded(): bool { return $this->quota_exceeded; }

    public function api_key(): string {
        return trim( get_option( 'autolingo_google_api_key', '' ) );
    }

    public function translate( string $text, string $target_lang ): string|false {
        $this->quota_exceeded = false;
        if ( empty( trim( $text ) ) ) return $text;

        $api_key = $this->api_key();
        if ( empty( $api_key ) ) {
            autolingo_log_error( 'Google Translate API key not configured.' );
            return false;
        }

        $response = wp_remote_post(
            'https://translation.googleapis.com/language/translate/v2?key=' . urlencode( $api_key ),
            [
                'timeout' => 30,
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( [
                    'q'      => $text,
                    'source' => strtolower( autolingo_source_lang() ),
                    'target' => strtolower( $target_lang ),
                    'format' => 'html',
                ] ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            autolingo_log_error( 'Google Translate connection error: ' . $response->get_error_message() );
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            // Daily / per-project character limits: 403 "Daily Limit Exceeded", "quotaExceeded", or 429.
            $this->quota_exceeded = $code === 429
                || ( $code === 403 && preg_match( '/limit exceeded|quota/i', wp_remote_retrieve_body( $response ) ) );
            autolingo_log_error(
                "Google Translate returned HTTP {$code}.",
                [ 'response' => mb_substr( wp_remote_retrieve_body( $response ), 0, 300 ) ]
            );
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $raw  = $body['data']['translations'][0]['translatedText'] ?? false;

        // Google HTML-encodes some entities in the response — decode them back.
        return $raw !== false ? html_entity_decode( $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : false;
    }

    /** Google Cloud Translation v2 has no simple usage-balance endpoint. */
    public function get_usage(): array|false {
        return false;
    }

    public function check_usage(): bool {
        return true;
    }

    public function invalidate_usage_cache(): void {}
}
