import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {test} from 'node:test';
const [context,layers,css,assets]=await Promise.all([
 readFile(new URL('../../assets/js/design/shell/context-menu.js',import.meta.url),'utf8'),
 readFile(new URL('../../assets/js/design/shell/layers.js',import.meta.url),'utf8'),
 readFile(new URL('../../assets/css/design/designer-composition.css',import.meta.url),'utf8'),
 readFile(new URL('../../src/Design/Editor/Assets.php',import.meta.url),'utf8'),
]);
test('CM-2 canonical Canvas mode delegates to Base Layers action presentation',()=>{
 assert.match(assets,/DESIGNER_CANONICAL_ACTION_MENU_API = 1/);
 assert.match(context,/actionPresenter = null, getTargetInfo = null/);
 assert.match(context,/actionPresenter\.openActions\(\{/);
 assert.match(context,/actionPresenter\.closeActions\(\{restoreFocus\}\)/);
 assert.match(context,/if \(!actionPresenter\) doc\.body\.append\(menu\)/);
 assert.match(layers,/closeActions\(options = \{\}\)/);
 assert.match(layers,/const entries = \['moveUp', 'moveDown', 'hide', 'show', 'duplicate', 'remove'\]/);
});
test('CM-2 canonical action chrome and nested Delete confirmation belong to Base',()=>{
 for(const icon of ["arrow-up","arrow-down","copy","trash-2"]) assert.ok(layers.includes("icon: '"+icon+"'") || layers.includes("icon:'"+icon+"'"));
 assert.match(layers,/button\.classList\.add\('is-destructive'\)/);
 assert.match(layers,/countDescendants\(children\) > 0/);
 assert.match(css,/\.cb-core-design-shell__layer-menu-item\.is-destructive/);
 assert.match(css,/\.cb-core-design-shell__layer-menu-separator/);
});
test('CM-2 immutable targets and command authority remain at Base target boundary',()=>{
 assert.match(context,/currentContext\(\) === entry\.context/);
 assert.match(context,/targets\.get\(element\) === resolved/);
 assert.match(context,/canInvoke\(Object\.freeze\(\{actionId, target: entry\.target\}\)\) === true/);
 assert.match(context,/item\.children !== undefined/);
 assert.match(context,/if \(!actionPresenter\) \{/);
});
