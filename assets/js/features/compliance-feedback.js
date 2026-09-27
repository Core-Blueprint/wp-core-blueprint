/**
 * Compliance Resources - one-shot server success feedback through Toast Foundation.
 */

const successPayload = document.querySelector( '[data-cb-core-compliance-success]' );

if ( successPayload ) {
	const message = successPayload.dataset.cbCoreComplianceSuccess || '';
	if ( message && window.cbCore?.toast?.success ) {
		window.cbCore.toast.success( message );
	}
	successPayload.remove();
}
