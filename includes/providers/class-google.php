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
        $r = $this->request( [ $text ], $target_lang );
        return $r === false ? false : $r[0];
    }

    /**
     * Several texts: up to 100 texts and about 25,000 characters per request (Google takes 128
     * and recommends under 30,000), so a long page (one text per block) needs few requests.
     */
    public function translate_batch( array $texts, string $target_lang ): array|false {
        $this->quota_exceeded = false;
        $texts = array_values( $texts );
        $out   = $texts;
        $todo  = array_filter( $texts, static fn( $t ) => trim( (string) $t ) !== '' );
        $chunk = [];
        $size  = 0;
        $flush = function () use ( &$chunk, &$size, &$out, $target_lang ): bool {
            if ( ! $chunk ) return true;
            $done = $this->request( array_values( $chunk ), $target_lang );
            if ( $done === false ) return false;
            foreach ( array_keys( $chunk ) as $n => $i ) $out[ $i ] = $done[ $n ];
            $chunk = [];
            $size  = 0;
            return true;
        };
        foreach ( $todo as $i => $text ) {
            if ( $chunk && ( count( $chunk ) >= 100 || $size + mb_strlen( $text ) > 25000 ) && ! $flush() ) return false;
            $chunk[ $i ] = $text;
            $size       += mb_strlen( $text );
        }
        return $flush() ? $out : false;
    }

    /** One request; the translations in the same order, or false (logged, quota flagged). */
    private function request( array $texts, string $target_lang ): array|false {
        $api_key = $this->api_key();
        if ( empty( $api_key ) ) {
            autolingo_log_error( 'Google Translate API key not configured.' );
            return false;
        }

        $response = wp_remote_post(
            'https://translation.googleapis.com/language/translate/v2?key=' . urlencode( $api_key ),
            [
                'timeout' => 60,
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( [
                    'q'      => count( $texts ) === 1 ? $texts[0] : $texts,
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
        $out  = [];
        foreach ( (array) ( $body['data']['translations'] ?? [] ) as $t ) {
            if ( ! isset( $t['translatedText'] ) ) return false;
            // Google HTML-encodes some entities in the response — decode them back.
            $out[] = html_entity_decode( (string) $t['translatedText'], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        }
        return count( $out ) === count( $texts ) ? $out : false;
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
