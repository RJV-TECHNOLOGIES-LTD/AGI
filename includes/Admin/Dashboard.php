<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\Admin;
use RJV_AGI_Bridge\{Settings, AuditLog};

class Dashboard {
    public function register_menu(): void {
        add_menu_page('RJV AGI Bridge','AGI Bridge','manage_options','rjv-agi-bridge',[$this,'render'],'dashicons-cloud',3);
        add_submenu_page('rjv-agi-bridge','Settings','Settings','manage_options','rjv-agi-settings',[$this,'settings']);
        add_submenu_page('rjv-agi-bridge','Audit Log','Audit Log','manage_options','rjv-agi-audit',[$this,'audit']);
        add_submenu_page('rjv-agi-bridge','AI Playground','AI Playground','manage_options','rjv-agi-playground',[$this,'playground']);
    }
    public function enqueue_assets(string $hook): void {
        if(strpos($hook,'rjv-agi')===false)return;
        wp_enqueue_style('rjv-agi',RJV_AGI_PLUGIN_URL.'admin/css/admin.css',[],RJV_AGI_VERSION);
        wp_enqueue_script('rjv-agi',RJV_AGI_PLUGIN_URL.'admin/js/admin.js',['jquery'],RJV_AGI_VERSION,true);
        wp_localize_script('rjv-agi','rjvAgi',['restUrl'=>rest_url('rjv-agi/v1/'),'nonce'=>wp_create_nonce('wp_rest'),'apiKey'=>Settings::get('api_key')]);
    }
    public function render(): void { $s=Settings::all(); ?>
<div class="wrap rjv-agi-wrap"><h1><?php esc_html_e('RJV AGI Bridge', 'rjv-agi-bridge'); ?></h1>
<div class="rjv-grid">
<div class="rjv-card"><h2><?php esc_html_e('System Status', 'rjv-agi-bridge'); ?></h2><div id="rjv-health" class="rjv-loading"><?php esc_html_e('Loading...', 'rjv-agi-bridge'); ?></div></div>
<div class="rjv-card"><h2><?php esc_html_e('Today', 'rjv-agi-bridge'); ?></h2><div id="rjv-stats" class="rjv-loading"><?php esc_html_e('Loading...', 'rjv-agi-bridge'); ?></div></div>
<div class="rjv-card"><h2><?php esc_html_e('AI Providers', 'rjv-agi-bridge'); ?></h2><div id="rjv-ai" class="rjv-loading"><?php esc_html_e('Loading...', 'rjv-agi-bridge'); ?></div></div>
<div class="rjv-card rjv-card-full"><h2><?php esc_html_e('API Key', 'rjv-agi-bridge'); ?></h2>
<p><?php esc_html_e('Header:', 'rjv-agi-bridge'); ?> <code>X-RJV-AGI-Key</code></p><div class="rjv-key-box"><code id="rjv-key"><?php echo esc_html($s['api_key']);?></code><button class="button" onclick="navigator.clipboard.writeText(document.getElementById('rjv-key').textContent)"><?php esc_html_e('Copy', 'rjv-agi-bridge'); ?></button></div>
</div></div></div><?php }

    public function settings(): void {
        if($_SERVER['REQUEST_METHOD']==='POST'&&check_admin_referer('rjv_s')){
            foreach(['openai_key','anthropic_key','default_model','openai_model','anthropic_model','rate_limit','audit_enabled','allowed_ips','log_retention_days'] as $f)
                if(isset($_POST["rjv_{$f}"]))Settings::set($f,sanitize_textarea_field(wp_unslash($_POST["rjv_{$f}"])));
            if(isset($_POST['rjv_regen']))Settings::set('api_key',wp_generate_password(64,false));
            echo '<div class="notice notice-success"><p>' . esc_html__('Settings saved.', 'rjv-agi-bridge') . '</p></div>';
        }$s=Settings::all(); ?>
<div class="wrap rjv-agi-wrap"><h1><?php esc_html_e('AGI Bridge Settings', 'rjv-agi-bridge'); ?></h1><form method="post"><?php wp_nonce_field('rjv_s');?>
<table class="form-table">
<tr><th><?php esc_html_e('OpenAI Key', 'rjv-agi-bridge'); ?></th><td><input type="password" name="rjv_openai_key" value="<?php echo esc_attr(Settings::get('openai_key'));?>" class="regular-text"></td></tr>
<tr><th><?php esc_html_e('Anthropic Key', 'rjv-agi-bridge'); ?></th><td><input type="password" name="rjv_anthropic_key" value="<?php echo esc_attr(Settings::get('anthropic_key'));?>" class="regular-text"></td></tr>
<tr><th><?php esc_html_e('Default Provider', 'rjv-agi-bridge'); ?></th><td><select name="rjv_default_model"><option value="anthropic" <?php selected($s['default_model'],'anthropic');?>>Anthropic</option><option value="openai" <?php selected($s['default_model'],'openai');?>>OpenAI</option></select></td></tr>
<tr><th><?php esc_html_e('OpenAI Model', 'rjv-agi-bridge'); ?></th><td><input name="rjv_openai_model" value="<?php echo esc_attr($s['openai_model']);?>" class="regular-text"></td></tr>
<tr><th><?php esc_html_e('Anthropic Model', 'rjv-agi-bridge'); ?></th><td><input name="rjv_anthropic_model" value="<?php echo esc_attr($s['anthropic_model']);?>" class="regular-text"></td></tr>
<tr><th><?php esc_html_e('Rate Limit/min', 'rjv-agi-bridge'); ?></th><td><input type="number" name="rjv_rate_limit" value="<?php echo esc_attr($s['rate_limit']);?>" min="1"></td></tr>
<tr><th><?php esc_html_e('Audit Logging', 'rjv-agi-bridge'); ?></th><td><label><input type="checkbox" name="rjv_audit_enabled" value="1" <?php checked($s['audit_enabled'],'1');?>> <?php esc_html_e('Enable', 'rjv-agi-bridge'); ?></label></td></tr>
<tr><th><?php esc_html_e('Log Retention (days)', 'rjv-agi-bridge'); ?></th><td><input type="number" name="rjv_log_retention_days" value="<?php echo esc_attr($s['log_retention_days']??90);?>" min="1" max="365"><p class="description"><?php esc_html_e('Audit log entries older than this will be automatically deleted.', 'rjv-agi-bridge'); ?></p></td></tr>
<tr><th><?php esc_html_e('IP Allowlist', 'rjv-agi-bridge'); ?></th><td><textarea name="rjv_allowed_ips" rows="3" class="large-text"><?php echo esc_textarea($s['allowed_ips']??'');?></textarea><p class="description"><?php esc_html_e('One IP per line. Leave empty to allow all.', 'rjv-agi-bridge'); ?></p></td></tr>
<tr><th><?php esc_html_e('Regenerate Key', 'rjv-agi-bridge'); ?></th><td><label><input type="checkbox" name="rjv_regen" value="1"> <?php esc_html_e('Generate new API key on save', 'rjv-agi-bridge'); ?></label></td></tr>
</table><?php submit_button();?></form></div><?php }

