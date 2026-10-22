#!/usr/bin/env node
/**
 * Read-only Designer consumer SOURCE gate. Never runs WordPress, database or browser.
 * Usage: node tools/validate-designer-layout.mjs templates/mail-designer.php --mail
 *        node tools/validate-designer-layout.mjs /path/to/consumer.php
 * SOURCE conformance is not installed ZIP or browser acceptance.
 */
import { readFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const attribute = (tag, name) =>
  tag.match(new RegExp('\\s' + name + '="([^"]*)"'))?.[1] ?? null;
const tabs = (source) => [...source.matchAll(/<button\b[^>]*\bdata-cb-design-shell-tab="[^"]+"[^>]*>/g)]
  .map((match) => match[0]);

const ordered = (roles, canonical, label, findings) => {
  const seen = new Set();
  let previous = -1;
  for (const role of roles) {
    const position = canonical.indexOf(role);
    if (position === -1) {
      findings.push(label + ': unsupported role ' + String(role));
      continue;
    }
    if (seen.has(role)) findings.push(label + ': duplicate role ' + role);
    if (position < previous) findings.push(label + ': roles not in canonical order');
    seen.add(role);
    previous = position;
  }
};

export const auditDesignerLayout = (source, { mail = false } = {}) => {
  if (typeof source !== 'string' || source.trim() === '') {
    throw new TypeError('Designer layout audit requires nonempty PHP/HTML source.');
  }
  const findings = [];
  const paletteMatch = /<section\b[^>]*class="[^"]*\bcb-core-design-shell__palette\b[^"]*"[^>]*>/m.exec(source);
  const canvasMatch = /<(?:section|main)\b[^>]*class="[^"]*\bcb-core-design-shell__canvas\b[^"]*"[^>]*>/m.exec(source);
  const sidebarMatch = /<aside\b[^>]*class="[^"]*\bcb-core-design-shell__sidebar\b[^"]*"[^>]*>/m.exec(source);
  if (!paletteMatch || !canvasMatch || !sidebarMatch) {
    return ['Missing canonical palette, canvas or sidebar section.'];
  }
  if (!(paletteMatch.index < canvasMatch.index && canvasMatch.index < sidebarMatch.index)) {
    findings.push('Rail order must be palette / canvas / sidebar.');
  }
  const palette = source.slice(paletteMatch.index, canvasMatch.index);
  const canvas = source.slice(canvasMatch.index, sidebarMatch.index);
  const sidebar = source.slice(sidebarMatch.index);
  if (!paletteMatch[0].includes('cb-core-design-shell__palette--composed')) {
    findings.push('Palette must use public Base composed panel.');
  }
  if (!sidebarMatch[0].includes('cb-core-design-shell__sidebar--composed')) {
    findings.push('Sidebar must use public Base composed panel.');
  }
  const leftTabs = tabs(palette);
  const rightTabs = tabs(sidebar);
  const leftRoles = [];
  for (const tab of leftTabs) {
    const role = attribute(tab, 'data-cb-design-shell-palette-role');
    const panelId = attribute(tab, 'data-cb-design-shell-tab');
    if (attribute(tab, 'data-cb-design-shell-group') !== 'palette') {
      findings.push('Palette tab missing palette group: ' + panelId);
    }
    if (role === null) findings.push('Palette tab missing semantic role: ' + panelId);
    else leftRoles.push(role);
    if (!palette.includes('data-cb-design-shell-panel="' + panelId + '"')) {
      findings.push('Palette tab has no matching panel: ' + panelId);
    }
  }
  ordered(leftRoles, ['navigation', 'elements', 'layers'], 'Left palette', findings);
  if (!leftRoles.includes('elements') && source.includes('data-cb-mail-binding')) {
    findings.push('Mail bindings require Elements palette role.');
  }
  if (rightTabs.length === 0 || !rightTabs.some((tab) =>
    attribute(tab, 'data-cb-design-shell-sidebar-role') === 'inspector')) {
    findings.push('Right sidebar requires Inspector role.');
  }
  const rightRoles = [];
  for (const tab of rightTabs) {
    const role = attribute(tab, 'data-cb-design-shell-sidebar-role');
    const panelId = attribute(tab, 'data-cb-design-shell-tab');
    if (role === null) findings.push('Sidebar tab missing semantic role: ' + panelId);
    else rightRoles.push(role);
    if (!sidebar.includes('data-cb-design-shell-panel="' + panelId + '"')) {
      findings.push('Sidebar tab has no matching panel: ' + panelId);
    }
  }
  ordered(rightRoles, ['inspector', 'settings'], 'Right sidebar', findings);
  if (source.includes('data-cb-design-shell-tab="dynamic-data"')
    || source.includes('data-cb-design-shell-panel="dynamic-data"')) {
    findings.push('Dynamic Data must be a section inside Elements, not a tab.');
  }
  if (source.includes('data-cb-design-shell-sidebar-role="layers"')) {
    findings.push('Layers must be left, never a right sidebar role.');
  }
  if (source.includes('data-cb-mail-binding')) {
    const elementsPanel = palette.indexOf('data-cb-design-shell-panel="elements"');
    const tokens = palette.indexOf('data-cb-designer-dynamic-data-heading');
    const layersPanel = palette.search(/data-cb-design-shell-panel="(?:structure|layers)"/);
    if (elementsPanel < 0 || tokens < elementsPanel || (layersPanel >= 0 && tokens > layersPanel)) {
      findings.push('Dynamic Data bindings must be nested under Mail Elements.');
    }
  }
  if (mail) {
    if (canvas.includes('cb-core-design-shell__canvas-header')
      || canvas.includes('Visual canvas')) {
      findings.push('Base Mail editing canvas must be headerless.');
    }
    if (!canvas.includes('data-cb-mail-preview-frame')) {
      findings.push('Mail canvas must retain public preview boundary.');
    }
  }
  return findings;
};

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const args = process.argv.slice(2);
  const mail = args.includes('--mail');
  const paths = args.filter((arg) => arg !== '--mail');
  if (paths.length !== 1 || paths[0].startsWith('-')) {
    process.stderr.write('Usage: node tools/validate-designer-layout.mjs <consumer.php> [--mail]\n');
    process.exitCode = 2;
  } else {
    try {
      const findings = auditDesignerLayout(await readFile(resolve(paths[0]), 'utf8'), { mail });
      if (findings.length > 0) {
        for (const finding of findings) process.stderr.write('DESIGNER CONTRACT FAIL: ' + finding + '\n');
        process.exitCode = 1;
      } else {
        process.stdout.write('Designer layout SOURCE contract: PASS (' + paths[0] + '; browser NOT RUN)\n');
      }
    } catch (error) {
      process.stderr.write('DESIGNER CONTRACT ERROR: ' + String(error?.message || error) + '\n');
      process.exitCode = 2;
    }
  }
}
