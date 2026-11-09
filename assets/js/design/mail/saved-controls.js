/**
 * Mail-only static ESM bridge into the native Platform Host.
 * Enqueued only on the actual fullscreen Mail Designer screen.
 * No dynamic import, custom Host, History or second Save authority.
 */
import {registerNativeMailTemplateActions} from '@cb-core/base-platform-v1';
import {bindMailTemplates} from '@cb-core/mail-saved-library';

registerNativeMailTemplateActions(bindMailTemplates);
