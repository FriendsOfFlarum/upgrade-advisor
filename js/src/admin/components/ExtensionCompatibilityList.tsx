import app from 'flarum/admin/app';
import Component, { ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LinkButton from 'flarum/common/components/LinkButton';
import Tooltip from 'flarum/common/components/Tooltip';
import Icon from 'flarum/common/components/Icon';
import type Mithril from 'mithril';

import type { CheckData, ExtAction, ExtensionCompat } from '../models/Report';
import { checkDescription } from '../utils/checkText';
import { contactLinks, extensionTitle, hintText } from '../utils/extensionLinks';

type GroupKey = 'environment' | 'decision' | 'unknown' | 'replace' | 'ready';

interface Attrs extends ComponentAttrs {
  extensions: ExtensionCompat[];
  checks: CheckData[];
}

/**
 * UI groups, in the order an admin works through them: what needs a decision
 * first, mechanical removals next, the ready inventory last. The CSV keeps the
 * finer-grained action keys.
 */
const GROUPS: { key: GroupKey; icon: string; actions: ExtAction[] }[] = [
  { key: 'decision', icon: 'fas fa-ban', actions: ['no_path'] },
  { key: 'unknown', icon: 'fas fa-question-circle', actions: ['unknown'] },
  { key: 'replace', icon: 'fas fa-exchange-alt', actions: ['remove', 'swap_after_upgrade', 'switch_replacement'] },
  { key: 'ready', icon: 'fas fa-check-circle', actions: ['none', 'remove_last'] },
];

const COLUMNS = 5;

/**
 * Every extension in one table, grouped by what the admin needs to do. Each
 * group explains itself once in its header; rows carry only what's specific
 * to that extension.
 */
export default class ExtensionCompatibilityList extends Component<Attrs> {
  collapsed: Record<GroupKey, boolean> = { environment: false, decision: false, unknown: false, replace: false, ready: true };
  copied: string | null = null;

  view() {
    // Warnings show on the header chips; only a failing check blocks the upgrade.
    const failing = this.attrs.checks.filter((check) => check.status === 'fail');
    const self = this.attrs.extensions.find((ext) => ext.action === 'remove_last');

    return (
      <div className="UpgradeAdvisorList">
        <table className="UpgradeAdvisorList-table">
          {failing.length > 0 &&
            this.group(
              'environment',
              'fas fa-server',
              failing.length,
              failing.map((check) => this.checkRow(check))
            )}

          {GROUPS.map(({ key, icon: iconName, actions }) => {
            const items = this.sorted(this.attrs.extensions.filter((ext) => actions.includes(ext.action)));

            if (!items.length) return null;

            return this.group(
              key,
              iconName,
              items.length,
              items.map((ext) => this.row(ext)),
              key === 'replace' ? this.commands(items) : null
            );
          })}
        </table>

        {self && (
          <p className="UpgradeAdvisorList-final">{app.translator.trans('fof-upgrade-advisor.admin.list.final_step', { title: self.title })}</p>
        )}
      </div>
    );
  }

  group(key: GroupKey, iconName: string, count: number, rows: Mithril.Children[], footer?: Mithril.Children) {
    const collapsed = this.collapsed[key];

    return (
      <tbody className={`UpgradeAdvisorList-group UpgradeAdvisorList-group--${key}`}>
        <tr className="UpgradeAdvisorList-groupHeader">
          <th colSpan={COLUMNS} scope="rowgroup">
            <button type="button" className="UpgradeAdvisorList-toggle" aria-expanded={!collapsed} onclick={() => (this.collapsed[key] = !collapsed)}>
              <Icon name={collapsed ? 'fas fa-chevron-right' : 'fas fa-chevron-down'} className="UpgradeAdvisorList-chevron" />
              <Icon name={iconName} className="UpgradeAdvisorList-groupIcon" />
              <span className="UpgradeAdvisorList-groupTitle">{app.translator.trans(`fof-upgrade-advisor.admin.list.groups.${key}.title`)}</span>
              <span className="UpgradeAdvisorList-count">{count}</span>
              <span className="UpgradeAdvisorList-groupDescription">
                {app.translator.trans(`fof-upgrade-advisor.admin.list.groups.${key}.description`)}
              </span>
            </button>
            {key === 'unknown' && !collapsed && (
              <LinkButton
                className="Button Button--link UpgradeAdvisorList-configure"
                icon="fas fa-key"
                href={app.route('extension', { id: 'fof-upgrade-advisor', page: 'repositories' })}
              >
                {app.translator.trans('fof-upgrade-advisor.admin.list.configure')}
              </LinkButton>
            )}
          </th>
        </tr>
        {!collapsed && rows}
        {!collapsed && footer}
      </tbody>
    );
  }

  row(ext: ExtensionCompat) {
    return (
      <tr className={`UpgradeAdvisorList-row UpgradeAdvisorList-row--${ext.action}`}>
        <td className="UpgradeAdvisorList-title">{extensionTitle(ext)}</td>
        <td className="UpgradeAdvisorList-package">{ext.name}</td>
        <td className="UpgradeAdvisorList-version">{ext.installedVersion || '—'}</td>
        <td className="UpgradeAdvisorList-detail">
          <Tooltip text={hintText(ext)}>
            <span tabindex="0">{this.detail(ext)}</span>
          </Tooltip>
        </td>
        <td className="UpgradeAdvisorList-contact">{ext.action === 'no_path' || ext.action === 'unknown' ? contactLinks(ext) : null}</td>
      </tr>
    );
  }

  checkRow(check: CheckData) {
    return (
      <tr className="UpgradeAdvisorList-row UpgradeAdvisorList-row--fail">
        <td className="UpgradeAdvisorList-title">
          <span className="UpgradeAdvisorPage-extension-title">{app.translator.trans(`fof-upgrade-advisor.admin.checks.${check.id}.title`)}</span>
        </td>
        <td className="UpgradeAdvisorList-detail" colSpan={COLUMNS - 1}>
          {checkDescription(check)}
        </td>
      </tr>
    );
  }

  /**
   * The one thing that's specific to this row; the group header covers the rest.
   * The full hint is in the tooltip.
   */
  detail(ext: ExtensionCompat): Mithril.Children {
    const key = (name: string, params: Record<string, string | null> = {}) =>
      app.translator.trans(`fof-upgrade-advisor.admin.list.details.${name}`, params);
    const replacement = { replacement: ext.replacement };

    switch (ext.action) {
      case 'none':
        return ext.compatibleVersion ? [<Icon name="fas fa-check" />, ' ', key('none', { version: ext.compatibleVersion })] : key('none_unversioned');
      case 'remove':
        return key('remove');
      case 'swap_after_upgrade':
      case 'switch_replacement':
        return ext.replacementCompatible === null && ext.action === 'switch_replacement'
          ? key('replacement_unverified', replacement)
          : [
              key('replacement', replacement),
              ext.replacementCompatible ? [' ', <Icon name="fas fa-check" className="UpgradeAdvisorList-ok" />] : null,
            ];
      case 'no_path':
        if (ext.status === 'abandoned') {
          return ext.replacement ? key('replacement_not_ready', replacement) : key('abandoned');
        }
        return ext.latestVersion ? key('latest', { version: ext.latestVersion }) : key('no_release');
      case 'remove_last':
        return key('remove_last');
      default:
        return key('unknown');
    }
  }

  /**
   * One command for everything to remove before upgrading, and one for the
   * replacements to install afterwards.
   */
  commands(items: ExtensionCompat[]): Mithril.Children {
    // Several extensions can share a replacement; install it once.
    const replacements = Array.from(new Set(items.map((ext) => ext.replacement).filter((name): name is string => !!name)));

    return [
      this.command('before', `composer remove ${items.map((ext) => ext.name).join(' ')}`),
      replacements.length > 0 && this.command('after', `composer require ${replacements.join(' ')}`),
    ];
  }

  command(step: 'before' | 'after', text: string) {
    const copied = this.copied === text;

    return (
      <tr className="UpgradeAdvisorList-command">
        <td className="UpgradeAdvisorList-command-label">{app.translator.trans(`fof-upgrade-advisor.admin.list.${step}`)}</td>
        <td colSpan={COLUMNS - 1}>
          <div className="UpgradeAdvisorList-command-body">
            <code>{text}</code>
            {navigator.clipboard && (
              <Button className="Button Button--link" icon={copied ? 'fas fa-check' : 'fas fa-copy'} onclick={() => this.copy(text)}>
                {app.translator.trans(`fof-upgrade-advisor.admin.list.${copied ? 'copied' : 'copy'}`)}
              </Button>
            )}
          </div>
        </td>
      </tr>
    );
  }

  copy(text: string) {
    navigator.clipboard.writeText(text).then(() => {
      this.copied = text;
      m.redraw();

      setTimeout(() => {
        if (this.copied === text) {
          this.copied = null;
          m.redraw();
        }
      }, 2000);
    });
  }

  sorted(extensions: ExtensionCompat[]): ExtensionCompat[] {
    return [...extensions].sort((a, b) => a.title.localeCompare(b.title));
  }
}
