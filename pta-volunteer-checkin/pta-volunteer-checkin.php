<?php
/**
 * Plugin Name: Volunteer Sign Up Sheets - Kiosk Ultimate (v6.10)
 * Description: Full Kiosk with HH:MM:SS duration formatting and admin management.
 * Version: 6.10
 * Author: Rob Lammert
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// HELPER FUNCTION: Seconds to HH:MM:SS
function pta_format_seconds($seconds) {
    if ($seconds <= 0) return "00:00:00";
    $h = floor($seconds / 3600);
    $m = floor(($seconds % 3600) / 60);
    $s = $seconds % 60;
    return sprintf('%02d:%02d:%02d', $h, $m, $s);
}

// 1. CSV EXPORT HANDLER
add_action('admin_init', 'pta_kiosk_csv_export_handler');
function pta_kiosk_csv_export_handler() {
    if ( !isset($_GET['pta_export']) || !current_user_can('manage_options') ) return;

    global $wpdb;
    $type = sanitize_text_field($_GET['pta_export']);
    $filename = "volunteer-" . $type . "-" . date('Y-m-d') . ".csv";

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);
    $output = fopen('php://output', 'w');

    if ($type == 'event_totals') {
        fputcsv($output, array('First Name', 'Last Name', 'Event', 'Total Duration (HH:MM:SS)'));
        $data = $wpdb->get_results("SELECT s.firstname, s.lastname, sh.title, SUM(TIMESTAMPDIFF(SECOND, c.check_in_time, c.check_out_time)) as total_sec FROM {$wpdb->prefix}pta_volunteer_checkins c JOIN {$wpdb->prefix}pta_sus_signups s ON c.signup_id = s.id JOIN {$wpdb->prefix}pta_sus_tasks ta ON s.task_id = ta.id JOIN {$wpdb->prefix}pta_sus_sheets sh ON ta.sheet_id = sh.id WHERE c.check_out_time IS NOT NULL GROUP BY s.id, sh.id");
        foreach ($data as $row) { fputcsv($output, array($row->firstname, $row->lastname, $row->title, pta_format_seconds($row->total_sec))); }
    } 
    
    if ($type == 'volunteer_lookup') {
        $search = sanitize_text_field($_GET['v_name']);
        $month = intval($_GET['v_month']);
        $year = intval($_GET['v_year']);
        $all_time = isset($_GET['all_time']) && $_GET['all_time'] == '1';
        fputcsv($output, array('Volunteer', 'Date', 'Event', 'Check In', 'Check Out', 'Duration (HH:MM:SS)'));
        $query = "SELECT s.firstname, s.lastname, s.date, sh.title, c.check_in_time, c.check_out_time FROM {$wpdb->prefix}pta_sus_signups s JOIN {$wpdb->prefix}pta_sus_tasks ta ON s.task_id = ta.id JOIN {$wpdb->prefix}pta_sus_sheets sh ON ta.sheet_id = sh.id LEFT JOIN {$wpdb->prefix}pta_volunteer_checkins c ON s.id = c.signup_id WHERE (s.firstname LIKE %s OR s.lastname LIKE %s)";
        $params = array("%$search%", "%$search%");
        if (!$all_time) {
            if ($month > 0) { $query .= " AND MONTH(s.date) = %d"; $params[] = $month; }
            $query .= " AND YEAR(s.date) = %d"; $params[] = $year;
        }
        $data = $wpdb->get_results($wpdb->prepare($query, $params));
        foreach ($data as $row) {
            $sec = ($row->check_in_time && $row->check_out_time) ? (strtotime($row->check_out_time)-strtotime($row->check_in_time)) : 0;
            fputcsv($output, array($row->firstname . ' ' . $row->lastname, $row->date, $row->title, $row->check_in_time, $row->check_out_time, pta_format_seconds($sec)));
        }
    }
    fclose($output);
    exit;
}

// 2. Database & Template Hijacker
register_activation_hook( __FILE__, 'pta_kiosk_setup_db' );
function pta_kiosk_setup_db() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'pta_volunteer_checkins';
    if($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        $wpdb->query("CREATE TABLE $table_name (id mediumint(9) NOT NULL AUTO_INCREMENT, signup_id mediumint(9) NOT NULL, check_in_time datetime DEFAULT NULL, check_out_time datetime DEFAULT NULL, PRIMARY KEY (id)) {$wpdb->get_charset_collate()};");
    }
}

add_filter( 'template_include', 'pta_kiosk_force_blank_template', 99 );
function pta_kiosk_force_blank_template( $template ) {
    if ( is_singular() && has_shortcode( get_post()->post_content, 'pta_checkin' ) ) {
        $kiosk_template = plugin_dir_path( __FILE__ ) . 'kiosk-template-temp.php';
        $content = '<?php echo "<!DOCTYPE html><html "; language_attributes(); echo "><head><meta charset=\'utf-8\'><meta name=\'viewport\' content=\'width=device-width, initial-scale=1\'>"; wp_head(); echo "<style>#sidebar, .sidebar, #secondary, #header, #footer, .site-header, .site-footer, .nav-menu, #masthead, #colophon { display: none !important; } body, html { margin: 0 !important; padding: 0 !important; width: 100% !important; background: #f0f2f5 !important; } .kiosk-fullscreen-wrapper { width: 100vw; min-height: 100vh; display: flex; justify-content: center; align-items: flex-start; padding-top: 50px; } .entry-content, .post-content { width: 100% !important; max-width: 100% !important; margin: 0 !important; }</style></head><body><div class=\'kiosk-fullscreen-wrapper\'>"; while ( have_posts() ) : the_post(); the_content(); endwhile; echo "</div>"; wp_footer(); echo "</body></html>"; ?>';
        file_put_contents($kiosk_template, $content);
        return $kiosk_template;
    }
    return $template;
}

// 3. Shortcode: [pta_checkin sheet_id="X"]
add_shortcode( 'pta_checkin', 'pta_kiosk_shortcode' );
function pta_kiosk_shortcode( $atts ) {
    global $wpdb;
    $args = shortcode_atts( array( 'sheet_id' => 0 ), $atts );
    $target_sheet_id = intval($args['sheet_id']);
    $signup_table = $wpdb->prefix . 'pta_sus_signups';
    $sheet_table  = $wpdb->prefix . 'pta_sus_sheets';
    $task_table = $wpdb->prefix . 'pta_sus_tasks';
    $checkin_table = $wpdb->prefix . 'pta_volunteer_checkins';
    ob_start();
    echo '<meta http-equiv="refresh" content="300">';
    ?>
    <style>.pta-kiosk { max-width: 600px; margin: 0 auto; padding: 30px; border: 1px solid #ddd; border-radius: 15px; background: #fff; box-shadow: 0 10px 25px rgba(0,0,0,0.1); font-family: -apple-system, sans-serif; } .pta-btn { width: 100%; padding: 18px; margin-top: 15px; cursor: pointer; border: none; font-weight: bold; border-radius: 8px; font-size: 18px; text-transform: uppercase; } .btn-blue { background: #2271b1; color: white; } .btn-green { background: #00a32a; color: white; } .btn-red { background: #d63638; color: white; } .pta-input { width: 100%; padding: 15px; margin: 12px 0; border: 2px solid #dcdcde; border-radius: 6px; box-sizing: border-box; font-size: 16px; }</style>
    <div class="pta-kiosk">
        <h1 style="text-align:center;">ICSP Volunteer Station</h1>
        <?php
        if ($target_sheet_id > 0) {
            $sheet_name = $wpdb->get_var($wpdb->prepare("SELECT title FROM $sheet_table WHERE id = %d", $target_sheet_id));
            echo "<div style='background: #f0f6fb; padding: 10px; border-radius: 5px; color: #2271b1; text-align: center; margin-bottom: 15px;'><b>Event:</b> " . esc_html($sheet_name) . "</div>";
        }
        
        // Action Handlers
        if ( isset($_POST['pta_action']) ) {
            $sid = intval($_POST['signup_id']); $pin = sanitize_text_field($_POST['pin']); $now = current_time('mysql');
            $user_phone = $wpdb->get_var($wpdb->prepare("SELECT phone FROM $signup_table WHERE id = %d", $sid));
            if ( substr(preg_replace('/[^0-9]/', '', $user_phone), -4) !== $pin ) { echo "<p style='color:red; text-align:center;'>PIN Incorrect.</p>"; } else {
                if ($_POST['pta_action'] == 'check_in') { $wpdb->insert($checkin_table, array('signup_id' => $sid, 'check_in_time' => $now)); echo "<h2 style='color:green; text-align:center;'>✅ Checked In</h2>"; } 
                else { $wpdb->update($checkin_table, array('check_out_time' => $now), array('signup_id' => $sid)); echo "<h2 style='color:blue; text-align:center;'>👋 Checked Out</h2>"; }
            }
        }
        $query = "SELECT s.id, s.firstname, s.lastname, t.title, t.time_start, t.time_end FROM $signup_table s JOIN $task_table t ON s.task_id = t.id";
        if ($target_sheet_id > 0) { $daily_signups = $wpdb->get_results($wpdb->prepare($query . " WHERE t.sheet_id = %d", $target_sheet_id)); } else { $daily_signups = $wpdb->get_results($query); }
        ?>
        <div id="kiosk-main">
            <input type="text" id="k-search" class="pta-input" placeholder="🔍 Search name..." onkeyup="filterNames()">
            <form method="post">
                <select id="k-select" name="signup_id" size="6" style="width:100%;" required><?php foreach ($daily_signups as $s) echo "<option value='{$s->id}'>".esc_html($s->firstname.' '.$s->lastname.' >> '.$s->title.' ('.$s->time_start.' - '.$s->time_end.')')."</option>"; ?></select>
                <input type="password" name="pin" class="pta-input" placeholder="Last 4 of phone" maxlength="4" required inputmode="numeric">
                <div style="display:flex; gap:10px;"><button type="submit" name="pta_action" value="check_in" class="pta-btn btn-green">In</button><button type="submit" name="pta_action" value="check_out" class="pta-btn btn-red">Out</button></div>
            </form>
            <p style="text-align:center;"><a href="javascript:void(0)" onclick="toggleView('kiosk-reg')">Register Here</a></p>
        </div>
        <div id="kiosk-reg" style="display:none;">
            <form method="post">
                <input type="hidden" name="sheet_id" value="<?php echo $target_sheet_id; ?>">
                <select name="task_id" class="pta-input" required><?php $tasks = $wpdb->get_results("SELECT id, title, time_start, time_end FROM $task_table WHERE sheet_id = $target_sheet_id"); foreach($tasks as $ta) echo "<option value='{$ta->id}'>{$ta->title} ({$ta->time_start} - {$ta->time_end})</option>"; ?></select>
                <input type="text" name="fname" class="pta-input" placeholder="First Name" required><input type="text" name="lname" class="pta-input" placeholder="Last Name" required><input type="email" name="email" class="pta-input" placeholder="Email" required><input type="tel" name="phone" class="pta-input" placeholder="Phone" required>
                <button type="submit" name="action_signup" class="pta-btn btn-blue">Register</button><p style="text-align:center;"><a href="javascript:void(0)" onclick="toggleView('kiosk-main')">Back</a></p>
            </form>
        </div>
    </div>
    <script>
    function filterNames() { var val = document.getElementById('k-search').value.toUpperCase(); var opts = document.getElementById('k-select').options; for (var i=0; i<opts.length; i++) opts[i].style.display = opts[i].text.toUpperCase().includes(val) ? '' : 'none'; }
    function toggleView(id) { document.getElementById('kiosk-main').style.display = (id === 'kiosk-main') ? 'block' : 'none'; document.getElementById('kiosk-reg').style.display = (id === 'kiosk-reg') ? 'block' : 'none'; }
    </script>
    <?php return ob_get_clean();
}

// 4. Admin Reports
add_action('admin_menu', 'pta_kiosk_admin_menu');
function pta_kiosk_admin_menu() { add_menu_page('Sign-up Sheets', 'Sign-up Reports', 'manage_options', 'pta-reports', 'pta_kiosk_admin_reports', 'dashicons-chart-area'); }

function pta_kiosk_admin_reports() {
    global $wpdb;
    $tab = isset($_GET['tab']) ? $_GET['tab'] : 'logs';
    $checkin_table = "{$wpdb->prefix}pta_volunteer_checkins";

    if (isset($_POST['pta_save_log'])) {
        $wpdb->update($checkin_table, array('check_in_time' => sanitize_text_field($_POST['edit_in']), 'check_out_time' => !empty($_POST['edit_out']) ? sanitize_text_field($_POST['edit_out']) : NULL), array('id' => intval($_POST['log_id'])));
        echo "<div class='updated'><p>Log updated.</p></div>";
    }

    if (isset($_POST['pta_delete_log'])) {
        $wpdb->delete($checkin_table, array('id' => intval($_POST['log_id'])));
        echo "<div class='error'><p>Log entry removed.</p></div>";
    }

    ?>
    <div class="wrap">
        <h1>Volunteer Attendance</h1>
        <h2 class="nav-tab-wrapper">
            <a href="?page=pta-reports&tab=logs" class="nav-tab <?php echo $tab=='logs'?'nav-tab-active':''; ?>">Recent Logs</a>
            <a href="?page=pta-reports&tab=event_totals" class="nav-tab <?php echo $tab=='event_totals'?'nav-tab-active':''; ?>">Event Totals</a>
            <a href="?page=pta-reports&tab=volunteer_search" class="nav-tab <?php echo $tab=='volunteer_search'?'nav-tab-active':''; ?>">Volunteer Lookup</a>
        </h2>

        <?php if($tab == 'logs'): 
            $edit_id = isset($_GET['edit_id']) ? intval($_GET['edit_id']) : 0;
            ?>
            <h3>Manage Activity Logs</h3>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th>Name</th><th>Event</th><th>Check-in</th><th>Check-out</th><th>Duration</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php
                    $logs = $wpdb->get_results("SELECT c.*, s.firstname, s.lastname, sh.title as 'event_title' FROM $checkin_table c JOIN {$wpdb->prefix}pta_sus_signups s ON c.signup_id = s.id JOIN {$wpdb->prefix}pta_sus_tasks ta ON s.task_id = ta.id JOIN {$wpdb->prefix}pta_sus_sheets sh ON ta.sheet_id = sh.id ORDER BY c.check_in_time DESC LIMIT 50");
                    foreach($logs as $l) {
                        $sec = ($l->check_in_time && $l->check_out_time) ? (strtotime($l->check_out_time)-strtotime($l->check_in_time)) : 0;
                        if ($edit_id == $l->id) {
                            $in_val = date('Y-m-d\TH:i', strtotime($l->check_in_time));
                            $out_val = $l->check_out_time ? date('Y-m-d\TH:i', strtotime($l->check_out_time)) : '';
                            echo "<tr><form method='post' action='?page=pta-reports&tab=logs'>
                                <td><strong>{$l->firstname} {$l->lastname}</strong></td><td>{$l->event_title}</td>
                                <td><input type='datetime-local' name='edit_in' value='$in_val' required></td>
                                <td><input type='datetime-local' name='edit_out' value='$out_val'></td>
                                <td>--</td>
                                <td><input type='hidden' name='log_id' value='{$l->id}'><input type='submit' name='pta_save_log' class='button button-primary' value='Save'> <a href='?page=pta-reports&tab=logs' class='button'>Cancel</a></td>
                            </form></tr>";
                        } else {
                            echo "<tr>
                                <td>{$l->firstname} {$l->lastname}</td><td>{$l->event_title}</td>
                                <td>" . date('M j, g:i a', strtotime($l->check_in_time)) . "</td>
                                <td>" . ($l->check_out_time ? date('g:i a', strtotime($l->check_out_time)) : "<span style='color:green;'>Active</span>") . "</td>
                                <td>" . pta_format_seconds($sec) . "</td>
                                <td><a href='?page=pta-reports&tab=logs&edit_id={$l->id}' class='button button-small'>Edit</a>
                                    <form method='post' style='display:inline; margin-left:5px;'><input type='hidden' name='log_id' value='{$l->id}'><input type='submit' name='pta_delete_log' class='button button-small button-link-delete' value='Delete' onclick='return confirm(\"Delete?\")'></form></td>
                            </tr>";
                        }
                    } ?>
                </tbody>
            </table>

        <?php elseif($tab == 'event_totals'): ?>
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <h3>Total Time per Person per Event</h3>
                <a href="<?php echo admin_url('admin.php?page=pta-reports&tab=event_totals&pta_export=event_totals'); ?>" class="button button-secondary">📥 Export CSV</a>
            </div>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th>Volunteer</th><th>Event</th><th>Total Time (HH:MM:SS)</th></tr></thead>
                <tbody>
                    <?php
                    $totals = $wpdb->get_results("SELECT s.firstname, s.lastname, sh.title, SUM(TIMESTAMPDIFF(SECOND, c.check_in_time, c.check_out_time)) as total_sec FROM $checkin_table c JOIN {$wpdb->prefix}pta_sus_signups s ON c.signup_id = s.id JOIN {$wpdb->prefix}pta_sus_tasks ta ON s.task_id = ta.id JOIN {$wpdb->prefix}pta_sus_sheets sh ON ta.sheet_id = sh.id WHERE c.check_out_time IS NOT NULL GROUP BY s.id, sh.id");
                    foreach($totals as $t) { echo "<tr><td>{$t->firstname} {$t->lastname}</td><td>{$t->title}</td><td>" . pta_format_seconds($t->total_sec) . "</td></tr>"; }
                    ?>
                </tbody>
            </table>

        <?php elseif($tab == 'volunteer_search'): 
            $search = isset($_POST['v_name']) ? sanitize_text_field($_POST['v_name']) : '';
            $month = isset($_POST['v_month']) ? intval($_POST['v_month']) : date('m');
            $year = isset($_POST['v_year']) ? intval($_POST['v_year']) : date('Y');
            $all_time = isset($_POST['all_time']);
            ?>
            <div style="display:flex; justify-content:space-between; align-items:center;"><h3>Volunteer Lookup</h3><?php if ($search): ?><a href="<?php echo admin_url('admin.php?page=pta-reports&tab=volunteer_search&pta_export=volunteer_lookup&v_name='.$search.'&v_month='.$month.'&v_year='.$year.'&all_time='.($all_time?'1':'0')); ?>" class="button button-secondary">📥 Export CSV</a><?php endif; ?></div>
            <form method="post" style="background:#fff; padding:15px; border:1px solid #ccc; margin:10px 0;">
                <input type="text" name="v_name" placeholder="Volunteer Name" value="<?php echo $search; ?>" required> | 
                <label><input type="checkbox" name="all_time" <?php checked($all_time); ?>> <strong>Search All Time</strong></label>
                <span style="margin-left:20px;"><select name="v_month"><option value="0" <?php selected($month, 0); ?>>-- Any Month --</option><?php for($m=1;$m<=12;$m++) echo "<option value='$m' ".selected($month,$m).">".date('F', mktime(0,0,0,$m,1))."</option>"; ?></select>
                <select name="v_year"><?php for($y=2024;$y<=2027;$y++) echo "<option value='$y' ".selected($year,$y).">$y</option>"; ?></select></span>
                <input type="submit" class="button button-primary" value="Run Report" style="margin-left:15px;">
            </form>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th>Date</th><th>Event</th><th>Check In</th><th>Check Out</th><th>Duration</th></tr></thead>
                <tbody>
                    <?php
                    if ($search) {
                        $total_accumulated_sec = 0;
                        $query = "SELECT s.date, sh.title, c.check_in_time, c.check_out_time FROM {$wpdb->prefix}pta_sus_signups s JOIN {$wpdb->prefix}pta_sus_tasks ta ON s.task_id = ta.id JOIN {$wpdb->prefix}pta_sus_sheets sh ON ta.sheet_id = sh.id LEFT JOIN $checkin_table c ON s.id = c.signup_id WHERE (s.firstname LIKE %s OR s.lastname LIKE %s)";
                        $params = array("%$search%", "%$search%");
                        if (!$all_time) { if ($month > 0) { $query .= " AND MONTH(s.date) = %d"; $params[] = $month; } $query .= " AND YEAR(s.date) = %d"; $params[] = $year; }
                        $v_results = $wpdb->get_results($wpdb->prepare($query, $params));
                        foreach($v_results as $v) {
                            $s = ($v->check_in_time && $v->check_out_time) ? (strtotime($v->check_out_time)-strtotime($v->check_in_time)) : 0;
                            $total_accumulated_sec += $s;
                            echo "<tr><td>{$v->date}</td><td>{$v->title}</td><td>{$v->check_in_time}</td><td>{$v->check_out_time}</td><td>".pta_format_seconds($s)."</td></tr>";
                        }
                        echo "<tr style='background:#f0f6fb; font-weight:bold;'><td colspan='4' style='text-align:right;'>Total Participation:</td><td>" . pta_format_seconds($total_accumulated_sec) . "</td></tr>";
                    } ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}