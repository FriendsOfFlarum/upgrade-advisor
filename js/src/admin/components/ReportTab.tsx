import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Alert from 'flarum/common/components/Alert';
import Tooltip from 'flarum/common/components/Tooltip';
import Icon from 'flarum/common/components/Icon';
import humanTime from 'flarum/common/utils/humanTime';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';

import Report, { BLOCKING_ACTIONS, CheckData, CheckStatus, READY_ACTIONS } from '../models/Report';
import { checkChip, checkDescription } from '../utils/checkText';
import ExtensionCompatibilityList from './ExtensionCompatibilityList';

const STATUS_ICONS: Record<CheckStatus, string> = {
  pass: 'fas fa-check-circle',
  warning: 'fas fa-exclamation-triangle',
  fail: 'fas fa-times-circle',
};

/**
 * Matches FoF\UpgradeAdvisor\CacheGeneration::COOLDOWN; the server ignores
 * refreshes inside this window anyway.
 */
/**
 * Bar segments, coloured to match the groups below: to-dos amber like
 * "Remove or replace", blockers red like "Needs a decision".
 */
type Segment = 'ready' | 'tasks' | 'blocked' | 'unknown';
const SEGMENTS: Segment[] = ['ready', 'tasks', 'blocked', 'unknown'];

const REFRESH_COOLDOWN_MS = 60 * 1000;

export default class ReportTab extends Component {
  report: Report | null = null;
  loading = true;
  refreshing = false;
  cooldownUntil = 0;
  ticker: number | undefined;

  oninit(vnode: Mithril.Vnode<{}, this>) {
    super.oninit(vnode);
    this.load();
  }

  onremove(vnode: Mithril.VnodeDOM<{}, this>) {
    super.onremove(vnode);
    clearInterval(this.ticker);
  }

  view() {
    if (this.loading) {
      return <LoadingIndicator />;
    }

    if (!this.report) {
      return (
        <Alert type="error" dismissible={false}>
          {app.translator.trans('fof-upgrade-advisor.admin.errors.load_failed')}
        </Alert>
      );
    }

    const extensions = this.report.extensions();

    return (
      <div>
        {this.summary()}
        <ExtensionCompatibilityList extensions={extensions} checks={this.report.otherChecks()} />
      </div>
    );
  }

  summary() {
    const report = this.report!;
    const extensions = report.extensions();

    const total = extensions.length;
    const counts: Record<Segment, number> = {
      ready: extensions.filter((ext) => READY_ACTIONS.includes(ext.action)).length,
      tasks: extensions.filter((ext) => BLOCKING_ACTIONS.includes(ext.action) && ext.action !== 'no_path').length,
      blocked: extensions.filter((ext) => ext.action === 'no_path').length,
      unknown: extensions.filter((ext) => ext.action === 'unknown').length,
    };
    // Floor, so 99.6% never reads as "100% ready". Only shown as the bar's tooltip:
    // the counts say more than one number that mixes to-dos with blockers.
    const percent = total ? Math.floor((counts.ready / total) * 100) : 100;
    const headline = this.headline();

    return (
      <div className={`UpgradeAdvisorPage-overall UpgradeAdvisorPage-overall--${headline.status}`}>
        <div className="UpgradeAdvisorPage-overall-main">
          <Icon name={STATUS_ICONS[headline.status]} className="UpgradeAdvisorPage-overall-icon" />
          <h3>{headline.text}</h3>
          {total > 0 && (
            <div className="UpgradeAdvisorPage-progress">
              <Tooltip text={extractText(app.translator.trans('fof-upgrade-advisor.admin.summary.progress', { percent }))}>
                <div className="UpgradeAdvisorPage-progress-bar" role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={percent}>
                  {SEGMENTS.map((kind) => this.segment(kind, counts[kind], total))}
                </div>
              </Tooltip>
              <div className="UpgradeAdvisorPage-progress-legend">{SEGMENTS.map((kind) => this.legend(kind, counts[kind]))}</div>
            </div>
          )}
        </div>
        <div className="UpgradeAdvisorPage-overall-footer">
          <div className="UpgradeAdvisorPage-chips">{report.otherChecks().map((check) => this.chip(check))}</div>
          {this.toolbar()}
        </div>
      </div>
    );
  }

  /**
   * Says what stands between the forum and the upgrade, rather than a bare
   * "not ready": real blockers (red), then work with a clear path (amber),
   * then ready (green). Unchecked extensions are named but don't block.
   */
  headline(): { status: CheckStatus; text: Mithril.Children } {
    const report = this.report!;
    const extensions = report.extensions();
    const version = report.flarumMajor();
    const trans = (key: string, params: Record<string, any> = {}) => app.translator.trans(`fof-upgrade-advisor.admin.overall.${key}`, params);

    const noPath = extensions.filter((ext) => ext.action === 'no_path').length;
    const tasks = extensions.filter((ext) => BLOCKING_ACTIONS.includes(ext.action) && ext.action !== 'no_path').length;
    const unchecked = extensions.filter((ext) => ext.action === 'unknown').length;
    const failing = report.otherChecks().filter((check) => check.status === 'fail');

    const blockers: Mithril.Children[] = [
      ...failing.map((check) => trans('reasons.check', { title: app.translator.trans(`fof-upgrade-advisor.admin.checks.${check.id}.title`) })),
      noPath > 0 && trans('reasons.no_path', { count: noPath }),
    ].filter(Boolean);

    if (blockers.length) {
      if (unchecked) blockers.push(trans('reasons.unchecked', { count: unchecked }));

      return { status: 'fail', text: trans('blocked', { reasons: this.join(blockers) }) };
    }

    if (tasks) {
      const text = trans('tasks', { count: tasks });

      return { status: 'warning', text: unchecked ? this.join([text, trans('reasons.unchecked', { count: unchecked })]) : text };
    }

    if (unchecked) {
      return { status: 'warning', text: trans('unchecked_only', { count: unchecked }) };
    }

    return { status: 'pass', text: trans('ready', { version }) };
  }

