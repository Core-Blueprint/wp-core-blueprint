import { assertDigest, exactKeys } from './manifest.js';
import { canonicalDigest } from './registry.js';

const failed = (code) => Object.freeze({ready: false, code});
const ready = Object.freeze({ready: true});

/**
 * Browser-only readiness. The evidence must already be minted and verified by
 * installed PHP; computed style detects stale CSS, not browser-byte integrity.
 * No fetch(), arbitrary import(), DOM-provided URLs or generated hashes.
 */
export async function preflight({evidence, frozenRegistry, root, contextEpoch, signal}) {
	if (signal?.aborted) return failed('ARTIFACT_RUNTIME_MISMATCH');
	if (!root?.isConnected || !root.ownerDocument?.defaultView?.getComputedStyle) return failed('CSS_RUNTIME_STALE');
	if (evidence !== frozenRegistry.evidence || evidence?.contractVersion !== 1
		|| evidence.verificationStatus !== 'verified'
		|| !Number.isSafeInteger(contextEpoch)
		|| frozenRegistry.identity.contextEpoch !== contextEpoch) return failed('ARTIFACT_RUNTIME_MISMATCH');
	try {
		assertDigest(frozenRegistry.fingerprint);
		assertDigest(evidence.buildId);
		assertDigest(evidence.moduleGraphFingerprint);
		// These describe installed transitive file paths, NOT Registry IDs.
		// Server-installed ArtifactEvidence verifies the underlying package graph.
		assertDigest(evidence.artifactModuleGraphFingerprint);
		if (evidence.context?.registryFingerprint !== frozenRegistry.fingerprint
			|| !evidence.approvedPackages?.base || !evidence.approvedPackages?.consumer) {
			return failed('ARTIFACT_RUNTIME_MISMATCH');
		}
		for (const packageId of ['base', 'consumer']) {
			assertDigest(evidence.approvedPackages[packageId].manifestDigest);
			assertDigest(evidence.approvedPackages[packageId].buildId);
		}
		await frozenRegistry.verify();
		if (signal.aborted) return failed('ARTIFACT_RUNTIME_MISMATCH');
		const expectedRegistryGraph = await canonicalDigest(frozenRegistry.modules, 'ModuleGraphV1');
		if (expectedRegistryGraph !== evidence.moduleGraphFingerprint) return failed('ARTIFACT_RUNTIME_MISMATCH');
		if (!evidence.moduleStamps || typeof evidence.moduleStamps !== 'object'
			|| Array.isArray(evidence.moduleStamps)
			|| !evidence.moduleFileBindings || typeof evidence.moduleFileBindings !== 'object'
			|| Array.isArray(evidence.moduleFileBindings)
			|| !Array.isArray(evidence.requiredCss)
			|| Object.keys(evidence.moduleStamps).length !== frozenRegistry.modules.length
			|| Object.keys(evidence.moduleFileBindings).length !== frozenRegistry.modules.length) {
			return failed('ARTIFACT_RUNTIME_MISMATCH');
		}
		const seenPaths = new Set();
		for (const module of frozenRegistry.modules) {
			const id = module.id;
			const path = module.packagePath;
			const binding = Object.hasOwn(evidence.moduleFileBindings, id)
				? evidence.moduleFileBindings[id] : null;
			if (typeof id !== 'string' || typeof path !== 'string'
				|| !/^(?:base|consumer)\/[A-Za-z0-9_.@/-]+\.js$/.test(path)
				|| path.includes('//') || path.split('/').some((part) => part === '..' || part === '.')
				|| seenPaths.has(path) || !Object.hasOwn(evidence.moduleStamps, id)
				|| evidence.moduleStamps[id] !== module.stamp
				|| !binding || typeof binding !== 'object' || Array.isArray(binding)) {
				return failed('ARTIFACT_RUNTIME_MISMATCH');
			}
			exactKeys(binding, ['packagePath', 'packagedDigest']);
			assertDigest(binding.packagedDigest);
			if (binding.packagePath !== path) return failed('ARTIFACT_RUNTIME_MISMATCH');
			seenPaths.add(path);
			const namespace = frozenRegistry.moduleNamespace(module.id);
			if (!namespace || typeof namespace !== 'object') return failed('HOST_MODULE_MISSING');
			// This stamp is exported by the installed ESM itself, not copied
			// from PHP evidence. Old JS + new PHP therefore refuses READY.
			if (!Object.hasOwn(namespace, '__cbPlatformModuleStamp')
				|| namespace.__cbPlatformModuleStamp !== module.stamp) return failed('ARTIFACT_RUNTIME_MISMATCH');
			for (const expectedExport of module.exports) {
				if (!Object.hasOwn(namespace, expectedExport) || typeof namespace[expectedExport] !== 'function') {
					return failed('HOST_MODULE_MISSING');
				}
			}
		}
		const seenAssets = new Set();
		const seenSelectors = new Set();
		for (const css of evidence.requiredCss) {
			exactKeys(css, ['assetId','approvedCssWitness','packagedDigest','selector']);
			if (typeof css.assetId !== 'string' || !/^[a-z][a-z0-9-]{0,63}$/.test(css.assetId)
				|| seenAssets.has(css.assetId) || typeof css.selector !== 'string'
				|| !/^\[data-cb-artifact-[a-z0-9-]+\]$/.test(css.selector)
				|| seenSelectors.has(css.selector)
				|| typeof css.approvedCssWitness !== 'string' || !/^[a-f0-9]{64}$/.test(css.approvedCssWitness)
				|| typeof css.packagedDigest !== 'string') {
				return failed('ARTIFACT_RUNTIME_MISMATCH');
			}
			assertDigest(css.packagedDigest);
			seenAssets.add(css.assetId);
			seenSelectors.add(css.selector);
			// Probe is temporarily attached UNDER the controlled, connected editor
			// root. Only the verified packaged CSS rule may set this property.
			const probe = root.ownerDocument.createElement('div');
			probe.setAttribute(css.selector.slice(1, -1), '');
			probe.setAttribute('aria-hidden', 'true');
			probe.style.cssText = 'position:absolute;width:0;height:0;overflow:hidden;pointer-events:none;';
			try {
				root.appendChild(probe);
				if (signal.aborted || !probe.isConnected || !root.contains(probe)) return failed('CSS_RUNTIME_STALE');
				const actual = root.ownerDocument.defaultView.getComputedStyle(probe)
					.getPropertyValue('--cb-artifact-witness').trim();
				if (actual !== css.approvedCssWitness) return failed('CSS_RUNTIME_STALE');
			} finally {
				probe.remove();
			}
			if (signal.aborted) return failed('ARTIFACT_RUNTIME_MISMATCH');
		}
		return ready;
	} catch (_) {
		return failed('ARTIFACT_RUNTIME_MISMATCH');
	}
}
