/**
 * core/time-picker.js - Core Blueprint Suite
 *
 * Progressive-enhancement 24-hour TimePicker Foundation. Enhances a wrapper
 * containing one real text input and one toggle button. The text input remains
 * the only submitted/persistent value and always uses the HH:MM data contract.
 *
 * Official markup:
 *   <div class="cb-core-time-picker" data-cb-time-picker>
 *     <input type="text" value="02:30" inputmode="numeric" autocomplete="off">
 *     <button type="button" class="button" data-cb-time-picker-toggle
 *             aria-label="Choose time"></button>
 *   </div>
 *
 * Public API:
 *   const instance = window.cbCore.timePicker.create( wrapperOrInput );
 *   window.cbCore.timePicker.init( root );
 *   window.cbCore.timePicker.normalize( '9:05' ); // '09:05'
 *
 * Native DOM only. No jQuery, React, wp.element, or @wordpress/components.
 *
 * @package CoreBlueprint\Core
 */

import { create as createIcon } from './icon.js';

const hasDocument = typeof document !== 'undefined';
const dataEl = hasDocument ? document.getElementById( 'wp-script-module-data-@cb-core/time-picker' ) : null;
let data = {};
try {
	data = dataEl ? JSON.parse( dataEl.textContent ) : {};
} catch {
	data = {};
}

const i18n = data.i18n || {};
const presentation = data.presentation === 'core' ? 'core' : 'wp-native';
const instances = new WeakMap();
let observer = null;
let activeInstance = null;
let nextId = 0;

const pad2 = ( value ) => String( value ).padStart( 2, '0' );

/**
 * Normalize a user-entered time to HH:MM.
 * Accepts HH:MM with one/two digit parts and compact HMM/HHMM input.
 * Empty input stays empty. Invalid non-empty input returns null.
 *
 * @param {unknown} value
 * @returns {string|null}
 */
export function normalize( value ) {
	const raw = String( value ?? '' ).trim();
	if ( raw === '' ) return '';

	let hour;
	let minute;
	let match = raw.match( /^(\d{1,2}):(\d{1,2})$/ );
	if ( match ) {
		hour = Number( match[1] );
		minute = Number( match[2] );
	} else if ( /^\d{3,4}$/.test( raw ) ) {
		hour = Number( raw.slice( 0, -2 ) );
		minute = Number( raw.slice( -2 ) );
	} else {
		return null;
	}

	if ( ! Number.isInteger( hour ) || ! Number.isInteger( minute ) || hour < 0 || hour > 23 || minute < 0 || minute > 59 ) {
		return null;
	}

	return `${ pad2( hour ) }:${ pad2( minute ) }`;
}

/** @param {unknown} value @returns {boolean} */
export function isValid( value ) {
	const normalized = normalize( value );
	return normalized !== null && normalized !== '';
}

/**
 * Parse a valid time.
 * @param {unknown} value
 * @returns {{hour:number,minute:number,value:string}|null}
 */
export function parse( value ) {
	const normalized = normalize( value );
	if ( ! normalized ) return null;
	return {
		hour: Number( normalized.slice( 0, 2 ) ),
		minute: Number( normalized.slice( 3, 5 ) ),
		value: normalized,
	};
}

/**
 * Format validated numeric parts.
 * @param {number} hour
 * @param {number} minute
 * @returns {string|null}
 */
export function format( hour, minute ) {
	if ( ! Number.isInteger( hour ) || ! Number.isInteger( minute ) || hour < 0 || hour > 23 || minute < 0 || minute > 59 ) {
		return null;
	}
	return `${ pad2( hour ) }:${ pad2( minute ) }`;
}

function dispatchValueEvents( input ) {
	input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
}

function validateInput( input, { normalizeValue = false } = {} ) {
	const raw = input.value.trim();
	if ( raw === '' ) {
		input.setCustomValidity( '' );
		input.removeAttribute( 'aria-invalid' );
		return true;
	}

	const normalized = normalize( raw );
	if ( normalized === null ) {
		input.setCustomValidity( i18n.invalidTime || 'Enter a valid time from 00:00 through 23:59.' );
		input.setAttribute( 'aria-invalid', 'true' );
		return false;
	}

	if ( normalizeValue && input.value !== normalized ) {
		input.value = normalized;
	}
	input.setCustomValidity( '' );
	input.removeAttribute( 'aria-invalid' );
	return true;
}

