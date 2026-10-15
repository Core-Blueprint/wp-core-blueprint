const isObject = (value) => value !== null && typeof value === 'object' && !Array.isArray(value);

export const MAIL_DESIGN_TYPE = 'mail-template';
export const MAIL_ROOT_TYPE = 'mail.root';
export const MAIL_WIDTH_MIN = 320;
export const MAIL_WIDTH_MAX = 800;
export const MAIL_WIDTH_DEFAULT = 600;
export const MAIL_FONT_FAMILIES = Object.freeze([
	'Arial, Helvetica, sans-serif',
	'Helvetica, Arial, sans-serif',
	'Georgia, Times New Roman, serif',
	'Tahoma, Verdana, sans-serif',
	'Verdana, Geneva, sans-serif',
]);

const normalizeNumber = (value, fallback, min, max) => {
	const number = Number(value);
	if (!Number.isFinite(number)) return fallback;
	return Math.max(min, Math.min(max, number));
};

const normalizeFontFamily = (value) => {
	const fontFamily = String(value || '').trim();
	return MAIL_FONT_FAMILIES.includes(fontFamily) ? fontFamily : MAIL_FONT_FAMILIES[0];
};

export const normalizeMailLayout = (layout = {}) => {
	if (!isObject(layout)) throw new TypeError('Mail layout must be an object.');
	return Object.freeze({
		width: normalizeNumber(layout.width, MAIL_WIDTH_DEFAULT, MAIL_WIDTH_MIN, MAIL_WIDTH_MAX),
		background: String(layout.background || '#f3f4f6'),
		contentBackground: String(layout.contentBackground || '#ffffff'),
		fontFamily: normalizeFontFamily(layout.fontFamily),
		textColor: String(layout.textColor || '#1f2937'),
		accentColor: String(layout.accentColor || '#2563eb'),
	});
};

const STRUCTURAL_TYPES = new Set(['mail.root', 'mail.section', 'mail.columns', 'mail.column']);
const validateNode = (node, depth = 0, parent = null) => {
	if (depth > 64) throw new RangeError('Mail design tree exceeds the supported nesting depth.');
	if (!isObject(node)) throw new TypeError('Every mail design node must be an object.');
	if (typeof node.type !== 'string' || node.type === '') throw new TypeError('Mail design nodes require a type.');
	if (typeof node.provider !== 'string' || node.provider === '') throw new TypeError('Mail design nodes require a provider.');
	if (!isObject(node.properties)) throw new TypeError('Mail design node properties must be an object.');
	if (!Array.isArray(node.children)) throw new TypeError('Mail design node children must be an array.');
	// Match the server's Mail structural contract without restricting
	// extension-owned leaf components or changing the shared Designer Shell.
	if (node.type === 'mail.section' && parent !== 'mail.root') {
		throw new TypeError('Mail sections must be direct children of the mail root.');
	}
	if (node.type === 'mail.columns') {
		if (parent !== 'mail.section') throw new TypeError('Mail columns must belong directly to a section.');
		if (node.children.length !== 2 || node.children.some((child) => (
			!isObject(child) || child.type !== 'mail.column' || child.provider !== 'core'
		))) throw new TypeError('Mail two-column layouts require two core column containers.');
	}
	if (node.type === 'mail.column') {
		if (parent !== 'mail.columns') throw new TypeError('Mail column containers require a parent columns layout.');
		if (node.children.some((child) => isObject(child) && STRUCTURAL_TYPES.has(child.type))) {
			throw new TypeError('Mail columns may contain content blocks, not structural layouts.');
		}
	}
	node.children.forEach((child) => validateNode(child, depth + 1, node.type));
};

export const validateProject = (project) => {
	if (!isObject(project) || project.design_type !== MAIL_DESIGN_TYPE) {
		throw new TypeError(`Mail editor requires design_type ${MAIL_DESIGN_TYPE}.`);
	}
	if (!isObject(project.root) || project.root.type !== MAIL_ROOT_TYPE) {
		throw new TypeError(`Mail editor requires root type ${MAIL_ROOT_TYPE}.`);
	}
	normalizeMailLayout(project.root.properties?.layout);
	validateNode(project.root);
	return project;
};
