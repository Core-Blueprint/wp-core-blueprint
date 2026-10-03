/**
 * Core Blueprint - shared Dashboard activation controls.
 *
 * Optional Base modules continue to use Modules\ActivationRegistry. Installed
 * Core Blueprint extensions use the separate Base-owned WordPress plugin
 * lifecycle endpoint; extensions do not provide their own global state writer.
 *
 * @since   1.0.0
 */

import { apiPost, qs, qsa } from '../core/dom.js';

const dataEl = document.getElementById( 'wp-script-module-data-@cb-core/module-activation' );
const data   = dataEl ? JSON.parse( dataEl.textContent ) : {};
const nonce  = data.nonce || '';
const extensions = Array.isArray( data.extensions ) ? new Set( data.extensions ) : new Set();
const i18n   = data.i18n || {};

const repaint = ( root, on ) => {
	const toggle = qs( '[data-cb-core-master-switch-toggle]', root );
	if ( toggle ) toggle.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
	for ( const option of qsa( '[data-cb-core-master-switch-state]', root ) ) {
		option.classList.toggle( 'is-active', option.dataset.cbCoreMasterSwitchState === ( on ? 'on' : 'off' ) );
	}
};

const setBusy = ( button, busy ) => {
	button.disabled = busy;
	if ( busy ) {
		button.setAttribute( 'aria-busy', 'true' );
	} else {
		button.removeAttribute( 'aria-busy' );
	}
};

if ( nonce ) {
	document.addEventListener( 'click', async ( event ) => {
		const extensionAction = event.target.closest( '[data-cb-core-extension-action]' );
		if ( extensionAction ) {
			const extension = extensionAction.dataset.cbCoreExtensionAction || '';
			if ( ! extensions.has( extension ) ) return;

			event.preventDefault();
			event.stopPropagation();
			const target = extensionAction.dataset.cbCoreExtensionActive === '1';
			setBusy( extensionAction, true );

			try {
				const response = await apiPost( 'cb_core_set_extension_active', nonce, {
					extension,
					active: target ? 1 : 0,
				} );
				if ( response?.success ) {
					window.location.reload();
					return;
				}
				window.cbCore?.toast?.error?.( response?.data?.message || i18n.extensionUpdateFailed || 'Could not update extension.' );
			} catch ( error ) {
				window.cbCore?.toast?.error?.( error?.message || i18n.extensionUpdateFailed || 'Could not update extension.' );
			} finally {
				setBusy( extensionAction, false );
			}
			return;
		}

		const menuAction = event.target.closest( '[data-cb-core-module-action]' );
		if ( menuAction ) {
			const module = menuAction.dataset.cbCoreModuleAction || '';

			event.preventDefault();
			event.stopPropagation();
			const target = menuAction.dataset.cbCoreModuleEnabled === '1';
			setBusy( menuAction, true );

			try {
				const response = await apiPost( 'cb_core_set_module_enabled', nonce, {
					module,
					enabled: target ? 1 : 0,
				} );
				if ( response?.success ) {
					window.location.reload();
					return;
				}
				window.cbCore?.toast?.error?.( response?.data?.message || i18n.updateFailed || 'Could not update module.' );
			} catch ( error ) {
				window.cbCore?.toast?.error?.( error?.message || i18n.updateFailed || 'Could not update module.' );
			} finally {
				setBusy( menuAction, false );
			}
			return;
		}

		const trigger = event.target.closest( '[data-cb-core-master-switch-toggle], [data-cb-core-master-switch-state]' );
		if ( ! trigger ) return;

		const root = trigger.closest( '[data-cb-core-master-switch]' );
		if ( ! root ) return;
		const module = root.dataset.cbCoreMasterSwitch || '';

		event.preventDefault();
		const toggle = qs( '[data-cb-core-master-switch-toggle]', root );
		const currentlyOn = toggle?.getAttribute( 'aria-pressed' ) === 'true';
		const target = trigger.matches( '[data-cb-core-master-switch-state]' )
			? trigger.dataset.cbCoreMasterSwitchState === 'on'
			: ! currentlyOn;
		if ( target === currentlyOn ) return;

		repaint( root, target );
		root.dataset.cbCoreModuleBusy = '1';

		try {
			const response = await apiPost( 'cb_core_set_module_enabled', nonce, {
				module,
				enabled: target ? 1 : 0,
			} );
			if ( response?.success ) {
				window.location.reload();
				return;
			}
			repaint( root, currentlyOn );
			window.cbCore?.toast?.error?.( response?.data?.message || i18n.updateFailed || 'Could not update module.' );
		} catch ( error ) {
			repaint( root, currentlyOn );
			window.cbCore?.toast?.error?.( error?.message || i18n.updateFailed || 'Could not update module.' );
		} finally {
			delete root.dataset.cbCoreModuleBusy;
		}
	} );
}