function minuteValues( selectedMinute ) {
	const values = [];
	for ( let minute = 0; minute < 60; minute += 5 ) values.push( minute );
	if ( Number.isInteger( selectedMinute ) && selectedMinute >= 0 && selectedMinute <= 59 && ! values.includes( selectedMinute ) ) {
		values.push( selectedMinute );
		values.sort( ( a, b ) => a - b );
	}
	return values;
}

function option( value, label = pad2( value ) ) {
	const node = document.createElement( 'option' );
	node.value = String( value );
	node.textContent = label;
	return node;
}

function resolveWrapper( target ) {
	if ( ! target || typeof target !== 'object' ) return null;
	if ( target.matches?.( '[data-cb-time-picker]' ) ) return target;
	if ( target.matches?.( 'input' ) ) return target.closest?.( '[data-cb-time-picker]' ) || null;
	return null;
}

function findToggle( wrapper ) {
	return wrapper.querySelector( '[data-cb-time-picker-toggle]' ) || wrapper.querySelector( 'button[type="button"]' );
}

function decorateToggle( toggle ) {
	if ( toggle.childNodes.length > 0 ) return;
	const icon = createIcon( 'clock', { size: 'default' } );
	if ( icon ) toggle.appendChild( icon );
}

function closeActive( except = null, returnFocus = false ) {
	if ( activeInstance && activeInstance !== except ) {
		activeInstance.close( { returnFocus } );
	}
}

function createPanel( wrapper, input, toggle ) {
	const baseId = input.id ? `${ input.id }-time-picker` : `cb-core-time-picker-${ ++nextId }`;
	let panelId = `${ baseId }-panel`;
	while ( document.getElementById( panelId ) ) panelId = `cb-core-time-picker-${ ++nextId }-panel`;

	const panel = document.createElement( 'div' );
	panel.id = panelId;
	panel.className = 'cb-core-time-picker__panel';
	panel.hidden = true;
	panel.setAttribute( 'role', 'dialog' );
	panel.setAttribute( 'aria-label', i18n.chooseTime || 'Choose time' );

	const controls = document.createElement( 'div' );
	controls.className = 'cb-core-time-picker__controls';

	const hourField = document.createElement( 'div' );
	hourField.className = 'cb-core-time-picker__field';
	const hourLabel = document.createElement( 'label' );
	const hourId = `${ baseId }-hour`;
	hourLabel.htmlFor = hourId;
	hourLabel.textContent = i18n.hour || 'Hour';
	const hourSelect = document.createElement( 'select' );
	hourSelect.id = hourId;
	hourSelect.className = 'cb-core-time-picker__select cb-core-time-picker__hour';
	for ( let hour = 0; hour < 24; hour++ ) hourSelect.appendChild( option( hour ) );
	hourField.append( hourLabel, hourSelect );

	const minuteField = document.createElement( 'div' );
	minuteField.className = 'cb-core-time-picker__field';
	const minuteLabel = document.createElement( 'label' );
	const minuteId = `${ baseId }-minute`;
	minuteLabel.htmlFor = minuteId;
	minuteLabel.textContent = i18n.minute || 'Minute';
	const minuteSelect = document.createElement( 'select' );
	minuteSelect.id = minuteId;
	minuteSelect.className = 'cb-core-time-picker__select cb-core-time-picker__minute';
	minuteField.append( minuteLabel, minuteSelect );

	controls.append( hourField, minuteField );

	const footer = document.createElement( 'div' );
	footer.className = 'cb-core-time-picker__footer';
	const done = document.createElement( 'button' );
	done.type = 'button';
	done.className = 'button cb-core-time-picker__done';
	done.textContent = i18n.done || 'Done';
	footer.appendChild( done );

	panel.append( controls, footer );
	wrapper.appendChild( panel );

	toggle.setAttribute( 'aria-expanded', 'false' );
	toggle.setAttribute( 'aria-controls', panel.id );
	toggle.setAttribute( 'aria-haspopup', 'dialog' );

	return { panel, hourSelect, minuteSelect, done };
}


