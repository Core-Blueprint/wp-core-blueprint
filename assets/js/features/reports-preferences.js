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
	createDesignerShell,
	createSession,
	commands,
	decorateDesignerControl,
} from '@cb-core/design-editor';

const dataEl      = document.getElementById( 'wp-script-module-data-@cb-core/reports-preferences' );
const data        = dataEl ? JSON.parse( dataEl.textContent ) : {};
const i18n        = data.i18n || {};
const blockLabels = data.blockLabels || {};
const composerUi  = data.composerUi || {};

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
	const saveBtn           = qs( '#cb-core-save-branding', FORM );
	const resetBtn          = qs( '#cb-core-reset-branding', FORM );
	const previewFrame      = qs( '[data-cb-report-preview]', FORM );
	const previewState      = qs( '[data-cb-report-preview-state]', FORM );
	const elementsBody      = qs( '[data-cb-report-elements]', FORM );
	const layersBody        = qs( '[data-cb-report-layers]', FORM );
	const inspectorBody     = qs( '[data-cb-report-inspector]', FORM );
	const previewHostApi    = window.CBBase?.Document?.FlowPreview;
	const previewHost       = previewFrame && typeof previewHostApi?.createHost === 'function'
		? previewHostApi.createHost( previewFrame )
		: null;

	const normalizeClientTemplate = ( raw ) => ( {
		schema_version: Number( raw?.schema_version ) || 1,
		blocks: Array.isArray( raw?.blocks )
			? raw.blocks
				.filter( ( block ) => block && typeof block.type === 'string' )
				.map( ( block ) => ( {
					id: block.id || block.type,
					type: block.type,
					enabled: block.enabled !== false,
					settings: {},
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
					settings: {},
				} ) )
			: [],
	} );

	let composer = normalizeClientTemplate( data.composer || {} );
	const composerSchemaVersion = composer.schema_version;
	const elementTypes = Object.keys( blockLabels ).length
		? Object.keys( blockLabels )
		: composer.blocks.map( ( block ) => block.type );

	let selectedBlockType = composer.blocks[0]?.type || '';
	let layerList = null;
	let inspectorTitle = null;
	let enabledControl = null;
	let mediaFrame = null;
	let previewTimer = null;
	let previewSequence = 0;
	let designerShell = null;

	const setSaveState = ( state ) => {
		if ( ! shell ) return;
		shell.dispatchEvent( new CustomEvent( 'cb:design-shell:savechange', { detail: { state } } ) );
	};

	const setPreviewState = ( message, kind = 'pending' ) => {
		if ( ! previewState ) return;
		previewState.textContent = message;
		previewState.dataset.kind = kind;
		previewState.hidden = message === '';
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
	const selectedBlock = () => composer.blocks.find( ( block ) => block.type === selectedBlockType ) || null;
	const selectedIndex = () => composer.blocks.findIndex( ( block ) => block.type === selectedBlockType );
	const isStructuralBlock = ( block ) => block?.type === 'header' || block?.type === 'footer';
	const schedulePreview = () => {
		window.clearTimeout( previewTimer );
		previewTimer = window.setTimeout( renderPreview, 180 );
	};

	const session = createSession( {
		project: projectFromComposer( composer ),
		profile: 'document-flow',
		onChange: ( project ) => {
			composer = composerFromProject( project, composerSchemaVersion );
			if ( ! composer.blocks.some( ( block ) => block.type === selectedBlockType ) ) {
				selectedBlockType = composer.blocks[0]?.type || '';
			}
			renderComposerControls();
			schedulePreview();
		},
		allowCommand: ( command ) => {
			if ( command?.label === 'remove-node' || command?.label === 'insert-node' ) return false;
			return true;
		},
	} );

	designerShell = shell ? createDesignerShell( shell, { session } ) : null;

	const selectBlock = ( type, { openInspector = true } = {} ) => {
		const index = composer.blocks.findIndex( ( block ) => block.type === type );
		if ( index < 0 ) return false;
		selectedBlockType = type;
		session.editorState.selection.select( [ index ] );
		renderComposerControls();
		if ( openInspector ) designerShell?.activatePanel( 'inspector' );
		return true;
	};

	const buildComposerControls = () => {
		if ( layersBody ) {
			const section = document.createElement( 'section' );
			section.className = 'cb-core-design-shell__panel-section';

			layerList = document.createElement( 'div' );
			layerList.className = 'cb-core-design-shell__layer-list';
			layerList.dataset.cbReportLayerList = '';
			section.append( layerList );
			layersBody.append( section );
		}

		if ( inspectorBody ) {
			const section = document.createElement( 'section' );
			section.className = 'cb-core-design-shell__panel-section';
			section.dataset.cbReportBlockInspector = '';

			inspectorTitle = document.createElement( 'h3' );
			inspectorTitle.className = 'cb-core-design-shell__panel-section-title';

			const enabledField = document.createElement( 'label' );
			enabledField.className = 'cb-core-design-shell__field';
			const enabledLabel = document.createElement( 'span' );
			enabledLabel.className = 'cb-core-design-shell__field-label';
			enabledLabel.textContent = composerUi.visible || 'Visible';
			enabledControl = document.createElement( 'input' );
			enabledControl.type = 'checkbox';
			enabledControl.dataset.cbReportBlockEnabled = '';
			enabledField.append( enabledLabel, enabledControl );

			section.append( inspectorTitle, enabledField );
			inspectorBody.append( section );
		}
	};

	const createLayerMoveButton = ( block, direction, disabled ) => {
		const button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'button cb-core-button cb-core-design-shell__layer-action';
		button.disabled = disabled;
		const action = direction < 0 ? ( composerUi.moveUp || 'Move up' ) : ( composerUi.moveDown || 'Move down' );
		const label = `${ action }: ${ blockLabel( block.type ) }`;
		button.textContent = label;
		decorateDesignerControl( button, direction < 0 ? 'arrow-up' : 'arrow-down', { iconOnly: true, label } );
		button.addEventListener( 'click', () => moveBlock( block.type, direction ) );
		return button;
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
			button.setAttribute( 'aria-pressed', type === selectedBlockType ? 'true' : 'false' );
			button.textContent = blockLabel( type );
			button.addEventListener( 'click', () => selectBlock( type ) );
			elementsBody.append( button );
		} );
	};

	const renderLayers = () => {
		if ( ! layerList ) return;
		layerList.replaceChildren();

		composer.blocks.forEach( ( block, index ) => {
			const row = document.createElement( 'div' );
			row.className = 'cb-core-design-shell__layer-row';
			row.dataset.cbReportLayer = block.type;
			if ( block.type === selectedBlockType ) row.classList.add( 'is-selected' );

			const select = document.createElement( 'button' );
			select.type = 'button';
			select.className = 'cb-core-design-shell__layer-select';

			const label = document.createElement( 'span' );
			label.className = 'cb-core-design-shell__layer-label';
			label.textContent = blockLabel( block.type );

			const meta = document.createElement( 'span' );
			meta.className = 'cb-core-design-shell__layer-meta';
			meta.textContent = block.enabled !== false
				? ( composerUi.visible || 'Visible' )
				: ( composerUi.hidden || 'Hidden' );

			select.append( label, meta );
			select.addEventListener( 'click', () => selectBlock( block.type ) );

			const actions = document.createElement( 'div' );
			actions.className = 'cb-core-design-shell__layer-actions';
			if ( ! isStructuralBlock( block ) ) {
				actions.append(
					createLayerMoveButton( block, -1, index <= 1 ),
					createLayerMoveButton( block, 1, index >= composer.blocks.length - 2 )
				);
			}

			row.append( select, actions );
			layerList.append( row );
		} );
	};

	const renderComposerControls = () => {
		const selected = selectedBlock();
		renderElements();
		renderLayers();

		if ( ! selected ) return;
		if ( inspectorTitle ) inspectorTitle.textContent = blockLabel( selected.type );
		if ( enabledControl ) {
			enabledControl.checked = selected.enabled !== false;
			enabledControl.disabled = isStructuralBlock( selected );
		}
	};

	const applyComposer = ( next ) => {
		if ( ! next || ! Array.isArray( next.blocks ) ) return;
		composer = normalizeClientTemplate( next );
		selectedBlockType = composer.blocks.some( ( block ) => block.type === selectedBlockType )
			? selectedBlockType
			: ( composer.blocks[0]?.type || '' );

		session.editorState.selection.clear();
		session.replace( projectFromComposer( composer ), { source: 'server' } );
		const index = selectedIndex();
		if ( index >= 0 ) session.editorState.selection.select( [ index ] );
		renderComposerControls();
		designerShell?.syncHistory();
	};

	function moveBlock( type, direction ) {
		const block = composer.blocks.find( ( candidate ) => candidate.type === type );
		if ( ! block || isStructuralBlock( block ) ) return false;

		const index = composer.blocks.findIndex( ( candidate ) => candidate.type === type );
		const target = index + direction;
		if ( target < 1 || target > composer.blocks.length - 2 ) return false;

		selectedBlockType = type;
		session.editorState.selection.select( [ index ] );
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
		provider_name: providerNameEl?.value || '',
		provider_contact: providerContactEl?.value || '',
		accent_color: colorHexEl?.value || colorEl?.value || '',
	} );

	const reportsPayload = () => ( {
		...brandingPayload(),
		template: JSON.stringify( composer ),
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
		if ( values.logo_url !== undefined ) {
			updateLogoPreview( values.logo_url || '' );
		}
	};

	async function renderPreview() {
		if ( ! nonce || ! previewFrame ) return;
		if ( ! previewHost ) {
			previewFrame.hidden = true;
			setPreviewState( 'The report preview host is unavailable. Reload the page.', 'error' );
			return;
		}

		const requestSequence = ++previewSequence;
		setPreviewState( i18n.previewLoading || 'Rendering report preview…', 'pending' );

		try {
			const response = await apiPost( 'cb_core_preview_report_branding', nonce, reportsPayload() );
			if ( requestSequence !== previewSequence ) return;

			if ( ! response?.success || ! response.data?.html ) {
				previewHost.clear();
				setPreviewState(
					response?.data?.message || i18n.previewFailed || 'The report preview could not be rendered.',
					'error'
				);
				return;
			}

			previewHost.render( response.data.html );
			setPreviewState( '', 'success' );
		} catch ( error ) {
			if ( requestSequence !== previewSequence ) return;
			previewHost.clear();
			setPreviewState(
				requestErrorMessage( error, i18n.previewFailed || 'The report preview could not be rendered.' ),
				'error'
			);
		}
	}

	buildComposerControls();
	selectBlock( selectedBlockType, { openInspector: false } );
	designerShell?.syncHistory();

	enabledControl?.addEventListener( 'change', () => {
		const selected = selectedBlock();
		const index = selectedIndex();
		if ( ! selected || index < 0 || isStructuralBlock( selected ) ) return;
		session.execute( commands.setProperty( [ index ], [ 'enabled' ], enabledControl.checked ) );
	} );

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

	saveBtn?.addEventListener( 'click', async () => {
		if ( ! nonce ) {
			setSaveState( 'error' );
			setPreviewState( i18n.reportsNonceMissing || 'Nonce missing - reload the page.', 'error' );
			return;
		}

		const hex = colorHexEl?.value || colorEl?.value || '';
		if ( ! /^#[0-9a-fA-F]{6}$/.test( hex ) ) {
			setSaveState( 'error' );
			setPreviewState( i18n.brandingInvalidHex || 'Hex colour must be in #RRGGBB form.', 'error' );
			return;
		}

		setSaveState( 'saving' );
		saveBtn.disabled = true;
		if ( resetBtn ) resetBtn.disabled = true;

		try {
			const response = await apiPost( 'cb_core_save_report_branding', nonce, reportsPayload() );
			if ( ! response?.success ) {
				setSaveState( 'error' );
				setPreviewState( response?.data?.message || i18n.saveFailedShort || 'Save failed.', 'error' );
				return;
			}

			applyBranding( response.data || {} );
			applyComposer( response.data?.template );
			setSaveState( 'saved' );
			await renderPreview();
		} catch ( error ) {
			setSaveState( 'error' );
			setPreviewState( requestErrorMessage( error, i18n.saveFailedShort || 'Save failed.' ), 'error' );
		} finally {
			saveBtn.disabled = false;
			if ( resetBtn ) resetBtn.disabled = false;
		}
	} );

	resetBtn?.addEventListener( 'click', async () => {
		if ( ! nonce ) {
			setSaveState( 'error' );
			setPreviewState( i18n.reportsNonceMissing || 'Nonce missing - reload the page.', 'error' );
			return;
		}

		const modal = window.cbCore?.modal;
		if ( typeof modal?.show !== 'function' ) return;

		const confirmed = await modal.show( {
			title: i18n.brandingConfirmResetTitle || 'Reset report settings?',
			body: i18n.brandingConfirmReset || 'Logo, report provider details, accent colour, and report layout will be reset to defaults.',
			confirmLabel: i18n.brandingConfirmResetConfirm || 'Reset to defaults',
			confirmVariant: 'danger',
		} );

		if ( ! confirmed ) return;

		setSaveState( 'saving' );
		resetBtn.disabled = true;
		saveBtn.disabled = true;

		try {
			const response = await apiPost( 'cb_core_reset_report_branding', nonce, {} );
			if ( ! response?.success ) {
				setSaveState( 'error' );
				setPreviewState( response?.data?.message || i18n.saveFailedShort || 'Save failed.', 'error' );
				return;
			}

			applyBranding( response.data || {} );
			applyComposer( response.data?.template );
			setSaveState( 'saved' );
			await renderPreview();
		} catch ( error ) {
			setSaveState( 'error' );
			setPreviewState( requestErrorMessage( error, i18n.saveFailedShort || 'Save failed.' ), 'error' );
		} finally {
			resetBtn.disabled = false;
			saveBtn.disabled = false;
		}
	} );

	window.addEventListener( 'beforeunload', () => {
		previewHost?.destroy();
		session.dispose();
	}, { once: true } );
	renderPreview();
}
