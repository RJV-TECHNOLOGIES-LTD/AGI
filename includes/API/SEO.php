<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;
use RJV_AGI_Bridge\Auth;

class SEO extends Base {
    public function register_routes(): void {
        register_rest_route($this->namespace,'/seo/audit',[['methods'=>'GET','callback'=>[$this,'audit'],'permission_callback'=>[Auth::class,'tier1'],'args'=>['page'=>['default'=>1],'per_page'=>['default'=>50]]]]);
        register_rest_route($this->namespace,'/seo/bulk-meta',[['methods'=>'POST','callback'=>[$this,'bulk_update'],'permission_callback'=>[Auth::class,'tier2']]]);
        register_rest_route($this->namespace,'/seo/missing',[['methods'=>'GET','callback'=>[$this,'missing'],'permission_callback'=>[Auth::class,'tier1'],'args'=>['page'=>['default'=>1],'per_page'=>['default'=>50],'type'=>['default'=>'all']]]]);
    }
    public function audit(\WP_REST_Request $r): \WP_REST_Response {
        global $wpdb;$total=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status='publish' AND post_type IN ('post','page')");
        $no_title=(int)$wpdb->get_var("SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON p.ID=m.post_id AND m.meta_key='_yoast_wpseo_title' LEFT JOIN {$wpdb->postmeta} m2 ON p.ID=m2.post_id AND m2.meta_key='rank_math_title' WHERE p.post_status='publish' AND p.post_type IN('post','page') AND (m.meta_value IS NULL OR m.meta_value='') AND (m2.meta_value IS NULL OR m2.meta_value='')");
        $no_desc=(int)$wpdb->get_var("SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON p.ID=m.post_id AND m.meta_key='_yoast_wpseo_metadesc' LEFT JOIN {$wpdb->postmeta} m2 ON p.ID=m2.post_id AND m2.meta_key='rank_math_description' WHERE p.post_status='publish' AND p.post_type IN('post','page') AND (m.meta_value IS NULL OR m.meta_value='') AND (m2.meta_value IS NULL OR m2.meta_value='')");
        return $this->success(['total_published'=>$total,'missing_seo_title'=>$no_title,'missing_seo_description'=>$no_desc,'score'=>$total>0?round((1-($no_title+$no_desc)/($total*2))*100):0]);
    }
    public function bulk_update(\WP_REST_Request $r): \WP_REST_Response {
        $items=$r->get_json_params()['items']??[];$n=0;
        foreach($items as $i){$id=(int)($i['id']??0);if(!$id)continue;
        if(isset($i['title'])){update_post_meta($id,'_yoast_wpseo_title',sanitize_text_field($i['title']));update_post_meta($id,'rank_math_title',sanitize_text_field($i['title']));}
        if(isset($i['description'])){update_post_meta($id,'_yoast_wpseo_metadesc',sanitize_textarea_field($i['description']));update_post_meta($id,'rank_math_description',sanitize_textarea_field($i['description']));}$n++;}
        $this->log('bulk_seo','seo',0,['count'=>$n],2);return $this->success(['updated'=>$n]);
    }
    public function missing(\WP_REST_Request $r): \WP_REST_Response {
        global $wpdb;$pp=min((int)$r['per_page'],100);$off=max(0,((int)$r['page']-1)*$pp);$type=$r['type'];
        $where="p.post_status='publish' AND p.post_type IN ('post','page')";
        if($type==='title')$where.=" AND (m.meta_value IS NULL OR m.meta_value='') AND (m2.meta_value IS NULL OR m2.meta_value='')";
        elseif($type==='description')$where.=" AND (md.meta_value IS NULL OR md.meta_value='') AND (md2.meta_value IS NULL OR md2.meta_value='')";
        else $where.=" AND ((m.meta_value IS NULL OR m.meta_value='') AND (m2.meta_value IS NULL OR m2.meta_value='') OR (md.meta_value IS NULL OR md.meta_value='') AND (md2.meta_value IS NULL OR md2.meta_value=''))";
        $sql="SELECT DISTINCT p.ID,p.post_title,p.post_type,p.post_name FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON p.ID=m.post_id AND m.meta_key='_yoast_wpseo_title' LEFT JOIN {$wpdb->postmeta} m2 ON p.ID=m2.post_id AND m2.meta_key='rank_math_title' LEFT JOIN {$wpdb->postmeta} md ON p.ID=md.post_id AND md.meta_key='_yoast_wpseo_metadesc' LEFT JOIN {$wpdb->postmeta} md2 ON p.ID=md2.post_id AND md2.meta_key='rank_math_description' WHERE {$where} ORDER BY p.ID DESC LIMIT %d OFFSET %d";
        $results=$wpdb->get_results($wpdb->prepare($sql,$pp,$off),ARRAY_A)?:[];
        return $this->success(['posts'=>array_map(fn($p)=>['id'=>(int)$p['ID'],'title'=>$p['post_title'],'type'=>$p['post_type'],'slug'=>$p['post_name']],$results),'page'=>(int)$r['page'],'per_page'=>$pp]);
    }
}
