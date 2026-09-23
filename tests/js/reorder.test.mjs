import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const sourceUrl = new URL('../../assets/js/core/reorder.js', import.meta.url);
const source = await readFile(sourceUrl, 'utf8');
const moduleUrl = `data:text/javascript;base64,${Buffer.from(source).toString('base64')}`;
const reorder = await import(moduleUrl);

const {
	applyMove,
	normalizeIdentifier,
	normalizeSnapshot,
	planMove,
} = reorder;

test('opaque identifiers normalize without domain inference', () => {
	assert.equal(normalizeIdentifier(42), '42');
	assert.equal(normalizeIdentifier('  doc:42  '), 'doc:42');
	assert.equal(normalizeIdentifier('section:getting-started'), 'section:getting-started');
	assert.equal(normalizeIdentifier(''), '');
	assert.equal(normalizeIdentifier('   '), '');
	assert.equal(normalizeIdentifier(false), '');
	assert.equal(normalizeIdentifier(null), '');
	assert.equal(normalizeIdentifier({ id: 42 }), '');
	assert.equal(normalizeIdentifier('a'.repeat(192)), '');
	assert.equal(normalizeIdentifier('a'.repeat(191)), 'a'.repeat(191));
});

test('snapshot validation rejects duplicate list and item identifiers', () => {
	assert.throws(
		() => normalizeSnapshot([
			{ listId: 'a', itemIds: ['1'] },
			{ listId: 'a', itemIds: ['2'] },
		]),
		/duplicate list/i
	);

	assert.throws(
		() => normalizeSnapshot([
			{ listId: 'a', itemIds: ['1'] },
			{ listId: 'b', itemIds: ['1'] },
		]),
		/duplicate item/i
	);
});

test('same-list reorder is deterministic', () => {
	const before = normalizeSnapshot([
		{ listId: 'alpha', itemIds: ['a', 'b', 'c', 'd'] },
	]);

	const move = planMove(before, 'c', 'alpha', 0, { crossList: false });
	assert.ok(move);
	assert.deepEqual(move.from, { listId: 'alpha', index: 2 });
	assert.deepEqual(move.to, { listId: 'alpha', index: 0 });

	const after = applyMove(before, move);
	assert.deepEqual(after, [
		{ listId: 'alpha', itemIds: ['c', 'a', 'b', 'd'] },
	]);
});

test('same-list forward move uses the requested final index', () => {
	const before = normalizeSnapshot([
		{ listId: 'alpha', itemIds: ['a', 'b', 'c', 'd'] },
	]);

	const move = planMove(before, 'a', 'alpha', 3, { crossList: false });
	assert.ok(move);

	const after = applyMove(before, move);
	assert.deepEqual(after[0].itemIds, ['b', 'c', 'd', 'a']);
});

test('cross-list moves are rejected unless explicitly enabled', () => {
	const before = normalizeSnapshot([
		{ listId: 'alpha', itemIds: ['a', 'b'] },
		{ listId: 'beta', itemIds: ['c'] },
	]);

	assert.equal(planMove(before, 'b', 'beta', 0, { crossList: false }), null);

	const move = planMove(before, 'b', 'beta', 0, { crossList: true });
	assert.ok(move);
	const after = applyMove(before, move);
	assert.deepEqual(after, [
		{ listId: 'alpha', itemIds: ['a'] },
		{ listId: 'beta', itemIds: ['b', 'c'] },
	]);
});

test('planned move reports the complete affected-list projection', () => {
	const before = normalizeSnapshot([
		{ listId: 'alpha', itemIds: ['a', 'b'] },
		{ listId: 'beta', itemIds: ['c', 'd'] },
	]);
	const move = planMove(before, 'b', 'beta', 1, { crossList: true });
	assert.ok(move);

	assert.deepEqual(move.affectedLists, [
		{ listId: 'alpha', itemIds: ['a'] },
		{ listId: 'beta', itemIds: ['c', 'b', 'd'] },
	]);
});

test('invalid targets and no-op moves fail safely', () => {
	const before = normalizeSnapshot([
		{ listId: 'alpha', itemIds: ['a', 'b'] },
	]);

	assert.equal(planMove(before, 'missing', 'alpha', 0, { crossList: false }), null);
	assert.equal(planMove(before, 'a', 'missing', 0, { crossList: false }), null);
	assert.equal(planMove(before, 'a', 'alpha', 0, { crossList: false }), null);
	assert.equal(planMove(before, 'a', 'alpha', -1, { crossList: false }), null);
	assert.equal(planMove(before, 'a', 'alpha', 99, { crossList: false }), null);
});


test('Reorder runtime owns reduced-motion-aware FLIP settling without expanding the public controller API', () => {
	assert.match(source, /MOVE_ANIMATION_DURATION_MS = 180/);
	assert.match(source, /prefers-reduced-motion: reduce/);
	assert.match(source, /getBoundingClientRect\(\)/);
	assert.match(source, /item\.animate\(/);
	assert.match(source, /\{ translate: `\$\{deltaX\}px \$\{deltaY\}px` \}/);
	assert.match(source, /applyAnimatedDomSnapshot\(after, affectedListIds\)/);
	assert.match(source, /applyAnimatedDomSnapshot\(before, affectedListIds\)/);
	assert.match(source, /for \(const animation of motionAnimations\) animation\.cancel\(\)/);
	assert.doesNotMatch(source, /animate:\s*options\./);
});
