import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Alert from 'flarum/common/components/Alert';
import icon from 'flarum/common/helpers/icon';
import type Mithril from 'mithril';

import Report, { CheckData, CheckStatus } from '../models/Report';
import ExtensionCompatibilityList from './ExtensionCompatibilityList';

const STATUS_ICONS: Record<CheckStatus, string> = {
  pass: 'fas fa-check-circle',
  warning: 'fas fa-exclamation-triangle',
  fail: 'fas fa-times-circle',
};

export default class ReportTab extends Component {
  report: Report | null = null;
  loading = true;

  oninit(vnode: Mithril.Vnode<{}, this>) {
    super.oninit(vnode);
    this.load();
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

    return (
      <div>
        {this.overallBanner()}
        {this.categories().map((category) => this.categorySection(category))}
        <div className="UpgradeAdvisorPage-actions">
          <Button className="Button" icon="fas fa-sync" loading={this.loading} onclick={() => this.load()}>
            {app.translator.trans('fof-upgrade-advisor.admin.actions.recheck')}
          </Button>
        </div>
      </div>
    );
  }

  overallBanner() {
    const overall = this.report!.overall();
    const major = this.report!.flarumMajor();

    return (
      <div className={`UpgradeAdvisorPage-overall UpgradeAdvisorPage-overall--${overall}`}>
        {icon(STATUS_ICONS[overall])}
        <div className="UpgradeAdvisorPage-overall-text">
          <h3>{app.translator.trans(`fof-upgrade-advisor.admin.overall.${overall}`, { version: major })}</h3>
          <p>{app.translator.trans('fof-upgrade-advisor.admin.overall.subtitle', { version: major })}</p>
        </div>
      </div>
    );
  }

  categorySection(category: string) {
    const checks = this.report!.checks().filter((c) => c.category === category);

    return (
      <section className="UpgradeAdvisorPage-category">
        <h4>{app.translator.trans(`fof-upgrade-advisor.admin.categories.${category}`)}</h4>
        <ul className="UpgradeAdvisorPage-checks">{checks.map((check) => this.checkRow(check))}</ul>
      </section>
    );
  }

  checkRow(check: CheckData) {
    return (
      <li className={`UpgradeAdvisorPage-check UpgradeAdvisorPage-check--${check.status}`}>
        <div className="UpgradeAdvisorPage-check-status">{icon(STATUS_ICONS[check.status])}</div>
        <div className="UpgradeAdvisorPage-check-body">
          <div className="UpgradeAdvisorPage-check-title">{app.translator.trans(`fof-upgrade-advisor.admin.checks.${check.id}.title`)}</div>
          <div className="UpgradeAdvisorPage-check-description">
            {app.translator.trans(this.descriptionKey(check), {
              current: check.current,
              required: check.meta.required,
              recommended: check.meta.recommended,
            })}
          </div>
          {this.checkDetails(check)}
        </div>
      </li>
    );
  }

  /**
   * The translation key for a check's description. Warnings may have subtypes
   * (e.g. the database check distinguishes "couldn't determine" from "below
   * recommended version").
   */
  descriptionKey(check: CheckData): string {
    const base = `fof-upgrade-advisor.admin.checks.${check.id}`;

    if (check.status === 'warning' && check.meta.warningType) {
      return `${base}.warning_${check.meta.warningType}`;
    }

    return `${base}.${check.status}`;
  }

  checkDetails(check: CheckData): Mithril.Children {
    if (check.id === 'extension-compatibility' && Array.isArray(check.meta.extensions)) {
      return <ExtensionCompatibilityList extensions={check.meta.extensions} />;
    }

    return null;
  }

  /**
   * Distinct categories, in the order the checks were returned by the backend.
   */
  categories(): string[] {
    const seen: string[] = [];

    this.report!.checks().forEach((c) => {
      if (!seen.includes(c.category)) seen.push(c.category);
    });

    return seen;
  }

  load() {
    this.loading = true;
    m.redraw();

    app
      .request<{ data: any }>({
        method: 'GET',
        url: app.forum.attribute('apiUrl') + '/fof/upgrade-advisor/report',
      })
      .then((result) => {
        this.report = app.store.pushObject(result.data) as Report;
        this.loading = false;
        m.redraw();
      })
      .catch(() => {
        this.report = null;
        this.loading = false;
        m.redraw();
      });
  }
}
