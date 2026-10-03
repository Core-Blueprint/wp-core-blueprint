/**
 * core/token-input.js - Core Blueprint Suite
 *
 * Progressive-enhancement Token Input Foundation. Enhances one real native
 * <input> into a segmented text/token editor while keeping the native input
 * as the single serialized form value and only persistent state.
 *
 * Public API:
 *   const instance = window.cbCore.tokenInput.create( input, {
 *     tokens: [
 *       { value: '{DATE}', label: 'Date' },
 *       { value: '{ID}', label: 'ID' },
 *     ],
 *   } );
 *
 *   instance.getValue();
 *   instance.setValue( 'REF-{DATE}-{ID}' );
 *   instance.focus();
 *   instance.destroy();
 *
 * Consumer-owned business validation is intentionally out of scope. Base only
 * distinguishes tokens present in the supplied allowlist from unknown balanced
 * brace tokens. The serialized string remains ordinary input.value state.
 *
 * Native DOM API only. No jQuery. No bundler. No contenteditable.
 *
 * @package CoreBlueprint\Core
 * @since   1.0.0
 */

const dataEl = document.getElementById( 'wp-script-module-data-@cb-core/token-input' );
let data = {};
try {
	data = dataEl ? JSON.parse( dataEl.textContent ) : {};
} catch {
	data = {};
}

const i18n = data.i18n || {};
const presentation = data.presentation === 'core' ? 'core' : 'wp-native';
const instances = new WeakMap();
const TOKEN_PATTERN = /\{[^{}\r\n]+\}/g;

function normalizeTokens( tokens ) {
	const normalized = [];
	const seen = new Set();

	for ( const candidate of Array.isArray( tokens ) ? tokens : [] ) {
		if ( ! candidate || typeof candidate !== 'object' ) continue;

		const value = String( candidate.value ?? '' ).trim();
		if ( ! value || seen.has( value ) || ! /^\{[^{}\r\n]+\}$/.test( value ) ) continue;

		const fallbackLabel = value.slice( 1, -1 ).replace( /[_-]+/g, ' ' );
		const label = String( candidate.label ?? fallbackLabel ).trim() || fallbackLabel;

		normalized.push( { value, label } );
		seen.add( value );
	}

	return normalized;
}

function buildTokenMap( tokens ) {
	return new Map( normalizeTokens( tokens ).map( ( token ) => [ token.value, token ] ) );
}

/**
 * Parse a serialized value into alternating text/token segments.
 * Empty text segments are intentionally preserved around adjacent tokens so
 * every token has a native text insertion point on both sides.
 *
 * @param {string} value
 * @param {Map<string,{value:string,label:string}>|Array} tokenSource
 * @returns {Array<{type:'text',value:string}|{type:'token',value:string,label:string,known:boolean}>}
 */
function parseValue( value, tokenSource = [] ) {
	const serialized = String( value ?? '' );
	const tokenMap = tokenSource instanceof Map ? tokenSource : buildTokenMap( tokenSource );
	const segments = [];
	let cursor = 0;

	TOKEN_PATTERN.lastIndex = 0;
	let match;
	while ( ( match = TOKEN_PATTERN.exec( serialized ) ) !== null ) {
		segments.push( {
			type: 'text',
			value: serialized.slice( cursor, match.index ),
		} );

		const token = tokenMap.get( match[ 0 ] );
		segments.push( {
			type: 'token',
			value: match[ 0 ],
			label: token?.label || match[ 0 ].slice( 1, -1 ),
			known: Boolean( token ),
		} );

		cursor = match.index + match[ 0 ].length;
	}

	segments.push( {
		type: 'text',
		value: serialized.slice( cursor ),
	} );

	return segments;
}

function serializeSegments( segments ) {
	return Array.isArray( segments )
		? segments.map( ( segment ) => String( segment?.value ?? '' ) ).join( '' )
		: '';
}

function getFieldLabel( input, options ) {
	if ( options.ariaLabel ) return String( options.ariaLabel );
	if ( input.getAttribute( 'aria-label' ) ) return input.getAttribute( 'aria-label' );
	if ( input.labels?.length ) {
		const label = input.labels[ 0 ].textContent?.trim();
		if ( label ) return label;
	}
	return input.name || input.id || i18n.tokenInput || 'Token input';
}

