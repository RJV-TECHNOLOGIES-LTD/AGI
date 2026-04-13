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
<div class="wrap"><h1>RJV AGI Bridge</h1>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(350px,1fr));gap:20px;margin-top:20px">
<div style="background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:20px"><h2 style="border-bottom:2px solid #2271b1;padding-bottom:8px">System Status</h2><div id="rjv-health">Loading...</div></div>
<div style="background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:20px"><h2 style="border-bottom:2px solid #2271b1;padding-bottom:8px">Today</h2><div id="rjv-stats">Loading...</div></div>
<div style="background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:20px"><h2 style="border-bottom:2px solid #2271b1;padding-bottom:8px">AI Providers</h2><div id="rjv-ai">Loading...</div></div>
<div style="background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:20px;grid-column:1/-1"><h2 style="border-bottom:2px solid #2271b1;padding-bottom:8px">API Key</h2>
<p>Header: <code>X-RJV-AGI-Key</code></p><div style="background:#f0f0f0;padding:12px;border-radius:4px;display:flex;gap:12px;align-items:center"><code id="rjv-key" style="flex:1;word-break:break-all"><?php echo esc_html($s['api_key']);?></code><button class="button" onclick="navigator.clipboard.writeText(document.getElementById('rjv-key').textContent)">Copy</button></div>
</div></div></div><?php }

    public function settings(): void {
        if($_SERVER['REQUEST_METHOD']==='POST'&&check_admin_referer('rjv_s')){
            foreach(['openai_key','anthropic_key','default_model','openai_model','anthropic_model','rate_limit','audit_enabled','allowed_ips'] as $f)
                if(isset($_POST["rjv_{$f}"]))Settings::set($f,sanitize_textarea_field(wp_unslash($_POST["rjv_{$f}"])));
            if(isset($_POST['rjv_regen']))Settings::set('api_key',wp_generate_password(64,false));
            echo '<div class="notice notice-success"><p>Saved.</p></div>';
        }$s=Settings::all(); ?>
<div class="wrap"><h1>AGI Bridge Settings</h1><form method="post"><?php wp_nonce_field('rjv_s');?>
<table class="form-table">
<tr><th>OpenAI Key</th><td><input type="password" name="rjv_openai_key" value="<?php echo esc_attr(Settings::get('openai_key'));?>" class="regular-text"></td></tr>
<tr><th>Anthropic Key</th><td><input type="password" name="rjv_anthropic_key" value="<?php echo esc_attr(Settings::get('anthropic_key'));?>" class="regular-text"></td></tr>
<tr><th>Default Provider</th><td><select name="rjv_default_model"><option value="anthropic" <?php selected($s['default_model'],'anthropic');?>>Anthropic</option><option value="openai" <?php selected($s['default_model'],'openai');?>>OpenAI</option></select></td></tr>
<tr><th>OpenAI Model</th><td><input name="rjv_openai_model" value="<?php echo esc_attr($s['openai_model']);?>" class="regular-text"></td></tr>
<tr><th>Anthropic Model</th><td><input name="rjv_anthropic_model" value="<?php echo esc_attr($s['anthropic_model']);?>" class="regular-text"></td></tr>
<tr><th>Rate Limit/min</th><td><input type="number" name="rjv_rate_limit" value="<?php echo esc_attr($s['rate_limit']);?>" min="1"></td></tr>
<tr><th>Audit</th><td><label><input type="checkbox" name="rjv_audit_enabled" value="1" <?php checked($s['audit_enabled'],'1');?>> Enable</label></td></tr>
<tr><th>IP Allowlist</th><td><textarea name="rjv_allowed_ips" rows="3" class="large-text"><?php echo esc_textarea($s['allowed_ips']??'');?></textarea><p class="description">One per line. Empty = allow all.</p></td></tr>
<tr><th>Regen Key</th><td><label><input type="checkbox" name="rjv_regen" value="1"> New API key on save</label></td></tr>
</table><?php submit_button();?></form></div><?php }

    public function audit(): void { $entries=AuditLog::query(['per_page'=>100]); ?>
<div class="wrap"><h1>Audit Log</h1>
<table class="wp-list-table widefat fixed striped"><thead><tr><th>Time</th><th>Agent</th><th>Action</th><th>Resource</th><th>Tier</th><th>Status</th><th>Tokens</th><th>Latency</th></tr></thead><tbody>
<?php foreach($entries as $e):?><tr><td><?php echo esc_html($e['timestamp']);?></td><td><?php echo esc_html($e['agent_id']);?></td><td><code><?php echo esc_html($e['action']);?></code></td><td><?php echo esc_html($e['resource_type'].($e['resource_id']?" #{$e['resource_id']}":'')); ?></td><td>T<?php echo esc_html($e['tier']);?></td><td><?php echo esc_html($e['status']);?></td><td><?php echo $e['tokens_used']?number_format((int)$e['tokens_used']):'-';?></td><td><?php echo $e['execution_time_ms']?$e['execution_time_ms'].'ms':'-';?></td></tr>
<?php endforeach;?></tbody></table></div><?php }

    public function playground(): void { ?>
<div class="wrap"><h1>AI Playground</h1>
<div style="max-width:800px">
<p><select id="rjv-prov"><option value="">Default</option><option value="anthropic">Anthropic</option><option value="openai">OpenAI</option></select></p>
<p><strong>System:</strong><br><textarea id="rjv-sys" rows="2" style="width:100%">You are a helpful assistant for RJV Technologies Ltd.</textarea></p>
<p><strong>Message:</strong><br><textarea id="rjv-msg" rows="4" style="width:100%"></textarea></p>
<p><button class="button button-primary" id="rjv-send">Send</button> <span id="rjv-load" style="display:none">Working...</span></p>
<div id="rjv-out" style="display:none;background:#f9f9f9;padding:16px;border-radius:4px;margin-top:16px"><pre id="rjv-text" style="white-space:pre-wrap;max-height:500px;overflow:auto"></pre><p id="rjv-meta" style="font-size:12px;color:#666;margin-top:8px"></p></div>
</div></div><?php }
}
