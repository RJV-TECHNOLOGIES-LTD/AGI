<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;
use RJV_AGI_Bridge\Auth;

class Options extends Base {
    private array $rw=['blogname','blogdescription','timezone_string','date_format','time_format','start_of_week','posts_per_page','posts_per_rss','rss_use_excerpt','page_on_front','page_for_posts','show_on_front','blog_public','default_ping_status','default_comment_status','comment_moderation','comment_previously_approved','comment_registration','close_comments_for_old_posts','close_comments_days_old','thread_comments','thread_comments_depth','page_comments','comments_per_page','default_comments_page','comment_order','uploads_use_yearmonth_folders','thumbnail_size_w','thumbnail_size_h','thumbnail_crop','medium_size_w','medium_size_h','large_size_w','large_size_h','permalink_structure','category_base','tag_base','blog_charset','admin_email','default_role','users_can_register','site_icon'];
    private array $ro=['siteurl','home','template','stylesheet','WPLANG'];
    public function register_routes(): void {
        register_rest_route($this->namespace,'/options',[['methods'=>'GET','callback'=>[$this,'get_all'],'permission_callback'=>[Auth::class,'tier1']],['methods'=>'PUT','callback'=>[$this,'update'],'permission_callback'=>[Auth::class,'tier3']]]);
    }
    public function get_all(\WP_REST_Request $r): \WP_REST_Response { $res=[];foreach(array_merge($this->rw,$this->ro) as $k) $res[$k]=get_option($k);return $this->success($res); }
    public function update(\WP_REST_Request $r): \WP_REST_Response {
        $d=$r->get_json_params();$up=[];
        foreach($d as $k=>$v){
            $k=sanitize_key((string)$k); if(!in_array($k,$this->rw,true)) continue;
            update_option($k,$this->sanitize_value($k,$v)); $up[]=$k;
        }
        $this->log('update_options','option',0,['keys'=>$up],3);return $this->success(['updated'=>$up]);
    }
    private function sanitize_value(string $key, mixed $value): mixed {
        $ints=['posts_per_page','posts_per_rss','page_on_front','page_for_posts','start_of_week','close_comments_days_old','thread_comments_depth','comments_per_page','thumbnail_size_w','thumbnail_size_h','medium_size_w','medium_size_h','large_size_w','large_size_h','site_icon'];
        $bools=['rss_use_excerpt','blog_public','comment_moderation','comment_previously_approved','comment_registration','close_comments_for_old_posts','thread_comments','page_comments','uploads_use_yearmonth_folders','thumbnail_crop','users_can_register'];
        if(in_array($key,$ints,true)) return (int)$value;
        if(in_array($key,$bools,true)) return !empty($value)?1:0;
        if($key==='admin_email') return sanitize_email((string)$value);
        return sanitize_text_field((string)$value);
    }
}