    public function audit(): void { $entries=AuditLog::query(['per_page'=>100]); ?>
<div class="wrap rjv-agi-wrap"><h1><?php esc_html_e('Audit Log', 'rjv-agi-bridge'); ?></h1>
<table class="wp-list-table widefat fixed striped rjv-audit-table"><thead><tr><th><?php esc_html_e('Time', 'rjv-agi-bridge'); ?></th><th><?php esc_html_e('Agent', 'rjv-agi-bridge'); ?></th><th><?php esc_html_e('Action', 'rjv-agi-bridge'); ?></th><th><?php esc_html_e('Resource', 'rjv-agi-bridge'); ?></th><th><?php esc_html_e('Tier', 'rjv-agi-bridge'); ?></th><th><?php esc_html_e('Status', 'rjv-agi-bridge'); ?></th><th><?php esc_html_e('Tokens', 'rjv-agi-bridge'); ?></th><th><?php esc_html_e('Latency', 'rjv-agi-bridge'); ?></th></tr></thead><tbody>
<?php foreach($entries as $e):?><tr><td><?php echo esc_html($e['timestamp']);?></td><td><?php echo esc_html($e['agent_id']);?></td><td><code><?php echo esc_html($e['action']);?></code></td><td><?php echo esc_html($e['resource_type'].($e['resource_id']?" #{$e['resource_id']}":'')); ?></td><td><span class="tier-badge tier-<?php echo esc_attr($e['tier']);?>">T<?php echo esc_html($e['tier']);?></span></td><td class="status-<?php echo esc_attr($e['status']);?>"><?php echo esc_html($e['status']);?></td><td><?php echo $e['tokens_used']?number_format((int)$e['tokens_used']):'-';?></td><td><?php echo $e['execution_time_ms']?esc_html($e['execution_time_ms']).'ms':'-';?></td></tr>
<?php endforeach;?></tbody></table></div><?php }

    public function playground(): void { ?>
<div class="wrap rjv-agi-wrap"><h1><?php esc_html_e('AI Playground', 'rjv-agi-bridge'); ?></h1>
<div class="rjv-playground">
<p><select id="rjv-prov"><option value=""><?php esc_html_e('Default', 'rjv-agi-bridge'); ?></option><option value="anthropic">Anthropic</option><option value="openai">OpenAI</option></select></p>
<p><strong><?php esc_html_e('System:', 'rjv-agi-bridge'); ?></strong><br><textarea id="rjv-sys" rows="2">You are a helpful assistant for RJV Technologies Ltd.</textarea></p>
<p><strong><?php esc_html_e('Message:', 'rjv-agi-bridge'); ?></strong><br><textarea id="rjv-msg" rows="4"></textarea></p>
<p><button class="button button-primary" id="rjv-send"><?php esc_html_e('Send', 'rjv-agi-bridge'); ?></button> <span id="rjv-load" style="display:none" class="rjv-loading"><?php esc_html_e('Working...', 'rjv-agi-bridge'); ?></span></p>
<div id="rjv-out" style="display:none" class="rjv-playground-output"><pre id="rjv-text"></pre><p id="rjv-meta" class="rjv-playground-meta"></p></div>
</div></div><?php }
}
