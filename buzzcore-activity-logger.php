<?php
/*
Plugin Name: BuzzCore Activity Logger
Plugin URI:  https://buzzcoresolutions.com/
Description: Logs plugin activities, shows status icons in the Plugins page, and provides a full activity log page with filtering.
Version: 1.0
Author: BuzzCore Solutions
Author URI:  https://buzzcoresolutions.com/
Text Domain: plugin-activity-logger
Domain Path: /languages
*/


if (!defined('ABSPATH')) exit;

class Plugin_Activity_Logger {

    private static $instance = null;
    private $table;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'plugin_activity_logs';

        register_activation_hook(__FILE__, [$this, 'create_table']);

        add_action('activate_plugin', [$this, 'log_activation'], 10, 1);
        add_action('deactivate_plugin', [$this, 'log_deactivation'], 10, 1);
        add_action('upgrader_process_complete', [$this, 'log_update'], 10, 2);

        add_filter('manage_plugins_columns', [$this, 'add_status_column']);
        add_action('manage_plugins_custom_column', [$this, 'render_status_column'], 10, 2);

        add_action('admin_menu', [$this, 'add_admin_menu']);

        add_action('load-tools_page_plugin-activity-log', [$this, 'add_screen_options']);
        add_filter('set-screen-option', [$this, 'set_screen_option'], 10, 3);

