<?php
declare(strict_types=1);

/**
 * Guest book -- owner page (password login, see includes/gbauth.php).
 * Moderate signatures (hide / show / delete), open or close the book,
 * and download everything as PNG pages.
 */

require_once __DIR__ . '/../includes/guestbook.php';
require_once __DIR__ . '/../includes/gbauth.php';
gba_require_admin();   // shows the login form (and stops) unless you're signed in

if (empty($_SESSION['gb_csrf'])) {
    $_SESSION['gb_csrf'] = bin2hex(random_bytes(24));
}
$csrf = (string) $_SESSION['gb_csrf'];

gb_dirs();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $in = json_decode((string) file_get_contents('php://input'), true);
    $tok = (string) ($in['csrf'] ?? '');
    if (!is_array($in) || !hash_equals($csrf, $tok)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'csrf']);
        exit;
    }
    $act = (string) ($in['action'] ?? '');
    $id  = (string) ($in['id'] ?? '');
    $ok  = false;
    switch ($act) {
        case 'hide':   $ok = gb_set_hidden($id, true);  break;
        case 'unhide': $ok = gb_set_hidden($id, false); break;
        case 'delete': $ok = gb_delete($id);            break;
        case 'open':   $ok = gb_set_open(true);         break;
        case 'close':  $ok = gb_set_open(false);        break;
    }
    echo json_encode(['ok' => $ok]);
    exit;
}

$entries = array_map(function (array $e): array {
    return [
        'id' => $e['id'], 'ts' => (int) ($e['ts'] ?? 0),
        'name' => (string) ($e['name'] ?? ''), 'msg' => (string) ($e['msg'] ?? ''),
        'img' => !empty($e['img']), 'hidden' => !empty($e['hidden']), 'ip' => (string) ($e['ip'] ?? ''),
    ];
}, gb_all_entries());
$boot = json_encode(['entries' => $entries, 'open' => gb_is_open(), 'csrf' => $csrf],
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);

