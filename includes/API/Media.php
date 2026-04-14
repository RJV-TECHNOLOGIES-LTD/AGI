<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\API;
use RJV_AGI_Bridge\Auth;

class Media extends Base {
    public function register_routes(): void {
        register_rest_route($this->namespace,'/media',[['methods'=>'GET','callback'=>[$this,'list_all'],'permission_callback'=>[Auth::class,'tier1']],['methods'=>'POST','callback'=>[$this,'upload'],'permission_callback'=>[Auth::class,'tier2']]]);
        register_rest_route($this->namespace,'/media/sideload',[['methods'=>'POST','callback'=>[$this,'sideload'],'permission_callback'=>[Auth::class,'tier2']]]);
        register_rest_route($this->namespace,'/media/(?P<id>\d+)',[['methods'=>'GET','callback'=>[$this,'get'],'permission_callback'=>[Auth::class,'tier1']],['methods'=>'PUT,PATCH','callback'=>[$this,'update'],'permission_callback'=>[Auth::class,'tier2']],['methods'=>'DELETE','callback'=>[$this,'delete'],'permission_callback'=>[Auth::class,'tier3']]]);
        register_rest_route($this->namespace,'/media/(?P<id>\d+)/regenerate',[['methods'=>'POST','callback'=>[$this,'regenerate'],'permission_callback'=>[Auth::class,'tier2']]]);
    }
    public function list_all(\WP_REST_Request $r): \WP_REST_Response {
        $q=new \WP_Query(['post_type'=>'attachment','post_status'=>'inherit','posts_per_page'=>50]);
        return $this->success(array_map(fn($p)=>['id'=>$p->ID,'title'=>$p->post_title,'url'=>wp_get_attachment_url($p->ID),'mime'=>$p->post_mime_type,'alt'=>get_post_meta($p->ID,'_wp_attachment_image_alt',true)],$q->posts));
    }
    public function upload(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $files=$r->get_file_params();if(empty($files['file']))return $this->error('No file');
        require_once ABSPATH.'wp-admin/includes/file.php';require_once ABSPATH.'wp-admin/includes/image.php';require_once ABSPATH.'wp-admin/includes/media.php';
        $id=media_handle_upload('file',0);if(is_wp_error($id))return $this->error($id->get_error_message(),500);
        $this->log('upload','media',$id,[],2);return $this->success(['id'=>$id,'url'=>wp_get_attachment_url($id)],201);
    }
    public function sideload(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $d=$r->get_json_params();if(empty($d['url']))return $this->error('url required');
        require_once ABSPATH.'wp-admin/includes/file.php';require_once ABSPATH.'wp-admin/includes/image.php';require_once ABSPATH.'wp-admin/includes/media.php';
        $tmp=download_url(esc_url_raw($d['url']),30);if(is_wp_error($tmp))return $this->error($tmp->get_error_message(),500);
        $file=['name'=>sanitize_file_name($d['filename']??basename(parse_url($d['url'],PHP_URL_PATH))),'tmp_name'=>$tmp];
        $id=media_handle_sideload($file,0);if(is_wp_error($id)){@unlink($tmp);return $this->error($id->get_error_message(),500);}
        if(!empty($d['alt']))update_post_meta($id,'_wp_attachment_image_alt',sanitize_text_field($d['alt']));
        $this->log('sideload','media',$id,[],2);return $this->success(['id'=>$id,'url'=>wp_get_attachment_url($id)],201);
    }
    public function get(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $id=(int)$r['id']; $a=get_post($id); if(!$a||$a->post_type!=='attachment') return $this->error('Not found',404);
        return $this->success(['id'=>$id,'title'=>$a->post_title,'caption'=>$a->post_excerpt,'description'=>$a->post_content,'alt'=>get_post_meta($id,'_wp_attachment_image_alt',true),'url'=>wp_get_attachment_url($id),'mime'=>$a->post_mime_type,'metadata'=>wp_get_attachment_metadata($id)]);
    }
    public function update(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $id=(int)$r['id']; $a=get_post($id); if(!$a||$a->post_type!=='attachment') return $this->error('Not found',404);
        $d=$r->get_json_params(); $u=['ID'=>$id];
        if(isset($d['title'])) $u['post_title']=sanitize_text_field((string)$d['title']);
        if(isset($d['caption'])) $u['post_excerpt']=sanitize_textarea_field((string)$d['caption']);
        if(isset($d['description'])) $u['post_content']=wp_kses_post((string)$d['description']);
        if(count($u)>1){$res=wp_update_post($u,true); if(is_wp_error($res)) return $this->error($res->get_error_message(),500);}
        if(array_key_exists('alt',$d)) update_post_meta($id,'_wp_attachment_image_alt',sanitize_text_field((string)$d['alt']));
        $this->log('update_media','media',$id,['fields'=>array_keys($d)],2);
        return $this->success(['updated'=>true,'id'=>$id]);
    }
    public function regenerate(\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
        $id=(int)$r['id']; if(get_post_type($id)!=='attachment') return $this->error('Not found',404);
        require_once ABSPATH.'wp-admin/includes/image.php';
        $file=get_attached_file($id); if(!$file||!file_exists($file)) return $this->error('Attachment file missing',404);
        $meta=wp_generate_attachment_metadata($id,$file); if(empty($meta)) return $this->error('Failed to regenerate metadata',500);
        wp_update_attachment_metadata($id,$meta);
        $this->log('regenerate_media','media',$id,[],2);
        return $this->success(['regenerated'=>true,'id'=>$id,'metadata'=>$meta]);
    }
    public function delete(\WP_REST_Request $r): \WP_REST_Response { wp_delete_attachment((int)$r['id'],true);$this->log('delete','media',(int)$r['id'],[],3);return $this->success(['deleted'=>true]); }
}
