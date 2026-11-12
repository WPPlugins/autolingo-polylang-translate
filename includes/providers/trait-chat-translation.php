<?php
/**
 * Translation through an OpenAI-compatible chat API (OpenAI, DeepSeek), on the site's own key.
 *
 * A page reaches the provider as a list of segments (its blocks, see autolingo_tm_segment()).
 * Several segments go in one request, as {"texts": [...]} → {"translations": [...]}, up to about
 * AUTOLINGO_CHAT_BATCH_CHARS characters: each answer stays short enough to come back well within
 * the time limit, even for a long page (a whole page in one request used to time out after 60 s
 * with gpt-4o-mini). The size can be changed with the "autolingo_chat_batch_chars" filter
 * (characters, and the provider's slug). The requests of one page go at the same time (see
 * send_parallel(); filter "autolingo_chat_parallel" to send them one after the other).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** Characters of source text per request to an AI provider. */
const AUTOLINGO_CHAT_BATCH_CHARS = 4000;
/** Requests to an AI provider sent at the same time for one page. */
const AUTOLINGO_CHAT_PARALLEL = 8;

trait AUTOLINGO_Chat_Translation {

    /** Whether the last call failed because the quota or balance is used up. */
    private bool $quota_exceeded = false;

    public function quota_exceeded(): bool { return $this->quota_exceeded; }

    /** The API's chat completions URL, model and name for the log. */
    abstract protected function endpoint(): string;
    abstract protected function model(): string;
    abstract protected function label(): string;

    public function translate( string $text, string $target_lang ): string|false {
        $this->quota_exceeded = false;
        if ( empty( trim( $text ) ) ) return $text;
        return $this->chat( $this->instructions( $target_lang, false ), $text, false );
    }

    /**
     * Texts in requests of at most the batch size (a text longer than that goes alone). The
     * requests of one page are sent at the same time (up to AUTOLINGO_CHAT_PARALLEL at once):
     * a long page takes about as long as its slowest request, not the sum of them.
     *
     * @param  string[] $texts
     * @return string[]|false Same order and count, or false on error (logged).
     */
    public function translate_batch( array $texts, string $target_lang ): array|false {
        $this->quota_exceeded = false;
        $limit  = max( 500, (int) apply_filters( 'autolingo_chat_batch_chars', AUTOLINGO_CHAT_BATCH_CHARS, $this->get_slug() ) );
        $chunks = [];
        $chunk  = [];
        $size   = 0;
        foreach ( array_values( $texts ) as $text ) {
            if ( $chunk && $size + mb_strlen( $text ) > $limit ) {
                $chunks[] = $chunk;
                $chunk    = [];
                $size     = 0;
            }
            $chunk[] = $text;
            $size   += mb_strlen( $text );
        }
        if ( $chunk ) $chunks[] = $chunk;

        // Every chunk's first request at once, when the server can; then each chunk is read in order.
        $payloads = [];
        foreach ( $chunks as $k => $c ) {
            $p = $this->payload( $c, $target_lang );
            if ( $p ) $payloads[ $k ] = $p;
        }
        $answers = count( $payloads ) > 1 && $this->parallel() ? $this->send_parallel( $payloads ) : [];
        if ( in_array( false, $answers, true ) ) return false;

        $out = [];
        foreach ( $chunks as $k => $c ) {
            $done = $this->request( $c, $target_lang, $answers[ $k ] ?? null );
            if ( $done === false ) return false;
            array_push( $out, ...$done );
        }
        return $out;
    }

