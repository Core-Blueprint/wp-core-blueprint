/**
 * Core Blueprint - Per-feature description toggle
 *
 * Wires up the per-feature plain/technical "peek" toggle on `.cb-core-dual`
 * blocks (rendered by `CoreBlueprint\Core\UI` wherever a feature has both a plain and
 * a technical description). Click the toggle button and the block flips
 * between the two registers.
 *
 * Behaviour by global description-mode:
 *   - `sync`            - flip every dual block on the page in unison.
 *                         Not persisted server-side; refreshing returns
 *                         to the seeded variant. Persisting per-click
 *                         would overwrite the seed Sync depends on.
 *   - `plain` / `technical` - flip just the clicked block (peek-only,
 *                             not persisted, not propagated).
 *

 * dashboard-level description-mode handlers (radio cards, scope selector,
 * reset link, helpers `updateModeActiveClass` and `applyDescModeToPage`)
 * - all dead since the dashboard description-mode UI was replaced by the
 * Preferences > Language equivalent (handled in `features/language.js`).
 * Dropped during this migration rather than ported.
 *
 * @since   1.0.0
 */

import { qs, qsa } from '../core/dom.js';

const dataEl   = document.getElementById( 'wp-script-module-data-@cb-core/description-toggle' );
const data     = dataEl ? JSON.parse( dataEl.textContent ) : {};
const i18n     = data.i18n || {};
const descMode = data.descMode || { current: 'plain' };

const flipSingleBlock = ( block, variant ) => {
	block.setAttribute( 'data-active', variant );

	for ( const plain of qsa( '.cb-core-desc-plain', block ) ) {
		plain.hidden = ( variant !== 'plain' );
	}
	for ( const tech of qsa( '.cb-core-desc-technical', block ) ) {
		tech.hidden = ( variant !== 'technical' );
	}

	const btn = qs( '.cb-core-desc-toggle', block );
	if ( ! btn ) return;
	btn.dataset.current = variant;

	const label = qs( '.cb-core-desc-toggle-label', btn );
	if ( label ) {
		label.textContent = ( variant === 'plain' )
			? ( i18n.labelTech  || 'tech' )
			: ( i18n.labelPlain || 'plain' );
	}

	const tooltip = ( variant === 'plain' )
		? ( i18n.showTechnical || 'Show technical description' )
		: ( i18n.showPlain     || 'Show plain description' );
	btn.setAttribute( 'aria-label', tooltip );
	btn.setAttribute( 'title',      tooltip );
};

const flipEntirePage = ( variant ) => {
	for ( const block of qsa( '.cb-core-dual' ) ) {
		flipSingleBlock( block, variant );
	}
};

document.addEventListener( 'click', ( event ) => {
	const btn = event.target.closest( '.cb-core-desc-toggle' );
	if ( ! btn ) return;
	event.preventDefault();
	event.stopPropagation(); // prevent surrounding label from toggling a checkbox

	const block = btn.closest( '.cb-core-dual' );
	if ( ! block ) return;

	const current = btn.dataset.current;
	const next    = current === 'plain' ? 'technical' : 'plain';
	const mode    = descMode.current;

	if ( mode === 'sync' ) {
		flipEntirePage( next );
	} else {
		// Plain or Technical mode: peek only the clicked block.
		flipSingleBlock( block, next );
	}
} );
