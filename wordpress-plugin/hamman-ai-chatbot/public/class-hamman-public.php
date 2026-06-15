<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Hamman_Public {
    public function inject_widget(): void {
        if (get_option('hamman_enabled','1') !== '1') return;
        $chatbot_id = get_option('hamman_chatbot_id','');
        $api_url    = rtrim(get_option('hamman_api_url', HAMMAN_API_BASE), '/');
        if (empty($chatbot_id)) return;
        ?>
<script>
(function(){
var H={chatbotId:<?php echo wp_json_encode($chatbot_id); ?>,apiUrl:<?php echo wp_json_encode($api_url); ?>,sessionId:(function(){var k='hamman_sid',s=localStorage.getItem(k);if(!s){s='s_'+Math.random().toString(36).substr(2,16);localStorage.setItem(k,s);}return s;})()};
var css=document.createElement('style');
css.textContent='#hm-w{position:fixed;bottom:24px;right:24px;z-index:9999;font-family:system-ui,sans-serif}#hm-btn{width:56px;height:56px;border-radius:50%;background:#1B3A6B;color:#fff;border:none;cursor:pointer;font-size:24px;box-shadow:0 4px 20px rgba(0,0,0,.25);display:flex;align-items:center;justify-content:center}#hm-box{display:none;flex-direction:column;width:360px;height:520px;background:#fff;border-radius:16px;box-shadow:0 8px 40px rgba(0,0,0,.18);overflow:hidden;margin-bottom:12px}#hm-hdr{background:#1B3A6B;color:#fff;padding:14px 16px;display:flex;justify-content:space-between;align-items:center}#hm-hdr h3{margin:0;font-size:15px}#hm-close{background:none;border:none;color:#fff;font-size:20px;cursor:pointer}#hm-msgs{flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:10px}.hm-msg{max-width:82%;padding:10px 14px;border-radius:12px;font-size:14px;line-height:1.5;word-break:break-word}.hm-msg.user{background:#1B3A6B;color:#fff;align-self:flex-end}.hm-msg.bot{background:#F1F5F9;color:#0F172A;align-self:flex-start}#hm-in-row{padding:10px 12px;border-top:1px solid #E2E8F0;display:flex;gap:8px}#hm-in{flex:1;padding:10px 12px;border:1px solid #CBD5E1;border-radius:10px;font-size:14px;outline:none;resize:none}#hm-send{background:#1B3A6B;color:#fff;border:none;border-radius:10px;padding:10px 16px;cursor:pointer;font-size:14px}#hm-powered{text-align:center;font-size:11px;color:#94A3B8;padding:6px;background:#F8FAFC}#hm-powered a{color:#1B3A6B;text-decoration:none}';
document.head.appendChild(css);
var w=document.createElement('div');w.id='hm-w';
w.innerHTML='<div id="hm-box"><div id="hm-hdr"><h3>💬 AI Assistant</h3><button id="hm-close">✕</button></div><div id="hm-msgs"></div><div id="hm-in-row"><textarea id="hm-in" rows="1" placeholder="Type your message..."></textarea><button id="hm-send">Send</button></div><div id="hm-powered">Powered by <a href="https://hamman.ir" target="_blank">Hamman AI</a></div></div><button id="hm-btn" title="Chat">💬</button>';
document.body.appendChild(w);
var box=document.getElementById('hm-box'),msgs=document.getElementById('hm-msgs'),inp=document.getElementById('hm-in'),convId=null,isOpen=false;
function toggle(){isOpen=!isOpen;box.style.display=isOpen?'flex':'none';if(isOpen&&!convId)init();if(isOpen)inp.focus();}
function addMsg(t,r){var d=document.createElement('div');d.className='hm-msg '+(r==='user'?'user':'bot');d.textContent=t;msgs.appendChild(d);msgs.scrollTop=msgs.scrollHeight;}
function init(){fetch(H.apiUrl+'/chat/session',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({chatbot_id:H.chatbotId,session_id:H.sessionId,page_url:window.location.href})}).then(r=>r.json()).then(data=>{convId=data.data&&data.data.conversation_id;if(data.data&&data.data.welcome_message)addMsg(data.data.welcome_message,'bot');}).catch(()=>{});}
function send(){var t=inp.value.trim();if(!t||!convId)return;inp.value='';addMsg(t,'user');fetch(H.apiUrl+'/chat/message',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({conversation_id:convId,message:t,session_id:H.sessionId})}).then(r=>r.json()).then(data=>{if(data.data&&data.data.response)addMsg(data.data.response,'bot');}).catch(()=>{addMsg('Sorry, something went wrong.','bot');});}
document.getElementById('hm-btn').onclick=toggle;
document.getElementById('hm-close').onclick=toggle;
document.getElementById('hm-send').onclick=send;
inp.addEventListener('keydown',function(e){if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();send();}});
})();
</script>
        <?php
    }
}
