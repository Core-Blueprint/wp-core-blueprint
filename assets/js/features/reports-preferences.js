/**
 * Core Blueprint - Preferences → Reports Designer
 *
 * Reports owns branding values, Composer state, validation, preview requests
 * and persistence. Base owns Designer Mode launch, shell, toolbar, responsive
 * composition and save-state presentation.
 *
 * @since 1.0.0
 */

import { qs, qsa, apiPost } from '../core/dom.js';
import { createDesignerShell } from '@cb-core/design-editor';

const dataEl        = document.getElementById( 'wp-script-module-data-@cb-core/reports-preferences' );
const data          = dataEl ? JSON.parse( dataEl.textContent ) : {};
const i18n          = data.i18n || {};
const blockLabels   = data.blockLabels || {};
const composerUi    = data.composerUi || {};

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
	const layersBody        = qs( '[data-cb-report-layers]', FORM );
	const inspectorBody     = qs( '[data-cb-report-inspector]', FORM );

	const designerShell = shell ? createDesignerShell( shell ) : null;

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

	let composer = normalizeClientTemplate( data.composer || {} );
	let selectedBlockType = composer.blocks[0]?.type || '';
	let blockList = null;
	let inspectorTitle = null;
	let enabledControl = null;
	let moveUpBtn = null;
	let moveDownBtn = null;
	let mediaFrame = null;
	let previewTimer = null;
	let previewSequence = 0;

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
	const isStructuralBlock = ( block ) => block?.type === 'header' || block?.type === 'footer';

	const buildComposerControls = () => {
		if ( layersBody ) {
			const section = document.createElement( 'section' );
			section.className = 'cb-core-design-shell__panel-section';

			const title = document.createElement( 'h3' );
			title.className = 'cb-core-design-shell__panel-section-title';
			title.textContent = composerUi.blocks || 'Blocks';

			blockList = document.createElement( 'div' );
			blockList.className = 'cb-core-design-shell__palette-grid';
			blockList.dataset.cbReportBlockList = '';
			section.append( title, blockList );
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

			const actions = document.createElement( 'div' );
			actions.className = 'cb-core-design-shell__panel-actions';
			moveUpBtn = document.createElement( 'button' );
			moveUpBtn.type = 'button';
			moveUpBtn.className = 'button';
			moveUpBtn.textContent = composerUi.moveUp || 'Move up';
			moveUpBtn.dataset.cbReportBlockMoveUp = '';
			moveDownBtn = document.createElement( 'button' );
			moveDownBtn.type = 'button';
			moveDownBtn.className = 'button';
			moveDownBtn.textContent = composerUi.moveDown || 'Move down';
			moveDownBtn.dataset.cbReportBlockMoveDown = '';
			actions.append( moveUpBtn, moveDownBtn );

			section.append( inspectorTitle, enabledField, actions );
			inspectorBody.append( section );
		}
	};

	const renderComposerControls = () => {
		const selected = selectedBlock();
		if ( blockList ) {
			blockList.replaceChildren();
			composer.blocks.forEach( ( block ) => {
				const button = document.createElement( 'button' );
				button.type = 'button';
				button.className = 'cb-core-design-shell__palette-item';
				button.dataset.cbReportBlock = block.type;
				button.setAttribute( 'aria-pressed', block.type === selectedBlockType ? 'true' : 'false' );
				button.textContent = blockLabel( block.type ) + ( block.enabled ? '' : ` · ${ composerUi.hidden || 'Hidden' }` );
				button.addEventListener( 'click', () => {
					selectedBlockType = block.type;
					renderComposerControls();
					designerShell?.activatePanel( 'inspector' );
				} );
				blockList.append( button );
			} );
		}

		if ( ! selected ) return;
		if ( inspectorTitle ) inspectorTitle.textContent = blockLabel( selected.type );
		const index = composer.blocks.findIndex( ( block ) => block.type === selected.type );
		const structural = isStructuralBlock( selected );
		if ( enabledControl ) {
			enabledControl.checked = selected.enabled !== false;
			enabledControl.disabled = structural;
		}
		if ( moveUpBtn ) moveUpBtn.disabled = structural || index <= 1;
		if ( moveDownBtn ) moveDownBtn.disabled = structural || index < 0 || index >= composer.blocks.length - 2;
	};

	const applyComposer = ( next ) => {
		if ( ! next || ! Array.isArray( next.blocks ) ) return;
		composer = normalizeClientTemplate( next );
		if ( ! composer.blocks.some( ( block ) => block.type === selectedBlockType ) ) {
			selectedBlockType = composer.blocks[0]?.type || '';
		}
		renderComposerControls();
	};

	const moveSelectedBlock = ( direction ) => {
		const selected = selectedBlock();
		if ( ! selected || isStructuralBlock( selected ) ) return;
		const index = composer.blocks.findIndex( ( block ) => block.type === selected.type );
		const target = index + direction;
		if ( target < 1 || target > composer.blocks.length - 2 ) return;
		[ composer.blocks[ index ], composer.blocks[ target ] ] = [ composer.blocks[ target ], composer.blocks[ index ] ];
		renderComposerControls();
		schedulePreview();
	};

	const updateLogoPreview = ( url ) => {
		if ( ! logoPreview ) return;

		logoPreview.replaceChildren();
		if ( url ) {
			const image = document.createElement( 'img' );
			image.src = url;
			image.alt = '';
			image.style.display = 'block';
			image.style.maxWidth = '100%';
			image.style.maxHeight = '96px';
			image.style.width = 'auto';
			image.style.height = 'auto';
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

	const renderPreview = async () => {
		if ( ! nonce || ! previewFrame ) return;

		const requestSequence = ++previewSequence;
		setPreviewState( i18n.previewLoading || 'Rendering report preview…', 'pending' );

		try {
			const response = await apiPost( 'cb_core_preview_report_branding', nonce, reportsPayload() );
			if ( requestSequence !== previewSequence ) return;

			if ( ! response?.success || ! response.data?.html ) {
				previewFrame.hidden = true;
				setPreviewState(
					response?.data?.message || i18n.previewFailed || 'The report preview could not be rendered.',
					'error'
				);
				return;
			}

			previewFrame.srcdoc = response.data.html;
			previewFrame.hidden = false;
			setPreviewState( '', 'success' );
		} catch ( error ) {
			if ( requestSequence !== previewSequence ) return;
			previewFrame.hidden = true;
			setPreviewState(
				requestErrorMessage( error, i18n.previewFailed || 'The report preview could not be rendered.' ),
				'error'
			);
		}
	};

	function schedulePreview() {
		window.clearTimeout( previewTimer );
		previewTimer = window.setTimeout( renderPreview, 180 );
	}

	buildComposerControls();
	renderComposerControls();

	enabledControl?.addEventListener( 'change', () => {
		const selected = selectedBlock();
		if ( ! selected || isStructuralBlock( selected ) ) return;
		selected.enabled = enabledControl.checked;
		renderComposerControls();
		schedulePreview();
	} );
	moveUpBtn?.addEventListener( 'click', () => moveSelectedBlock( -1 ) );
	moveDownBtn?.addEventListener( 'click', () => moveSelectedBlock( 1 ) );

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
		const confirmed = modal
			? await modal.show( {
				title: i18n.brandingConfirmResetTitle || 'Reset report settings?',
				body: i18n.brandingConfirmReset || 'Logo, report provider details, accent colour, and report layout will be reset to defaults.',
				confirmLabel: i18n.brandingConfirmResetConfirm || 'Reset to defaults',
				confirmVariant: 'danger',
			} )
			: true;

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

	renderPreview();
}
