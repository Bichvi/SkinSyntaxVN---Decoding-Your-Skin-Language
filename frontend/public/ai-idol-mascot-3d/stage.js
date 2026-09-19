function box(ctx,x,y,w,h,r,fill) { ctx.fillStyle=fill; ctx.beginPath(); ctx.roundRect(x,y,w,h,r); ctx.fill(); }
function text(ctx,value,x,y,size=18,color='#284c3a',weight=400) { ctx.fillStyle=color; ctx.font=`${weight} ${size}px "Segoe UI",sans-serif`; ctx.fillText(value,x,y); }

export function drawStage(ctx, scene, pose, caption) {
  const bg=ctx.createLinearGradient(0,0,0,720); bg.addColorStop(0,'#fafaf0'); bg.addColorStop(1,'#e3eddf');
  ctx.fillStyle=bg; ctx.fillRect(0,0,1280,720);
  ctx.fillStyle='#f1f4e8'; ctx.beginPath(); ctx.ellipse(548,357,250,310,0,0,Math.PI*2); ctx.fill();
  text(ctx,'SkinSyntax',35,47,23,'#244b35',650); text(ctx,'SYNA / BẢN THỬ 3D',1000,45,15,'#63745c',600);
  box(ctx,35,160,281,415,23,'#fffefa');
  text(ctx,'ĐANG GIỚI THIỆU',57,195,13,'#788370',600);
  text(ctx,'Gel dưỡng rau má',57,230,24,'#284c3a',650);
  box(ctx,57,251,237,180,16,'#edf2e2');
  ctx.fillStyle='#dce6ca'; ctx.beginPath(); ctx.ellipse(175,413,60,8,0,0,Math.PI*2); ctx.fill();
  const bottle=ctx.createLinearGradient(130,0,220,0); bottle.addColorStop(0,'#a9c489'); bottle.addColorStop(.55,'#e1ecc5'); bottle.addColorStop(1,'#aec791');
  box(ctx,137,285,78,120,13,bottle); box(ctx,142,267,68,26,6,'#325e40'); box(ctx,146,312,60,66,5,'#fffdf2');
  text(ctx,'SYNA',156,336,14,'#38643f',650); text(ctx,'CICA',160,357,11,'#7d9665',500);
  text(ctx,'50 ml · sản phẩm minh họa',57,460,16,'#6f7f65');
  text(ctx,'199.000 đ',57,501,32,'#244b35',650); text(ctx,'GIÁ MẪU · CHƯA KẾT NỐI DANH MỤC',57,529,11,'#788370',500);
  box(ctx,816,565,10,56,5,'#9db397'); box(ctx,1186,565,10,56,5,'#9db397');
  box(ctx,779,177,464,400,23,'rgba(34,65,39,.06)'); box(ctx,772,169,464,400,23,'#fffdf5');
  box(ctx,948,160,112,18,6,'#b3c6a4');
  text(ctx,'BẢNG GHI CHÚ',798,211,13,'#788370',600); text(ctx,'Thành phần chính',798,249,28,'#284c3a',650);
  box(ctx,795,291,418,105,15,pose.highlight?'#e2eed7':'#f0f3e8');
  if(pose.highlight) box(ctx,795,305,4,76,2,'#719057');
  box(ctx,812,308,31,32,15,pose.highlight?'#2a563c':'#dbe6d0');
  text(ctx,'01',817,330,14,pose.highlight?'#ffffff':'#627855',650);
  text(ctx,pose.board?'Chiết xuất rau má':'Syna sắp bật mí…',855,335,23,'#284c3a',650);
  text(ctx,pose.board?'Centella asiatica':'Nghe cùng mình nhé',855,364,18,'#6f7f65');
  if(pose.board) {
    text(ctx,'Hỗ trợ làm dịu',802,439,22,'#50723e',600);
    text(ctx,'Công dụng tham khảo của thành phần.',802,470,16,'#788370');
  } else { text(ctx,'Mỗi lần một ý, dễ theo dõi hơn.',802,443,18,'#788370'); }
  text(ctx,'Ảnh, tên và giá sản phẩm luôn được giữ lại.',798,537,15,'#788370');
  ctx.drawImage(scene.renderer.domElement,0,0);
  text(ctx,'Sản phẩm và giá minh họa; không phải công thức thật.',35,616,13,'#718068');
  if(caption) {
    ctx.font='500 22px "Segoe UI",sans-serif'; const lines=[]; let line='';
    for(const word of caption.split(/\s+/)) {
      const next=line?`${line} ${word}`:word;
      if(line&&ctx.measureText(next).width>1120) { lines.push(line); line=word; } else line=next;
    }
    if(line) lines.push(line); const height=lines.length*27+20;
    box(ctx,40,707-height,1200,height,13,'rgba(255,254,249,.97)'); ctx.textAlign='center';
    lines.forEach((value,i)=>text(ctx,value,640,707-height+29+i*27,22,'#284c3a',500)); ctx.textAlign='left';
  }
}
