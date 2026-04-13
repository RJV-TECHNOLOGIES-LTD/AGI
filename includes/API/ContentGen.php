<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;
use RJV_AGI_Bridge\Auth;
use RJV_AGI_Bridge\AI\Router;

class ContentGen extends Base {
    public function register_routes(): void {
        register_rest_route($this->namespace,'/ai/complete',[['methods'=>'POST','callback'=>[$this,'complete'],'permission_callback'=>[Auth::class,'tier2']]]);
        register_rest_route($this->namespace,'/ai/generate-post',[['methods'=>'POST','callback'=>[$this,'gen_post'],'permission_callback'=>[Auth::class,'tier2']]]);
        register_rest_route($this->namespace,'/ai/generate-seo',[['methods'=>'POST','callback'=>[$this,'gen_seo'],'permission_callback'=>[Auth::class,'tier2']]]);
        register_rest_route($this->namespace,'/ai/rewrite',[['methods'=>'POST','callback'=>[$this,'rewrite'],'permission_callback'=>[Auth::class,'tier2']]]);
        register_rest_route($this->namespace,'/ai/status',[['methods'=>'GET','callback'=>[$this,'status'],'permission_callback'=>[Auth::class,'tier1']]]);
    }
    public function complete(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d=$r->get_json_params();if(empty($d['message']))return $this->error('message required');
        $ai=new Router();$res=$ai->complete($d['system_prompt']??'You are an assistant for RJV Technologies Ltd.',$d['message'],['provider'=>$d['provider']??'','temperature'=>(float)($d['temperature']??0.3),'max_tokens'=>(int)($d['max_tokens']??4096)]);
        if(!empty($res['error']))return $this->error($res['error'],500);return $this->success($res);
    }
    public function gen_post(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d=$r->get_json_params();$topic=sanitize_text_field($d['topic']??'');if(empty($topic))return $this->error('topic required');
        $ai=new Router();$res=$ai->complete('You are an expert content writer for RJV Technologies Ltd. Write in British English. Output valid HTML.',"Write a ".($d['length']??'1500 words')." blog post about: {$topic}\nTone: ".($d['tone']??'professional')."\nInclude H2/H3 headings, clear paragraphs, compelling intro and conclusion.",['provider'=>$d['provider']??'','max_tokens'=>(int)($d['max_tokens']??8192),'temperature'=>0.7]);
        if(!empty($res['error']))return $this->error($res['error'],500);
        if(!empty($d['auto_create'])){$pid=wp_insert_post(['post_title'=>sanitize_text_field($d['title']??$topic),'post_content'=>wp_kses_post($res['content']),'post_status'=>'draft','post_type'=>'post'],true);if(!is_wp_error($pid))$res['post_id']=$pid;}
        return $this->success($res);
    }
    public function gen_seo(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d=$r->get_json_params();$pid=(int)($d['post_id']??0);$post=get_post($pid);if(!$post)return $this->error('Post not found',404);
        $ai=new Router();$res=$ai->complete('You are an SEO expert. Respond ONLY in JSON: {"title":"...","description":"...","focus_keyword":"...","slug":"..."}',"Generate SEO metadata for:\nTitle: {$post->post_title}\nContent (first 500 chars): ".mb_substr(wp_strip_all_tags($post->post_content),0,500),['provider'=>$d['provider']??'','temperature'=>0.2,'max_tokens'=>500]);
        if(!empty($res['error']))return $this->error($res['error'],500);
        $seo=json_decode($res['content'],true);
        if($seo&&!empty($d['auto_apply'])){if(!empty($seo['title'])){update_post_meta($pid,'_yoast_wpseo_title',sanitize_text_field($seo['title']));update_post_meta($pid,'rank_math_title',sanitize_text_field($seo['title']));}if(!empty($seo['description'])){update_post_meta($pid,'_yoast_wpseo_metadesc',sanitize_textarea_field($seo['description']));update_post_meta($pid,'rank_math_description',sanitize_textarea_field($seo['description']));}$seo['applied']=true;}
        return $this->success(['seo'=>$seo??$res['content'],'tokens'=>$res['tokens']??0]);
    }
    public function rewrite(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d=$r->get_json_params();if(empty($d['content']))return $this->error('content required');
        $ai=new Router();$res=$ai->complete("Rewrite in a ".sanitize_text_field($d['style']??'professional')." style. Output only the rewritten text.",$d['content'],['provider'=>$d['provider']??'','temperature'=>0.5]);
        if(!empty($res['error']))return $this->error($res['error'],500);return $this->success($res);
    }
    public function status(\WP_REST_Request $r): \WP_REST_Response { return $this->success((new Router())->status()); }
}
