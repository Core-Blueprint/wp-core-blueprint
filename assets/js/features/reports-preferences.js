/**
 * Core Blueprint - Preferences → Reports Designer
 *
 * Reports owns branding values, validation, preview requests and persistence.
 * Base owns Designer Mode launch, shell, toolbar, responsive composition and
 * save-state presentation.
 *
 * @since 1.0.0
 */

import { qs, apiPost } from '../core/dom.js';
import { createDesignerShell } from '@cb-core/design-editor';

const dataEl = document.getElementById( 'wp-script-module-data-@cb-core/reports-preferences' );
const data   = dataEl ? JSON.parse( dataEl.textContent ) : {};
const i18n   = data.i18n || {};

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

	if ( shell ) {
		createDesignerShell( shell );
	}

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
			const response = await apiPost( 'cb_core_preview_report_branding', nonce, brandingPayload() );
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
		} catch {
			if ( requestSequence !== previewSequence ) return;
			previewFrame.hidden = true;
			setPreviewState( i18n.networkError || 'Network error - try again.', 'error' );
		}
	};

	const schedulePreview = () => {
		window.clearTimeout( previewTimer );
		previewTimer = window.setTimeout( renderPreview, 180 );
	};

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
			const response = await apiPost( 'cb_core_save_report_branding', nonce, brandingPayload() );
			if ( ! response?.success ) {
				setSaveState( 'error' );
				setPreviewState( response?.data?.message || i18n.saveFailedShort || 'Save failed.', 'error' );
				return;
			}

			applyBranding( response.data || {} );
			setSaveState( 'saved' );
			await renderPreview();
		} catch {
			setSaveState( 'error' );
			setPreviewState( i18n.networkError || 'Network error - try again.', 'error' );
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
				body: i18n.brandingConfirmReset || 'Logo, report provider details, and accent colour will be cleared and reset to defaults.',
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
			setSaveState( 'saved' );
			await renderPreview();
		} catch {
			setSaveState( 'error' );
			setPreviewState( i18n.networkError || 'Network error - try again.', 'error' );
		} finally {
			resetBtn.disabled = false;
			saveBtn.disabled = false;
		}
	} );

	renderPreview();
}
