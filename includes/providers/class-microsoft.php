<?php
/**
 * Microsoft Translator provider (Azure AI Translator, Text API v3).
 *
 * Free tier (F0): 2,000,000 characters a month. A key of a regional or multi-service
 * Azure resource also needs its region; global keys don't.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class AUTOLINGO_Provider_Microsoft implements AUTOLINGO_Provider {

    private const ENDPOINT = 'https://api.cognitive.microsofttranslator.com/translate';

    // Per request: at most 1,000 texts and 50,000 characters (kept below, with margin).
    private const MAX_TEXTS = 1000;
    private const MAX_CHARS = 45000;

    /** Whether the last call failed because the free quota is used up. */
    private bool $quota_exceeded = false;

    public function get_slug(): string { return 'microsoft'; }
    public function get_name(): string { return 'Microsoft Translator'; }
    public function quota_exceeded(): bool { return $this->quota_exceeded; }

    public function api_key(): string {
        return trim( get_option( 'autolingo_microsoft_api_key', '' ) );
    }

    public function region(): string {
        return trim( get_option( 'autolingo_microsoft_region', '' ) );
    }

    public function translate( string $text, string $target_lang ): string|false {
        if ( empty( trim( $text ) ) ) return $text;
        $results = $this->translate_batch( [ $text ], $target_lang );
        return $results === false ? false : ( $results[0] ?? false );
    }

    /**
     * @param  string[] $texts       Prepared texts (protections already applied by the caller).
     * @return string[]|false        Translations in the same order, or false on error.
     */
    public function translate_batch( array $texts, string $target_lang ): array|false {
        $this->quota_exceeded = false;
        if ( empty( $texts ) ) return [];

        if ( $this->api_key() === '' ) {
            autolingo_log_error( 'Microsoft Translator key not configured.' );
            return false;
        }

        $results = [];
        foreach ( $this->chunk( array_values( $texts ) ) as $chunk ) {
            $translated = $this->translate_chunk( $chunk, $target_lang );
            if ( $translated === false ) return false;
            $results = array_merge( $results, $translated );
        }
        return $results;
    }

    private function translate_chunk( array $texts, string $target_lang ): array|false {
        $headers = [
            'Content-Type'              => 'application/json; charset=UTF-8',
            'Ocp-Apim-Subscription-Key' => $this->api_key(),
        ];
        if ( $this->region() !== '' && strtolower( $this->region() ) !== 'global' ) {
            $headers['Ocp-Apim-Subscription-Region'] = $this->region();
        }
        $url  = self::ENDPOINT . '?' . http_build_query( [
            'api-version' => '3.0',
            'from'        => self::to_microsoft_lang( autolingo_source_lang() ),
            'to'          => self::to_microsoft_lang( $target_lang ),
            'textType'    => 'html',
        ] );
        $args = [
            'timeout' => 30,
            'headers' => $headers,
            'body'    => wp_json_encode( array_map( fn( $t ) => [ 'Text' => (string) $t ], $texts ) ),
        ];

        $response = wp_remote_post( $url, $args );
        $code     = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );

        // Rate limiting (429000–429002): retry with back-off — not quota exhaustion.
        $retries = 0;
        while ( $code === 429 && $retries < 3 ) {
            sleep( 2 ** ( $retries + 1 ) );
            $retries++;
            $response = wp_remote_post( $url, $args );
            $code     = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
        }

        if ( is_wp_error( $response ) ) {
            autolingo_log_error( 'Microsoft Translator connection error: ' . $response->get_error_message(), [ 'lang' => $target_lang ] );
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code !== 200 ) {
            $error = is_array( $body ) ? ( $body['error'] ?? [] ) : [];
            // 403001: "The operation isn't allowed because the subscription exceeded its free quota."
            $this->quota_exceeded = (int) ( $error['code'] ?? 0 ) === 403001;
            autolingo_log_error(
                "Microsoft Translator returned HTTP {$code}.",
                [ 'lang' => $target_lang, 'response' => mb_substr( (string) ( $error['message'] ?? wp_remote_retrieve_body( $response ) ), 0, 300 ) ]
            );
            return false;
        }

        $translations = is_array( $body ) ? array_map( fn( $item ) => $item['translations'][0]['text'] ?? null, $body ) : [];
        if ( count( $translations ) !== count( $texts ) || in_array( null, $translations, true ) ) {
            autolingo_log_error( 'Microsoft Translator returned an unexpected response.', [ 'lang' => $target_lang ] );
            return false;
        }
        return $translations;
    }

    private function chunk( array $texts ): array {
        $chunks  = [];
        $current = [];
        $size    = 0;
        foreach ( $texts as $text ) {
            $len = mb_strlen( (string) $text );
            if ( $current && ( count( $current ) >= self::MAX_TEXTS || $size + $len > self::MAX_CHARS ) ) {
                $chunks[] = $current;
                $current  = [];
                $size     = 0;
            }
            $current[] = $text;
            $size     += $len;
        }
        if ( $current ) $chunks[] = $current;
        return $chunks;
    }

    /** Polylang / WPML language slug or locale (fr, pt-br, pt_PT, zh-hant…) → Microsoft code (fr, pt, pt-pt, zh-Hans, zh-Hant, fr-ca…). */
    public static function to_microsoft_lang( string $lang ): string {
        $code = strtolower( str_replace( '_', '-', trim( $lang ) ) );
        $base = explode( '-', $code )[0];
        switch ( $base ) {
            case 'zh':
                return preg_match( '/-(tw|hk|mo|hant)\b/', $code ) ? 'zh-Hant' : 'zh-Hans';
            case 'pt':
                return str_ends_with( $code, '-pt' ) ? 'pt-pt' : 'pt';
            case 'fr':
                return str_ends_with( $code, '-ca' ) ? 'fr-ca' : 'fr';
            case 'no':
            case 'nn':
                return 'nb';
            case 'sr':
                return str_contains( $code, 'cyrl' ) ? 'sr-Cyrl' : 'sr-Latn';
            default:
                return $base;
        }
    }

    /** Microsoft Translator has no usage endpoint for keys. */
    public function get_usage(): array|false {
        return false;
    }

    public function check_usage(): bool {
        return true;
    }

    public function invalidate_usage_cache(): void {}
}
