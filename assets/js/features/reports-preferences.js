/**
 * Core Blueprint - Preferences → Reports Designer
 *
 * Reports owns branding values, bounded Composer semantics, preview requests
 * and persistence. Base owns Designer Mode, DesignProject/session/history,
 * toolbar, Layers presentation and responsive chrome.
 *
 * @since 1.0.0
 */

import { qs, qsa, apiPost } from '../core/dom.js';
import {
	commands,
	createDesignerInspectorControls,
	createDesignerInspectorIdentity,
	createDesignerInspectorToggle,
	createDesignerLayerTree,
	decorateDesignerElement,
	createDesignerSelectionController,
	createDesignerShell,
	createSession,
	profiles,
} from '@cb-core/design-editor';

const createFlowPreviewHost = profiles['document-flow']?.createFlowPreviewHost;
if ( typeof createFlowPreviewHost !== 'function' ) {
	throw new Error( 'Design Foundation document-flow preview host is unavailable.' );
}

const dataEl      = document.getElementById( 'wp-script-module-data-@cb-core/reports-preferences' );
const data        = dataEl ? JSON.parse( dataEl.textContent ) : {};
const i18n        = data.i18n || {};
const blockLabels = data.blockLabels || {};
const composerUi    = data.composerUi || {};
const blockSettings = data.blockSettings || {};

const FLOW_LAYOUT = Object.freeze( {
	mode: 'flow',
	units: 'mm',
	page: Object.freeze( { width: 210, height: 297 } ),
	margins: Object.freeze( { top: 18, right: 18, bottom: 18, left: 18 } ),
} );

