<?php
/**
 * Bootstrap for PHPUnit unit tests — no WordPress install required.
 *
 * Provides minimal stubs for every WP function / class used by the
 * provider classes and the deepl.php helper functions.
 */
declare(strict_types=1);

// ── Constants ──────────────────────────────────────────────────────────────
if (!defined('ABSPATH'))          define('ABSPATH', '/tmp/');
if (!defined('HOUR_IN_SECONDS'))  define('HOUR_IN_SECONDS', 3600);

// ── State containers ───────────────────────────────────────────────────────
$GLOBALS['autolingo_options']        = [];
$GLOBALS['autolingo_transients']     = [];
// Queue of HTTP responses consumed FIFO by wp_remote_post / wp_remote_get.
// Each entry is either:
//   ['response' => ['code' => int], 'body' => string]
//   or a WP_Error instance (returned as-is)
$GLOBALS['autolingo_http_responses'] = [];
$GLOBALS['autolingo_log']            = [];

// ── WordPress option stubs ─────────────────────────────────────────────────
function get_option(string $key, $default = false): mixed
{
    return array_key_exists($key, $GLOBALS['autolingo_options'])
        ? $GLOBALS['autolingo_options'][$key]
        : $default;
}

function update_option(string $key, $value): bool
{
    $GLOBALS['autolingo_options'][$key] = $value;
    return true;
}

function delete_option(string $key): bool
{
    unset($GLOBALS['autolingo_options'][$key]);
    return true;
}

// ── Transient stubs ────────────────────────────────────────────────────────
function get_transient(string $key): mixed
{
    return $GLOBALS['autolingo_transients'][$key] ?? false;
}

function set_transient(string $key, $val, int $exp = 0): bool
{
    $GLOBALS['autolingo_transients'][$key] = $val;
    return true;
}

function delete_transient(string $key): bool
{
    unset($GLOBALS['autolingo_transients'][$key]);
    return true;
}

// ── HTTP helper stubs ──────────────────────────────────────────────────────
function _autolingo_dequeue_response(): array|WP_Error
{
    if (!empty($GLOBALS['autolingo_http_responses'])) {
        return array_shift($GLOBALS['autolingo_http_responses']);
    }
    // Default fallback when the queue is empty
    return ['response' => ['code' => 200], 'body' => '{}'];
}

function wp_remote_post(string $url, array $args = []): array|WP_Error
{
    return _autolingo_dequeue_response();
}

function wp_remote_get(string $url, array $args = []): array|WP_Error
{
    return _autolingo_dequeue_response();
}

function wp_remote_retrieve_response_code($response): int
{
    return $response['response']['code'] ?? 0;
}

function wp_remote_retrieve_body($response): string
{
    return $response['body'] ?? '';
}

function is_wp_error($thing): bool
{
    return $thing instanceof WP_Error;
}

function wp_json_encode($data, int $flags = 0): string
{
    return (string) json_encode($data, $flags);
}

// ── WP_Error stub ──────────────────────────────────────────────────────────
class WP_Error
{
    private string $code;
    private string $msg;

    public function __construct(string $code, string $msg)
    {
        $this->code = $code;
        $this->msg  = $msg;
    }

    public function get_error_code(): string   { return $this->code; }
    public function get_error_message(): string { return $this->msg; }
}

// ── Plugin helper stubs ────────────────────────────────────────────────────
function autolingo_log_error(string $msg, array $ctx = []): void
{
    $GLOBALS['autolingo_log'][] = $msg;
}

function autolingo_source_lang(): string
{
    return (string) get_option('autolingo_source_lang', 'fr');
}

function autolingo_alert_threshold(): int
{
    return (int) get_option('autolingo_alert_threshold', 100000);
}

function autolingo_provider_slug(): string
{
    return (string) get_option('autolingo_provider', 'deepl');
}

// ── Load production code ───────────────────────────────────────────────────
// Note: the 429-retry test in ProviderDeepLTest exercises real sleep() calls
// (2 s + 4 s). PHP does not allow overriding built-in functions in the global
// namespace, so those waits are unavoidable without refactoring the provider.
require_once __DIR__ . '/../../includes/providers/interface-provider.php';
require_once __DIR__ . '/../../includes/providers/class-deepl.php';
require_once __DIR__ . '/../../includes/providers/class-openai.php';
require_once __DIR__ . '/../../includes/providers/class-google.php';
require_once __DIR__ . '/../../includes/deepl.php';
