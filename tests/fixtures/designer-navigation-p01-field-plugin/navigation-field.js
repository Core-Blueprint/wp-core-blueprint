/**
 * Development-only consumer of the PUBLIC Core Blueprint Designer API.
 * No private paths, no persistence, no external requests.
 */
import {
    createDesignerNavigation,
    createDesignerShell,
    DESIGNER_PALETTE_ROLES,
} from '@cb-core/design-editor';

const start = () => {
    const launchRoot = document.querySelector('[data-cb-p01-field-mode]');
    const shell = launchRoot?.querySelector('[data-cb-design-shell]');
    const mount = shell?.querySelector('[data-cb-p01-navigation-mount]');
    if (!shell || !mount) return;

    const status = shell.querySelector('[data-cb-design-shell-status]');
    const report = (value) => { if (status) status.textContent = value; };
    const mode = launchRoot.dataset.cbP01FieldMode;

    try {
        if (DESIGNER_PALETTE_ROLES[0] !== 'navigation') {
            throw new Error('Expected the Base Navigation role to precede Elements.');
        }

        const navigation = createDesignerNavigation({
            root: shell,
            ariaLabel: 'Workflow stages',
            activeId: 'when',
            items: [
                { id: 'when', step: '1', label: 'When', description: 'Trigger', targetId: 'cb-p01-stage-when', status: { label: 'Required', tone: 'warning' } },
                { id: 'if', step: '2', label: 'Only if', description: 'Condition', targetId: 'cb-p01-stage-if' },
                { id: 'then', step: '3', label: 'Then', description: 'Actions', targetId: 'cb-p01-stage-then', status: { label: 'Ready', tone: 'success' } },
            ],
        });
        mount.append(navigation.element);

        // Exactly one canonical Base shell controller. Base Designer Mode
        // observes readiness and owns fullscreen, drawers and toolbar chrome.
        const shellController = createDesignerShell(shell, {
            defaultPanels: mode === 'mixed' ? { palette: 'navigation' } : {},
        });

        shell.querySelectorAll('[data-cb-p01-element]').forEach((button) => {
            button.addEventListener('click', () => report('Sample ' + button.dataset.cbP01Element + ' selected (not saved).'));
        });

        shell.addEventListener('cb:design-shell:fullscreenchange', (event) => {
            if (event.detail?.fullscreen) report('Designer ready. This fixture never saves data.');
        });

        window.addEventListener('pagehide', () => {
            navigation.destroy();
            shellController.destroy();
        }, { once: true });
    } catch (error) {
        console.error('[Navigation P01 field fixture]', error);
        report('Fixture initialization failed: ' + (error?.message || 'unknown error'));
        shell.dispatchEvent(new CustomEvent('cb:design-shell:failure', {
            bubbles: true,
            detail: { phase: 'fixture', message: 'Fixture initialization failed: ' + (error?.message || 'unknown error') },
        }));
    }
};

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
else start();
