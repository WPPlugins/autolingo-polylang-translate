<?php
/**
 * DeepSeek translation provider (OpenAI-compatible chat completions API).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class AUTOLINGO_Provider_DeepSeek implements AUTOLINGO_Provider {

    public function get_slug(): string { return 'deepseek'; }
    public function get_name(): string { return 'DeepSeek'; }

    public function api_key(): string {
        return trim( get_option( 'autolingo_deepseek_api_key', '' ) );
    }

    public function translate( string $text, string $target_lang ): string|false {
        if ( empty( trim( $text ) ) ) return $text;

        $api_key = $this->api_key();
        if ( empty( $api_key ) ) {
            autolingo_log_error( 'DeepSeek API key not configured.' );
            return false;
        }

        $lang_name = $this->lang_name( $target_lang );

        $response = wp_remote_post( 'https://api.deepseek.com/chat/completions', [
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( [
                'model'       => 'deepseek-chat',
                'temperature' => 0.1,
                'messages'    => [
                    [
                        'role'    => 'system',
                        'content' =>
                            "You are a professional translator. Translate the following HTML/WordPress content to {$lang_name}. " .
                            'Rules: preserve all HTML tags and attributes exactly; do not translate text inside square brackets ' .
                            '(WordPress shortcodes like [gallery id="1"]); preserve WordPress block comments (<!-- wp:... -->); ' .
                            'return only the translated content, no explanations or extra text.',
                    ],
                    [ 'role' => 'user', 'content' => $text ],
                ],
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            autolingo_log_error( 'DeepSeek connection error: ' . $response->get_error_message() );
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            autolingo_log_error(
                "DeepSeek returned HTTP {$code}.",
                [ 'response' => mb_substr( wp_remote_retrieve_body( $response ), 0, 300 ) ]
            );
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return $body['choices'][0]['message']['content'] ?? false;
    }

    /** DeepSeek has no simple character-balance endpoint. */
    public function get_usage(): array|false {
        return false;
    }

    public function check_usage(): bool {
        return true;
    }

    public function invalidate_usage_cache(): void {}

    private function lang_name( string $code ): string {
        $map = [
            'AR' => 'Arabic',     'BG' => 'Bulgarian',  'CS' => 'Czech',
            'DA' => 'Danish',     'DE' => 'German',      'EL' => 'Greek',
            'EN' => 'English',    'ES' => 'Spanish',     'ET' => 'Estonian',
            'FI' => 'Finnish',    'FR' => 'French',      'HU' => 'Hungarian',
            'ID' => 'Indonesian', 'IT' => 'Italian',     'JA' => 'Japanese',
            'KO' => 'Korean',     'LT' => 'Lithuanian',  'LV' => 'Latvian',
            'NB' => 'Norwegian',  'NL' => 'Dutch',       'PL' => 'Polish',
            'PT' => 'Portuguese', 'RO' => 'Romanian',    'RU' => 'Russian',
            'SK' => 'Slovak',     'SL' => 'Slovenian',   'SV' => 'Swedish',
            'TR' => 'Turkish',    'UK' => 'Ukrainian',   'ZH' => 'Chinese',
        ];
        return $map[ strtoupper( $code ) ] ?? $code;
    }
}
