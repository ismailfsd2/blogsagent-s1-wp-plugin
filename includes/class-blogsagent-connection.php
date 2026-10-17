<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Stores the connection key and signs/sends requests to the BlogsAgent API.
 *
 * The key looks like "ba1_<base64url(json)>" and carries the API address, this site's id and the
 * shared secret. Every request in either direction is signed with HMAC-SHA256 over "timestamp.body".
 */
class BlogsAgent_Connection
{
    public static function parse_key($key)
    {
        $key = trim((string) $key);
        if (strpos($key, 'ba1_') !== 0) {
            return null;
        }
        $json = base64_decode(strtr(substr($key, 4), '-_', '+/'), true);
        $data = $json ? json_decode($json, true) : null;
        if (!is_array($data) || empty($data['a']) || empty($data['i']) || empty($data['s'])) {
            return null;
        }
        return ['api' => untrailingslashit(esc_url_raw($data['a'])), 'site_id' => sanitize_text_field($data['i']), 'secret' => (string) $data['s']];
    }

    public static function get()
    {
        $c = get_option(BLOGSAGENT_OPTION);
        return is_array($c) && !empty($c['secret']) ? $c : null;
    }

    public static function save($key)
    {
        $parsed = self::parse_key($key);
        if (!$parsed) {
            return false;
        }
        update_option(BLOGSAGENT_OPTION, $parsed + ['status' => 'saved', 'message' => '', 'last_ok' => 0], false);
        return true;
    }

    public static function set_status($status, $message = '')
    {
        $c = get_option(BLOGSAGENT_OPTION);
        if (!is_array($c)) {
            return;
        }
        $c['status'] = $status;
        $c['message'] = $message;
        if ($status === 'connected') {
            $c['last_ok'] = time();
        }
        update_option(BLOGSAGENT_OPTION, $c, false);
    }

    public static function sign($secret, $timestamp, $body)
    {
        return hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    /** Signed POST to the SaaS. Returns [ok(bool), message(string)]. */
    public static function post($path, array $payload, $blocking = true)
    {
        $c = self::get();
        if (!$c) {
            return [false, __('No connection key saved.', 'blogsagent-connector')];
        }
        $body = wp_json_encode($payload);
        $ts = (string) time();
        $res = wp_remote_post($c['api'] . '/api/plugin/' . $path, [
            'timeout' => $blocking ? 20 : 3,
            'blocking' => $blocking,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-BA-Site' => $c['site_id'],
                'X-BA-Timestamp' => $ts,
                'X-BA-Signature' => self::sign($c['secret'], $ts, $body),
            ],
            'body' => $body,
        ]);
        if (!$blocking) {
            return [true, ''];
        }
        if (is_wp_error($res)) {
            return [false, $res->get_error_message()];
        }
        $code = wp_remote_retrieve_response_code($res);
        if ($code < 200 || $code >= 300) {
            $data = json_decode(wp_remote_retrieve_body($res), true);
            return [false, is_array($data) && !empty($data['message']) ? $data['message'] : sprintf('HTTP %d', $code)];
        }
        return [true, ''];
    }

    public static function categories_payload()
    {
        $terms = get_terms(['taxonomy' => 'category', 'hide_empty' => false]);
        $out = [];
        if (is_wp_error($terms)) {
            return $out;
        }
        foreach ($terms as $t) {
            $out[] = ['id' => (int) $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'parent' => (int) $t->parent, 'count' => (int) $t->count];
        }
        return $out;
    }
}
