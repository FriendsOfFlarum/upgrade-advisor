import Model from 'flarum/common/Model';

export type CheckStatus = 'pass' | 'warning' | 'fail';

export type ExtStatus = 'compatible' | 'incompatible' | 'unknown' | 'superseded' | 'abandoned';

/**
 * Mirrors FoF\UpgradeAdvisor\ExtensionAction.
 */
export type ExtAction = 'none' | 'remove' | 'swap_after_upgrade' | 'switch_replacement' | 'no_path' | 'unknown';

export const BLOCKING_ACTIONS: ExtAction[] = ['remove', 'swap_after_upgrade', 'switch_replacement', 'no_path'];
export const READY_ACTIONS: ExtAction[] = ['none'];

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
  reason: 'into_core' | 'replaced' | null;
  replacement: string | null;
  replacementCompatible: boolean | null;
  compatibleVersion: string | null;
  latestVersion: string | null;
  source: 'packagist' | 'composer' | 'floxum' | 'discuss' | 'abandoned_list' | 'composer_abandoned' | 'superseded_list' | null;
  contact: Contact;
  action: ExtAction;
  hint: { key: string; params: Record<string, string> };
}

export interface CheckData {
  id: string;
  category: string;
  status: CheckStatus;
  current: string | null;
  meta: Record<string, any>;
}

export default class Report extends Model {
  overall() {
    return Model.attribute<CheckStatus>('overall').call(this);
  }

  flarumMajor() {
    return Model.attribute<string>('flarumMajor').call(this);
  }

  checkedAt() {
    return Model.attribute('checkedAt', Model.transformDate).call(this);
  }

  dataAsOf() {
    return Model.attribute('dataAsOf', Model.transformDate).call(this);
  }

  checks() {
    return Model.attribute<CheckData[]>('checks').call(this) || [];
  }

  /**
   * The extension breakdown from the compatibility check.
   */
  extensions(): ExtensionCompat[] {
    const check = this.checks().find((c) => Array.isArray(c.meta.extensions));

    return check ? check.meta.extensions : [];
  }

  /**
   * Every check except the extension compatibility one, which has its own UI.
   */
  otherChecks(): CheckData[] {
    return this.checks().filter((c) => !Array.isArray(c.meta.extensions));
  }
}
