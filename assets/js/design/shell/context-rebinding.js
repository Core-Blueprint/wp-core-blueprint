import { prepareDesignerShellRebinding } from './index.js';
import { prepareDesignerSelectionRebinding } from './selection-controller.js';

/**
 * CX-02C: Base-private, synchronous view binding for the CX-02D coordinator.
 *
 * This does not change managed write authority or persistence. The caller
 * must switch canonical authority before commit(), verify all mounted views,
 * then finalize() only after successful retirement. Until finalize() both
 * original subscriptions remain available for a lossless rollback.
 *
 * No public controller, window API or consumer receives this receipt.
 */
export const prepareDesignerViewRebinding = ({
	shell, selection = null, sourceSession, targetSession,
} = {}) => {
	if (!sourceSession || !targetSession || sourceSession === targetSession) {
		throw new Error('DESIGNER_VIEW_REBIND_REFUSED');
	}
	const shellReceipt = prepareDesignerShellRebinding(shell, targetSession, sourceSession);
	let selectionReceipt = null;
	try {
		if (selection !== null) {
			selectionReceipt = prepareDesignerSelectionRebinding(
				selection, targetSession, sourceSession,
			);
		}
	} catch (error) {
		try { shellReceipt.rollback(); }
		catch (rollbackError) {
			const recovery = new Error('DESIGNER_VIEW_RECOVERY_REQUIRED', {cause: error});
			recovery.code = 'RECOVERY_REQUIRED';
			recovery.rollbackError = rollbackError;
			throw recovery;
		}
		throw error;
	}
	let phase = 'PREPARED';
	const rollback = () => {
		if (phase === 'ROLLED_BACK') return true;
		if (phase === 'FINALIZED') throw new Error('DESIGNER_VIEW_REBIND_FINALIZED');
		const failures = [];
		try { selectionReceipt?.rollback(); } catch (error) { failures.push(error); }
		try { shellReceipt.rollback(); } catch (error) { failures.push(error); }
		phase = failures.length ? 'RECOVERY_REQUIRED' : 'ROLLED_BACK';
		if (failures.length) {
			const error = new Error('DESIGNER_VIEW_RECOVERY_REQUIRED');
			error.code = 'RECOVERY_REQUIRED';
			error.rollbackErrors = failures;
			throw error;
		}
		return true;
	};
	return Object.freeze({
		commit() {
			if (phase !== 'PREPARED') throw new Error('DESIGNER_VIEW_REBIND_REFUSED');
			try {
				shellReceipt.commit();
				selectionReceipt?.commit();
				phase = 'COMMITTED';
				return true;
			} catch (error) {
				try { rollback(); }
				catch (rollbackError) {
					if (rollbackError.code === 'RECOVERY_REQUIRED') throw rollbackError;
					const recovery = new Error('DESIGNER_VIEW_RECOVERY_REQUIRED', {cause: error});
					recovery.code = 'RECOVERY_REQUIRED';
					recovery.rollbackError = rollbackError;
					throw recovery;
				}
				throw error;
			}
		},
		rollback,
		finalize() {
			if (phase !== 'COMMITTED') throw new Error('DESIGNER_VIEW_REBIND_REFUSED');
			try {
				selectionReceipt?.finalize();
				shellReceipt.finalize();
			} catch (error) {
				phase = 'RECOVERY_REQUIRED';
				const recovery = new Error('DESIGNER_VIEW_RECOVERY_REQUIRED', {cause: error});
				recovery.code = 'RECOVERY_REQUIRED';
				throw recovery;
			}
			phase = 'FINALIZED';
			return true;
		},
	});
};