function create( input, options = {} ) {
	if ( ! ( input instanceof HTMLInputElement ) ) {
		throw new TypeError( 'cbCore.tokenInput.create() requires an HTMLInputElement.' );
	}

	if ( instances.has( input ) ) return instances.get( input );

	const tokens = normalizeTokens( options.tokens );
	const tokenMap = buildTokenMap( tokens );
	const fieldLabel = getFieldLabel( input, options );
	const originalTabindex = input.getAttribute( 'tabindex' );
	const originalAriaHidden = input.getAttribute( 'aria-hidden' );
	const originalClassHadNative = input.classList.contains( 'cb-core-token-input__native' );
	const form = input.form;

	let segments = parseValue( input.value, tokenMap );
	let activeOffset = input.value.length;
	let allSelected = false;
	let syncingNative = false;
	let destroyed = false;
	let lastCommittedValue = input.value;
	let lastUnknownSignature = '';

	const wrapper = document.createElement( 'div' );
	wrapper.className = `cb-core-token-input cb-core-token-input--${ presentation }`;
	wrapper.dataset.cbCoreTokenInput = 'true';

	const editor = document.createElement( 'div' );
	editor.className = 'cb-core-token-input__editor';
	editor.setAttribute( 'role', 'group' );
	editor.setAttribute( 'aria-label', fieldLabel );

	const picker = document.createElement( 'div' );
	picker.className = 'cb-core-token-input__picker';
	picker.setAttribute( 'role', 'group' );
	picker.setAttribute( 'aria-label', options.availableTokensLabel || i18n.availableVariables || 'Available variables' );

	const pickerLabel = document.createElement( 'span' );
	pickerLabel.className = 'cb-core-token-input__picker-label';
	pickerLabel.textContent = options.availableTokensLabel || i18n.availableVariables || 'Available variables';
	picker.appendChild( pickerLabel );

	for ( const token of tokens ) {
		const button = document.createElement( 'button' );
		button.type = 'button';
		button.className = presentation === 'wp-native'
			? 'cb-core-token-input__insert button button-secondary'
			: 'cb-core-token-input__insert button cb-core-button cb-core-button--secondary cb-core-button--compact';
		button.dataset.tokenValue = token.value;
		button.textContent = token.label;
		button.title = token.value;
		button.setAttribute( 'aria-label', `${ i18n.insertVariable || 'Insert variable' }: ${ token.label }` );
		picker.appendChild( button );
	}

	const status = document.createElement( 'span' );
	status.className = 'cb-core-token-input__status';
	status.setAttribute( 'role', 'status' );
	status.setAttribute( 'aria-live', 'polite' );
	status.setAttribute( 'aria-atomic', 'true' );

	const selectionStatus = document.createElement( 'span' );
	selectionStatus.className = 'cb-core-token-input__status cb-core-token-input__selection-status';
	selectionStatus.setAttribute( 'role', 'status' );
	selectionStatus.setAttribute( 'aria-live', 'polite' );
	selectionStatus.setAttribute( 'aria-atomic', 'true' );

	wrapper.appendChild( editor );
	if ( tokens.length && options.showAvailableTokens !== false ) wrapper.appendChild( picker );
	wrapper.appendChild( status );
	wrapper.appendChild( selectionStatus );
	input.insertAdjacentElement( 'afterend', wrapper );

	// Progressive enhancement: only hide the original native control after the
	// complete interactive layer exists. The input remains the submitted field.
	input.classList.add( 'cb-core-token-input__native' );
	input.setAttribute( 'aria-hidden', 'true' );
	input.setAttribute( 'tabindex', '-1' );

	function segmentStartOffset( segmentIndex ) {
		let offset = 0;
		for ( let index = 0; index < segmentIndex; index++ ) {
			offset += segments[ index ]?.value.length || 0;
		}
		return offset;
	}

	function updateActiveOffsetFromText( textInput ) {
		const segmentIndex = Number( textInput.dataset.segmentIndex );
		if ( ! Number.isInteger( segmentIndex ) ) return;
		const localOffset = Number.isInteger( textInput.selectionStart ) ? textInput.selectionStart : textInput.value.length;
		activeOffset = segmentStartOffset( segmentIndex ) + localOffset;
	}

	function setAllSelected( selected ) {
		allSelected = selected;
		wrapper.classList.toggle( 'is-all-selected', selected );
		selectionStatus.textContent = selected ? ( i18n.entireValueSelected || 'Entire value selected' ) : '';
	}

	function dispatchNativeEvent( type ) {
		syncingNative = true;
		try {
			input.dispatchEvent( new Event( type, { bubbles: true } ) );
		} finally {
			syncingNative = false;
		}
	}

	function syncNativeValue( value, emitInput = true ) {
		input.value = String( value ?? '' );
		if ( emitInput ) dispatchNativeEvent( 'input' );
	}

	function commitChange() {
		if ( input.value === lastCommittedValue ) return;
		lastCommittedValue = input.value;
		dispatchNativeEvent( 'change' );
	}

	function focusTextSegment( segmentIndex, caret ) {
		const textInput = editor.querySelector( `.cb-core-token-input__text[data-segment-index="${ segmentIndex }"]` );
		if ( ! textInput ) return false;
		textInput.focus();
		const resolvedCaret = Math.max( 0, Math.min( Number( caret ) || 0, textInput.value.length ) );
		textInput.setSelectionRange( resolvedCaret, resolvedCaret );
		updateActiveOffsetFromText( textInput );
		return true;
	}

	function focusAtOffset( requestedOffset ) {
		const valueLength = input.value.length;
		const offset = Math.max( 0, Math.min( Number( requestedOffset ) || 0, valueLength ) );
		let position = 0;

		for ( let index = 0; index < segments.length; index++ ) {
			const segment = segments[ index ];
			const end = position + segment.value.length;

			if ( segment.type === 'text' && offset >= position && offset <= end ) {
				return focusTextSegment( index, offset - position );
			}

			if ( segment.type === 'token' && offset > position && offset <= end ) {
				if ( offset === end ) {
					for ( let next = index + 1; next < segments.length; next++ ) {
						if ( segments[ next ].type === 'text' ) return focusTextSegment( next, 0 );
					}
				}

				for ( let previous = index - 1; previous >= 0; previous-- ) {
					if ( segments[ previous ].type === 'text' ) {
						return focusTextSegment( previous, segments[ previous ].value.length );
					}
				}
			}

			position = end;
		}

		for ( let index = segments.length - 1; index >= 0; index-- ) {
			if ( segments[ index ].type === 'text' ) {
				return focusTextSegment( index, segments[ index ].value.length );
			}
		}
		return false;
	}

	function render( focusOffset = null ) {
		editor.replaceChildren();
		const unknownTokens = [];

		segments.forEach( ( segment, index ) => {
			if ( segment.type === 'text' ) {
				const textInput = document.createElement( 'input' );
				textInput.type = 'text';
				textInput.className = 'cb-core-token-input__text';
				textInput.dataset.segmentIndex = String( index );
				textInput.value = segment.value;
				textInput.autocomplete = 'off';
				textInput.spellcheck = false;
				textInput.readOnly = input.readOnly;
				textInput.disabled = input.disabled;
				textInput.style.width = `${ Math.max( 2, Math.min( 64, segment.value.length + 1 ) ) }ch`;
				textInput.setAttribute( 'aria-label', `${ fieldLabel} — ${ i18n.textSegment || 'text' }` );
				editor.appendChild( textInput );
				return;
			}

			const token = document.createElement( 'span' );
			token.className = `cb-core-token-input__token${ segment.known ? '' : ' is-unknown' }`;
			token.dataset.segmentIndex = String( index );
			token.dataset.tokenValue = segment.value;
			token.setAttribute( 'role', 'group' );
			token.setAttribute(
				'aria-label',
				segment.known
					? `${ segment.label } ${ i18n.variable || 'variable' }`
					: `${ i18n.unknownVariable || 'Unknown variable' }: ${ segment.label }`
			);
			token.title = segment.value;

			const label = document.createElement( 'span' );
			label.className = 'cb-core-token-input__token-label';
			label.textContent = segment.known ? segment.label : segment.value;

			const remove = document.createElement( 'button' );
			remove.type = 'button';
			remove.className = 'cb-core-token-input__remove';
			remove.dataset.segmentIndex = String( index );
			remove.textContent = '×';
			remove.disabled = input.disabled || input.readOnly;
			remove.setAttribute( 'aria-label', `${ i18n.removeVariable || 'Remove variable' }: ${ segment.label }` );

			token.appendChild( label );
			token.appendChild( remove );
			editor.appendChild( token );

			if ( ! segment.known ) unknownTokens.push( segment.value );
		} );

		const hasUnknown = unknownTokens.length > 0;
		editor.setAttribute( 'aria-invalid', hasUnknown ? 'true' : 'false' );
		wrapper.classList.toggle( 'has-unknown-tokens', hasUnknown );
		wrapper.classList.toggle( 'is-disabled', input.disabled );
		wrapper.classList.toggle( 'is-readonly', input.readOnly );
		editor.setAttribute( 'aria-disabled', input.disabled ? 'true' : 'false' );
		editor.setAttribute( 'aria-readonly', input.readOnly ? 'true' : 'false' );
		picker.querySelectorAll( 'button' ).forEach( ( button ) => {
			button.disabled = input.disabled || input.readOnly;
		} );

		const unknownSignature = unknownTokens.join( '\u0000' );
		if ( unknownSignature !== lastUnknownSignature ) {
			if ( unknownTokens.length === 1 ) {
				status.textContent = `${ i18n.unknownVariable || 'Unknown variable' }: ${ unknownTokens[ 0 ] }`;
			} else if ( unknownTokens.length > 1 ) {
				status.textContent = `${ i18n.unknownVariables || 'Unknown variables' }: ${ unknownTokens.join( ', ' ) }`;
			} else {
				status.textContent = '';
			}
			lastUnknownSignature = unknownSignature;
		}

		if ( focusOffset !== null ) focusAtOffset( focusOffset );
	}

	function replaceSerializedValue( value, focusOffset, emitInput = true ) {
		setAllSelected( false );
		syncNativeValue( value, emitInput );
		segments = parseValue( input.value, tokenMap );
		activeOffset = Math.max( 0, Math.min( focusOffset, input.value.length ) );
		render( activeOffset );
	}

	function removeTokenAt( segmentIndex ) {
		const segment = segments[ segmentIndex ];
		if ( ! segment || segment.type !== 'token' || input.disabled || input.readOnly ) return;

		const start = segmentStartOffset( segmentIndex );
		const serialized = serializeSegments( segments );
		const nextValue = serialized.slice( 0, start ) + serialized.slice( start + segment.value.length );
		replaceSerializedValue( nextValue, start );
	}

	function insertToken( tokenValue ) {
		if ( input.disabled || input.readOnly || ! tokenMap.has( tokenValue ) ) return;

		const serialized = input.value;
		const offset = Math.max( 0, Math.min( activeOffset, serialized.length ) );
		const nextValue = serialized.slice( 0, offset ) + tokenValue + serialized.slice( offset );
		replaceSerializedValue( nextValue, offset + tokenValue.length );
	}

	function handleTextKeydown( event, textInput ) {
		const segmentIndex = Number( textInput.dataset.segmentIndex );
		if ( ! Number.isInteger( segmentIndex ) ) return;

		if ( ( event.ctrlKey || event.metaKey ) && event.key.toLowerCase() === 'a' ) {
			event.preventDefault();
			setAllSelected( true );
			return;
		}

		if ( allSelected ) {
			if ( event.key === 'Backspace' || event.key === 'Delete' ) {
				event.preventDefault();
				replaceSerializedValue( '', 0 );
				return;
			}
			if ( event.key === 'ArrowLeft' || event.key === 'ArrowRight' ) {
				event.preventDefault();
				setAllSelected( false );
				focusAtOffset( event.key === 'ArrowLeft' ? 0 : input.value.length );
				return;
			}
			if ( event.key.length === 1 && ! event.ctrlKey && ! event.metaKey && ! event.altKey ) {
				event.preventDefault();
				replaceSerializedValue( event.key, event.key.length );
				return;
			}
		}

		const start = textInput.selectionStart ?? 0;
		const end = textInput.selectionEnd ?? start;
		if ( start !== end ) return;

		if ( event.key === 'Backspace' && start === 0 && segments[ segmentIndex - 1 ]?.type === 'token' ) {
			event.preventDefault();
			removeTokenAt( segmentIndex - 1 );
			return;
		}

		if ( event.key === 'Delete' && start === textInput.value.length && segments[ segmentIndex + 1 ]?.type === 'token' ) {
			event.preventDefault();
			removeTokenAt( segmentIndex + 1 );
			return;
		}

		if ( event.key === 'ArrowLeft' && start === 0 && segments[ segmentIndex - 1 ]?.type === 'token' ) {
			event.preventDefault();
			const previousTextIndex = segmentIndex - 2;
			focusTextSegment( previousTextIndex, segments[ previousTextIndex ]?.value.length || 0 );
			return;
		}

		if ( event.key === 'ArrowRight' && start === textInput.value.length && segments[ segmentIndex + 1 ]?.type === 'token' ) {
			event.preventDefault();
			focusTextSegment( segmentIndex + 2, 0 );
		}
	}

	function handlePaste( event, textInput ) {
		if ( input.disabled || input.readOnly ) return;
		const pasted = event.clipboardData?.getData( 'text/plain' );
		if ( typeof pasted !== 'string' ) return;
		event.preventDefault();

		if ( allSelected ) {
			replaceSerializedValue( pasted, pasted.length );
			return;
		}

		const segmentIndex = Number( textInput.dataset.segmentIndex );
		if ( ! Number.isInteger( segmentIndex ) ) return;
		const segmentStart = segmentStartOffset( segmentIndex );
		const localStart = textInput.selectionStart ?? textInput.value.length;
		const localEnd = textInput.selectionEnd ?? localStart;
		const globalStart = segmentStart + localStart;
		const globalEnd = segmentStart + localEnd;
		const serialized = input.value;
		const nextValue = serialized.slice( 0, globalStart ) + pasted + serialized.slice( globalEnd );
		replaceSerializedValue( nextValue, globalStart + pasted.length );
	}

	function handleTokenKeydown( event, removeButton ) {
		const segmentIndex = Number( removeButton.dataset.segmentIndex );
		if ( ! Number.isInteger( segmentIndex ) ) return;

		if ( event.key === 'Backspace' || event.key === 'Delete' ) {
			event.preventDefault();
			removeTokenAt( segmentIndex );
			return;
		}

		if ( event.key === 'ArrowLeft' ) {
			event.preventDefault();
			focusTextSegment( segmentIndex - 1, segments[ segmentIndex - 1 ]?.value.length || 0 );
			return;
		}

		if ( event.key === 'ArrowRight' ) {
			event.preventDefault();
			focusTextSegment( segmentIndex + 1, 0 );
		}
	}

	function onEditorInput( event ) {
		const textInput = event.target.closest?.( '.cb-core-token-input__text' );
		if ( ! textInput ) return;
		setAllSelected( false );

		const segmentIndex = Number( textInput.dataset.segmentIndex );
		if ( ! Number.isInteger( segmentIndex ) || segments[ segmentIndex ]?.type !== 'text' ) return;

		const caret = segmentStartOffset( segmentIndex ) + ( textInput.selectionStart ?? textInput.value.length );
		segments[ segmentIndex ].value = textInput.value;
		syncNativeValue( serializeSegments( segments ) );
		segments = parseValue( input.value, tokenMap );
		activeOffset = caret;
		render( caret );
	}

	function onEditorKeydown( event ) {
		const textInput = event.target.closest?.( '.cb-core-token-input__text' );
		if ( textInput ) {
			handleTextKeydown( event, textInput );
			return;
		}

		const removeButton = event.target.closest?.( '.cb-core-token-input__remove' );
		if ( removeButton ) handleTokenKeydown( event, removeButton );
	}

	function onEditorPaste( event ) {
		const textInput = event.target.closest?.( '.cb-core-token-input__text' );
		if ( textInput ) handlePaste( event, textInput );
	}

	function onEditorCopy( event ) {
		if ( ! allSelected || ! event.clipboardData ) return;
		event.clipboardData.setData( 'text/plain', input.value );
		event.preventDefault();
	}

	function onEditorClick( event ) {
		const removeButton = event.target.closest?.( '.cb-core-token-input__remove' );
		if ( removeButton ) {
			removeTokenAt( Number( removeButton.dataset.segmentIndex ) );
			return;
		}

		const textInput = event.target.closest?.( '.cb-core-token-input__text' );
		if ( textInput ) updateActiveOffsetFromText( textInput );
	}

	function onEditorSelectionActivity( event ) {
		const textInput = event.target.closest?.( '.cb-core-token-input__text' );
		if ( textInput ) updateActiveOffsetFromText( textInput );
	}

	function onPickerClick( event ) {
		const button = event.target.closest?.( '.cb-core-token-input__insert' );
		if ( ! button ) return;
		insertToken( button.dataset.tokenValue || '' );
	}

	function onWrapperFocusOut( event ) {
		if ( ! wrapper.contains( event.relatedTarget ) ) commitChange();
	}

	function onNativeFocus() {
		if ( ! destroyed ) focusAtOffset( activeOffset );
	}

	function onNativeInput() {
		if ( syncingNative || destroyed ) return;
		segments = parseValue( input.value, tokenMap );
		activeOffset = Math.min( activeOffset, input.value.length );
		setAllSelected( false );
		render();
	}

	function onNativeChange() {
		if ( syncingNative || destroyed ) return;
		lastCommittedValue = input.value;
		onNativeInput();
	}

	function onFormReset() {
		setTimeout( () => {
			if ( destroyed ) return;
			segments = parseValue( input.value, tokenMap );
			activeOffset = input.value.length;
			lastCommittedValue = input.value;
			setAllSelected( false );
			render();
		}, 0 );
	}

	editor.addEventListener( 'input', onEditorInput );
	editor.addEventListener( 'keydown', onEditorKeydown );
	editor.addEventListener( 'paste', onEditorPaste );
	editor.addEventListener( 'copy', onEditorCopy );
	editor.addEventListener( 'click', onEditorClick );
	editor.addEventListener( 'keyup', onEditorSelectionActivity );
	editor.addEventListener( 'select', onEditorSelectionActivity );
	editor.addEventListener( 'pointerdown', () => setAllSelected( false ) );
	picker.addEventListener( 'click', onPickerClick );
	wrapper.addEventListener( 'focusout', onWrapperFocusOut );
	input.addEventListener( 'focus', onNativeFocus );
	input.addEventListener( 'input', onNativeInput );
	input.addEventListener( 'change', onNativeChange );
	form?.addEventListener( 'reset', onFormReset );

	render();

	const instance = {
		getValue() {
			return input.value;
		},
		setValue( value ) {
			if ( destroyed ) return;
			const serialized = String( value ?? '' );
			syncNativeValue( serialized );
			segments = parseValue( input.value, tokenMap );
			activeOffset = input.value.length;
			setAllSelected( false );
			render();
			lastCommittedValue = input.value;
			dispatchNativeEvent( 'change' );
		},
		focus() {
			if ( ! destroyed ) focusAtOffset( activeOffset );
		},
		destroy() {
			if ( destroyed ) return;
			destroyed = true;

			input.removeEventListener( 'focus', onNativeFocus );
			input.removeEventListener( 'input', onNativeInput );
			input.removeEventListener( 'change', onNativeChange );
			form?.removeEventListener( 'reset', onFormReset );
			wrapper.remove();

			if ( ! originalClassHadNative ) input.classList.remove( 'cb-core-token-input__native' );
			if ( originalTabindex === null ) input.removeAttribute( 'tabindex' );
			else input.setAttribute( 'tabindex', originalTabindex );
			if ( originalAriaHidden === null ) input.removeAttribute( 'aria-hidden' );
			else input.setAttribute( 'aria-hidden', originalAriaHidden );

			instances.delete( input );
		},
	};

	instances.set( input, instance );
	return instance;
}

const tokenInput = { create };
window.cbCore = window.cbCore || {};
window.cbCore.tokenInput = tokenInput;

export { create, parseValue, serializeSegments };
export default tokenInput;
