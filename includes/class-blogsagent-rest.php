<?php
if (!defined('ABSPATH')) {
    exit;
}

/** REST endpoints the BlogsAgent service calls. Authenticated by HMAC signature, never by WP login. */
class BlogsAgent_REST
{
    const NS = 'blogsagent/v1';

    public static function init()
    {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    public static function routes()
    {
        foreach (['ping' => 'ping', 'categories' => 'categories', 'posts' => 'create_post', 'posts/status' => 'set_status'] as $route => $cb) {
            register_rest_route(self::NS, '/' . $route, [
                'methods' => 'POST',
                'callback' => [__CLASS__, $cb],
                'permission_callback' => [__CLASS__, 'authorize'],
            ]);
        }
    }

    /** Verifies site id, timestamp window, signature and rejects replays of a signature already seen. */
    public static function authorize(WP_REST_Request $request)
    {
        $c = BlogsAgent_Connection::get();
        if (!$c) {
            return new WP_Error('ba_not_connected', 'This site has no BlogsAgent connection key.', ['status' => 403]);
        }
        $site = (string) $request->get_header('x-ba-site');
        $ts = (string) $request->get_header('x-ba-timestamp');
        $sig = (string) $request->get_header('x-ba-signature');
        if ($site === '' || $ts === '' || $sig === '' || !hash_equals($c['site_id'], $site)) {
            return new WP_Error('ba_bad_request', 'Missing or unknown site.', ['status' => 401]);
        }
        if (abs(time() - (int) $ts) > 300) {
            return new WP_Error('ba_expired', 'Request timestamp is outside the allowed window.', ['status' => 401]);
        }
        $expected = BlogsAgent_Connection::sign($c['secret'], $ts, $request->get_body());
        if (!hash_equals($expected, $sig)) {
            return new WP_Error('ba_bad_signature', 'Invalid signature.', ['status' => 401]);
        }
        $replay = BLOGSAGENT_REPLAY_PREFIX . substr($sig, 0, 40);
        if (get_transient($replay)) {
            return new WP_Error('ba_replay', 'This request was already processed.', ['status' => 409]);
        }
        set_transient($replay, 1, 10 * MINUTE_IN_SECONDS);
        return true;
    }

    public static function ping()
    {
        global $wp_version;
        return ['ok' => true, 'wpVersion' => $wp_version, 'pluginVersion' => BLOGSAGENT_VERSION];
    }

    public static function categories()
    {
        return ['categories' => BlogsAgent_Connection::categories_payload()];
    }

    public static function create_post(WP_REST_Request $request)
    {
        $p = $request->get_json_params();
        $title = isset($p['title']) ? sanitize_text_field($p['title']) : '';
        $content = isset($p['content']) ? wp_kses_post($p['content']) : '';
        $external = isset($p['externalId']) ? sanitize_text_field($p['externalId']) : '';
        $status = (isset($p['status']) && $p['status'] === 'publish') ? 'publish' : 'draft';
        $cat = isset($p['categoryWpId']) ? (int) $p['categoryWpId'] : 0;
        if ($title === '' || $content === '') {
            return new WP_Error('ba_invalid', 'A title and content are required.', ['status' => 400]);
        }
        if ($cat && !term_exists($cat, 'category')) {
            return new WP_Error('ba_no_category', 'That category no longer exists on this site.', ['status' => 422]);
        }

        // Retries must not create duplicates.
        if ($external !== '') {
            $existing = get_posts([
                'post_type' => 'post', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids',
                'meta_key' => '_blogsagent_external_id', 'meta_value' => $external,
            ]);
            if ($existing) {
                return self::result((int) $existing[0]);
            }
        }

        $author = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
        $post_id = wp_insert_post([
            'post_type' => 'post',
            'post_title' => $title,
            'post_content' => $content,
            'post_excerpt' => isset($p['excerpt']) ? sanitize_textarea_field($p['excerpt']) : '',
            'post_name' => isset($p['slug']) ? sanitize_title($p['slug']) : '',
            'post_status' => $status,
            'post_category' => $cat ? [$cat] : [],
            'post_author' => $author ? (int) $author[0] : 0,
        ], true);
        if (is_wp_error($post_id)) {
            return new WP_Error('ba_insert_failed', $post_id->get_error_message(), ['status' => 500]);
        }
        if ($external !== '') {
            update_post_meta($post_id, '_blogsagent_external_id', $external);
        }
        if (!empty($p['metaDescription'])) {
            $desc = sanitize_text_field($p['metaDescription']);
            update_post_meta($post_id, '_yoast_wpseo_metadesc', $desc);
            update_post_meta($post_id, 'rank_math_description', $desc);
            update_post_meta($post_id, '_aioseo_description', $desc);
        }
        return self::result($post_id);
    }

    public static function set_status(WP_REST_Request $request)
    {
        $p = $request->get_json_params();
        $id = isset($p['postId']) ? (int) $p['postId'] : 0;
        $status = (isset($p['status']) && $p['status'] === 'publish') ? 'publish' : 'draft';
        $post = $id ? get_post($id) : null;
        // Only touch posts this plugin created.
        if (!$post || !get_post_meta($id, '_blogsagent_external_id', true)) {
            return new WP_Error('ba_not_found', 'Post not found.', ['status' => 404]);
        }
        if ($post->post_status !== $status) {
            $r = wp_update_post(['ID' => $id, 'post_status' => $status], true);
            if (is_wp_error($r)) {
                return new WP_Error('ba_update_failed', $r->get_error_message(), ['status' => 500]);
            }
        }
        return self::result($id);
    }

    private static function result($post_id)
    {
        $post = get_post($post_id);
        return ['postId' => (int) $post_id, 'url' => get_permalink($post_id), 'status' => $post ? $post->post_status : 'draft'];
    }
}
