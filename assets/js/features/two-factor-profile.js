/**
 * Two-factor profile interactions.
 *
 * Pending-enrollment cancellation uses the shared Modal Foundation. The form
 * itself remains a normal nonce-protected admin-post action and is submitted
 * only after an explicit modal confirmation.
 */
document.addEventListener( 'click', async ( event ) => {
	const trigger = event.target.closest( '[data-cb-two-factor-cancel]' );
	if ( ! trigger ) {
		return;
	}

	event.preventDefault();

	const formId = trigger.getAttribute( 'data-cb-two-factor-cancel-form' ) || '';
	const form = formId ? document.getElementById( formId ) : null;
	const modal = window.cbCore?.modal;

	if ( ! form || form.tagName !== 'FORM' || ! modal?.show ) {
		console.error( 'Core Blueprint two-factor cancellation confirmation is unavailable.' );
		return;
	}

	const confirmed = await modal.show( {
		title: trigger.getAttribute( 'data-cb-two-factor-cancel-title' ) || '',
		body: trigger.getAttribute( 'data-cb-two-factor-cancel-body' ) || '',
		confirmLabel: trigger.getAttribute( 'data-cb-two-factor-cancel-confirm' ) || '',
		cancelLabel: trigger.getAttribute( 'data-cb-two-factor-cancel-dismiss' ) || '',
		confirmVariant: 'danger',
	} );

	if ( confirmed === true ) {
		form.submit();
	}
} );
