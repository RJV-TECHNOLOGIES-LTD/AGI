<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\AI;
use RJV_AGI_Bridge\{Settings, AuditLog};

class Anthropic implements Provider {
    private string $key, $model;
    public function __construct() { $this->key=(string)Settings::get('anthropic_key',''); $this->model=(string)Settings::get('anthropic_model','claude-sonnet-4-20250514'); }
    public function get_name(): string { return 'anthropic'; }
    public function get_model(): string { return $this->model; }
    public function is_configured(): bool { return !empty($this->key); }
    public function complete(string $sys, string $msg, array $opts=[]): array {
        if(!$this->is_configured()) return ['error'=>'Anthropic not configured','content'=>''];
        $start=microtime(true);
        $body=['model'=>$opts['model']??$this->model,'max_tokens'=>$opts['max_tokens']??4096,'system'=>$sys,'messages'=>[['role'=>'user','content'=>$msg]]];
        if(isset($opts['temperature'])) $body['temperature']=$opts['temperature'];
        $r=wp_remote_post('https://api.anthropic.com/v1/messages',['timeout'=>$opts['timeout']??120,'headers'=>['x-api-key'=>$this->key,'anthropic-version'=>'2023-06-01','Content-Type'=>'application/json'],'body'=>wp_json_encode($body)]);
        $ms=(int)((microtime(true)-$start)*1000);
        if(is_wp_error($r)){AuditLog::log('ai_error','anthropic',0,['error'=>$r->get_error_message()],1,'error',$ms);return['error'=>$r->get_error_message(),'content'=>''];}
        $code=wp_remote_retrieve_response_code($r);$data=json_decode(wp_remote_retrieve_body($r),true);
        if($code!==200||empty($data['content'][0]['text'])){$e=$data['error']['message']??'Anthropic error '.$code;AuditLog::log('ai_error','anthropic',0,['error'=>$e],1,'error',$ms);return['error'=>$e,'content'=>''];}
        $content=$data['content'][0]['text'];$tokens=($data['usage']['input_tokens']??0)+($data['usage']['output_tokens']??0);
        AuditLog::log('ai_completion','anthropic',0,['model'=>$body['model']],1,'success',$ms,$tokens,$body['model']);
        return['content'=>$content,'model'=>$body['model'],'tokens'=>$tokens,'latency_ms'=>$ms,'provider'=>'anthropic'];
    }
}
