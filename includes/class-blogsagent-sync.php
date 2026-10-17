<?php
if (!defined('ABSPATH')) {
    exit;
}

/** Pushes changes on this site back to BlogsAgent: category edits and status changes of our posts. */
class BlogsAgent_Sync
{
    public static function init()
    {
        add_action('transition_post_status', [__CLASS__, 'on_status'], 10, 3);
        foreach (['created_category', 'edited_category', 'delete_category'] as $hook) {
            add_action($hook, [__CLASS__, 'queue_categories']);
        }
        add_action('blogsagent_push_categories', [__CLASS__, 'push_categories']);
    }

    public static function on_status($new, $old, $post)
    {
        if ($new === $old || $post->post_type !== 'post' || !BlogsAgent_Connection::get()) {
            return;
        }
        if (!get_post_meta($post->ID, '_blogsagent_external_id', true)) {
            return;
        }
        BlogsAgent_Connection::post('post-status', [
            'wpPostId' => (int) $post->ID,
            'status' => $new,
            'url' => get_permalink($post->ID),
        ], false);
    }

    /** Batches bursts of category edits into a single sync a few seconds later. */
    public static function queue_categories()
    {
        if (BlogsAgent_Connection::get() && !wp_next_scheduled('blogsagent_push_categories')) {
            wp_schedule_single_event(time() + 15, 'blogsagent_push_categories');
        }
    }

    public static function push_categories()
    {
        global $wp_version;
        BlogsAgent_Connection::post('categories', [
            'categories' => BlogsAgent_Connection::categories_payload(),
            'wpVersion' => $wp_version,
            'pluginVersion' => BLOGSAGENT_VERSION,
        ]);
    }
}
