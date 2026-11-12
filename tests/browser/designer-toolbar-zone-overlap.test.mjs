import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {mkdtemp, rm, writeFile} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {fileURLToPath, pathToFileURL} from 'node:url';
import {test} from 'node:test';

const root = fileURLToPath(new URL('../../', import.meta.url));
const chrome = process.env.CHROMIUM_BIN || ['chromium', 'chromium-browser', 'google-chrome'].find(bin =>
    spawnSync(bin, ['--version'], {encoding:'utf8',timeout:3000}).status === 0);
const cases = [
    [1600,1600,40],[1281,1281,40],[1280,1280,40],[1024,1024,40],
    [920,920,60],[760,760,60],[700,700,60],[600,600,60],
    [500,500,60],[440,440,40],[375,375,40],[320,320,40],
];

function fixture(viewport, width, height) {
    const compact = viewport <= 1280;
    const links = ['editor-shell.css','designer-mode.css','designer-toolbar.css','platform-fullscreen.css']
        .map(name=>'<link rel="stylesheet" href="'+
            pathToFileURL(join(root,'assets/css/design',name)).href+'">').join('');
    const btn = (id, more='') => '<button type="button" data-test-control="'+id+'" '+
        'class="button cb-core-button cb-core-design-shell__icon-button '+more+'"><svg width="20" height="20"></svg></button>';
    const group=(name,buttons)=>'<div class="cb-core-design-shell__toolbar-group cb-core-design-shell__toolbar-group--'+name+
        (compact?' cb-core-design-shell__toolbar-group--adaptive is-adaptive-compact has-adaptive-'+name+'-switcher':'')+'">'+
        (compact
            ? btn(name,'cb-core-design-shell__adaptive-trigger cb-core-design-shell__'+name+'-trigger')+
              '<div class="cb-core-design-shell__adaptive-options" hidden>'+buttons+'</div>'
            :buttons)+'</div>';
    const view=group('viewport',btn('mobile')+btn('tablet')+btn('desktop'));
    const history=group('history',btn('undo')+btn('redo'));
    const script = function () {
        window.addEventListener('load', () => {
            const toolbar=document.querySelector('.cb-core-design-shell__toolbar--designer');
            const box=el=>{const r=el.getBoundingClientRect();
                return {left:r.left,right:r.right,top:r.top,bottom:r.bottom};};
            const controls=[...toolbar.querySelectorAll('[data-test-control]')].filter(el=>
                el.getClientRects().length>0).map(el=>({name:el.dataset.testControl,...box(el)}));
            const close=toolbar.querySelector('[data-test-control="close"]');
            const zones=Object.fromEntries(['start','center','end'].map(name=>
                [name,box(toolbar.querySelector('.cb-core-design-shell__toolbar-zone--'+name))]));
            const initialScroll=toolbar.scrollLeft;
            const scrollable=toolbar.scrollWidth>toolbar.clientWidth+1;
            toolbar.scrollLeft=toolbar.scrollWidth;
            const closeAtRight=box(close).right<=box(toolbar).right+2;
            const scrollReachedEnd=toolbar.scrollLeft>initialScroll+1;
            document.documentElement.setAttribute('data-zone-report',
                encodeURIComponent(JSON.stringify({bounds:box(toolbar),zones,controls,
                    columns:getComputedStyle(toolbar).gridTemplateColumns,
                    viewport:innerWidth,scrollable,scrollReachedEnd,closeAtRight})));
        });
    };
    return '<!doctype html><html><head><meta charset="utf-8">'+links+'<style>'+
        ':root{--cb-control-height:'+height+'px;--cb-space-1:4px;--cb-space-2:8px;'+
        '--cb-space-3:12px;--cb-space-4:16px;--cb-space-5:20px;'+
        '--cb-border:#34404b;--cb-surface-0:#0b0f16;--cb-surface-1:#181f28;'+
        '--cb-surface-2:#26303e;--cb-text:#eee;--cb-text-strong:#fff;'+
        '--cb-accent:#00bfff;--cb-radius-md:8px;}' +
        'html,body{margin:0;padding:0;background:#0b0f16;}'+
        '.cb-core-platform-screen{width:'+width+'px;max-width:100vw;}'+
        '.cb-core-design-shell--native{width:100% !important;height:auto !important;}'+
        '.cb-core-design-shell__brand{display:flex;gap:8px;align-items:center;}'+
        '.cb-core-design-shell__brand-mark{display:block;flex:0 0 32px;width:32px;height:32px;}'+
        '.cb-core-design-shell__brand-wordmark{font-size:24px;white-space:nowrap;}'+
        '</style></head><body><div class="cb-core-platform-screen">'+
        '<div class="cb-core-design-shell cb-core-design-shell--native">'+
        '<div class="cb-core-design-shell__toolbar cb-core-design-shell__toolbar--designer">'+
        '<div class="cb-core-design-shell__toolbar-zone cb-core-design-shell__toolbar-zone--start">'+
        '<div class="cb-core-design-shell__brand"><span class="cb-core-design-shell__brand-mark"></span>'+
        '<span class="cb-core-design-shell__brand-wordmark">mail.designer</span></div>'+
        btn('left','cb-core-design-shell__drawer-launcher')+'</div>'+
        '<div class="cb-core-design-shell__toolbar-zone cb-core-design-shell__toolbar-zone--center">'+view+'</div>'+
        '<div class="cb-core-design-shell__toolbar-zone cb-core-design-shell__toolbar-zone--end">'+
        btn('right','cb-core-design-shell__drawer-launcher')+
        '<div class="cb-core-design-shell__toolbar-group cb-core-design-shell__toolbar-group--resource">'+btn('browser')+'</div>'+
        '<div class="cb-core-design-shell__toolbar-group cb-core-design-shell__toolbar-group--operations">'+
        history+btn('save')+'<span class="cb-core-design-shell__native-close">'+btn('close')+'</span>'+
        '</div></div></div></div></div>'+
        '<script>('+script.toString()+')();</script></body></html>';
}

