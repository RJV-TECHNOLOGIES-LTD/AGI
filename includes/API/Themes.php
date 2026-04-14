<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;
use RJV_AGI_Bridge\Auth;

class Themes extends Base {
    public function register_routes(): void {
        register_rest_route($this->namespace,'/themes',[['methods'=>'GET','callback'=>[$this,'list_all'],'permission_callback'=>[Auth::class,'tier1']]]);
        register_rest_route($this->namespace,'/themes/activate',[['methods'=>'POST','callback'=>[$this,'activate'],'permission_callback'=>[Auth::class,'tier3']]]);
        register_rest_route($this->namespace,'/themes/install',[['methods'=>'POST','callback'=>[$this,'install'],'permission_callback'=>[Auth::class,'tier3']]]);
        register_rest_route($this->namespace,'/themes/update',[['methods'=>'POST','callback'=>[$this,'update_theme'],'permission_callback'=>[Auth::class,'tier3']]]);
        register_rest_route($this->namespace,'/themes/delete',[['methods'=>'POST','callback'=>[$this,'delete_theme'],'permission_callback'=>[Auth::class,'tier3']]]);
        register_rest_route($this->namespace,'/themes/customizer',[['methods'=>'GET','callback'=>[$this,'get_mods'],'permission_callback'=>[Auth::class,'tier1']],['methods'=>'PUT','callback'=>[$this,'set_mods'],'permission_callback'=>[Auth::class,'tier2']]]);
        register_rest_route($this->namespace,'/themes/template-parts',[['methods'=>'GET','callback'=>[$this,'template_parts'],'permission_callback'=>[Auth::class,'tier1']]]);
        register_rest_route($this->namespace,'/themes/patterns',[['methods'=>'GET','callback'=>[$this,'patterns'],'permission_callback'=>[Auth::class,'tier1']]]);
        register_rest_route($this->namespace,'/themes/global-styles',[['methods'=>'GET','callback'=>[$this,'global_styles'],'permission_callback'=>[Auth::class,'tier1']]]);
    }
    public function list_all(\WP_REST_Request $r): \WP_REST_Response { $active=get_stylesheet();return $this->success(array_map(fn($s,$t)=>['slug'=>$s,'name'=>$t->get('Name'),'version'=>$t->get('Version'),'active'=>$s===$active],array_keys(wp_get_themes()),wp_get_themes())); }
    public function activate(\WP_REST_Request $r): \WP_REST_Response|\WP_Error { $s=sanitize_text_field($r->get_json_params()['slug']??'');$t=wp_get_theme($s);if(!$t->exists())return $this->error('Not found',404);switch_theme($s);$this->log('activate_theme','theme',0,['slug'=>$s],3);return $this->success(['activated'=>$s]); }
    public function install(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d=(array)$r->get_json_params(); $slug=sanitize_key((string)($d['slug'] ?? ''));
        if($slug==='') return $this->error('slug required');
        require_once ABSPATH.'wp-admin/includes/theme-install.php';
        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
        $api=themes_api('theme_information',['slug'=>$slug,'fields'=>['sections'=>false]]);
        if(is_wp_error($api)) return $this->error($api->get_error_message(),500);
        $result=$this->with_theme_guard(function() use ($api) {
            $upgrader=new \Theme_Upgrader(new \Automatic_Upgrader_Skin());
            return $upgrader->install((string)$api->download_link);
        });
        if(is_wp_error($result) || $result!==true) return $this->error(is_wp_error($result)?$result->get_error_message():'Failed to install theme',500);
        if(!empty($d['activate'])) switch_theme($slug);
        $this->log('install_theme','theme',0,['slug'=>$slug,'activate'=>!empty($d['activate'])],3);
        return $this->success(['installed'=>true,'slug'=>$slug,'activated'=>!empty($d['activate'])],201);
    }
    public function update_theme(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d=(array)$r->get_json_params(); $slug=sanitize_key((string)($d['slug'] ?? ''));
        if($slug==='') return $this->error('slug required');
        if(!wp_get_theme($slug)->exists()) return $this->error('Theme not found',404);
        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
        $result=$this->with_theme_guard(function() use ($slug) {
            $upgrader=new \Theme_Upgrader(new \Automatic_Upgrader_Skin());
            return $upgrader->upgrade($slug);
        });
        if(is_wp_error($result) || !$result) return $this->error(is_wp_error($result)?$result->get_error_message():'Failed to update theme',500);
        $this->log('update_theme','theme',0,['slug'=>$slug],3);
        return $this->success(['updated'=>true,'slug'=>$slug]);
    }
    public function delete_theme(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d=(array)$r->get_json_params(); $slug=sanitize_key((string)($d['slug'] ?? ''));
        if($slug==='') return $this->error('slug required');
        if(!wp_get_theme($slug)->exists()) return $this->error('Theme not found',404);
        if($slug===get_stylesheet()||$slug===get_template()) return $this->error('Cannot delete active theme');
        require_once ABSPATH.'wp-admin/includes/theme.php';
        require_once ABSPATH.'wp-admin/includes/file.php';
        $result=$this->with_theme_guard(fn()=>delete_theme($slug));
        if(is_wp_error($result) || $result!==true) return $this->error(is_wp_error($result)?$result->get_error_message():'Failed to delete theme',500);
        $this->log('delete_theme','theme',0,['slug'=>$slug],3);
        return $this->success(['deleted'=>true,'slug'=>$slug]);
    }
    public function get_mods(\WP_REST_Request $r): \WP_REST_Response { return $this->success(get_theme_mods()); }
    public function set_mods(\WP_REST_Request $r): \WP_REST_Response { foreach($r->get_json_params() as $k=>$v) set_theme_mod(sanitize_key($k),$v);$this->log('set_theme_mods','theme',0,[],2);return $this->success(['updated'=>true]); }
    public function template_parts(\WP_REST_Request $r): \WP_REST_Response {
        $parts=get_posts(['post_type'=>'wp_template_part','post_status'=>'publish,draft','posts_per_page'=>200]);
        return $this->success(array_map(fn(\WP_Post $p)=>['id'=>$p->ID,'slug'=>$p->post_name,'title'=>$p->post_title,'status'=>$p->post_status,'theme'=>get_post_meta($p->ID,'theme',true)],$parts));
    }
    public function patterns(\WP_REST_Request $r): \WP_REST_Response {
        $registry=\WP_Block_Patterns_Registry::get_instance(); $patterns=$registry->get_all_registered();
        return $this->success(array_map(fn(array $p)=>['name'=>$p['name']??'','title'=>$p['title']??'','categories'=>$p['categories']??[]],$patterns));
    }
    public function global_styles(\WP_REST_Request $r): \WP_REST_Response {
        $styles=get_posts(['post_type'=>'wp_global_styles','post_status'=>'publish,draft','posts_per_page'=>50]);
        return $this->success(array_map(fn(\WP_Post $p)=>['id'=>$p->ID,'slug'=>$p->post_name,'status'=>$p->post_status,'modified'=>$p->post_modified_gmt],$styles));
    }
    private function with_theme_guard(callable $op) {
        $before_stylesheet=get_stylesheet();
        $before_template=get_template();
        try {
            $result=$op();
            if(is_wp_error($result) || $result===false || $result===null){
                if(get_stylesheet()!==$before_stylesheet || get_template()!==$before_template) switch_theme($before_stylesheet);
            }
            return $result;
        } catch(\Throwable $e){
            if(get_stylesheet()!==$before_stylesheet || get_template()!==$before_template) switch_theme($before_stylesheet);
            return new \WP_Error('theme_guard_failed',$e->getMessage(),['status'=>500]);
        }
    }
}
