const path=require('node:path');const {chromium}=require('playwright');
const OUT=__dirname,IN=path.join(OUT,'inputs');
(async()=>{const b=await chromium.launch({channel:'chrome'});const p=await (await b.newContext()).newPage();
await p.route('**/index.php?r=ai_chat_agent_stream*',r=>r.fulfill({status:404,contentType:'application/json',body:'{"fallback":true}'}));
await p.goto('http://127.0.0.1:8080/index.php',{waitUntil:'domcontentloaded'});
if((await p.getAttribute('[data-ai-chat-toggle]','aria-expanded'))!=='true')await p.click('[data-ai-chat-toggle]');
await p.waitForSelector('[data-ai-label-input]',{state:'attached'});
for(const f of ['4-rivaj-no-inci.png','7-ha-cream-partial.png','4-rivaj-no-inci.png']){
const w=p.waitForResponse(r=>r.url().includes('r=chat_label_scan'),{timeout:90000});
await p.setInputFiles('[data-ai-label-input]',path.join(IN,f));const r=await w;const t=await r.text();
let j={};try{j=JSON.parse(t)}catch(e){}
console.log(f,r.status(),JSON.stringify({ok:j.ok,code:j.code,msg:j.message_vi,status:j.result&&j.result.status,warn:j.result&&j.result.warnings,total:j.result&&j.result.ingredients&&j.result.ingredients.total,took:j.result&&j.result.took_ms}),t.length);
await p.waitForTimeout(1500);}
await b.close()})().catch(e=>{console.error(e);process.exit(2)});
