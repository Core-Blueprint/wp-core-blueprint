/**
 * Core Blueprint - site-wide two-factor policy.
 *
 * Policy mutation is server-authoritative. The browser only captures explicit
 * intent and a fresh WordPress password confirmation, then reloads from the
 * persisted canonical state.
 */

import { qsa, apiPost } from '../core/dom.js';

const dataEl = document.getElementById( 'wp-script-module-data-@cb-core/two-factor-policy' );
const data = dataEl ? JSON.parse( dataEl.textContent ) : {};
const nonce = data.nonce || '';
const currentMode = data.currentMode || 'optional';
const i18n = data.i18n || {};

if ( nonce ) {
	const modal = window.cbCore?.modal;
	const toast = window.cbCore?.toast;

	document.addEventListener( 'change', async ( event ) => {
		const input = event.target.closest( '[data-cb-core-two-factor-mode]' );
		if ( ! input ) return;

		const mode = input.value || '';
		if ( mode !== 'optional' && mode !== 'enforce' ) return;
		if ( mode === currentMode ) return;

		const radios = qsa( '[data-cb-core-two-factor-mode]' );
		for ( const radio of radios ) radio.disabled = true;

		const password = modal
			? await modal.show( {
				title: i18n.changeTitle || 'Change two-factor policy?',
				body: mode === 'enforce'
					? ( i18n.enforceBody || 'Re-enter your WordPress password to enable enforcement.' )
					: ( i18n.optionalBody || 'Re-enter your WordPress password to change the policy.' ),
				confirmLabel: i18n.changeConfirm || 'Change policy',
				confirmVariant: mode === 'enforce' ? 'primary' : 'remediation',
				input: {
					type: 'password',
					placeholder: i18n.passwordPlaceholder || 'WordPress password',
				},
			} )
			: null;

		if ( password === null ) {
			for ( const radio of radios ) {
				radio.checked = radio.value === currentMode;
				radio.disabled = false;
			}
			return;
		}

		try {
			const response = await apiPost( 'cb_core_set_two_factor_policy', nonce, { mode, password } );
			if ( response?.success ) {
				window.location.reload();
				return;
			}
			for ( const radio of radios ) radio.checked = radio.value === currentMode;
			toast?.error( response?.data?.message || i18n.saveFailed || 'Could not update the two-factor policy.' );
		} catch ( error ) {
			for ( const radio of radios ) radio.checked = radio.value === currentMode;
			toast?.error( error?.message || i18n.networkError || i18n.saveFailed || 'Could not update the two-factor policy.' );
		} finally {
			for ( const radio of radios ) radio.disabled = false;
		}
	} );
}