  join(parts: Mithril.Children[]): Mithril.Children[] {
    return parts.reduce<Mithril.Children[]>((all, part, i) => (i ? [...all, ' · ', part] : [part]), []);
  }

  legend(kind: Segment, count: number) {
    if (!count) {
      return null;
    }

    return (
      <span className={`UpgradeAdvisorPage-progress-key UpgradeAdvisorPage-progress-key--${kind}`}>
        {app.translator.trans(`fof-upgrade-advisor.admin.summary.${kind}`, { count })}
      </span>
    );
  }

  segment(kind: Segment, count: number, total: number) {
    if (!count) {
      return null;
    }

    return (
      <div
        className={`UpgradeAdvisorPage-progress-segment UpgradeAdvisorPage-progress-segment--${kind}`}
        style={{ width: `${(count / total) * 100}%` }}
      />
    );
  }

  chip(check: CheckData) {
    // A warning says why right on the chip, so it needs no separate card.
    const recommended = check.status === 'warning' && check.meta.recommended;

    return (
      <Tooltip text={checkDescription(check)}>
        <span className={`UpgradeAdvisorPage-chip UpgradeAdvisorPage-chip--${check.status}`} tabindex="0">
          <Icon name={STATUS_ICONS[check.status]} />
          {checkChip(check)}
          {recommended && (
            <span className="UpgradeAdvisorPage-chip-note">
              {app.translator.trans('fof-upgrade-advisor.admin.summary.recommended', { version: check.meta.recommended })}
            </span>
          )}
        </span>
      </Tooltip>
    );
  }

  toolbar() {
    const report = this.report!;
    const remaining = Math.ceil((this.cooldownUntil - Date.now()) / 1000);
    const checkedAt = report.checkedAt();
    const dataAsOf = report.dataAsOf();

    return (
      <div className="UpgradeAdvisorPage-toolbar">
        <span className="UpgradeAdvisorPage-freshness">
          {checkedAt && app.translator.trans('fof-upgrade-advisor.admin.summary.checked', { time: humanTime(checkedAt) })}
          {dataAsOf && (
            <Tooltip text={extractText(app.translator.trans('fof-upgrade-advisor.admin.summary.data_as_of_help'))}>
              <span tabindex="0">
                {' · '}
                {app.translator.trans('fof-upgrade-advisor.admin.summary.data_as_of', { time: humanTime(dataAsOf) })}{' '}
                <Icon name="fas fa-info-circle" />
              </span>
            </Tooltip>
          )}
        </span>
        <Button className="Button" icon="fas fa-sync" loading={this.refreshing} disabled={remaining > 0} onclick={() => this.refresh()}>
          {remaining > 0
            ? app.translator.trans('fof-upgrade-advisor.admin.summary.refresh_cooldown', { seconds: remaining })
            : app.translator.trans('fof-upgrade-advisor.admin.summary.refresh')}
        </Button>
        <a className="Button" href={`${app.forum.attribute('apiUrl')}/fof/upgrade-advisor/report/export`} download>
          <Icon name="fas fa-file-csv" className="Button-icon" />
          <span className="Button-label">{app.translator.trans('fof-upgrade-advisor.admin.summary.export')}</span>
        </a>
      </div>
    );
  }

  load() {
    this.loading = true;

    this.request('GET', '/fof/upgrade-advisor/report').finally(() => {
      this.loading = false;
      m.redraw();
    });
  }

  /**
   * Re-run the checks with fresh remote lookups. Keeps the current report on
   * screen while it runs, since fetching every package can take a while.
   */
  refresh() {
    this.refreshing = true;
    this.cooldownUntil = Date.now() + REFRESH_COOLDOWN_MS;

    clearInterval(this.ticker);
    this.ticker = window.setInterval(() => {
      if (Date.now() >= this.cooldownUntil) clearInterval(this.ticker);
      m.redraw();
    }, 1000);

    this.request('POST', '/fof/upgrade-advisor/report/refresh').finally(() => {
      this.refreshing = false;
      m.redraw();
    });
  }

  request(method: 'GET' | 'POST', path: string) {
    return app
      .request<{ data: any }>({ method, url: app.forum.attribute('apiUrl') + path })
      .then((result) => {
        this.report = app.store.pushObject(result.data) as Report;
      })
      .catch(() => {
        this.report = null;
      });
  }
}
