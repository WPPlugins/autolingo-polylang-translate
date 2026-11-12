<?php
/**
 * DeepSeek translation provider (OpenAI-compatible chat completions API), on the site's key
 * (requests: trait-chat-translation.php).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class AUTOLINGO_Provider_DeepSeek implements AUTOLINGO_Provider {

    use AUTOLINGO_Chat_Translation;

    public function get_slug(): string { return 'deepseek'; }
    public function get_name(): string { return 'DeepSeek'; }

    public function api_key(): string {
        return trim( get_option( 'autolingo_deepseek_api_key', '' ) );
    }

    protected function endpoint(): string { return 'https://api.deepseek.com/chat/completions'; }
    protected function model(): string { return 'deepseek-chat'; }
    protected function label(): string { return 'DeepSeek'; }
}