function positionPanel( wrapper, panel ) {
	wrapper.classList.remove( 'is-align-right', 'is-above' );
	const rect = panel.getBoundingClientRect();
	const viewportWidth = window.innerWidth || document.documentElement.clientWidth;
	const viewportHeight = window.innerHeight || document.documentElement.clientHeight;
	if ( rect.right > viewportWidth - 8 ) wrapper.classList.add( 'is-align-right' );
	if ( rect.bottom > viewportHeight - 8 ) wrapper.classList.add( 'is-above' );
}

/**
 * Enhance one TimePicker wrapper (or an input inside one).
 *
 * @param {Element} target
 * @param {Object} [options]
 * @returns {Object|null}
 */
export function create( target ) {
	if ( ! hasDocument ) return null;
	const wrapper = resolveWrapper( target );
	if ( ! wrapper ) return null;
	if ( instances.has( wrapper ) ) return instances.get( wrapper );

	const input = wrapper.querySelector( 'input[type="text"]' );
	const toggle = findToggle( wrapper );
	if ( ! input || ! toggle ) return null;

	const controller = new AbortController();
	const { signal } = controller;

	wrapper.classList.add( 'cb-core-time-picker', `cb-core-time-picker--${ presentation }` );
	wrapper.dataset.cbTimePickerReady = 'true';
	input.classList.add( 'cb-core-time-picker__input' );
	input.inputMode = 'numeric';
	input.autocomplete = 'off';
	if ( ! input.maxLength || input.maxLength > 5 ) input.maxLength = 5;
	if ( ! input.pattern ) input.pattern = '(?:[01]\\d|2[0-3]):[0-5]\\d';

	toggle.classList.add( 'button', 'cb-core-time-picker__toggle' );
	toggle.dataset.cbTimePickerToggle = '';
	if ( ! toggle.getAttribute( 'aria-label' ) ) toggle.setAttribute( 'aria-label', i18n.chooseTime || 'Choose time' );
	decorateToggle( toggle );

	const { panel, hourSelect, minuteSelect, done } = createPanel( wrapper, input, toggle );

	const getParsedOrDefault = () => parse( input.value ) || { hour: 0, minute: 0, value: '00:00' };

	const rebuildMinutes = ( selectedMinute ) => {
		minuteSelect.replaceChildren();
		for ( const minute of minuteValues( selectedMinute ) ) minuteSelect.appendChild( option( minute ) );
		minuteSelect.value = String( selectedMinute );
	};

	const syncPickerFromInput = () => {
		const selected = getParsedOrDefault();
		hourSelect.value = String( selected.hour );
		rebuildMinutes( selected.minute );
	};

	const commitPicker = () => {
		const value = format( Number( hourSelect.value ), Number( minuteSelect.value ) );
		if ( value === null ) return;
		input.value = value;
		validateInput( input );
		dispatchValueEvents( input );
	};

	const instance = {
		wrapper,
		input,
		toggle,
		panel,
		getValue() {
			return input.value;
		},
		setValue( value, setOptions = {} ) {
			const normalized = normalize( value );
			if ( normalized === null ) {
				input.value = String( value ?? '' );
				validateInput( input );
				return false;
			}
			input.value = normalized;
			validateInput( input );
			if ( setOptions.emit !== false ) dispatchValueEvents( input );
			if ( ! panel.hidden ) syncPickerFromInput();
			return true;
		},
		open() {
			if ( input.disabled || input.readOnly || toggle.disabled ) return false;
			closeActive( instance, false );
			validateInput( input, { normalizeValue: true } );
			syncPickerFromInput();
			panel.hidden = false;
			toggle.setAttribute( 'aria-expanded', 'true' );
			wrapper.classList.add( 'is-open' );
			activeInstance = instance;
			requestAnimationFrame( () => positionPanel( wrapper, panel ) );
			hourSelect.focus();
			return true;
		},
		close( closeOptions = {} ) {
			if ( panel.hidden ) return;
			panel.hidden = true;
			toggle.setAttribute( 'aria-expanded', 'false' );
			wrapper.classList.remove( 'is-open', 'is-align-right', 'is-above' );
			if ( activeInstance === instance ) activeInstance = null;
			if ( closeOptions.returnFocus ) toggle.focus();
		},
		focus() {
			input.focus();
		},
		destroy() {
			if ( activeInstance === instance ) activeInstance = null;
			controller.abort();
			panel.remove();
			wrapper.classList.remove( 'cb-core-time-picker', `cb-core-time-picker--${ presentation }`, 'is-open', 'is-align-right', 'is-above' );
			delete wrapper.dataset.cbTimePickerReady;
			input.classList.remove( 'cb-core-time-picker__input' );
			toggle.classList.remove( 'cb-core-time-picker__toggle' );
			toggle.removeAttribute( 'aria-expanded' );
			toggle.removeAttribute( 'aria-controls' );
			toggle.removeAttribute( 'aria-haspopup' );
			instances.delete( wrapper );
		},
	};

	instances.set( wrapper, instance );

	toggle.addEventListener( 'click', () => {
		if ( panel.hidden ) instance.open();
		else instance.close( { returnFocus: true } );
	}, { signal } );

	toggle.addEventListener( 'keydown', ( event ) => {
		if ( event.key === 'ArrowDown' ) {
			event.preventDefault();
			instance.open();
		}
	}, { signal } );

	hourSelect.addEventListener( 'change', commitPicker, { signal } );
	minuteSelect.addEventListener( 'change', commitPicker, { signal } );
	done.addEventListener( 'click', () => instance.close( { returnFocus: true } ), { signal } );

	input.addEventListener( 'change', () => validateInput( input, { normalizeValue: true } ), { signal } );
	input.addEventListener( 'blur', () => validateInput( input, { normalizeValue: true } ), { signal } );
	input.addEventListener( 'input', () => {
		if ( input.value.trim() === '' || normalize( input.value ) !== null ) {
			input.setCustomValidity( '' );
			input.removeAttribute( 'aria-invalid' );
		}
	}, { signal } );

	wrapper.addEventListener( 'focusout', ( event ) => {
		if ( panel.hidden ) return;
		const next = event.relatedTarget;
		if ( next && ! wrapper.contains( next ) ) instance.close();
	}, { signal } );

	validateInput( input, { normalizeValue: true } );
	return instance;
}

