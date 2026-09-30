import Model from 'flarum/common/Model';
export type CheckStatus = 'pass' | 'warning' | 'fail';
export type ExtStatus = 'compatible' | 'incompatible' | 'unknown' | 'superseded' | 'abandoned';
/**
 * Mirrors FoF\UpgradeAdvisor\ExtensionAction.
 */
export type ExtAction = 'none' | 'remove' | 'swap_after_upgrade' | 'switch_replacement' | 'no_path' | 'unknown' | 'remove_last';
export declare const BLOCKING_ACTIONS: ExtAction[];
export declare const READY_ACTIONS: ExtAction[];
export interface ContactAuthor {
    name: string | null;
    email: string | null;
    homepage: string | null;
}
export interface Contact {
    forum: string | null;
    issues: string | null;
    source: string | null;
    authors: ContactAuthor[];
}
export interface ExtensionCompat {
    id: string;
    name: string;
    title: string;
    installedVersion: string | null;
    status: ExtStatus;
    reason: 'into_core' | 'replaced' | 'self' | null;
    replacement: string | null;
    replacementCompatible: boolean | null;
    compatibleVersion: string | null;
    latestVersion: string | null;
    source: 'packagist' | 'composer' | 'floxum' | 'discuss' | 'abandoned_list' | 'composer_abandoned' | 'superseded_list' | null;
    contact: Contact;
    action: ExtAction;
    hint: {
        key: string;
        params: Record<string, string>;
    };
}
export interface CheckData {
    id: string;
    category: string;
    status: CheckStatus;
    current: string | null;
    meta: Record<string, any>;
}
export default class Report extends Model {
    overall(): CheckStatus;
    flarumMajor(): string;
    checkedAt(): Date | null | undefined;
    dataAsOf(): Date | null | undefined;
    checks(): CheckData[];
    /**
     * The extension breakdown from the compatibility check.
     */
    extensions(): ExtensionCompat[];
    /**
     * Every check except the extension compatibility one, which has its own UI.
     */
    otherChecks(): CheckData[];
}
