const { chromium }=require('playwright');
const assert=require('node:assert/strict');
const fs=require('node:fs/promises');
const path=require('node:path');
(async()=>{
  const output=path.resolve('report/ai-idol-syna-3d');await fs.mkdir(output,{recursive:true});
  const browser=await chromium.launch({channel:'chrome',headless:true});
  try{
    const context=await browser.newContext({viewport:{width:1500,height:1000},acceptDownloads:true});
    const page=await context.newPage(),errors=[],requests=[],results=[];
    page.on('pageerror',(e)=>errors.push(e.message));page.on('request',(r)=>requests.push({url:r.url(),method:r.method()}));
    const base='http://localhost:8080/ai-idol-mascot-3d/index.html';
    assert.equal((await page.goto(base)).status(),200);await page.waitForSelector('#stage[data-ready="true"]');
    await page.waitForFunction(()=>Number(document.querySelector('#stage').dataset.triangles)>0);
    assert.equal(await page.locator('.action-atlas img').getAttribute('alt').then((value)=>value.includes('Mười trạng thái Syna')),true);
    await page.locator('.action-atlas summary').click();
    await page.waitForFunction(()=>document.querySelector('.action-atlas img').naturalWidth>1000);
    assert.equal(await page.locator('#script button').count(),3);
    assert.match(await page.locator('.notes').innerText(),/199.000/);
    await page.screenshot({path:path.join(output,'desktop.png'),fullPage:true});
    const front=await page.locator('#stage').screenshot({path:path.join(output,'front.png')});
    await page.locator('[data-view="35"]').click();
    await page.waitForFunction(()=>Number(document.querySelector('#stage').dataset.view)>.5);
    const side=await page.locator('#stage').screenshot({path:path.join(output,'side.png')});assert.notDeepEqual(front,side);
    for(const degrees of [-90,90,180]){
      await page.locator(`[data-view="${degrees}"]`).click();
      await page.waitForFunction((a)=>Math.abs(Number(document.querySelector('#stage').dataset.view)-a*Math.PI/180)<.001,degrees);
      assert.equal(await page.locator('#angleValue').innerText(),`${degrees}°`);
      await page.locator('#stage').screenshot({path:path.join(output,`profile-${degrees}.png`)});
    }
    await page.locator('#angle').focus();await page.keyboard.press('Home');
    await page.waitForFunction(()=>Number(document.querySelector('#stage').dataset.view)<-3.14);
    await page.keyboard.press('End');await page.waitForFunction(()=>Number(document.querySelector('#stage').dataset.view)>3.14);
    results.push('PASS: both true 90-degree profiles, rear view and full-turn keyboard inspection');
    await page.locator('[data-view="0"]').click();results.push('PASS: real WebGL geometry, front/side views, product price and separate board');
    await page.locator('#play').click();await page.waitForFunction(()=>Number(document.querySelector('#stage').dataset.mouth)>.12);
    await page.locator('#play').click();await page.waitForFunction(()=>document.querySelector('#stage').dataset.mouth==='0.000');
    const idle=await page.evaluate(()=>new Promise((resolve)=>{
      const stage=document.querySelector('#stage'),before=stage.dataset.renderedFrames;
      setTimeout(()=>resolve({before,after:stage.dataset.renderedFrames}),750);
    }));assert.equal(idle.before,idle.after);
    // No screenshots or viewport changes during this uninterrupted playback sample.
    await page.locator('#restart').click();
    const metrics=await page.evaluate(()=>new Promise((resolve)=>{
      const stage=document.querySelector('#stage'),times=[];
      const observer=new MutationObserver((records)=>{if(records.some((r)=>r.attributeName==='data-time'))times.push(performance.now());});
      observer.observe(stage,{attributes:true,attributeFilter:['data-time']});
      setTimeout(()=>{
        observer.disconnect();const gaps=times.slice(1).map((t,i)=>t-times[i]);
        const probe=document.createElement('canvas').getContext('webgl2');
        const ext=probe?.getExtension('WEBGL_debug_renderer_info');
        resolve({fps:stage.dataset.fps,renderMs:stage.dataset.renderMs,triangles:stage.dataset.triangles,
          geometries:stage.dataset.geometries,sampleSeconds:7,observedFrames:times.length,
          maxGapMs:Math.round(Math.max(...gaps)),renderer:ext?probe.getParameter(ext.UNMASKED_RENDERER_WEBGL):'unavailable'});
      },7000);
    }));
    metrics.idleRenders=Number(idle.after)-Number(idle.before);
    await page.locator('#play').click();
    results.push('PASS: paused scene does not redraw; recorded uninterrupted seven-second playback metrics');
    async function seek(time){await page.locator('#seek').evaluate((input,t)=>{input.value=String(t);input.dispatchEvent(new Event('input',{bubbles:true}));},time);await page.waitForFunction((t)=>Math.abs(Number(document.querySelector('#stage').dataset.time)-t)<.04,time);}
    for(const [name,time] of [['greeting',1.5],['blink-half',1.75],['blink-closed',1.8],['pointing',6],['returned',11]]){
      await seek(time);await page.locator('#stage').screenshot({path:path.join(output,`${name}.png`)});
      if(time===6){assert.ok(Number(await page.locator('#stage').getAttribute('data-head-yaw'))>.5);assert.equal(await page.locator('#stage').getAttribute('data-board'),'true');}
      if(time===11)assert.equal(await page.locator('#stage').getAttribute('data-head-yaw'),'0.000');
    }
    await seek(6);await page.locator('#reducedMotion').check();await page.waitForFunction(()=>document.querySelector('#stage').dataset.headYaw==='0.000');
    assert.equal(await page.locator('#stage').getAttribute('data-board'),'true');await page.locator('#reducedMotion').uncheck();
    results.push('PASS: audio mouth, pause, seek, wave/turn/point/return and reduced motion');
    const download=page.waitForEvent('download',{timeout:45000});await page.locator('#export').click();
    assert.equal(await page.locator('#angle').isDisabled(),true);const file=await download;await file.saveAs(path.join(output,'syna-3d-pilot.webm'));
    await page.waitForFunction(()=>document.querySelector('#exportNote').textContent.includes('Đã xuất'));
    results.push('PASS: real fifteen-second WebM export with narration');
    await page.locator('#export').click();await page.locator('#export').click();await page.waitForFunction(()=>!document.querySelector('#play').disabled);
    assert.match(await page.locator('#exportNote').innerText(),/Đã hủy/);
    await page.setViewportSize({width:390,height:844});await seek(6);
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
    await page.screenshot({path:path.join(output,'mobile.png'),fullPage:true});
    await page.locator('#play').focus();await page.keyboard.press('Enter');await page.waitForFunction(()=>Number(document.querySelector('#stage').dataset.mouth)>.1);
    await page.locator('#play').click();
    results.push('PASS: cancel, mobile layout and keyboard playback');
    const failed=await context.newPage();await failed.route('**/assets/syna-pilot.wav',(route)=>route.fulfill({status:404,body:''}));await failed.goto(base);
    await failed.waitForSelector('#error:not([hidden])');assert.equal(await failed.locator('#play').isDisabled(),true);await failed.close();
    const noGL=await context.newPage();
    await noGL.addInitScript(()=>{const get=HTMLCanvasElement.prototype.getContext;HTMLCanvasElement.prototype.getContext=function(type,...args){return /^webgl/.test(type)?null:get.call(this,type,...args);};});
    await noGL.goto(base);await noGL.waitForSelector('#error:not([hidden])');assert.equal(await noGL.locator('#play').isDisabled(),true);await noGL.close();
    const lost=await context.newPage();
    await lost.addInitScript(()=>{const get=HTMLCanvasElement.prototype.getContext;HTMLCanvasElement.prototype.getContext=function(type,...args){const result=get.call(this,type,...args);if(type==='webgl2')window.testWebGL=result;return result;};});
    await lost.goto(base);await lost.waitForSelector('#stage[data-ready="true"]');
    await lost.evaluate(()=>window.testWebGL.getExtension('WEBGL_lose_context').loseContext());
    await lost.waitForSelector('#error:not([hidden])');assert.equal(await lost.locator('#play').isDisabled(),true);
    assert.match(await lost.locator('#error').innerText(),/mất kết nối đồ họa/);await lost.close();
    const old=await context.newPage();await old.goto('http://localhost:8080/ai-idol-mascot-demo/index.html?rev=syna-v6-1');
    await old.waitForSelector('#stage[data-ready="true"]');assert.equal(await old.locator('#gestureChoice option').count(),12);await old.close();
    assert.deepEqual(errors,[]);assert.ok(requests.every((r)=>r.method==='GET'&&(r.url.startsWith('http://localhost:8080/')||r.url.startsWith('blob:'))));
    results.push('PASS: missing audio, WebGL unavailable/lost, old 2D still works, no JS errors, external requests or writes');
    await fs.writeFile(path.join(output,'browser-results.json'),JSON.stringify({url:base,results,metrics,errors},null,2));
    results.forEach((r)=>console.log(r));console.log(JSON.stringify(metrics));
  }finally{await browser.close();}
})().catch((e)=>{console.error(e);process.exitCode=1;});
