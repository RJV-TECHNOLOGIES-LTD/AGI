(function($){'use strict';
function api(ep,method,data){return $.ajax({url:rjvAgi.restUrl+ep,method:method||'GET',contentType:'application/json',headers:{'X-RJV-AGI-Key':rjvAgi.apiKey},data:data?JSON.stringify(data):undefined});}
if($('#rjv-health').length){
api('health').done(function(r){var d=r.data;var $p1=$('<p>').append($('<b>').text(d.status),$('<span>').text(' | WP '+d.wordpress+' | PHP '+d.php));var $p2=$('<p>').text('Posts: '+d.posts+' | Pages: '+d.pages+' | Users: '+d.users);$('#rjv-health').empty().append($p1,$p2);}).fail(function(){$('#rjv-health').empty().append($('<p>').css('color','red').text('Failed'));});
api('health/stats').done(function(r){var d=r.data;$('#rjv-stats').empty().append($('<p>').text('Actions: '+d.today+' | AI: '+d.ai_calls_today+' | Tokens: '+d.tokens_today.toLocaleString()+' | Errors: '+d.errors_today));});
api('ai/status').done(function(r){var d=r.data;var $c=$('#rjv-ai').empty();for(var p in d){var $p=$('<p>').append($('<b>').text(p+':'),$('<span>').text(' '+(d[p].configured?'\u2705 '+d[p].model:'\u274c Not set')));$c.append($p);}});
}
$('#rjv-send').on('click',function(){var b=$(this);b.prop('disabled',true);$('#rjv-load').show();$('#rjv-out').hide();
api('ai/complete','POST',{system_prompt:$('#rjv-sys').val(),message:$('#rjv-msg').val(),provider:$('#rjv-prov').val()}).done(function(r){var d=r.data;$('#rjv-text').text(d.content);$('#rjv-meta').text(d.provider+' | '+d.model+' | '+d.tokens+' tokens | '+d.latency_ms+'ms');$('#rjv-out').show();}).fail(function(x){$('#rjv-text').text('ERROR: '+(x.responseJSON?x.responseJSON.message:'Failed'));$('#rjv-out').show();}).always(function(){b.prop('disabled',false);$('#rjv-load').hide();});
});
})(jQuery);
