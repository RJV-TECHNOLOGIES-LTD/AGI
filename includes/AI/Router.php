<?php
declare(strict_types=1);
namespace RJV_AGI_Bridge\AI;
use RJV_AGI_Bridge\Settings;

class Router {
    private array $providers=[];
    public function __construct() { $this->providers['openai']=new OpenAI(); $this->providers['anthropic']=new Anthropic(); }
    public function get(string $name=''): Provider {
        if(empty($name)) $name=(string)Settings::get('default_model','anthropic');
        return $this->providers[$name] ?? throw new \InvalidArgumentException("Unknown provider: {$name}");
    }
    public function complete(string $sys, string $msg, array $opts=[]): array {
        $p=$this->get($opts['provider']??'');
        if(!$p->is_configured()) foreach($this->providers as $f) if($f->is_configured()){$p=$f;break;}
        if(!$p->is_configured()) return['error'=>'No AI configured','content'=>''];
        return $p->complete($sys,$msg,$opts);
    }
    public function status(): array {
        $r=[];foreach($this->providers as $n=>$p) $r[$n]=['configured'=>$p->is_configured(),'model'=>$p->get_model()];
        return $r;
    }
}
