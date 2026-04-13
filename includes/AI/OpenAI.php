<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\AI;
use RJV_AGI_Bridge\{Settings, AuditLog};

class OpenAI implements Provider {
    private string $key, $model;
    public function __construct() { $this->key=(string)Settings::get('openai_key',''); $this->model=(string)Settings::get('openai_model','gpt-4.1-mini'); }
    public function get_name(): string { return 'openai'; }
    public function get_model(): string { return $this->model; }
    public function is_configured(): bool { return !empty($this->key); }
    public function complete(string $sys, string $msg, array $opts=[]): array {
        if(!$this->is_configured()) return ['error'=>'OpenAI not configured','content'=>''];
        $start=microtime(true);
        $body=['model'=>$opts['model']??$this->model,'messages'=>[['role'=>'system','content'=>$sys],['role'=>'user','content'=>$msg]],'max_tokens'=>$opts['max_tokens']??4096,'temperature'=>$opts['temperature']??0.3];
        if(!empty($opts['json_mode'])) $body['response_format']=['type'=>'json_object'];
        $r=wp_remote_post('https://api.openai.com/v1/chat/completions',['timeout'=>$opts['timeout']??120,'headers'=>['Authorization'=>'Bearer '.$this->key,'Content-Type'=>'application/json'],'body'=>wp_json_encode($body)]);
        $ms=(int)((microtime(true)-$start)*1000);
        if(is_wp_error($r)){AuditLog::log('ai_error','openai',0,['error'=>$r->get_error_message()],1,'error',$ms);return['error'=>$r->get_error_message(),'content'=>''];}
        $code=wp_remote_retrieve_response_code($r);$data=json_decode(wp_remote_retrieve_body($r),true);
        if($code!==200||empty($data['choices'][0]['message']['content'])){$e=$data['error']['message']??'OpenAI error '.$code;AuditLog::log('ai_error','openai',0,['error'=>$e],1,'error',$ms);return['error'=>$e,'content'=>''];}
        $content=$data['choices'][0]['message']['content'];$tokens=$data['usage']['total_tokens']??0;
        AuditLog::log('ai_completion','openai',0,['model'=>$body['model']],1,'success',$ms,$tokens,$body['model']);
        return['content'=>$content,'model'=>$body['model'],'tokens'=>$tokens,'latency_ms'=>$ms,'provider'=>'openai'];
    }
}
