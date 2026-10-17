<?php
if (!defined('ABSPATH')) {
    exit;
}

/** Settings > BlogsAgent: paste the connection key, connect, and see the status. */
class BlogsAgent_Admin
{
    public static function init()
    {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_post_blogsagent_save', [__CLASS__, 'handle_save']);
        add_action('admin_post_blogsagent_disconnect', [__CLASS__, 'handle_disconnect']);
    }

    public static function menu()
    {
        add_options_page('BlogsAgent', 'BlogsAgent', 'manage_options', 'blogsagent', [__CLASS__, 'render']);
    }

    public static function handle_save()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do this.', 'blogsagent-connector'));
        }
        check_admin_referer('blogsagent_save');
        $key = isset($_POST['blogsagent_key']) ? wp_unslash($_POST['blogsagent_key']) : '';
        if (!BlogsAgent_Connection::save($key)) {
            self::back('error', __('That connection key is not valid. Copy it again from your BlogsAgent dashboard.', 'blogsagent-connector'));
        }
        global $wp_version;
        list($ok, $msg) = BlogsAgent_Connection::post('handshake', [
            'siteUrl' => home_url(),
            'wpVersion' => $wp_version,
            'pluginVersion' => BLOGSAGENT_VERSION,
            'categories' => BlogsAgent_Connection::categories_payload(),
        ]);
        if ($ok) {
            BlogsAgent_Connection::set_status('connected');
            self::back('ok', __('Connected. Your categories have been sent to BlogsAgent.', 'blogsagent-connector'));
        }
        BlogsAgent_Connection::set_status('error', $msg);
        /* translators: %s: error message */
        self::back('error', sprintf(__('The key was saved but BlogsAgent could not be reached: %s', 'blogsagent-connector'), $msg));
    }

    public static function handle_disconnect()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do this.', 'blogsagent-connector'));
        }
        check_admin_referer('blogsagent_disconnect');
        delete_option(BLOGSAGENT_OPTION);
        self::back('ok', __('Disconnected.', 'blogsagent-connector'));
    }

    private static function back($type, $message)
    {
        wp_safe_redirect(add_query_arg(['page' => 'blogsagent', 'ba_type' => $type, 'ba_msg' => rawurlencode($message)], admin_url('options-general.php')));
        exit;
    }

    public static function render()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $c = BlogsAgent_Connection::get();
        $type = isset($_GET['ba_type']) ? sanitize_key($_GET['ba_type']) : '';
        $msg = isset($_GET['ba_msg']) ? sanitize_text_field(rawurldecode(wp_unslash($_GET['ba_msg']))) : '';
        ?>
        <div class="wrap">
            <h1>BlogsAgent</h1>
            <?php if ($msg) : ?>
                <div class="notice notice-<?php echo $type === 'ok' ? 'success' : 'error'; ?> is-dismissible"><p><?php echo esc_html($msg); ?></p></div>
            <?php endif; ?>

            <?php if ($c) : ?>
                <table class="form-table" role="presentation">
                    <tr><th scope="row"><?php esc_html_e('Status', 'blogsagent-connector'); ?></th>
                        <td>
                            <?php if ($c['status'] === 'connected') : ?>
                                <strong style="color:#1a7f37;"><?php esc_html_e('Connected', 'blogsagent-connector'); ?></strong>
                            <?php elseif ($c['status'] === 'error') : ?>
                                <strong style="color:#b32d2e;"><?php esc_html_e('Connection problem', 'blogsagent-connector'); ?></strong>
                                <p class="description"><?php echo esc_html($c['message']); ?></p>
                            <?php else : ?>
                                <strong><?php esc_html_e('Key saved, not yet confirmed', 'blogsagent-connector'); ?></strong>
                            <?php endif; ?>
                        </td></tr>
                    <tr><th scope="row"><?php esc_html_e('BlogsAgent server', 'blogsagent-connector'); ?></th><td><code><?php echo esc_html($c['api']); ?></code></td></tr>
                    <?php if (!empty($c['last_ok'])) : ?>
                        <tr><th scope="row"><?php esc_html_e('Last successful contact', 'blogsagent-connector'); ?></th><td><?php echo esc_html(human_time_diff($c['last_ok']) . ' ' . __('ago', 'blogsagent-connector')); ?></td></tr>
                    <?php endif; ?>
                </table>
            <?php endif; ?>

            <h2><?php echo $c ? esc_html__('Update connection key', 'blogsagent-connector') : esc_html__('Connect this site', 'blogsagent-connector'); ?></h2>
            <p><?php esc_html_e('Copy the connection key from your BlogsAgent dashboard (Websites, then your site) and paste it below.', 'blogsagent-connector'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="blogsagent_save">
                <?php wp_nonce_field('blogsagent_save'); ?>
                <textarea name="blogsagent_key" rows="3" class="large-text code" placeholder="ba1_..." required></textarea>
                <?php submit_button($c ? __('Save and reconnect', 'blogsagent-connector') : __('Connect', 'blogsagent-connector')); ?>
            </form>

            <?php if ($c) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Disconnect this site from BlogsAgent? Scheduled blogs will stop being delivered.', 'blogsagent-connector')); ?>');">
                    <input type="hidden" name="action" value="blogsagent_disconnect">
                    <?php wp_nonce_field('blogsagent_disconnect'); ?>
                    <?php submit_button(__('Disconnect', 'blogsagent-connector'), 'delete'); ?>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }
}