const FORM = qs( '#cb-core-branding-form' );
if ( FORM ) {
	const shell             = qs( '[data-cb-design-shell]', FORM );
	const nonce             = FORM.dataset.nonce;
	const logoIdEl          = qs( '#cb-core-logo-id', FORM );
	const logoPreview       = qs( '#cb-core-logo-preview', FORM );
	const logoPick          = qs( '#cb-core-logo-pick', FORM );
	const logoRemove        = qs( '#cb-core-logo-remove', FORM );
	const providerNameEl    = qs( '#cb-core-provider-name', FORM );
	const providerContactEl = qs( '#cb-core-provider-contact', FORM );
	const colorEl           = qs( '#cb-core-accent-color', FORM );
	const colorHexEl        = qs( '#cb-core-accent-hex', FORM );
	const showLogoEl        = qs( '#cb-core-show-logo', FORM );
	const surfaceStyleEl    = qs( '#cb-core-report-surface-style', FORM );
	const densityEl         = qs( '#cb-core-report-density', FORM );
	const cornerStyleEl     = qs( '#cb-core-report-corner-style', FORM );
	const textScaleEl       = qs( '#cb-core-report-text-scale', FORM );
	const saveBtn           = qs( '#cb-core-save-branding', FORM );
	const resetBtn          = qs( '#cb-core-reset-branding', FORM );
	const previewFrame      = qs( '[data-cb-report-preview]', FORM );
	const previewState      = qs( '[data-cb-report-preview-state]', FORM );
	const elementsBody      = qs( '[data-cb-report-elements]', FORM );
	const layersBody        = qs( '[data-cb-report-layers]', FORM );
	const inspectorBody     = qs( '[data-cb-report-inspector]', FORM );

	const normalizeBlockSettings = ( type, raw ) => {
		const definitions = blockSettings[ type ] || {};
		const source = raw && typeof raw === 'object' && ! Array.isArray( raw ) ? raw : {};
		return Object.fromEntries(
			Object.keys( definitions ).map( ( key ) => [ key, source[ key ] !== false ] )
		);
	};

	const normalizeClientTemplate = ( raw ) => ( {
		schema_version: Number( raw?.schema_version ) || 1,
		blocks: Array.isArray( raw?.blocks )
			? raw.blocks
				.filter( ( block ) => block && typeof block.type === 'string' )
				.map( ( block ) => ( {
					id: block.id || block.type,
					type: block.type,
					enabled: block.enabled !== false,
					settings: normalizeBlockSettings( block.type, block.settings ),
				} ) )
			: [],
	} );

	const projectFromComposer = ( template ) => ( {
		schema_version: 1,
		design_type: 'reports.maintenance',
		root: {
			type: 'reports.maintenance',
			provider: 'core',
			properties: { layout: FLOW_LAYOUT },
			children: template.blocks.map( ( block ) => ( {
				type: `reports.block.${ block.type }`,
				provider: 'core',
				properties: {
					id: block.id || block.type,
					type: block.type,
					enabled: block.enabled !== false,
					settings: { ...( block.settings || {} ) },
				},
				children: [],
			} ) ),
		},
	} );

	const composerFromProject = ( project, schemaVersion ) => ( {
		schema_version: Number( schemaVersion ) || 1,
		blocks: Array.isArray( project?.root?.children )
			? project.root.children
				.filter( ( node ) => node && typeof node?.properties?.type === 'string' )
				.map( ( node ) => ( {
					id: node.properties.id || node.properties.type,
					type: node.properties.type,
					enabled: node.properties.enabled !== false,
					settings: normalizeBlockSettings( node.properties.type, node.properties.settings ),
				} ) )
			: [],
	} );

	let composer = normalizeClientTemplate( data.composer || {} );
	let syncDirtyIndicator = () => {};
	let serverRevision = String( FORM.dataset.revision || '' );
	let saveInFlight = false;
	let saveUncertain = false;
	let brandingBaseline = null;
	const validRevision = ( value ) => value === 'legacy'
		|| ( typeof value === 'string' && /^(0|[1-9][0-9]*)$/.test( value ) );
	const brandSignature = ( input ) => JSON.stringify( {
		logo_attachment_id: Number( input?.logo_attachment_id || 0 ),
		show_logo: input?.show_logo === true || input?.show_logo === '1',
		provider_name: String( input?.provider_name || '' ),
		provider_contact: String( input?.provider_contact || '' ),
		accent_color: String( input?.accent_color || '' ).toLowerCase(),
		surface_style: String( input?.surface_style || 'cards' ),
		density: String( input?.density || 'comfortable' ),
		corner_style: String( input?.corner_style || 'soft' ),
		text_scale: String( input?.text_scale || 'standard' ),
	} );

	const composerSchemaVersion = composer.schema_version;
	const elementTypes = Object.keys( blockLabels ).length
		? Object.keys( blockLabels )
		: composer.blocks.map( ( block ) => block.type );

	let layerTree = null;
	let inspectorIdentity = null;
	let inspectorControls = null;
	let mediaFrame = null;
	let previewTimer = null;
	let previewSequence = 0;
	let previewHost = null;
	let previewRecoveryAttempted = false;
	let designerShell = null;
	let selectionController = null;

	const setSaveState = ( state, message = '' ) => {
		if ( ! shell ) return;
		shell.dispatchEvent( new CustomEvent( 'cb:design-shell:savechange', { detail: { state, message } } ) );
	};

	const setPreviewState = ( message, kind = 'pending' ) => {
		if ( ! previewState ) return;
		previewState.textContent = message;
		previewState.dataset.kind = kind;
		previewState.hidden = message === '';
		if ( kind === 'error' && message ) window.cbCore?.toast?.error?.( message );
	};

	const ensurePreviewHost = () => {
		if ( ! previewFrame ) return null;
		if ( previewHost ) return previewHost;
		previewFrame.hidden = false;
		previewHost = createFlowPreviewHost( previewFrame, {
			onFirstSizeTimeout: () => {
				// A fresh iframe can miss its first layout handshake while Designer enters.
				// Retry at most once per opening; invalid HTML and AJAX failures never retry.
				if ( previewRecoveryAttempted || ! designerShell?.isFullscreen() ) return;
				previewRecoveryAttempted = true;
				schedulePreview();
			},
			onActivate: ( type ) => {
				// An opaque iframe may only select a currently visible Composer block.
				if ( ! composer.blocks.some( ( block ) => block.type === type && block.enabled !== false ) ) return;
				selectBlock( type, { source: 'canvas' } );
			},
		} );
		return previewHost;
	};

	const requestErrorMessage = ( error, fallback ) => {
		if ( error instanceof TypeError ) {
			return i18n.networkError || 'Network error - try again.';
		}
		const message = error instanceof Error ? String( error.message || '' ).trim() : '';
		if ( ! message || error instanceof SyntaxError || /^HTTP \d+$/.test( message ) ) {
			return fallback;
		}
		return message;
	};

	const blockLabel = ( type ) => blockLabels[ type ] || type.replaceAll( '_', ' ' );
	const selectedIndex = () => {
		const primary = session.selection().primary;
		return Array.isArray( primary ) && primary.length === 1 && Number.isInteger( primary[0] )
			? primary[0]
			: -1;
	};
	const selectedBlock = () => {
		const index = selectedIndex();
		return index >= 0 ? ( composer.blocks[ index ] || null ) : null;
	};
	const isStructuralBlock = ( block ) => block?.type === 'header' || block?.type === 'footer';
	const syncPreviewSelection = () => {
		const selected = selectedBlock();
		previewHost?.setSelection( selected && selected.enabled !== false ? selected.type : null );
	};
	const schedulePreview = () => {
		window.clearTimeout( previewTimer );
		previewTimer = window.setTimeout( renderPreview, 180 );
	};

	const session = createSession( {
		project: projectFromComposer( composer ),
		profile: 'document-flow',
		onChange: ( project ) => {
			composer = composerFromProject( project, composerSchemaVersion );
			renderComposerControls();
			syncPreviewSelection();
			schedulePreview();
			syncDirtyIndicator();
		},
		allowCommand: ( command ) => {
			if ( command?.label === 'remove-node' || command?.label === 'insert-node' ) return false;
			return true;
		},
	} );

	designerShell = shell ? createDesignerShell( shell, { session } ) : null;

	const selectBlock = ( type, { openInspector = true, source = 'ui' } = {} ) => {
		const index = composer.blocks.findIndex( ( block ) => block.type === type );
		if ( index < 0 || ! selectionController ) return false;
		return selectionController.select( [ index ], { openInspector, source } );
	};

	const buildComposerControls = () => {
		if ( layersBody ) {
			const section = document.createElement( 'section' );
			section.className = 'cb-core-design-shell__panel-section';

			layerTree = createDesignerLayerTree( {
				documentRef: document,
				ariaLabel: 'Layers',
				onSelectItem: ( path, options ) => selectionController?.select( path, options ),
			} );
			layerTree.element.dataset.cbReportLayerList = '';
			section.append( layerTree.element );
			layersBody.append( section );
		}

		if ( inspectorBody ) {
			const section = document.createElement( 'section' );
			section.className = 'cb-core-design-shell__panel-section';
			section.dataset.cbReportBlockInspector = '';

			inspectorIdentity = createDesignerInspectorIdentity( { documentRef: document } );
			inspectorControls = createDesignerInspectorControls( { documentRef: document } );
			inspectorControls.dataset.cbReportBlockSettings = '';

			section.append( inspectorIdentity.element, inspectorControls );
			inspectorBody.append( section );
		}
	};

	const renderElements = () => {
		if ( ! elementsBody ) return;
		elementsBody.replaceChildren();
		elementTypes.forEach( ( type ) => {
			const block = composer.blocks.find( ( candidate ) => candidate.type === type );
			if ( ! block ) return;
			const button = document.createElement( 'button' );
			button.type = 'button';
			button.className = 'cb-core-design-shell__palette-item';
			button.dataset.cbReportElement = type;
			button.setAttribute( 'aria-pressed', type === selectedBlock()?.type ? 'true' : 'false' );
			button.textContent = blockLabel( type );
			decorateDesignerElement( button, type, { label: blockLabel( type ) } );
			button.addEventListener( 'click', () => selectBlock( type ) );
			elementsBody.append( button );
		} );
	};

	const renderLayers = () => {
		if ( ! layerTree ) return;

		layerTree.render( composer.blocks.map( ( block, index ) => {
			const structural = isStructuralBlock( block );
			return {
				key: block.type,
				path: [ index ],
				label: blockLabel( block.type ),
				meta: block.enabled !== false
					? ( composerUi.visible || 'Visible' )
					: ( composerUi.hidden || 'Hidden' ),
				selected: index === selectedIndex(),
				dataset: { cbReportLayer: block.type },
				reorder: structural ? null : {
					index,
					minIndex: 1,
					maxIndex: composer.blocks.length - 2,
					onMove: ( targetIndex ) => moveBlock( block.type, targetIndex - index ),
				},
				children: [],
			};
		} ) );
	};

	const renderInspector = () => {
		const selected = selectedBlock();
		inspectorControls?.replaceChildren();

		if ( ! selected ) {
			inspectorIdentity?.setLabel( '' );
			return;
		}

		inspectorIdentity?.setLabel( blockLabel( selected.type ) );
		if ( ! inspectorControls ) return;

		const index = selectedIndex();
		const enabled = createDesignerInspectorToggle( {
			label: composerUi.visible || 'Visible',
			checked: selected.enabled !== false,
			disabled: isStructuralBlock( selected ),
			documentRef: document,
			dataset: { cbReportBlockEnabled: '' },
		} );
		enabled.control.addEventListener( 'change', () => {
			if ( index < 0 || isStructuralBlock( selected ) ) return;
			session.execute( commands.setProperty( [ index ], [ 'enabled' ], enabled.control.checked ) );
		} );
		inspectorControls.append( enabled.element );

		const definitions = blockSettings[ selected.type ] || {};
		Object.entries( definitions ).forEach( ( [ key, label ] ) => {
			const setting = createDesignerInspectorToggle( {
				label,
				checked: selected.settings?.[ key ] !== false,
				documentRef: document,
				dataset: { cbReportBlockSetting: key },
			} );
			setting.control.addEventListener( 'change', () => {
				if ( index < 0 ) return;
				session.execute( commands.setProperty( [ index ], [ 'settings', key ], setting.control.checked ) );
			} );
			inspectorControls.append( setting.element );
		} );
	};

	const renderComposerControls = () => {
		renderElements();
		renderLayers();
		renderInspector();
	};

	const applyComposer = ( next ) => {
		if ( ! next || ! Array.isArray( next.blocks ) ) return;
		const previousType = selectedBlock()?.type || '';
		composer = normalizeClientTemplate( next );
		session.replace( projectFromComposer( composer ), { source: 'server', resetSelection: true } );

		const preferredIndex = composer.blocks.findIndex( ( block ) => block.type === previousType );
		const nextIndex = preferredIndex >= 0 ? preferredIndex : ( composer.blocks.length ? 0 : -1 );
		if ( nextIndex >= 0 ) {
			selectionController?.select( [ nextIndex ], { openInspector: false, source: 'server' } );
		}
		designerShell?.syncHistory();
	};

	function moveBlock( type, direction ) {
		const block = composer.blocks.find( ( candidate ) => candidate.type === type );
		if ( ! block || isStructuralBlock( block ) ) return false;

		const index = composer.blocks.findIndex( ( candidate ) => candidate.type === type );
		const target = index + direction;
		if ( target < 1 || target > composer.blocks.length - 2 ) return false;

		selectionController?.select( [ index ], { openInspector: false, source: 'layers' } );
		session.execute( commands.reorderNode( [], index, target ) );
		return true;
	}

	const updateLogoPreview = ( url ) => {
		if ( ! logoPreview ) return;

		logoPreview.replaceChildren();
		if ( url ) {
			const image = document.createElement( 'img' );
			image.src = url;
			image.alt = '';
			logoPreview.append( image );
			logoPreview.dataset.hasLogo = 'yes';
			if ( logoRemove ) logoRemove.hidden = false;
			if ( logoPick ) logoPick.textContent = i18n.brandingChangeLogo || 'Change logo';
			return;
		}

		const empty = document.createElement( 'span' );
		empty.textContent = i18n.brandingNoLogo || 'No logo set';
		logoPreview.append( empty );
		logoPreview.dataset.hasLogo = 'no';
		if ( logoRemove ) logoRemove.hidden = true;
		if ( logoPick ) logoPick.textContent = i18n.brandingSelectLogo || 'Select logo';
	};

	const brandingPayload = () => ( {
		logo_attachment_id: logoIdEl?.value || 0,
		show_logo: showLogoEl?.checked ?? true,
		provider_name: providerNameEl?.value || '',
		provider_contact: providerContactEl?.value || '',
		accent_color: colorHexEl?.value || colorEl?.value || '',
		surface_style: surfaceStyleEl?.value || 'cards',
		density: densityEl?.value || 'comfortable',
		corner_style: cornerStyleEl?.value || 'soft',
		text_scale: textScaleEl?.value || 'standard',
	} );

	const reportsPayload = () => ( {
		...brandingPayload(),
		template: JSON.stringify( composer ),
	} );
	brandingBaseline = brandSignature( brandingPayload() );
	const dirtyIndicator = document.createElement( 'span' );
	dirtyIndicator.className = 'cb-core-reports-designer__dirty';
	dirtyIndicator.setAttribute( 'role', 'status' );
	dirtyIndicator.textContent = 'Unsaved report design changes';
	dirtyIndicator.hidden = true;
	syncDirtyIndicator = () => {
		const dirty = saveInFlight || saveUncertain
			|| brandSignature( brandingPayload() ) !== brandingBaseline
			|| JSON.stringify( session.snapshot() ) !== JSON.stringify( session.baselineSnapshot() );
		dirtyIndicator.hidden = ! dirty;
	};
	saveBtn?.insertAdjacentElement( 'afterend', dirtyIndicator );
	FORM.addEventListener( 'input', syncDirtyIndicator );
	FORM.addEventListener( 'change', syncDirtyIndicator );
	window.addEventListener( 'beforeunload', ( event ) => {
		if ( dirtyIndicator.hidden ) return;
		event.preventDefault();
		event.returnValue = '';
	} );

	const applyBranding = ( values ) => {
		if ( logoIdEl && values.logo_attachment_id !== undefined ) {
			logoIdEl.value = String( values.logo_attachment_id ?? 0 );
		}
		if ( providerNameEl && values.provider_name !== undefined ) {
			providerNameEl.value = values.provider_name ?? '';
		}
		if ( providerContactEl && values.provider_contact !== undefined ) {
			providerContactEl.value = values.provider_contact ?? '';
		}
		if ( values.accent_color ) {
			if ( colorEl ) colorEl.value = values.accent_color;
			if ( colorHexEl ) colorHexEl.value = values.accent_color;
		}
		if ( showLogoEl && values.show_logo !== undefined ) {
			showLogoEl.checked = values.show_logo !== false;
		}
		if ( surfaceStyleEl && values.surface_style ) surfaceStyleEl.value = values.surface_style;
		if ( densityEl && values.density ) densityEl.value = values.density;
		if ( cornerStyleEl && values.corner_style ) cornerStyleEl.value = values.corner_style;
		if ( textScaleEl && values.text_scale ) textScaleEl.value = values.text_scale;
		if ( values.logo_url !== undefined ) {
			updateLogoPreview( values.logo_url || '' );
		}
	};

	async function renderPreview() {
		if ( ! nonce || ! previewFrame ) return;

		const requestSequence = ++previewSequence;
		if ( previewHost ) {
			setPreviewState( '', 'pending' );
		} else {
			setPreviewState( i18n.previewLoading || 'Rendering report preview…', 'pending' );
		}

		try {
			const response = await apiPost( 'cb_core_preview_report_branding', nonce, reportsPayload() );
			if ( requestSequence !== previewSequence ) return;

			if ( ! response?.success || ! response.data?.html ) {
				setPreviewState(
					response?.data?.message || i18n.previewFailed || 'The report preview could not be rendered.',
					'error'
				);
				return;
			}

			const host = ensurePreviewHost();
			if ( ! host ) {
				setPreviewState( 'The report preview host is unavailable. Reload the page.', 'error' );
				return;
			}
			if ( host.render( response.data.html ) !== true ) {
				// The shared opaque preview host rejected the HTML/protocol.
				// Its visible failure state must not be disguised as success.
				setPreviewState( i18n.previewFailed || 'The report preview could not be rendered.', 'error' );
				return;
			}
			syncPreviewSelection();
			setPreviewState( '', 'success' );
		} catch ( error ) {
			if ( requestSequence !== previewSequence ) return;
			setPreviewState(
				requestErrorMessage( error, i18n.previewFailed || 'The report preview could not be rendered.' ),
				'error'
			);
		}
	}

	buildComposerControls();
	selectionController = createDesignerSelectionController( {
		session,
		shell: designerShell,
		renderLayers: () => {
			renderElements();
			renderLayers();
		},
		renderInspector,
		syncCanvas: syncPreviewSelection,
	} );
	if ( composer.blocks.length ) {
		selectionController.select( [ 0 ], { openInspector: false, source: 'initial' } );
	}
	designerShell?.syncHistory();

	colorEl?.addEventListener( 'input', ( event ) => {
		const hex = event.target.value;
		if ( colorHexEl ) colorHexEl.value = hex;
		schedulePreview();
	} );

	colorHexEl?.addEventListener( 'input', ( event ) => {
		const hex = event.target.value;
		if ( /^#[0-9a-fA-F]{6}$/.test( hex ) ) {
			if ( colorEl ) colorEl.value = hex;
			schedulePreview();
		}
	} );

	providerNameEl?.addEventListener( 'input', schedulePreview );
	providerContactEl?.addEventListener( 'input', schedulePreview );
	showLogoEl?.addEventListener( 'change', schedulePreview );
	surfaceStyleEl?.addEventListener( 'change', schedulePreview );
	densityEl?.addEventListener( 'change', schedulePreview );
	cornerStyleEl?.addEventListener( 'change', schedulePreview );
	textScaleEl?.addEventListener( 'change', schedulePreview );

	logoPick?.addEventListener( 'click', ( event ) => {
		event.preventDefault();
		if ( ! window.wp?.media ) {
			setPreviewState( i18n.brandingMediaUnavailable || 'Media Library not available - reload the page.', 'error' );
			return;
		}

		if ( ! mediaFrame ) {
			mediaFrame = window.wp.media( {
				title: i18n.brandingPickerTitle || 'Select logo',
				button: { text: i18n.brandingPickerButton || 'Use this image' },
				multiple: false,
				library: { type: 'image' },
			} );

			mediaFrame.on( 'select', () => {
				const attachment = mediaFrame.state().get( 'selection' ).first()?.toJSON();
				if ( ! attachment?.id ) return;

				if ( logoIdEl ) logoIdEl.value = String( attachment.id );
				const url = attachment.sizes?.medium?.url || attachment.sizes?.full?.url || attachment.url || '';
				updateLogoPreview( url );
				schedulePreview();
			} );
		}

		mediaFrame.open();
	} );

	logoRemove?.addEventListener( 'click', ( event ) => {
		event.preventDefault();
		if ( logoIdEl ) logoIdEl.value = '0';
		updateLogoPreview( '' );
		schedulePreview();
	} );


	const saveReceipt = ( response, submitted, reset = false ) => {
		const payload = response?.data;
		const doc = payload?.document;
		if ( response?.success !== true || !doc
			|| !validRevision( payload?.revision )
			|| !doc?.branding || !doc?.composer?.maintenance
			|| ( serverRevision !== 'legacy' && payload.revision === 'legacy' ) ) {
			throw new Error( 'Reports server acknowledgement is incomplete. Reload to verify.' );
		}
		if ( ! reset && (
			brandSignature( doc.branding ) !== brandSignature( submitted.branding )
			|| JSON.stringify( doc.composer.maintenance ) !== JSON.stringify( submitted.composer )
		) ) {
			throw new Error( 'Reports save response differs from the submitted state. Reload to verify.' );
		}
		// Only the Base session may reconcile Designer history. Existing and
		// in-flight Undo/Redo must survive, or rebase rejects the acknowledgement.
		session.rebase( {
			saveToken: submitted.checkpoint.token,
			baselineProject: projectFromComposer( doc.composer.maintenance ),
		} );
		serverRevision = payload.revision;
		brandingBaseline = brandSignature( doc.branding );
		syncDirtyIndicator();
		return doc;
	};

	const captureRequest = () => {
		if ( saveInFlight || saveUncertain || !validRevision( serverRevision ) ) {
			throw new Error( 'Reports save requires a fresh verified editor revision.' );
		}
		const checkpoint = session.captureSave();
		const branding = brandingPayload();
		const composerSnapshot = composerFromProject( checkpoint.submittedProject, composerSchemaVersion );
		return Object.freeze( {
			checkpoint,
			branding: Object.freeze( branding ),
			composer: composerSnapshot,
			revision: serverRevision,
		} );
	};

	saveBtn?.addEventListener( 'click', async () => {
		if ( ! nonce ) {
			setSaveState( 'error', i18n.reportsNonceMissing || 'Nonce missing - reload the page.' );
			return;
		}
		const hex = colorHexEl?.value || colorEl?.value || '';
		if ( ! /^#[0-9a-fA-F]{6}$/.test( hex ) ) {
			setSaveState( 'error', i18n.brandingInvalidHex || 'Invalid accent colour.' );
			return;
		}
		let submitted;
		try {
			submitted = captureRequest();
		} catch ( error ) {
			setSaveState( 'error', error.message );
			return;
		}
		saveInFlight = true;
		syncDirtyIndicator();
		setSaveState( 'saving' );
		saveBtn.disabled = true;
		if ( resetBtn ) resetBtn.disabled = true;
		try {
			const response = await apiPost( 'cb_core_save_report_branding', nonce, {
				...submitted.branding,
				template: JSON.stringify( submitted.composer ),
				expected_revision: submitted.revision,
			} );
			saveReceipt( response, submitted );
			setSaveState( 'saved' );
			await renderPreview();
		} catch ( error ) {
			// A failed or lost response may still follow a committed server write.
			saveUncertain = true;
			setSaveState( 'error', requestErrorMessage( error, 'Reports save outcome requires a reload.' ) );
		} finally {
			saveInFlight = false;
			syncDirtyIndicator();
			saveBtn.disabled = false;
			if ( resetBtn ) resetBtn.disabled = false;
		}
	} );

	resetBtn?.addEventListener( 'click', async () => {
		if ( ! nonce || saveInFlight || saveUncertain ) return;
		const modal = window.cbCore?.modal;
		if ( typeof modal?.show !== 'function' ) return;
		const confirmed = await modal.show( {
			title: i18n.brandingConfirmResetTitle || 'Reset report settings?',
			body: i18n.brandingConfirmReset || 'Restore Reports design defaults?',
			confirmLabel: i18n.brandingConfirmResetConfirm || 'Reset to defaults',
			confirmVariant: 'danger',
		} );
		if ( ! confirmed ) return;
		let submitted;
		try {
			submitted = captureRequest();
		} catch ( error ) {
			setSaveState( 'error', error.message );
			return;
		}
		saveInFlight = true;
		setSaveState( 'saving' );
		resetBtn.disabled = true;
		if ( saveBtn ) saveBtn.disabled = true;
		try {
			const response = await apiPost( 'cb_core_reset_report_branding', nonce, {
				expected_revision: submitted.revision,
			} );
			const currentBranding = brandSignature( brandingPayload() );
			const doc = saveReceipt( response, submitted, true );
			// Only update form inputs if the operator made no newer changes.
			if ( brandSignature( submitted.branding ) === currentBranding ) {
				applyBranding( { ...doc.branding, logo_url: response.data?.logo_url || '' } );
			}
			setSaveState( 'saved' );
			await renderPreview();
		} catch ( error ) {
			saveUncertain = true;
			setSaveState( 'error', requestErrorMessage( error, 'Reports reset requires reload to reconcile history.' ) );
		} finally {
			saveInFlight = false;
			syncDirtyIndicator();
			resetBtn.disabled = false;
			if ( saveBtn ) saveBtn.disabled = false;
		}
	} );
	shell?.addEventListener( 'cb:design-shell:fullscreenchange', ( event ) => {
		if ( event.detail?.fullscreen === true ) schedulePreview();
		else previewRecoveryAttempted = false;
	} );
	if ( designerShell?.isFullscreen() ) schedulePreview();

	window.addEventListener( 'beforeunload', () => {
		previewHost?.destroy();
		selectionController?.destroy();
		session.dispose();
	}, { once: true } );
}