/** Enhance all declarative TimePickers within a root. */
export function init( root = hasDocument ? document : null ) {
	if ( ! root?.querySelectorAll ) return [];
	const wrappers = [];
	if ( root.matches?.( '[data-cb-time-picker]' ) ) wrappers.push( root );
	wrappers.push( ...root.querySelectorAll( '[data-cb-time-picker]' ) );
	return wrappers.map( ( wrapper ) => create( wrapper ) ).filter( Boolean );
}

/** Destroy one declarative TimePicker wrapper or input. */
export function destroy( target ) {
	const wrapper = resolveWrapper( target );
	const instance = wrapper ? instances.get( wrapper ) : null;
	if ( ! instance ) return false;
	instance.destroy();
	return true;
}

function destroyWithin( root ) {
	if ( ! root || root.nodeType !== 1 ) return;
	if ( root.matches?.( '[data-cb-time-picker]' ) ) destroy( root );
	root.querySelectorAll?.( '[data-cb-time-picker]' ).forEach( ( wrapper ) => destroy( wrapper ) );
}

function startObserver() {
	if ( observer || ! hasDocument || ! document.documentElement ) return;
	observer = new MutationObserver( ( mutations ) => {
		for ( const mutation of mutations ) {
			mutation.addedNodes.forEach( ( node ) => {
				if ( node.nodeType === 1 ) init( node );
			} );
			mutation.removedNodes.forEach( ( node ) => destroyWithin( node ) );
		}
	} );
	observer.observe( document.documentElement, { childList: true, subtree: true } );
}

if ( hasDocument ) {
	document.addEventListener( 'pointerdown', ( event ) => {
		if ( activeInstance && ! activeInstance.wrapper.contains( event.target ) ) activeInstance.close();
	} );

	document.addEventListener( 'keydown', ( event ) => {
		if ( event.key === 'Escape' && activeInstance ) {
			event.preventDefault();
			activeInstance.close( { returnFocus: true } );
		}
	} );

	const boot = () => {
		init( document );
		startObserver();
	};
	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot, { once: true } );
	else boot();
}

const timePicker = Object.freeze( { create, init, destroy, normalize, isValid, parse, format } );
if ( typeof window !== 'undefined' ) {
	window.cbCore = window.cbCore || {};
	window.cbCore.timePicker = timePicker;
}

export default timePicker;