header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
header('X-Robots-Tag: noindex, nofollow');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Guest Book · Admin</title>
<style>
@font-face{font-family:"Pinyon Script";font-weight:400;font-display:swap;src:url(/guestbook/assets/fonts/pinyon-script-latin-400-normal.woff2) format("woff2")}
@font-face{font-family:"Cormorant Garamond";font-style:normal;font-weight:500;font-display:swap;src:url(/guestbook/assets/fonts/cormorant-garamond-latin-500-normal.woff2) format("woff2")}
@font-face{font-family:"Cormorant Garamond";font-style:normal;font-weight:600;font-display:swap;src:url(/guestbook/assets/fonts/cormorant-garamond-latin-600-normal.woff2) format("woff2")}
@font-face{font-family:"Cormorant Garamond";font-style:italic;font-weight:500;font-display:swap;src:url(/guestbook/assets/fonts/cormorant-garamond-latin-500-italic.woff2) format("woff2")}
:root{--ivory:#fbf7ee;--paper:#fffdf8;--ink:#3a2a1f;--soft:#6f5d4c;--olive:#6b6a2e;--olive-d:#4f4e20;--gold:#d9a21b;--line:#e7dcc4;--bad:#a8402c}
*{box-sizing:border-box}
body{margin:0;background:var(--ivory);color:var(--ink);font:500 18px/1.5 "Cormorant Garamond",Georgia,serif;padding:20px 16px 60px}
.wrap{max-width:1100px;margin:0 auto}
h1{font:400 3rem/1.1 "Pinyon Script",cursive;margin:.2em 0 0}
h2{font:600 .8rem/1 "Cormorant Garamond",serif;letter-spacing:.24em;text-transform:uppercase;color:var(--olive);margin:0 0 12px}
.top{display:flex;gap:16px;align-items:center;justify-content:space-between;flex-wrap:wrap}
.top a{color:var(--olive-d)}
.panel{background:var(--paper);border:1px solid var(--line);border-radius:16px;padding:18px 20px;margin-top:18px;box-shadow:0 8px 24px -16px rgba(58,42,31,.35)}
.row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
button,.btn{font:600 1rem "Cormorant Garamond",serif;border-radius:999px;border:1px solid var(--olive);background:#fff;color:var(--olive-d);padding:8px 18px;cursor:pointer;text-decoration:none;display:inline-block}
button:hover{background:#f6f4e0}
button.pri{background:var(--olive);color:#fff}
button.pri:hover{background:var(--olive-d)}
button.bad{border-color:var(--bad);color:var(--bad)}
button:disabled{opacity:.5;cursor:wait}
.pill{display:inline-block;border-radius:999px;padding:2px 12px;font-size:.85rem;font-weight:600;letter-spacing:.06em}
.pill.open{background:#e8efd0;color:var(--olive-d)}.pill.closed{background:#f5dcd6;color:var(--bad)}
.small{color:var(--soft);font-size:.95rem}
.opt{display:flex;gap:18px;flex-wrap:wrap;margin:6px 0 12px}
.opt label{display:flex;gap:6px;align-items:center;font-size:1rem}
#prog{margin-top:10px;min-height:1.4em;color:var(--olive-d);font-style:italic}
.bar{height:6px;background:#efe6cf;border-radius:4px;overflow:hidden;margin-top:6px;display:none}.bar i{display:block;height:100%;width:0;background:var(--olive);transition:width .2s}
#previews{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:14px;margin-top:16px}
#previews figure{margin:0;text-align:center}
#previews canvas{width:100%;height:auto;border:1px solid var(--line);border-radius:6px;background:#fff;box-shadow:0 6px 16px -10px rgba(0,0,0,.4)}
#previews figcaption{font-size:.9rem;margin-top:6px}
.list{display:grid;gap:12px;margin-top:6px}
.item{display:grid;grid-template-columns:150px 1fr auto;gap:14px;align-items:start;border:1px solid var(--line);border-radius:12px;padding:10px;background:#fff}
.item.hid{opacity:.55;background:#f5f1e6}
.item img{width:150px;height:105px;object-fit:cover;border-radius:8px;border:1px solid var(--line);background:#f6efdc;display:block}
.item .noimg{width:150px;height:105px;border-radius:8px;border:1px dashed var(--line);display:grid;place-items:center;color:var(--soft);font-style:italic}
.item .nm{font:400 1.7rem/1.1 "Pinyon Script",cursive}
.item .tx{white-space:pre-wrap;overflow-wrap:anywhere;color:var(--soft)}
.item .meta{font-size:.82rem;color:var(--soft);letter-spacing:.04em}
.item .btns{display:flex;flex-direction:column;gap:6px}
@media(max-width:700px){.item{grid-template-columns:100px 1fr}.item img,.item .noimg{width:100px;height:70px}.item .btns{grid-column:1/-1;flex-direction:row}}
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <div><h1>Guest Book</h1><div class="small"><a href="/guestbook/" target="_blank" rel="noopener">View the public page ↗</a> · <a href="/guestbook/admin.php?logout=1">Log out</a></div></div>
    <div class="row"><span id="stat" class="small"></span> <span id="state" class="pill"></span> <button id="toggle" type="button"></button></div>
  </div>

  <div class="panel">
    <h2>Download the book</h2>
    <p class="small" style="margin-top:0">Hidden pages are left out. Everything is built right here in your browser and nothing is uploaded anywhere.</p>
    <div class="opt">
      <label><input type="checkbox" id="optWhite"> White background (cheaper to print)</label>
      <label><input type="checkbox" id="optNewest"> Newest first</label>
    </div>
    <div class="row">
      <button class="pri" id="bPages" type="button">Print pages · A4 (PNG in a ZIP)</button>
      <button class="pri" id="bLong" type="button">One big picture (PNG)</button>
      <button id="bOrig" type="button">Every original drawing + messages (ZIP)</button>
    </div>
    <div class="bar" id="bar"><i></i></div>
    <div id="prog" role="status" aria-live="polite"></div>
    <div id="previews"></div>
  </div>

  <div class="panel">
    <h2>All signatures</h2>
    <div class="list" id="list"></div>
    <p class="small" id="none" hidden>No one has signed yet.</p>
  </div>
</div>

<script>
(function(){
'use strict';
var BOOT=<?= $boot ?>;
var ENTRIES=BOOT.entries, OPEN=BOOT.open, CSRF=BOOT.csrf;
var $=function(s){return document.querySelector(s)};
function el(t,c,x){var n=document.createElement(t); if(c) n.className=c; if(x!=null) n.textContent=x; return n;}

/* ---------- moderation ---------- */
async function act(action,id){
  var r=await fetch(location.pathname,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:CSRF,action:action,id:id||''})});
  var j=null; try{j=await r.json()}catch(e){}
  return !!(j&&j.ok);
}
function fmt(ts){var d=new Date(ts*1000); return isNaN(d)?'':d.toLocaleString(undefined,{month:'short',day:'numeric',hour:'numeric',minute:'2-digit'});}
function renderList(){
  var box=$('#list'); box.textContent='';
  var shown=ENTRIES.slice().reverse();
  shown.forEach(function(e){
    var it=el('div','item'+(e.hidden?' hid':'')); it.dataset.id=e.id;
    if(e.img){var im=el('img'); im.loading='lazy'; im.src='/guestbook/d/'+e.id+'.jpg'; im.alt=''; it.appendChild(im);} else it.appendChild(el('div','noimg','text only'));
    var mid=el('div'); mid.appendChild(el('div','nm',e.name)); if(e.msg) mid.appendChild(el('div','tx',e.msg));
    mid.appendChild(el('div','meta',fmt(e.ts)+(e.hidden?' · hidden':'')+(e.ip?' · '+e.ip:''))); it.appendChild(mid);
    var b=el('div','btns');
    var h=el('button',null,e.hidden?'Show':'Hide'); h.type='button';
    h.onclick=async function(){h.disabled=true; if(await act(e.hidden?'unhide':'hide',e.id)){e.hidden=!e.hidden; renderList(); stats();} else {h.disabled=false; alert('That did not save. Please try again.');}};
    var d=el('button','bad','Delete'); d.type='button';
    d.onclick=async function(){ if(!confirm('Delete this signature for good?')) return; d.disabled=true; if(await act('delete',e.id)){ENTRIES=ENTRIES.filter(function(x){return x.id!==e.id}); renderList(); stats();} else {d.disabled=false; alert('That did not save. Please try again.');}};
    b.appendChild(h); b.appendChild(d); it.appendChild(b); box.appendChild(it);
  });
  $('#none').hidden=ENTRIES.length>0;
}
function stats(){
  var vis=ENTRIES.filter(function(e){return !e.hidden}).length;
  $('#stat').textContent=ENTRIES.length+' signatures · '+vis+' showing';
  var s=$('#state'); s.textContent=OPEN?'Open':'Closed'; s.className='pill '+(OPEN?'open':'closed');
  $('#toggle').textContent=OPEN?'Close the book':'Reopen the book';
}
$('#toggle').onclick=async function(){
  var b=this; if(OPEN&&!confirm('Close the book? Guests will no longer be able to sign (they can still look).')) return;
  b.disabled=true; if(await act(OPEN?'close':'open')){OPEN=!OPEN; stats();} else alert('That did not save. Please try again.'); b.disabled=false;
};
renderList(); stats();

/* ---------- export engine ---------- */
var UNIT={W:2480,H:3508,M:170,GAP:70,PAD:30};
var FONT_S='"Pinyon Script",cursive', FONT_B='"Cormorant Garamond",Georgia,serif';
var cache={};
function loadImg(src){
  if(cache[src]) return cache[src];
  cache[src]=new Promise(function(res){var i=new Image(); i.onload=function(){res(i)}; i.onerror=function(){res(null)}; i.src=src;});
  return cache[src];
}
function status(t,pct){ $('#prog').textContent=t||''; var bar=$('#bar'); if(pct==null){bar.style.display='none';} else {bar.style.display='block'; bar.firstChild.style.width=Math.round(pct*100)+'%';} }
function visible(){
  var v=ENTRIES.filter(function(e){return !e.hidden});
  return $('#optNewest').checked?v.slice().reverse():v;
}
var mctx=document.createElement('canvas').getContext('2d');
function wrap(text,font,maxW){
  mctx.font=font; var out=[];
  String(text).split('\n').forEach(function(para){
    if(para.trim()===''){ out.push(''); return; }
    var words=para.split(/\s+/), line='';
    words.forEach(function(w){
      while(mctx.measureText(w).width>maxW){ // break very long words
        var k=w.length; while(k>1&&mctx.measureText(w.slice(0,k)).width>maxW) k--;
        if(line){out.push(line); line='';}
        out.push(w.slice(0,k)); w=w.slice(k);
      }
      var test=line?line+' '+w:w;
      if(mctx.measureText(test).width<=maxW) line=test; else { out.push(line); line=w; }
    });
    out.push(line);
  });
  while(out.length&&out[out.length-1]==='') out.pop();
  return out;
}
var F={name:'400 84px '+FONT_S, msg:'italic 500 44px '+FONT_B, quote:'italic 500 54px '+FONT_B};
var LH={name:100,msg:58,quote:70};
function measure(e,colW){
  var inner=colW-2*UNIT.PAD, m={e:e,w:colW};
  var h=UNIT.PAD;
  if(e.img){
    m.imgH=Math.round(inner*0.7); h+=m.imgH+24;
    m.nameL=wrap(e.name,F.name,inner); h+=m.nameL.length*LH.name;
    m.msgL=e.msg?wrap(e.msg,F.msg,inner):[]; if(m.msgL.length) h+=12+m.msgL.length*LH.msg;
  } else {
    m.quoteL=wrap(e.msg,F.quote,inner-20); h+=60+m.quoteL.length*LH.quote+20;
    m.nameL=wrap('— '+e.name,F.name,inner); h+=m.nameL.length*LH.name;
  }
  m.h=h+UNIT.PAD; return m;
}
/* flow the cards into columns; opts: firstTop, otherTop, bottomPad, pageH (Infinity = one tall sheet) */
function layout(list,o){
  var colW=(UNIT.W-2*UNIT.M-UNIT.GAP)/2, pages=[];
  function fresh(){var top=pages.length?o.otherTop:o.firstTop; var p={items:[],y:[top,top],top:top}; pages.push(p); return p;}
  var p=fresh();
  list.forEach(function(e){
    var m=measure(e,colW), limit=o.pageH-o.bottomPad;
    var c=p.y[0]<=p.y[1]?0:1;
    if(p.y[c]+m.h>limit){ c=1-c; if(p.y[c]+m.h>limit){ p=fresh(); c=0; } }
    m.x=UNIT.M+c*(colW+UNIT.GAP); m.y=p.y[c]; p.y[c]+=m.h+UNIT.GAP; p.items.push(m);
  });
  pages.forEach(function(pg){ pg.bottom=Math.max(pg.y[0],pg.y[1]); });
  return pages;
}
function rr(c,x,y,w,h,r){c.beginPath(); c.moveTo(x+r,y); c.arcTo(x+w,y,x+w,y+h,r); c.arcTo(x+w,y+h,x,y+h,r); c.arcTo(x,y+h,x,y,r); c.arcTo(x,y,x+w,y,r); c.closePath();}
function spaced(c,t,x,y,sp){ // centred, letter-spaced text
  if('letterSpacing' in c){ c.letterSpacing=sp+'px'; c.fillText(t,x+sp/2,y); c.letterSpacing='0px'; return; }
  c.fillText(t,x,y);
}
function headerFirst(c,logo,white){
  var cx=UNIT.W/2; c.textAlign='center'; c.textBaseline='alphabetic';
  if(logo) c.drawImage(logo,cx-210,120,420,420);
  c.fillStyle='#6b6a2e'; c.font='600 38px '+FONT_B; spaced(c,'THE WEDDING GUEST BOOK',cx,610,12);
  c.fillStyle='#3a2a1f'; c.font='400 230px '+FONT_S; c.fillText('Meadow & Vincent',cx,810);
  c.fillStyle='#6f5d4c'; c.font='500 44px '+FONT_B; spaced(c,'SATURDAY  ·  24 OCTOBER 2026  ·  PALOMINO HIGHLANDS',cx,900,6);
  divider(c,cx,980);
}
function headerSmall(c,logo){
  var cx=UNIT.W/2; c.textAlign='center';
  if(logo) c.drawImage(logo,UNIT.M,110,150,150);
  c.fillStyle='#3a2a1f'; c.font='400 110px '+FONT_S; c.fillText('Meadow & Vincent',cx,225);
  c.fillStyle='#6b6a2e'; c.font='600 30px '+FONT_B; spaced(c,'THE WEDDING GUEST BOOK',cx,285,10);
  divider(c,cx,345);
}
function divider(c,cx,y){
  c.save(); c.strokeStyle='rgba(217,162,27,.7)'; c.lineWidth=3;
  c.beginPath(); c.moveTo(cx-520,y); c.lineTo(cx-60,y); c.moveTo(cx+60,y); c.lineTo(cx+520,y); c.stroke();
  c.fillStyle='#d9a21b'; c.beginPath(); c.arc(cx,y,14,0,Math.PI*2); c.fill();
  c.fillStyle='#4a3220'; c.beginPath(); c.arc(cx,y,6,0,Math.PI*2); c.fill(); c.restore();
}
async function drawCard(c,m,white){
  var e=m.e, x=m.x, y=m.y, w=m.w, P=UNIT.PAD, inner=w-2*P;
  c.save();
  rr(c,x,y,w,m.h,26); c.fillStyle=white?'#ffffff':'#fffdf8'; c.fill();
  c.lineWidth=4; c.strokeStyle='#e7dcc4'; c.stroke();
  c.textAlign='left'; c.textBaseline='alphabetic';
  var cy=y+P;
  if(e.img){
    var im=await loadImg('/guestbook/d/'+e.id+'.png');
    c.save(); rr(c,x+P,cy,inner,m.imgH,14); c.clip();
    c.fillStyle='#fffdf8'; c.fillRect(x+P,cy,inner,m.imgH);
    if(im) c.drawImage(im,x+P,cy,inner,m.imgH);
    c.restore(); c.lineWidth=3; c.strokeStyle='#efe6cf'; rr(c,x+P,cy,inner,m.imgH,14); c.stroke();
    cy+=m.imgH+24;
    c.fillStyle='#3a2a1f'; c.font=F.name;
    m.nameL.forEach(function(l){ cy+=LH.name; c.fillText(l,x+P,cy-18); });
    if(m.msgL.length){ cy+=12; c.fillStyle='#6f5d4c'; c.font=F.msg; m.msgL.forEach(function(l){ cy+=LH.msg; c.fillText(l,x+P,cy-14); }); }
  } else {
    c.fillStyle='rgba(217,162,27,.6)'; c.font='400 170px '+FONT_B; c.fillText('“',x+P-4,cy+120);
    cy+=60; c.fillStyle='#3a2a1f'; c.font=F.quote;
    m.quoteL.forEach(function(l){ cy+=LH.quote; c.fillText(l,x+P+10,cy-16); });
    cy+=20; c.fillStyle='#6b6a2e'; c.font=F.name;
    m.nameL.forEach(function(l){ cy+=LH.name; c.fillText(l,x+P,cy-18); });
  }
  c.restore();
}
function blobOf(canvas){ return new Promise(function(res){canvas.toBlob(res,'image/png')}); }
async function ready(){
  await Promise.all([
    document.fonts.load('400 80px "Pinyon Script"'),
    document.fonts.load('italic 500 44px "Cormorant Garamond"'),
    document.fonts.load('600 30px "Cormorant Garamond"'),
    document.fonts.load('500 30px "Cormorant Garamond"')
  ]).catch(function(){});
}
/* mode 'pages' = A4 sheets; mode 'long' = tall picture(s) */
async function renderBook(mode,onProgress){
  await ready();
  var list=visible(); if(!list.length) throw new Error('Nothing to export yet.');
  var white=$('#optWhite').checked, logo=await loadImg('/guestbook/assets/mv-logo.png');
  var pages, scale, outs=[];
  if(mode==='pages'){
    pages=layout(list,{firstTop:1060,otherTop:430,bottomPad:230,pageH:UNIT.H}); scale=1;
  } else {
    scale=0.64;
    var one=layout(list,{firstTop:1060,otherTop:430,bottomPad:150,pageH:Infinity});
    var total=one[0].bottom+150;
    pages=(total*scale<=15000)?one:layout(list,{firstTop:1060,otherTop:430,bottomPad:150,pageH:18000});
  }
  var N=pages.length, done=0, totalCards=list.length, cardsDone=0;
  for(var pi=0;pi<N;pi++){
    var pg=pages[pi], H=(mode==='pages')?UNIT.H:Math.round(pg.bottom+150);
    var cv=document.createElement('canvas'); cv.width=Math.round(UNIT.W*scale); cv.height=Math.round(H*scale);
    var c=cv.getContext('2d'); c.scale(scale,scale);
    c.fillStyle=white?'#ffffff':'#fbf7ee'; c.fillRect(0,0,UNIT.W,H);
    if(!white){ c.strokeStyle='rgba(217,162,27,.5)'; c.lineWidth=4; rr(c,60,60,UNIT.W-120,H-120,34); c.stroke(); }
    if(pi===0) headerFirst(c,logo,white); else headerSmall(c,logo);
    for(var k=0;k<pg.items.length;k++){ await drawCard(c,pg.items[k],white); cardsDone++; onProgress((pi+cardsDone/totalCards*0)/N+ (k+1)/pg.items.length/N, 'Drawing page '+(pi+1)+' of '+N+'…'); }
    c.textAlign='center'; c.fillStyle='#6f5d4c'; c.font='500 38px '+FONT_B;
    c.fillText((mode==='pages'?'Page '+(pi+1)+' of '+N+'   ·   ':(N>1?'Part '+(pi+1)+' of '+N+'   ·   ':''))+'Meadow & Vincent   ·   24 October 2026',UNIT.W/2,H-(mode==='pages'?110:70));
    var blob=await blobOf(cv); outs.push({blob:blob,canvas:cv,w:cv.width,h:cv.height});
    await new Promise(function(r){setTimeout(r,0)});
  }
  return outs;
}

/* ---------- zip (store only, no compression needed for PNGs) ---------- */
var CRC=(function(){var t=new Uint32Array(256); for(var n=0;n<256;n++){var c=n; for(var k=0;k<8;k++) c=c&1?0xEDB88320^(c>>>1):c>>>1; t[n]=c>>>0;} return t;})();
function crc32(u8){var c=0xFFFFFFFF; for(var i=0;i<u8.length;i++) c=CRC[(c^u8[i])&255]^(c>>>8); return (c^0xFFFFFFFF)>>>0;}
async function makeZip(files){ // files: [{name, blob|text}]
  var enc=new TextEncoder(), parts=[], central=[], offset=0, now=new Date();
  var dt=((now.getFullYear()-1980)<<9|(now.getMonth()+1)<<5|now.getDate())&0xFFFF, tm=(now.getHours()<<11|now.getMinutes()<<5|(now.getSeconds()>>1))&0xFFFF;
  for(var i=0;i<files.length;i++){
    var f=files[i], data=f.blob?new Uint8Array(await f.blob.arrayBuffer()):enc.encode(f.text), nm=enc.encode(f.name), crc=crc32(data);
    var lh=new DataView(new ArrayBuffer(30)); lh.setUint32(0,0x04034b50,true); lh.setUint16(4,20,true); lh.setUint16(6,0x0800,true); lh.setUint16(8,0,true);
    lh.setUint16(10,tm,true); lh.setUint16(12,dt,true); lh.setUint32(14,crc,true); lh.setUint32(18,data.length,true); lh.setUint32(22,data.length,true); lh.setUint16(26,nm.length,true); lh.setUint16(28,0,true);
    parts.push(lh.buffer,nm,data);
    var ch=new DataView(new ArrayBuffer(46)); ch.setUint32(0,0x02014b50,true); ch.setUint16(4,20,true); ch.setUint16(6,20,true); ch.setUint16(8,0x0800,true); ch.setUint16(10,0,true);
    ch.setUint16(12,tm,true); ch.setUint16(14,dt,true); ch.setUint32(16,crc,true); ch.setUint32(20,data.length,true); ch.setUint32(24,data.length,true); ch.setUint16(28,nm.length,true);
    ch.setUint32(42,offset,true); central.push(ch.buffer,nm);
    offset+=30+nm.length+data.length;
  }
  var cdSize=0; central.forEach(function(b){cdSize+=b.byteLength});
  var end=new DataView(new ArrayBuffer(22)); end.setUint32(0,0x06054b50,true); end.setUint16(8,files.length,true); end.setUint16(10,files.length,true); end.setUint32(12,cdSize,true); end.setUint32(16,offset,true);
  return new Blob(parts.concat(central,[end.buffer]),{type:'application/zip'});
}
function save(blob,name){
  var a=document.createElement('a'); a.href=URL.createObjectURL(blob); a.download=name; document.body.appendChild(a); a.click();
  setTimeout(function(){URL.revokeObjectURL(a.href); a.remove();},4000);
}
function showPreviews(outs,prefix){
  var box=$('#previews'); box.textContent='';
  outs.forEach(function(o,i){
    var f=el('figure'), cv=document.createElement('canvas'); cv.width=380; cv.height=Math.round(380*o.h/o.w);
    var c=cv.getContext('2d'); c.imageSmoothingQuality='high'; c.drawImage(o.canvas,0,0,cv.width,cv.height);
    var cap=el('figcaption'), b=el('button',null,'Download '+(outs.length>1?(prefix+' '+(i+1)):prefix)+' (PNG)'); b.type='button';
    b.onclick=function(){save(o.blob,'guest-book-'+prefix.toLowerCase()+'-'+String(i+1).padStart(2,'0')+'.png')};
    cap.appendChild(b); f.appendChild(cv); f.appendChild(cap); box.appendChild(f);
  });
}
function busy(on){['#bPages','#bLong','#bOrig'].forEach(function(s){$(s).disabled=on});}
function today(){var d=new Date(); return d.getFullYear()+String(d.getMonth()+1).padStart(2,'0')+String(d.getDate()).padStart(2,'0');}

$('#bPages').onclick=async function(){
  busy(true);
  try{
    status('Preparing…',0);
    var outs=await renderBook('pages',function(p,t){status(t,p)});
    showPreviews(outs,'Page');
    status('Packing your '+outs.length+' page'+(outs.length>1?'s':'')+'…',1);
    var files=outs.map(function(o,i){return {name:'page-'+String(i+1).padStart(2,'0')+'.png',blob:o.blob}});
    save(await makeZip(files),'guest-book-pages-'+today()+'.zip');
    status('Done. '+outs.length+' A4 page'+(outs.length>1?'s':'')+' downloaded (300 dpi). You can also grab single pages below.',null);
  }catch(e){status('Sorry, that failed: '+(e&&e.message||e),null);}
  busy(false);
};
$('#bLong').onclick=async function(){
  busy(true);
  try{
    status('Preparing…',0);
    var outs=await renderBook('long',function(p,t){status(t,p)});
    showPreviews(outs,'Part');
    if(outs.length===1){ save(outs[0].blob,'guest-book-'+today()+'.png'); status('Done. The whole book is one picture ('+outs[0].w+' × '+outs[0].h+' px).',null); }
    else { status('Packing '+outs.length+' parts…',1); save(await makeZip(outs.map(function(o,i){return {name:'guest-book-part-'+String(i+1).padStart(2,'0')+'.png',blob:o.blob}})),'guest-book-long-'+today()+'.zip'); status('The book is too tall for one picture, so it was cut into '+outs.length+' parts (between signatures, never through one). Downloaded as a ZIP.',null); }
  }catch(e){status('Sorry, that failed: '+(e&&e.message||e),null);}
  busy(false);
};
$('#bOrig').onclick=async function(){
  busy(true);
  try{
    var list=visible(); if(!list.length) throw new Error('Nothing to export yet.');
    var files=[], csv=['"#","name","message","signed","drawing"'];
    for(var i=0;i<list.length;i++){
      var e=list[i]; status('Collecting '+(i+1)+' of '+list.length+'…',(i+1)/list.length);
      var safe=e.name.replace(/[^\p{L}\p{N}]+/gu,'-').replace(/^-+|-+$/g,'').slice(0,30)||'guest';
      var fn='';
      if(e.img){ var r=await fetch('/guestbook/d/'+e.id+'.png',{credentials:'same-origin'}); if(r.ok){ fn='drawings/'+String(i+1).padStart(3,'0')+'-'+safe+'.png'; files.push({name:fn,blob:await r.blob()}); } }
      var q=function(s){return '"'+String(s).replace(/"/g,'""').replace(/^([=+\-@])/,"'$1")+'"'};
      csv.push([i+1,q(e.name),q(e.msg),q(new Date(e.ts*1000).toISOString()),q(fn)].join(','));
    }
    files.push({name:'messages.csv',text:'﻿'+csv.join('\r\n')});
    save(await makeZip(files),'guest-book-originals-'+today()+'.zip');
    status('Done. '+list.length+' signatures saved (drawings as PNG + a spreadsheet of the messages).',null);
  }catch(e){status('Sorry, that failed: '+(e&&e.message||e),null);}
  busy(false);
};
})();
</script>
</body>
</html>
