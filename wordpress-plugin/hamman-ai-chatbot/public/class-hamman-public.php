<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Hamman_Public {
    public function inject_widget(): void {
        if (get_option('hamman_enabled','1') !== '1') return;
        $chatbot_id = get_option('hamman_chatbot_id','');
        $api_url    = rtrim(get_option('hamman_api_url', HAMMAN_API_BASE), '/');
        if (empty($chatbot_id)) return;

        $qq = get_option('hamman_quick_questions', []);
        if (!is_array($qq)) $qq = [];

        // First-paint-only fallback, before /chat/session has responded with
        // this chatbot's own widget_config (see ChatController::
        // mergedWidgetConfig() and App\Support\WidgetDefaults on the backend)
        // — that response, once it lands, always wins: a chatbot configured
        // for English by its owner reads English even on a Persian WP site,
        // and vice versa. This is only what a visitor sees for the fraction
        // of a second before that happens.
        $is_fa = strpos(get_locale(), 'fa') === 0;
        $l10n_defaults = $is_fa ? [
            'sendButtonLabel'        => 'ارسال',
            'placeholder'            => 'پیام خود را بنویسید...',
            'unavailableMessage'     => 'چت‌بات در حال حاضر در دسترس نیست. لطفاً بعداً دوباره تلاش کنید.',
            'genericErrorMessage'    => 'خطایی رخ داد.',
            'connectionErrorMessage' => 'ارتباط با سرور برقرار نشد. لطفاً دوباره تلاش کنید.',
        ] : [
            'sendButtonLabel'        => 'Send',
            'placeholder'            => 'Write your message...',
            'unavailableMessage'     => "The chatbot isn't available right now. Please try again later.",
            'genericErrorMessage'    => 'Something went wrong.',
            'connectionErrorMessage' => 'Could not connect to the server. Please try again.',
        ];

        $cfg = [
            'chatbotId'   => $chatbot_id,
            'apiUrl'      => $api_url,
            'aiName'      => get_option('hamman_ai_name','AI BOT'),
            'chatTitle'   => get_option('hamman_chat_title','') ?: get_option('hamman_ai_name','AI BOT'),
            'placeholder' => get_option('hamman_input_placeholder','') ?: $l10n_defaults['placeholder'],
            'sendButtonLabel'        => $l10n_defaults['sendButtonLabel'],
            'unavailableMessage'     => $l10n_defaults['unavailableMessage'],
            'genericErrorMessage'    => $l10n_defaults['genericErrorMessage'],
            'connectionErrorMessage' => $l10n_defaults['connectionErrorMessage'],
            'quickQuestions' => $qq,
        ];
        ?>
<script>
(function(){
var CFG=<?php echo wp_json_encode($cfg); ?>;
var H={chatbotId:CFG.chatbotId,apiUrl:CFG.apiUrl,sessionId:(function(){var k='hamman_sid',s=localStorage.getItem(k);if(!s){s='s_'+Math.random().toString(36).substr(2,16);localStorage.setItem(k,s);}return s;})()};
var css=document.createElement('style');
css.textContent='#hm-w{position:fixed;bottom:24px;right:24px;z-index:9999;font-family:system-ui,sans-serif}#hm-btn{width:56px;height:56px;border-radius:50%;background:#1B3A6B;color:#fff;border:none;cursor:pointer;font-size:24px;box-shadow:0 4px 20px rgba(0,0,0,.25);display:flex;align-items:center;justify-content:center}#hm-box{display:none;flex-direction:column;width:360px;height:520px;background:#fff;border-radius:16px;box-shadow:0 8px 40px rgba(0,0,0,.18);overflow:hidden;margin-bottom:12px}#hm-hdr{background:#1B3A6B;color:#fff;padding:14px 16px;display:flex;justify-content:space-between;align-items:center}#hm-hdr h3{margin:0;font-size:15px}#hm-hdr span{font-size:11px;opacity:.75}#hm-close{background:none;border:none;color:#fff;font-size:20px;cursor:pointer}#hm-msgs{flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:10px}.hm-msg{max-width:82%;padding:10px 14px;border-radius:12px;font-size:14px;line-height:1.5;word-break:break-word}.hm-msg.user{background:#1B3A6B;color:#fff;align-self:flex-end}.hm-msg.bot{background:#F1F5F9;color:#0F172A;align-self:flex-start}#hm-qq{display:flex;flex-direction:column;gap:6px;padding:0 14px 10px}#hm-qq button{background:#fff;border:1px solid #1B3A6B;color:#1B3A6B;border-radius:10px;padding:7px 10px;font-size:12.5px;cursor:pointer;text-align:right}#hm-in-row{padding:10px 12px;border-top:1px solid #E2E8F0;display:flex;gap:8px}#hm-in{flex:1;padding:10px 12px;border:1px solid #CBD5E1;border-radius:10px;font-size:14px;outline:none;resize:none}#hm-send{background:#1B3A6B;color:#fff;border:none;border-radius:10px;padding:10px 16px;cursor:pointer;font-size:14px}#hm-send:disabled{opacity:.5;cursor:default}#hm-powered{text-align:center;font-size:11px;color:#94A3B8;padding:6px;background:#F8FAFC}#hm-powered a{color:#1B3A6B;text-decoration:none}';
document.head.appendChild(css);
var w=document.createElement('div');w.id='hm-w';
var qqHtml='';
if(CFG.quickQuestions&&CFG.quickQuestions.length){qqHtml='<div id="hm-qq">'+CFG.quickQuestions.map(function(q,i){return '<button type="button" data-i="'+i+'">'+esc(q.question)+'</button>';}).join('')+'</div>';}
w.innerHTML='<div id="hm-box"><div id="hm-hdr"><div><h3>'+esc(CFG.chatTitle)+'</h3><span>'+esc(CFG.aiName)+'</span></div><button id="hm-close">✕</button></div><div id="hm-msgs"></div>'+qqHtml+'<div id="hm-in-row"><textarea id="hm-in" rows="1" placeholder="'+esc(CFG.placeholder)+'"></textarea><button id="hm-send">'+esc(CFG.sendButtonLabel)+'</button></div><div id="hm-powered">Powered by <a href="https://hamman.ir" target="_blank">Hamman AI</a></div></div><button id="hm-btn" title="Chat">💬</button>';
document.body.appendChild(w);
var box=document.getElementById('hm-box'),msgs=document.getElementById('hm-msgs'),inp=document.getElementById('hm-in'),sendBtn=document.getElementById('hm-send'),qqBox=document.getElementById('hm-qq'),convId=null,isOpen=false,unavailable=false;
function esc(t){var d=document.createElement('div');d.textContent=t==null?'':t;return d.innerHTML;}
// Once /chat/session responds with this chatbot's own widget_config, its
// text wins over the get_locale()-based CFG defaults set above — a chatbot
// an admin configured for English reads English even on a Persian WP site.
function applyWidgetConfig(wc){
  if(!wc)return;
  if(wc.send_button_label){CFG.sendButtonLabel=wc.send_button_label;sendBtn.textContent=wc.send_button_label;}
  if(wc.input_placeholder){CFG.placeholder=wc.input_placeholder;inp.setAttribute('placeholder',wc.input_placeholder);}
  if(wc.unavailable_message)CFG.unavailableMessage=wc.unavailable_message;
  if(wc.generic_error_message)CFG.genericErrorMessage=wc.generic_error_message;
  if(wc.connection_error_message)CFG.connectionErrorMessage=wc.connection_error_message;
}
function toggle(){isOpen=!isOpen;box.style.display=isOpen?'flex':'none';if(isOpen&&!convId&&!unavailable)init();if(isOpen)inp.focus();}
function mdToHtml(t){return esc(t).replace(/\*\*(.*?)\*\*/g,'<strong>$1</strong>').replace(/\*(.*?)\*/g,'<em>$1</em>').replace(/\[([^\]]+)\]\((https?:\/\/[^\)]+)\)/g,'<a href="$2" target="_blank" style="color:#1B3A6B;text-decoration:underline">$1</a>').replace(/\n/g,'<br>');}
function addMsg(t,r){var d=document.createElement('div');d.className='hm-msg '+(r==='user'?'user':'bot');if(r==='bot'){d.innerHTML=mdToHtml(t);}else{d.textContent=t;}msgs.appendChild(d);msgs.scrollTop=msgs.scrollHeight;}
function init(){
  fetch(H.apiUrl+'/chat/session',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({chatbot_id:H.chatbotId,session_id:H.sessionId,page_url:window.location.href})})
  .then(function(r){ if(!r.ok){ throw new Error('unavailable'); } return r.json(); })
  .then(function(data){
    convId=data.data&&data.data.conversation_id;
    applyWidgetConfig(data.data&&data.data.widget_config);
    if(data.data&&data.data.welcome_message)addMsg(data.data.welcome_message,'bot');
  })
  .catch(function(){ unavailable=true; addMsg(CFG.unavailableMessage,'bot'); });
}
function send(){
  var t=inp.value.trim();if(!t||!convId)return;
  inp.value='';addMsg(t,'user');sendBtn.disabled=true;
  fetch(H.apiUrl+'/chat/message',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({chatbot_id:H.chatbotId,conversation_id:convId,message:t,session_id:H.sessionId})})
  .then(function(r){ return r.json().then(function(data){ return {ok:r.ok,data:data}; }); })
  .then(function(res){
    sendBtn.disabled=false;
    if(!res.ok){ addMsg(res.data&&res.data.error?res.data.error:CFG.genericErrorMessage,'bot'); return; }
    if(res.data.data&&res.data.data.response)addMsg(res.data.data.response,'bot');
  })
  .catch(function(){ sendBtn.disabled=false; addMsg(CFG.connectionErrorMessage,'bot'); });
}
if(qqBox){qqBox.addEventListener('click',function(e){
  var btn=e.target.closest('button[data-i]');if(!btn)return;
  var q=CFG.quickQuestions[parseInt(btn.getAttribute('data-i'),10)];if(!q)return;
  addMsg(q.question,'user');addMsg(q.answer,'bot');
});}
document.getElementById('hm-btn').onclick=toggle;
document.getElementById('hm-close').onclick=toggle;
sendBtn.onclick=send;
inp.addEventListener('keydown',function(e){if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();send();}});
})();
</script>
        <?php
    }
}