for(const [viewport,width,height] of cases) {
    test('FIELD-06E: no action overlap at '+width+'px, controls '+height+'px', async () => {
        assert.ok(chrome,'Chromium required, set CHROMIUM_BIN');
        const dir=await mkdtemp(join(tmpdir(),'cb-zone-field06e-'));
        try {
            const file=join(dir,'fixture.html');
            await writeFile(file,fixture(viewport,width,height),'utf8');
            const run=spawnSync(chrome,[
                '--headless=new','--no-sandbox','--disable-gpu','--disable-dev-shm-usage',
                '--allow-file-access-from-files','--window-size='+viewport+',900',
                '--virtual-time-budget=1200','--dump-dom',pathToFileURL(file).href,
            ],{encoding:'utf8',timeout:30000,maxBuffer:4*1024*1024});
            assert.equal(run.status,0,run.stderr);
            const matched=run.stdout.match(/data-zone-report="([^"]+)"/);
            assert.ok(matched,'Missing geometry report: '+run.stderr);
            const r=JSON.parse(decodeURIComponent(matched[1]));
            const required=['browser','save','close'];
            if(viewport<=1280)required.push('left','right','viewport','history');
            else required.push('desktop','undo','redo');
            for(const name of required)
                assert.ok(r.controls.some(x=>x.name===name),name+' disappeared at '+width+'px');
            const overlaps=[];
            for(let i=0;i<r.controls.length;i++)for(let j=i+1;j<r.controls.length;j++){
                const a=r.controls[i],b=r.controls[j];
                const x=Math.min(a.right,b.right)-Math.max(a.left,b.left);
                const y=Math.min(a.bottom,b.bottom)-Math.max(a.top,b.top);
                if(x>1&&y>1)overlaps.push(a.name+' vs '+b.name);
            }
            assert.deepEqual(overlaps,[],'Overlapping controls at '+width+'px: '+JSON.stringify(r));
            // The toolbar ALWAYS occupies one row; no mobile-only row 2.
            for(const name of ['start','center','end'])
                assert.ok(Math.abs(r.zones[name].top-r.zones.start.top)<=2,
                    name+' moved to a second row at '+width+'px: '+JSON.stringify(r));
            if(r.scrollable) {
                // Even with enlarged controls, every original command stays
                // on row one and Close can be reached with a horizontal swipe.
                assert.equal(r.scrollReachedEnd,true);
                assert.equal(r.closeAtRight,true,'Close must be reachable by scrolling');
            } else {
                for(const a of r.controls)
                    assert.ok(a.left>=r.bounds.left-2&&a.right<=r.bounds.right+2,
                        a.name+' escaped toolbar at '+width+'px: '+JSON.stringify(r));
            }
            if(width<=375)
                assert.equal(r.scrollable,true,
                    'Very narrow toolbars cannot show full-size buttons without horizontal scrolling');

        }finally{await rm(dir,{recursive:true,force:true});}
    });
}
