export {
	MAIL_DESIGN_TYPE,
	MAIL_FONT_FAMILIES,
	MAIL_ROOT_TYPE,
	MAIL_WIDTH_DEFAULT,
	MAIL_WIDTH_MAX,
	MAIL_WIDTH_MIN,
	normalizeMailLayout,
	validateProject,
} from './layout.js';

// Additive public Mail profile editor control. Consumers import through
// profiles.mail on @cb-core/design-editor, never private relative files.
export { createMailRichTextControls } from './rich-text-controls.js';
