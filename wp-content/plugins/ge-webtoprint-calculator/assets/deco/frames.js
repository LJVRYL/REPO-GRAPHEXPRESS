'use strict';
(()=>{
 const images=new Map(),frames=new Map();
 const point=(x,y)=>[x,y],lerp=(a,b,t)=>a.map((v,i)=>v+(b[i]-v)*t),distance=(a,b)=>Math.hypot(a[0]-b[0],a[1]-b[1]);
 function load(src){if(!images.has(src)&&images.size>=24)images.delete(images.keys().next().value);if(!images.has(src))images.set(src,new Promise((resolve,reject)=>{const im=new Image();im.onload=()=>resolve(im);im.onerror=()=>{images.delete(src);reject(Error('No pudimos cargar esta vista.'))};im.src=src}));return images.get(src)}
 function triangle(ctx,image,s,d){const [p,q,r]=s,[u,v,w]=d,det=(q[0]-p[0])*(r[1]-p[1])-(r[0]-p[0])*(q[1]-p[1]);if(Math.abs(det)<1e-8)return;
  const a=((v[0]-u[0])*(r[1]-p[1])-(w[0]-u[0])*(q[1]-p[1]))/det,b=((v[1]-u[1])*(r[1]-p[1])-(w[1]-u[1])*(q[1]-p[1]))/det,c=((w[0]-u[0])*(q[0]-p[0])-(v[0]-u[0])*(r[0]-p[0]))/det,dv=((w[1]-u[1])*(q[0]-p[0])-(v[1]-u[1])*(r[0]-p[0]))/det;
  ctx.save();ctx.beginPath();const center=[(u[0]+v[0]+w[0])/3,(u[1]+v[1]+w[1])/3],expand=p=>{const length=distance(center,p);return p.map((n,i)=>n+(n-center[i])*.7/Math.max(length,.001))};ctx.moveTo(...expand(u));ctx.lineTo(...expand(v));ctx.lineTo(...expand(w));ctx.closePath();ctx.clip();ctx.transform(a,b,c,dv,u[0]-a*p[0]-c*p[1],u[1]-b*p[0]-dv*p[1]);ctx.drawImage(image,0,0);ctx.restore();
 }
 function quad(ctx,image,s,d){const at=(q,x,y)=>lerp(lerp(q[0],q[1],x),lerp(q[3],q[2],x),y),n=3;for(let y=0;y<n;y++)for(let x=0;x<n;x++){const uv=[[x/n,y/n],[(x+1)/n,y/n],[(x+1)/n,(y+1)/n],[x/n,(y+1)/n]],ss=uv.map(p=>at(s,...p)),dd=uv.map(p=>at(d,...p));triangle(ctx,image,[ss[0],ss[1],ss[2]],[dd[0],dd[1],dd[2]]);triangle(ctx,image,[ss[0],ss[2],ss[3]],[dd[0],dd[2],dd[3]])}}
 function ring(o,i,lx,ty){const topL=lerp(o[0],o[1],lx),topR=lerp(o[0],o[1],1-lx),rightT=lerp(o[1],o[2],ty),rightB=lerp(o[1],o[2],1-ty),bottomR=lerp(o[3],o[2],1-lx),bottomL=lerp(o[3],o[2],lx),leftB=lerp(o[0],o[3],1-ty),leftT=lerp(o[0],o[3],ty);return [[o[0],topL,i[0],leftT],[topL,topR,i[1],i[0]],[topR,o[1],rightT,i[1]],[i[1],rightT,rightB,i[2]],[i[2],rightB,o[2],bottomR],[i[3],i[2],bottomR,bottomL],[leftB,i[3],bottomL,o[3]],[leftT,i[0],i[3],leftB]]}
 function canvas(w,h){const c=document.createElement('canvas');c.width=Math.round(w);c.height=Math.round(h);return c}
 function geometry(template,option){
  const size=option.size,sheet=option.matSheetCm||size,rim=template.rimCm;
  if(!Number.isFinite(rim)||rim<=0||size.some(n=>!Number.isFinite(n)||n<=0)||sheet.some((n,i)=>n<size[i]))throw Error('Esta vista está pendiente de calibración.');
  const mat=[(sheet[0]-size[0])/2,(sheet[1]-size[1])/2],width=sheet[0]+2*rim,height=sheet[1]+2*rim,scale=Math.min(900/size[0],1600/Math.max(width,height));
  return {rim,mat,scale,width:width*scale,height:height*scale,profile:rim*scale,area:{x:(rim+mat[0])*scale,y:(rim+mat[1])*scale,width:size[0]*scale,height:size[1]*scale}};
 }
 function mat(ctx,g,color){
  const p=g.profile,a=g.area;ctx.fillStyle=color;ctx.fillRect(p,p,g.width-2*p,g.height-2*p);
  // Fine paper grain and a bevel are rendered at the selected physical scale.
  const tile=canvas(32,32),t=tile.getContext('2d');let seed=17;
  for(let n=0;n<200;n++){seed=(seed*16807)%2147483647;t.fillStyle=n%2?'#ffffff12':'#00000008';t.fillRect(seed%32,Math.floor(seed/32)%32,1,1)}
  ctx.fillStyle=ctx.createPattern(tile,'repeat');ctx.fillRect(p,p,g.width-2*p,g.height-2*p);
  const b=Math.max(1,g.scale*.06);ctx.lineWidth=b;ctx.strokeStyle='#55534a45';ctx.strokeRect(a.x-b/2,a.y-b/2,a.width+b,a.height+b);
  ctx.strokeStyle='#ffffff99';ctx.lineWidth=b*.7;ctx.beginPath();ctx.moveTo(a.x,a.y+a.height+b);ctx.lineTo(a.x+a.width+b,a.y+a.height+b);ctx.lineTo(a.x+a.width+b,a.y);ctx.stroke();
 }
 async function frame(template,option,orientation){const key=template.id+':'+option.size.join('x')+':'+(option.matSheetCm||[]).join('x')+':'+orientation;if(!frames.has(key)&&frames.size>=16)frames.delete(frames.keys().next().value);if(!frames.has(key))frames.set(key,(async()=>{
  const g=geometry(template,option),im=await load(template.asset),coords=values=>Array.from({length:4},(_,i)=>point(values[i*2]*im.width,values[i*2+1]*im.height)),o=coords(template.outer),opening=coords(template.matOuter||template.opening),center=opening.reduce((a,p)=>a.map((v,j)=>v+p[j]/4),[0,0]),i=opening.map(p=>{const length=distance(center,p);return p.map((v,j)=>v+3*(v-center[j])/length)}),ow=(distance(o[0],o[1])+distance(o[3],o[2]))/2,oh=(distance(o[0],o[3])+distance(o[1],o[2]))/2,iw=(distance(i[0],i[1])+distance(i[3],i[2]))/2,ih=(distance(i[0],i[3])+distance(i[1],i[2]))/2;
  const rimX=Math.max(2,(ow-iw)/2),rimY=Math.max(2,(oh-ih)/2),c=canvas(g.width,g.height),ctx=c.getContext('2d'),p=g.profile,out=[[0,0],[c.width,0],[c.width,c.height],[0,c.height]],inner=[[p,p],[c.width-p,p],[c.width-p,c.height-p],[p,c.height-p]],src=ring(o,i,rimX/ow,rimY/oh),dst=ring(out,inner,p/c.width,p/c.height);
  ctx.imageSmoothingQuality='high';for(let n=0;n<src.length;n++)quad(ctx,im,src[n],dst[n]);if(template.matOuter)mat(ctx,g,template.matColor||'#eeeae2');let area=g.area;if(orientation==='landscape'){const rotated=canvas(c.height,c.width),r=rotated.getContext('2d');r.translate(rotated.width,0);r.rotate(Math.PI/2);r.drawImage(c,0,0);area={x:rotated.width-area.y-area.height,y:area.x,width:area.height,height:area.width};return {canvas:rotated,area,geometry:g}}return {canvas:c,area,geometry:g};
 })().catch(e=>{frames.delete(key);throw e}));return frames.get(key)}
 async function draw(target,work,option,state,label){const stamp={};target._frameStamp=stamp;target.replaceChildren();target.setAttribute('aria-busy','true');try{const template=GRAPHEX_CATALOG.templates.find(t=>t.id===option.template);if(!template)throw Error('Vista pendiente.');const [f,art]=await Promise.all([frame(template,option,state.orientation),load(work.image)]);if(target._frameStamp!==stamp)return;const c=canvas(f.canvas.width,f.canvas.height),ctx=c.getContext('2d'),a=f.area,scale=(state.fit==='cover'?Math.max:Math.min)(a.width/art.width,a.height/art.height),w=art.width*scale,h=art.height*scale;
  ctx.drawImage(f.canvas,0,0);ctx.save();ctx.beginPath();ctx.rect(a.x,a.y,a.width,a.height);ctx.clip();ctx.fillStyle='#fff';ctx.fillRect(a.x,a.y,a.width,a.height);ctx.drawImage(art,a.x+(a.width-w)/2,a.y+(a.height-h)/2,w,h);ctx.restore();c.setAttribute('role','img');c.setAttribute('aria-label',label);c.dataset.template=template.id;c.dataset.fit=state.fit;c.dataset.orientation=state.orientation;target.replaceChildren(c);
 }catch(e){if(target._frameStamp===stamp){const p=document.createElement('p');p.className='hint';p.textContent=e.message;target.replaceChildren(p)}}finally{if(target._frameStamp===stamp)target.removeAttribute('aria-busy')}}
 window.GraphexFrames={draw,geometry};
})();
