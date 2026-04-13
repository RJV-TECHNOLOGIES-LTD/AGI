(function($){'use strict';
function api(ep,method,data){return $.ajax({url:rjvAgi.restUrl+ep,method:method||'GET',contentType:'application/json',headers:{'X-RJV-AGI-Key':rjvAgi.apiKey},data:data?JSON.stringify(data):undefined});}
if($('#rjv-health').length){
api('health').done(function(r){var d=r.data;$('#rjv-health').html('<p><b>'+d.status+'</b> | WP '+d.wordpress+' | PHP '+d.php+'</p><p>Posts: '+d.posts+' | Pages: '+d.pages+' | Users: '+d.users+'</p>');}).fail(function(){$('#rjv-health').html('<p style="color:red">Failed</p>');});
api('health/stats').done(function(r){var d=r.data;$('#rjv-stats').html('<p>Actions: '+d.today+' | AI: '+d.ai_calls_today+' | Tokens: '+d.tokens_today.toLocaleString()+' | Errors: '+d.errors_today+'</p>');});
api('ai/status').done(function(r){var d=r.data;var h='';for(var p in d)h+='<p><b>'+p+':</b> '+(d[p].configured?'\u2705 '+d[p].model:'\u274c Not set')+'</p>';$('#rjv-ai').html(h);});
}
$('#rjv-send').on('click',function(){var b=$(this);b.prop('disabled',true);$('#rjv-load').show();$('#rjv-out').hide();
api('ai/complete','POST',{system_prompt:$('#rjv-sys').val(),message:$('#rjv-msg').val(),provider:$('#rjv-prov').val()}).done(function(r){var d=r.data;$('#rjv-text').text(d.content);$('#rjv-meta').html(d.provider+' | '+d.model+' | '+d.tokens+' tokens | '+d.latency_ms+'ms');$('#rjv-out').show();}).fail(function(x){$('#rjv-text').text('ERROR: '+(x.responseJSON?x.responseJSON.message:'Failed'));$('#rjv-out').show();}).always(function(){b.prop('disabled',false);$('#rjv-load').hide();});
});
})(jQuery);
