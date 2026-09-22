/**
 * Core Blueprint Snippets admin glue.
 * Native DOM + WordPress Code Editor only; no framework, no telemetry.
 */
( () => {
	'use strict';

	const data = window.cbCoreSnippetsData || {};
	const locations = data.locations || {};
	const i18n = data.i18n || {};

	const typeSelect = document.getElementById( 'cb-snippet-type' );
	const locationSelect = document.getElementById( 'cb-snippet-location' );
	const shortcodeField = document.querySelector( '.cb-snippets-shortcode-field' );
	const codeTextarea = document.getElementById( 'cb-snippet-code' );
	const editorForm = document.querySelector( '.cb-snippets-editor' );
	const preserveIds = document.querySelector( '[data-cb-snippets-preserve-ids]' );
	const restoreAcknowledgementTemplate = document.getElementById( 'cb-snippets-restore-acknowledgement-template' );
	const restoreAcknowledgementHost = document.querySelector( '[data-cb-snippets-restore-acknowledgement]' );
	const draftKey = 'cb-core-snippets-editor-draft';
	const draftMaxAge = 5 * 60 * 1000;
	let editor = null;

	const modeForType = ( type ) => {
		switch ( type ) {
			case 'css': return 'text/css';
			case 'js': return 'text/javascript';
			case 'html': return 'text/html';
			default: return 'text/x-php';
		}
	};

	const updateShortcodeVisibility = () => {
		if ( ! shortcodeField || ! locationSelect ) return;
		shortcodeField.hidden = locationSelect.value !== 'shortcode';
	};

	const replaceLocationOptions = ( type, preferred = '' ) => {
		if ( ! locationSelect ) return;
		const options = locations[ type ] || {};
		locationSelect.replaceChildren();

		Object.entries( options ).forEach( ( [ value, label ] ) => {
			const option = document.createElement( 'option' );
			option.value = value;
			option.textContent = label;
			locationSelect.appendChild( option );
		} );

		if ( Object.prototype.hasOwnProperty.call( options, preferred ) ) {
			locationSelect.value = preferred;
		}
	};

	const rebuildLocations = () => {
		if ( ! typeSelect || ! locationSelect ) return;
		const type = typeSelect.value;
		const previous = locationSelect.value;
		replaceLocationOptions( type, previous );

		if ( editor?.codemirror ) {
			editor.codemirror.setOption( 'mode', modeForType( type ) );
		}
		updateShortcodeVisibility();
	};

	const clearRecoveryDraft = () => {
		try {
			window.sessionStorage.removeItem( draftKey );
		} catch ( error ) {
			// Storage can be disabled by browser/privacy policy; recovery is optional.
		}
	};

	const restoreRecoveryDraft = () => {
		if ( ! editorForm ) return;

		// A recovery draft is relevant only after the server returned a save
		// error. Success/normal renders immediately discard any stale tab copy.
		const saveError = document.querySelector( '.cb-snippets-wrap > .notice.notice-error.inline' );
		if ( ! saveError ) {
			clearRecoveryDraft();
			return;
		}

		let draft;
		try {
			const raw = window.sessionStorage.getItem( draftKey );
			draft = raw ? JSON.parse( raw ) : null;
		} catch ( error ) {
			clearRecoveryDraft();
			return;
		}

		if ( ! draft || ! Number.isFinite( draft.savedAt ) || Date.now() - draft.savedAt > draftMaxAge || ! Array.isArray( draft.controls ) ) {
			clearRecoveryDraft();
			return;
		}

		const snippetId = editorForm.querySelector( '[name="snippet_id"]' )?.value || '';
		if ( String( draft.snippetId || '' ) !== snippetId ) {
			clearRecoveryDraft();
			return;
		}

		// Restore the type first, then rebuild its valid location options before
		// restoring the selected location. This keeps recovery correct when a
		// failed save changed both fields at once.
		const savedType = draft.controls.find( ( saved ) => saved?.name === 'type' );
		if ( typeSelect && savedType ) {
			typeSelect.value = String( savedType.value ?? typeSelect.value );
		}
		const savedLocation = draft.controls.find( ( saved ) => saved?.name === 'location' );
		if ( typeSelect && locationSelect ) {
			replaceLocationOptions( typeSelect.value, String( savedLocation?.value ?? '' ) );
		}

		draft.controls.forEach( ( saved ) => {
			if ( ! saved || typeof saved.name !== 'string' || saved.name === 'location' ) return;
			const controls = Array.from( editorForm.elements ).filter( ( control ) => control.name === saved.name );
			controls.forEach( ( control ) => {
				if ( control.type === 'checkbox' || control.type === 'radio' ) {
					if ( String( control.value ) === String( saved.value ) ) {
						control.checked = Boolean( saved.checked );
					}
				} else if ( controls.length === 1 ) {
					control.value = String( saved.value ?? '' );
				}
			} );
		} );

		updateShortcodeVisibility();

		// The copy has served its one recovery purpose. A subsequent submit will
		// create a fresh copy immediately before navigation if needed again.
		clearRecoveryDraft();
	};

	const storeRecoveryDraft = () => {
		if ( ! editorForm ) return;
		editor?.codemirror?.save?.();

		const controls = Array.from( editorForm.elements )
			.filter( ( control ) => control.name && ! [ '_wpnonce', '_wp_http_referer', 'action' ].includes( control.name ) )
			.map( ( control ) => ( {
				name: control.name,
				value: control.value,
				checked: control.type === 'checkbox' || control.type === 'radio' ? control.checked : undefined,
			} ) );

		try {
			window.sessionStorage.setItem( draftKey, JSON.stringify( {
				savedAt: Date.now(),
				snippetId: editorForm.querySelector( '[name="snippet_id"]' )?.value || '',
				controls,
			} ) );
		} catch ( error ) {
			// The save itself must never depend on optional browser recovery state.
		}
	};

	// Restore before CodeMirror initializes so it starts from the recovered
	// textarea value rather than needing a second editor synchronization pass.
	restoreRecoveryDraft();

	if ( codeTextarea && window.wp?.codeEditor?.initialize ) {
		const settings = data.editor && typeof data.editor === 'object'
			? JSON.parse( JSON.stringify( data.editor ) )
			: {};
		settings.codemirror = settings.codemirror || {};
		settings.codemirror.mode = modeForType( typeSelect?.value || 'php' );
		settings.codemirror.lineNumbers = true;
		settings.codemirror.indentUnit = 4;
		settings.codemirror.tabSize = 4;
		settings.codemirror.indentWithTabs = true;
		settings.codemirror.lineWrapping = false;
		editor = window.wp.codeEditor.initialize( codeTextarea, settings );
	}

	editorForm?.addEventListener( 'submit', storeRecoveryDraft );
	typeSelect?.addEventListener( 'change', rebuildLocations );
	locationSelect?.addEventListener( 'change', updateShortcodeVisibility );
	updateShortcodeVisibility();

	const syncRestoreAcknowledgement = () => {
		if ( ! preserveIds || ! restoreAcknowledgementTemplate || ! restoreAcknowledgementHost ) return;
		restoreAcknowledgementHost.replaceChildren();
		if ( preserveIds.checked ) {
			restoreAcknowledgementHost.appendChild( restoreAcknowledgementTemplate.content.cloneNode( true ) );
		}
	};
	if ( preserveIds ) {
		preserveIds.addEventListener( 'change', syncRestoreAcknowledgement );
		syncRestoreAcknowledgement();
	}

	// Destructive row action uses the suite modal when available. The browser
	// confirm fallback exists only for partial/failed asset loads.
	document.addEventListener( 'submit', async ( event ) => {
		const form = event.target.closest( 'form[data-cb-snippet-delete="1"]' );
		if ( ! form || form.dataset.cbConfirmed === '1' ) return;
		event.preventDefault();

		const title = form.dataset.snippetTitle || '';
		let confirmed = false;
		if ( window.cbCore?.modal?.show ) {
			confirmed = await window.cbCore.modal.show( {
				title: i18n.deleteTitle || 'Delete snippet?',
				body: title ? `${ title }\n\n${ i18n.deleteBody || '' }` : ( i18n.deleteBody || '' ),
				confirmLabel: i18n.delete || 'Delete snippet',
				cancelLabel: i18n.cancel || 'Cancel',
				confirmVariant: 'danger',
			} );
		} else {
			confirmed = window.confirm( i18n.deleteBody || 'Delete this snippet?' );
		}

		if ( confirmed ) {
			form.dataset.cbConfirmed = '1';
			form.submit();
		}
	} );
} )();