    /**
     * What to send for a chunk: [ system, user, json ], or null when it has nothing to translate.
     *
     * @return array{0:string, 1:string, 2:bool}|null
     */
    private function payload( array $texts, string $target_lang ): ?array {
        $todo = array_filter( $texts, static fn( $t ) => trim( (string) $t ) !== '' );
        if ( ! $todo ) return null;
        if ( count( $todo ) === 1 ) return [ $this->instructions( $target_lang, false ), (string) reset( $todo ), false ];
        return [ $this->instructions( $target_lang, true ), (string) wp_json_encode( [ 'texts' => array_values( $texts ) ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), true ];
    }

    /**
     * A chunk's translations. $answer: the answer to its payload when it was already sent (in
     * parallel), else it is sent now.
     *
     * @return string[]|false
     */
    private function request( array $texts, string $target_lang, ?string $answer = null ): array|false {
        $p = $this->payload( $texts, $target_lang );
        if ( ! $p ) return $texts;
        $answer ??= $this->chat( ...$p );
        if ( $answer === false ) return false;
        if ( ! $p[2] ) { // one text
            $out = $texts;
            foreach ( $texts as $i => $t ) {
                if ( trim( (string) $t ) !== '' ) $out[ $i ] = $answer;
            }
            return $out;
        }
        $answer = (string) preg_replace( '/^```(?:json)?\s*|\s*```$/', '', trim( $answer ) ); // some models fence their JSON
        $list   = json_decode( $answer, true )['translations'] ?? null;
        if ( is_array( $list ) && count( $list ) === count( $texts ) && count( array_filter( $list, 'is_string' ) ) === count( $list ) ) {
            return array_values( $list );
        }
        // A list of the wrong length: one text at a time rather than translations in the wrong places.
        autolingo_log( 'warning', $this->label() . ' answered a list of the wrong length: texts translated one by one.' );
        $out = [];
        foreach ( $texts as $text ) {
            if ( trim( (string) $text ) === '' ) {
                $out[] = $text;
                continue;
            }
            $one = $this->chat( $this->instructions( $target_lang, false ), $text, false );
            if ( $one === false ) return false;
            $out[] = $one;
        }
        return $out;
    }

    /**
     * Requests can go at the same time: WordPress's HTTP library is there, the site does not block
     * outgoing requests, and the "autolingo_chat_parallel" filter (true, provider slug) allows it.
     */
    private function parallel(): bool {
        if ( ! class_exists( '\WpOrg\Requests\Requests' ) ) return false;
        if ( defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && WP_HTTP_BLOCK_EXTERNAL ) return false; // wp_remote_post() applies the site's rules
        return (bool) apply_filters( 'autolingo_chat_parallel', true, $this->get_slug() );
    }

    /**
     * Payloads sent at the same time, AUTOLINGO_CHAT_PARALLEL at once, with WordPress's
     * certificates and proxy.
     *
     * @return array<int, string|false> The answers, by the payloads' keys.
     */
    private function send_parallel( array $payloads ): array {
        $api_key = $this->api_key();
        if ( empty( $api_key ) ) {
            autolingo_log_error( $this->label() . ' API key not configured.' );
            return array_fill_keys( array_keys( $payloads ), false );
        }
        $options = [ 'timeout' => 120, 'connect_timeout' => 10 ];
        $bundle  = ABSPATH . WPINC . '/certificates/ca-bundle.crt';
        if ( is_readable( $bundle ) ) $options['verify'] = $bundle;
        if ( class_exists( 'WP_HTTP_Proxy' ) ) {
            $proxy = new WP_HTTP_Proxy();
            if ( $proxy->is_enabled() && $proxy->send_through_proxy( $this->endpoint() ) ) {
                $address            = $proxy->host() . ':' . $proxy->port();
                $options['proxy']   = new \WpOrg\Requests\Proxy\Http( $proxy->use_authentication() ? [ $address, $proxy->username(), $proxy->password() ] : $address );
            }
        }
        $requests = [];
        foreach ( $payloads as $k => [ $system, $user, $json ] ) {
            $requests[ $k ] = [
                'url'     => $this->endpoint(),
                'type'    => 'POST',
                'headers' => [ 'Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json' ],
                'data'    => (string) wp_json_encode( $this->body( $system, $user, $json ) ),
            ];
        }
        $out = [];
        foreach ( array_chunk( $requests, AUTOLINGO_CHAT_PARALLEL, true ) as $wave ) {
            foreach ( \WpOrg\Requests\Requests::request_multiple( $wave, $options ) as $k => $r ) {
                if ( $r instanceof \Exception ) {
                    autolingo_log_error( $this->label() . ' connection error: ' . $r->getMessage() );
                    $out[ $k ] = false;
                    continue;
                }
                $out[ $k ] = $this->answer( (int) $r->status_code, (string) $r->body );
            }
            if ( in_array( false, $out, true ) ) break; // no more requests once one failed
        }
        return $out;
    }

    private function instructions( string $target_lang, bool $list ): string {
        $context = autolingo_translation_context();
        return "You are a professional translator. Translate the following HTML/WordPress content to {$this->lang_name( $target_lang )}. "
            . 'Rules: preserve all HTML tags and attributes exactly; do not translate text inside square brackets '
            . '(WordPress shortcodes like [gallery id="1"]); preserve WordPress block comments (<!-- wp:... -->) and placeholders such as <!--PDT0-->, <!--ALNO0--> or __ALATTR0__; '
            . ( $list
                ? 'You receive a JSON object {"texts": [...]}: answer with a JSON object {"translations": [...]} holding the translation of each text, in the same order, the same number of items.'
                : 'return only the translated content, no explanations or extra text.' )
            . ( $context !== '' ? ' Context, for your understanding only (do not translate it or add it): ' . $context : '' );
    }

    /** The request body. */
    private function body( string $system, string $user, bool $json ): array {
        $body = [
            'model'       => $this->model(),
            'temperature' => 0.1,
            'messages'    => [
                [ 'role' => 'system', 'content' => $system ],
                [ 'role' => 'user', 'content' => $user ],
            ],
        ];
        if ( $json ) $body['response_format'] = [ 'type' => 'json_object' ];
        return $body;
    }

    /** One chat call; the answer's text, or false (logged, quota flagged). */
    private function chat( string $system, string $user, bool $json ): string|false {
        $api_key = $this->api_key();
        if ( empty( $api_key ) ) {
            autolingo_log_error( $this->label() . ' API key not configured.' );
            return false;
        }
        $response = wp_remote_post( $this->endpoint(), [
            'timeout' => 120,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( $this->body( $system, $user, $json ) ),
        ] );
        if ( is_wp_error( $response ) ) {
            autolingo_log_error( $this->label() . ' connection error: ' . $response->get_error_message() );
            return false;
        }
        return $this->answer( (int) wp_remote_retrieve_response_code( $response ), (string) wp_remote_retrieve_body( $response ) );
    }

    /** An HTTP answer: the model's text, or false (logged; quota flagged when the balance is used up). */
    private function answer( int $code, string $body ): string|false {
        if ( $code !== 200 ) {
            // Balance used up: OpenAI 429 with type "insufficient_quota" (code "insufficient_quota", or
            // "credit_balance_exhausted" since 2026; other 429s are rate limits), DeepSeek 402.
            $error = (array) ( json_decode( $body, true )['error'] ?? [] );
            if ( $code === 402 || ( $code === 429 && array_intersect( [ $error['type'] ?? '', $error['code'] ?? '' ], [ 'insufficient_quota', 'credit_balance_exhausted' ] ) ) ) {
                $this->quota_exceeded = true;
            }
            autolingo_log_error( $this->label() . " returned HTTP {$code}.", [ 'response' => mb_substr( $body, 0, 300 ) ] );
            return false;
        }
        $content = json_decode( $body, true )['choices'][0]['message']['content'] ?? null;
        return is_string( $content ) ? $content : false;
    }

    /** OpenAI and DeepSeek have no simple character-balance endpoint. */
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
