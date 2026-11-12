<?php
/**
 * OpenAI (GPT-4o-mini) translation provider, on the site's key (requests: trait-chat-translation.php).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class AUTOLINGO_Provider_OpenAI implements AUTOLINGO_Provider {

    use AUTOLINGO_Chat_Translation;

    public function get_slug(): string { return 'openai'; }
    public function get_name(): string { return 'OpenAI (GPT-4o)'; }

    public function api_key(): string {
        return trim( get_option( 'autolingo_openai_api_key', '' ) );
    }

    protected function endpoint(): string { return 'https://api.openai.com/v1/chat/completions'; }
    protected function model(): string { return 'gpt-4o-mini'; }
    protected function label(): string { return 'OpenAI'; }
}
