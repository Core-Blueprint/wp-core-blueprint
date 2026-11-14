import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const read = path => readFileSync( new URL( '../../' + path, import.meta.url ), 'utf8' );
const menu = read( 'assets/js/core/template-actions.js' );
const browser = read( 'assets/js/core/template-browser.js' );
const assets = read( 'src/TemplateLibrary/Assets.php' );
const listing = read( 'src/TemplateLibrary/BrowserController.php' );
const registry = read( 'src/TemplateLibrary/TemplateSourceRegistry.php' );
const download = read( 'src/DataExchange/ResourcePackageController.php' );

test( 'T4-01J resource ZIP is advertised only after a provider-specific global capability', () => {
    assert.match( listing, /'resourcePackageExport'\s*=>\s*null === \$scope/ );
    assert.match( listing, /TemplateSourceRegistry::supports_resource_export\( \$item \)/ );
    assert.match( registry, /\$source instanceof TemplateResourceExportSourceInterface/ );
    assert.match( registry, /\$source->can_export_resource_package\( \$item->id, \$item->version, \$actor \)/ );
    assert.match( menu, /options\.globalMode && item\.resourcePackageExport === true/ );
    assert.match( menu, /resourceZip \? \[ 'export-zip' \] : \[\]/ );
    assert.match( menu, /'export-zip': \[ 'exportZip', 'Export ZIP' \]/ );
} );

test( 'T4-01J ZIP browser action uses a strict same-origin POST, never a GET link or document payload', () => {
    assert.match( assets, /'downloadRoot'\]\s*=\s*esc_url_raw\( admin_url\( 'admin-post\.php' \) \)/ );
    assert.match( browser, /downloadRoot: settings\.downloadRoot/ );
    assert.match( menu, /target\.origin !== window\.location\.origin/ );
    assert.match( menu, /target\.pathname\.endsWith\( '\/admin-post\.php' \)/ );
    assert.match( menu, /form\.method = 'POST'/ );
    assert.match( menu, /form\.rel = 'noopener noreferrer'/ );
    assert.match( menu, /action: 'cb_core_template_resource_export'/ );
    assert.match( menu, /_wpnonce: options\.nonce/ );
    assert.doesNotMatch( menu, /fetch\(\s*options\.downloadRoot/ );
    assert.match( menu, /if \( !requestResourceZip\( item, options \) \)/ );
} );

test( 'T4-01J download never accepts URLs, server paths, resource bytes or caller-owned callbacks', () => {
    assert.match( download, /admin_post_cb_core_template_resource_export/ );
    assert.match( download, /self::exact\( \$input, \[ 'action', '_wpnonce', 'source', 'templateId', 'version' \] \)/ );
    assert.match( download, /wp_verify_nonce\( \$input\['_wpnonce'\], 'wp_rest' \)/ );
    assert.match( download, /TemplateSourceRegistry::export_resource_package\(/ );
    assert.match( download, /hash_equals\( \$digest, \$current \)/ );
    assert.match( download, /ResourcePackageExport::discard\( \$path, \$root \)/ );
    assert.match( download, /Cache-Control: private, no-store, max-age=0/ );
    assert.match( download, /X-Content-Type-Options: nosniff/ );
    assert.match( download, /AuditLog::log\(/ );
    assert.match( download, /template_package_export_delivery/ );
    assert.match( download, /\$ready = false !== \$audit/ );
    assert.match( registry, /ResourcePackageExport::create\(/ );
    assert.match( registry, /ResourcePackageExport::discard\(/ );
} );
