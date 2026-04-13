<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;
use RJV_AGI_Bridge\Auth;

class Posts extends Base {
    public function register_routes(): void {
        register_rest_route($this->namespace, '/posts', [
            ['methods'=>'GET','callback'=>[$this,'list_posts'],'permission_callback'=>[Auth::class,'tier1'],
             'args'=>['per_page'=>['default'=>20],'page'=>['default'=>1],'status'=>['default'=>'any'],'search'=>['default'=>'']]],
            ['methods'=>'POST','callback'=>[$this,'create'],'permission_callback'=>[Auth::class,'tier2']],
        ]);
        register_rest_route($this->namespace, '/posts/(?P<id>\d+)', [
            ['methods'=>'GET','callback'=>[$this,'get'],'permission_callback'=>[Auth::class,'tier1']],
            ['methods'=>'PUT,PATCH','callback'=>[$this,'update'],'permission_callback'=>[Auth::class,'tier2']],
            ['methods'=>'DELETE','callback'=>[$this,'delete'],'permission_callback'=>[Auth::class,'tier3']],
        ]);
        register_rest_route($this->namespace, '/posts/bulk', [
            ['methods'=>'POST','callback'=>[$this,'bulk'],'permission_callback'=>[Auth::class,'tier2']],
        ]);
    }
    public function list_posts(\WP_REST_Request $r): \WP_REST_Response {
        $args = ['post_type'=>'post','posts_per_page'=>min((int)$r['per_page'],100),'paged'=>(int)$r['page'],'post_status'=>$r['status']];
        if($s=$r['search']) $args['s']=$s;
        $q = new \WP_Query($args);
        $posts = array_map(fn($p) => $this->fmt($p), $q->posts);
        $this->log('list_posts','post',0,['count'=>count($posts)]);
        return $this->success(['posts'=>$posts,'total'=>$q->found_posts,'pages'=>$q->max_num_pages]);
    }
    public function get(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $p=get_post((int)$r['id']); if(!$p) return $this->error('Not found',404);
        return $this->success($this->fmt($p, true));
    }
    public function create(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d=$r->get_json_params();
        $id=wp_insert_post(['post_title'=>sanitize_text_field($d['title']??''),'post_content'=>wp_kses_post($d['content']??''),
            'post_status'=>sanitize_text_field($d['status']??'draft'),'post_excerpt'=>sanitize_textarea_field($d['excerpt']??''),
            'post_type'=>'post','post_author'=>(int)($d['author']??1)],true);
        if(is_wp_error($id)) return $this->error($id->get_error_message(),500);
        if(!empty($d['categories'])) wp_set_post_categories($id, array_map('absint',(array)$d['categories']));
        if(!empty($d['tags'])) wp_set_post_tags($id, array_map('sanitize_text_field',(array)$d['tags']));
        if(!empty($d['featured_image_id'])) set_post_thumbnail($id,(int)$d['featured_image_id']);
        if(!empty($d['meta'])&&is_array($d['meta'])) foreach($d['meta'] as $k=>$v) update_post_meta($id,sanitize_key($k),sanitize_text_field($v));
        if(!empty($d['seo'])) $this->set_seo($id,$d['seo']);
        $this->log('create_post','post',$id,['title'=>$d['title']??''],2);
        return $this->success($this->fmt(get_post($id),true),201);
    }
    public function update(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $id=(int)$r['id']; $p=get_post($id); if(!$p) return $this->error('Not found',404);
        $d=$r->get_json_params(); $u=['ID'=>$id];
        $map=['title'=>'post_title','content'=>'post_content','status'=>'post_status','excerpt'=>'post_excerpt','slug'=>'post_name'];
        foreach($map as $i=>$f) if(isset($d[$i])) $u[$f]=$i==='content'?wp_kses_post($d[$i]):sanitize_text_field((string)$d[$i]);
        $res=wp_update_post($u,true); if(is_wp_error($res)) return $this->error($res->get_error_message(),500);
        if(!empty($d['categories'])) wp_set_post_categories($id,array_map('absint',(array)$d['categories']));
        if(!empty($d['tags'])) wp_set_post_tags($id,array_map('sanitize_text_field',(array)$d['tags']));
        if(isset($d['featured_image_id'])) $d['featured_image_id']?set_post_thumbnail($id,(int)$d['featured_image_id']):delete_post_thumbnail($id);
        if(!empty($d['meta'])&&is_array($d['meta'])) foreach($d['meta'] as $k=>$v) update_post_meta($id,sanitize_key($k),sanitize_text_field($v));
        if(!empty($d['seo'])) $this->set_seo($id,$d['seo']);
        $this->log('update_post','post',$id,['fields'=>array_keys($d)],2);
        return $this->success($this->fmt(get_post($id),true));
    }
    public function delete(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $id=(int)$r['id']; $d=$r->get_json_params();
        wp_delete_post($id, !empty($d['force']));
        $this->log('delete_post','post',$id,[],3);
        return $this->success(['deleted'=>true,'id'=>$id]);
    }
    public function bulk(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d=$r->get_json_params(); $action=$d['action']??''; $ids=array_map('absint',(array)($d['ids']??[]));
        if(empty($ids)||empty($action)) return $this->error('action and ids required');
        $results=[];
        foreach($ids as $id) {
            match($action) {
                'publish'=>wp_update_post(['ID'=>$id,'post_status'=>'publish']),
                'draft'=>wp_update_post(['ID'=>$id,'post_status'=>'draft']),
                'trash'=>wp_trash_post($id), 'delete'=>wp_delete_post($id,true),
                default=>null,
            };
            $results[]=['id'=>$id,'done'=>true];
        }
        $this->log('bulk_posts','post',0,['action'=>$action,'count'=>count($ids)],$action==='delete'?3:2);
        return $this->success($results);
    }
    private function fmt(\WP_Post $p, bool $full=false): array {
        $d=['id'=>$p->ID,'title'=>$p->post_title,'slug'=>$p->post_name,'status'=>$p->post_status,
            'date'=>$p->post_date,'modified'=>$p->post_modified,'author'=>(int)$p->post_author,
            'excerpt'=>$p->post_excerpt,'permalink'=>get_permalink($p->ID),
            'featured_image'=>get_the_post_thumbnail_url($p->ID,'full')?:null,
            'categories'=>wp_get_post_categories($p->ID,['fields'=>'names']),
            'tags'=>wp_get_post_tags($p->ID,['fields'=>'names'])];
        if($full){$d['content']=$p->post_content;$d['meta']=get_post_meta($p->ID);$d['seo']=$this->get_seo($p->ID);}
        return $d;
    }
    private function get_seo(int $id): array {
        return array_filter(['title'=>get_post_meta($id,'_yoast_wpseo_title',true)?:get_post_meta($id,'rank_math_title',true),
            'description'=>get_post_meta($id,'_yoast_wpseo_metadesc',true)?:get_post_meta($id,'rank_math_description',true),
            'focus_kw'=>get_post_meta($id,'_yoast_wpseo_focuskw',true)?:get_post_meta($id,'rank_math_focus_keyword',true)]);
    }
    private function set_seo(int $id, array $s): void {
        if(isset($s['title'])){update_post_meta($id,'_yoast_wpseo_title',sanitize_text_field($s['title']));update_post_meta($id,'rank_math_title',sanitize_text_field($s['title']));}
        if(isset($s['description'])){update_post_meta($id,'_yoast_wpseo_metadesc',sanitize_textarea_field($s['description']));update_post_meta($id,'rank_math_description',sanitize_textarea_field($s['description']));}
        if(isset($s['focus_kw'])){update_post_meta($id,'_yoast_wpseo_focuskw',sanitize_text_field($s['focus_kw']));update_post_meta($id,'rank_math_focus_keyword',sanitize_text_field($s['focus_kw']));}
    }
}