        add_action('admin_init', [$this, 'handle_clear_logs']);

    }

    /* ---------------------------------------------------------
       HELPER: Get Real Plugin Name
    --------------------------------------------------------- */

    private function get_plugin_name($plugin_slug) {

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        static $plugins = null;

        if ($plugins === null) {
            $plugins = get_plugins();
        }

        return $plugins[$plugin_slug]['Name'] ?? $plugin_slug;
    }

    /* ---------------------------------------------------------
       DATABASE
    --------------------------------------------------------- */

    public function create_table() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$this->table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            plugin_slug VARCHAR(255) NOT NULL,
            plugin_name VARCHAR(255) NOT NULL,
            action VARCHAR(50) NOT NULL,
            timestamp DATETIME NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            PRIMARY KEY (id),
            KEY plugin_slug (plugin_slug),
            KEY timestamp (timestamp)
        ) {$charset};";

        dbDelta($sql);
    }

    /* ---------------------------------------------------------
       LOGGING
    --------------------------------------------------------- */

    private function insert_log($plugin, $action) {
        global $wpdb;

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins = get_plugins();
        $plugin_name = $plugins[$plugin]['Name'] ?? $plugin;

        $wpdb->insert(
            $this->table,
            [
                'plugin_slug' => $plugin,
                'plugin_name' => $plugin_name,
                'action'      => $action,
                'timestamp'   => current_time('mysql'),
                'user_id'     => get_current_user_id() ?: null
            ],
            ['%s','%s','%s','%s','%d']
        );
    }

    public function log_activation($plugin) {
        if ($plugin !== plugin_basename(__FILE__)) {
            $this->insert_log($plugin, 'activated');
        }
    }

    public function log_deactivation($plugin) {
        if ($plugin !== plugin_basename(__FILE__)) {
            $this->insert_log($plugin, 'deactivated');
        }
    }

    public function log_update($upgrader, $options) {
        if (
            isset($options['type'], $options['action']) &&
            $options['type'] === 'plugin' &&
            $options['action'] === 'update' &&
            !empty($options['plugins'])
        ) {
            foreach ($options['plugins'] as $plugin) {
                $this->insert_log($plugin, 'updated');
            }
        }
    }

    /* ---------------------------------------------------------
       PLUGIN COLUMN
    --------------------------------------------------------- */

    public function add_status_column($columns) {
        $columns['pal_status'] = 'Activity';
        return $columns;
    }

    public function render_status_column($column, $plugin_file) {
        if ($column !== 'pal_status') return;

        global $wpdb;

        $logs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table}
                 WHERE plugin_slug = %s
                 ORDER BY timestamp DESC
                 LIMIT 5",
                $plugin_file
            )
        );

        if (!$logs) {
            echo '—';
            return;
        }

        $latest = $logs[0];

        $color = '#999';
        if ($latest->action === 'activated') $color = '#28a745';
        if ($latest->action === 'deactivated') $color = '#dc3545';
        if ($latest->action === 'updated') $color = '#0073aa';

        $tooltip = [];

        foreach ($logs as $log) {
            $user = $log->user_id ? get_userdata($log->user_id) : null;
            $user_name = $user ? $user->user_login : 'System';

            $tooltip[] = ucfirst($log->action) .
             " – " . wp_date(
                 get_option('date_format') . ' ' . get_option('time_format'),
                 strtotime($log->timestamp)
             ) .
             " – {$user_name}";
        }

        echo '<span style="
            display:inline-block;
            width:14px;
            height:14px;
            border-radius:50%;
            background:' . esc_attr($color) . ';
            cursor:help;
        " title="' . esc_attr(implode("&#10;", $tooltip)) . '"></span>';
    }

    /* ---------------------------------------------------------
       SCREEN OPTIONS
    --------------------------------------------------------- */

    public function add_screen_options() {

        $option = 'per_page';

        $args = [
            'label'   => 'Logs per page',
            'default' => 20,
            'option'  => 'pal_logs_per_page'
        ];

        add_screen_option($option, $args);
    }

    public function set_screen_option($status, $option, $value) {
        if ($option === 'pal_logs_per_page') {
            return (int) $value;
        }
        return $status;
    }

    private function get_per_page() {

        $user = get_current_user_id();
        $per_page = get_user_meta($user, 'pal_logs_per_page', true);

        if (empty($per_page)) {
            $per_page = 20;
        }

        return (int) $per_page;
    }

    /* ---------------------------------------------------------
       CLEAR LOGS
    --------------------------------------------------------- */

    public function handle_clear_logs() {

        if (
            isset($_POST['pal_clear_logs']) &&
            current_user_can('manage_options')
        ) {

            check_admin_referer('pal_clear_logs_action', 'pal_clear_logs_nonce');

            global $wpdb;

            // Optional: clear only filtered results
            $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : '';
            $plugin = isset($_POST['plugin']) ? sanitize_text_field($_POST['plugin']) : '';

            if ($status || $plugin) {

                $where  = "WHERE 1=1";
                $params = [];

                if ($status) {
                    $where .= " AND action = %s";
                    $params[] = $status;
                }

                if ($plugin) {
                    $where .= " AND plugin_slug = %s";
                    $params[] = $plugin;
                }

                $query = "DELETE FROM {$this->table} {$where}";

                $prepared = $wpdb->prepare($query, ...$params);
                $wpdb->query($prepared);

            } else {

                // Clear entire table
                $wpdb->query("TRUNCATE TABLE {$this->table}");
            }

            wp_redirect(
                add_query_arg('pal_cleared', '1', menu_page_url('plugin-activity-log', false))
            );
            exit;
        }
    }


    /* ---------------------------------------------------------
       ADMIN PAGE
    --------------------------------------------------------- */

    public function add_admin_menu() {
        add_submenu_page(
            'tools.php',
            'Plugin Activity Log',
            'Plugin Activity Log',
            'manage_options',
            'plugin-activity-log',
            [$this, 'render_admin_page']
        );
    }

    public function render_admin_page() {

        global $wpdb;

        $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
        $date_to   = isset($_GET['date_to'])   ? sanitize_text_field($_GET['date_to'])   : '';
        $status    = isset($_GET['status'])    ? sanitize_text_field($_GET['status'])    : '';
        $plugin    = isset($_GET['plugin'])    ? sanitize_text_field($_GET['plugin'])    : '';

        $where  = "WHERE 1=1";
        $params = [];

        if ($date_from) {
            $where .= " AND timestamp >= %s";
            $params[] = $date_from . ' 00:00:00';
        }

        if ($date_to) {
            $where .= " AND timestamp <= %s";
            $params[] = $date_to . ' 23:59:59';
        }

        if ($status) {
            $where .= " AND action = %s";
            $params[] = $status;
        }

        if ($plugin) {
            $where .= " AND plugin_slug = %s";
            $params[] = $plugin;
        }

        /* ---------------------------------------------------------
           Pagination
        --------------------------------------------------------- */

        $per_page = $this->get_per_page();
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($paged - 1) * $per_page;

        $query = "SELECT SQL_CALC_FOUND_ROWS *
                  FROM {$this->table}
                  {$where}
                  ORDER BY timestamp DESC
                  LIMIT %d OFFSET %d";

        $params[] = $per_page;
        $params[] = $offset;

        $prepared = $wpdb->prepare($query, ...$params);
        $logs = $wpdb->get_results($prepared);

        $total = $wpdb->get_var("SELECT FOUND_ROWS()");
        $total_pages = ceil($total / $per_page);

        /* ---------------------------------------------------------
           Fetch unique plugins for dropdown
        --------------------------------------------------------- */

        $plugins = $wpdb->get_col(
            "SELECT DISTINCT plugin_name
             FROM {$this->table}
             ORDER BY plugin_slug ASC"
        );

        ?>

        <div class="wrap">

            <?php if (isset($_GET['pal_cleared'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p>Log data cleared successfully.</p>
                </div>
            <?php endif; ?>

            <h1>Plugin Activity Log</h1>

            <form method="get" style="margin-bottom:20px;">
                <input type="hidden" name="page" value="plugin-activity-log">

                <label>Date From:</label>
                <input type="date" name="date_from" value="<?php echo esc_attr($date_from); ?>">

                <label style="margin-left:10px;">Date To:</label>
                <input type="date" name="date_to" value="<?php echo esc_attr($date_to); ?>">

                <label style="margin-left:10px;">Status:</label>
                <select name="status">
                    <option value="">All</option>
                    <option value="activated" <?php selected($status,'activated'); ?>>Activated</option>
                    <option value="deactivated" <?php selected($status,'deactivated'); ?>>Deactivated</option>
                    <option value="updated" <?php selected($status,'updated'); ?>>Updated</option>
                </select>

                <label style="margin-left:10px;">Plugin:</label>
                <select name="plugin">
                    <option value="">All</option>
                    <?php foreach ($plugins as $p): ?>
                        <option value="<?php echo esc_attr($p); ?>" <?php selected($plugin,$p); ?>>
                            <?php echo esc_html($p); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button class="button button-primary" style="margin-left:10px;">Filter</button>
            </form>

            <hr>

            <form method="post" style="margin-bottom:15px;">
                <?php wp_nonce_field('pal_clear_logs_action', 'pal_clear_logs_nonce'); ?>

                <input type="hidden" name="status" value="<?php echo esc_attr($status); ?>">
                <input type="hidden" name="plugin" value="<?php echo esc_attr($plugin); ?>">

                <button
                    type="submit"
                    name="pal_clear_logs"
                    class="button button-secondary"
                    onclick="return confirm('Are you sure you want to clear these log entries? This cannot be undone.');"
                >
                    Clear Log Data
                </button>
            </form>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Plugin</th>
                        <th>Action</th>
                        <th>Date</th>
                        <th>User</th>
                    </tr>
                </thead>
                <tbody>

                <?php if ($logs): foreach ($logs as $log):
                    $user = $log->user_id ? get_userdata($log->user_id) : null;
                    $user_name = $user ? $user->user_login : 'System';
                ?>
                    <tr>
                        <td><?php echo esc_html($log->plugin_name); ?></td>
                        <td><?php echo esc_html(ucfirst($log->action)); ?></td>
                        <td><?php echo esc_html($log->timestamp); ?></td>
                        <td><?php echo esc_html($user_name); ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="4">No results found.</td></tr>
                <?php endif; ?>

                </tbody>
            </table>

            <?php if ($total_pages > 1): ?>
                <div style="margin-top:20px;">
                    <?php
                    echo paginate_links([
                        'base' => add_query_arg([
                            'paged' => '%#%',
                            'date_from' => $date_from,
                            'date_to'   => $date_to,
                            'status'    => $status,
                            'plugin'    => $plugin,
                        ]),
                        'format'  => '',
                        'current' => $paged,
                        'total'   => $total_pages,
                    ]);
                    ?>
                </div>
            <?php endif; ?>

        </div>

        <?php
    }
}

Plugin_Activity_Logger::instance();
