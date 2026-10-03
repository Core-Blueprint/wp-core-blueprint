/**
 * core/busy.js - Core Blueprint UI Foundation busy/loading helpers.
 *
 * Public API:
 *   window.cbCore.busy.button(button, isBusy, { label, spinner, spinnerOnly })
 *   window.cbCore.busy.region(element, isBusy)
 *   window.cbCore.busy.spinner({ decorative })
 *
 * @package CoreBlueprint\Core
 * @since   1.0.0
 */

function spinner( { decorative = true } = {} ) {
	const root = document.createElement( 'span' );
	root.className = 'cb-core-spinner is-active';
	if ( decorative ) {
		root.setAttribute( 'aria-hidden', 'true' );
	} else {
		root.setAttribute( 'role', 'status' );
	}

	const ring = document.createElement( 'span' );
	ring.className = 'cb-core-spinner__ring';
	ring.setAttribute( 'aria-hidden', 'true' );
	root.appendChild( ring );
	return root;
}

function button( element, isBusy, options = {} ) {
	if ( ! element ) {
		return;
	}

	const label       = typeof options.label === 'string' ? options.label : '';
	const showSpinner = options.spinner !== false;
	const spinnerOnly = options.spinnerOnly === true;

	if ( isBusy ) {
		if ( typeof element.dataset.cbCoreOriginalMarkup === 'undefined' ) {
			element.dataset.cbCoreOriginalMarkup = element.innerHTML;
		}
		element.disabled = true;
		element.setAttribute( 'aria-busy', 'true' );
		element.classList.add( 'is-busy' );

		if ( spinnerOnly ) {
			element.replaceChildren();
			if ( showSpinner ) {
				element.appendChild( spinner() );
			}
		} else if ( label ) {
			element.replaceChildren();
			if ( showSpinner ) {
				element.appendChild( spinner() );
			}
			const text = document.createElement( 'span' );
			text.className = 'cb-core-button__label';
			text.textContent = label;
			element.appendChild( text );
		}
		return;
	}

	element.disabled = false;
	element.removeAttribute( 'aria-busy' );
	element.classList.remove( 'is-busy' );
	if ( typeof element.dataset.cbCoreOriginalMarkup !== 'undefined' ) {
		element.innerHTML = element.dataset.cbCoreOriginalMarkup;
		delete element.dataset.cbCoreOriginalMarkup;
	}
}

function region( element, isBusy ) {
	if ( ! element ) {
		return;
	}
	if ( isBusy ) {
		element.dataset.cbCoreBusyRegion = 'true';
		element.setAttribute( 'aria-busy', 'true' );
		return;
	}
	element.removeAttribute( 'aria-busy' );
	delete element.dataset.cbCoreBusyRegion;
}

const busy = { button, region, spinner };
window.cbCore = window.cbCore || {};
window.cbCore.busy = busy;

export { button, region, spinner };
export default busy;
