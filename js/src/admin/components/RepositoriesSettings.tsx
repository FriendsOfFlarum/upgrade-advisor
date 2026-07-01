import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Select from 'flarum/common/components/Select';
import saveSettings from 'flarum/admin/utils/saveSettings';
import type Mithril from 'mithril';

type RepoType = 'floxum' | 'composer';

interface RepoRow {
  type: RepoType;
  url?: string;
  username?: string;
  token?: string;
}

const SETTING = 'fof-upgrade-advisor.repositories';

interface TestState {
  testing: boolean;
  ok: boolean | null;
  reason: string | null;
}

export default class RepositoriesSettings extends Component {
  repos: RepoRow[] = [];
  tests: TestState[] = [];
  saving = false;

  oninit(vnode: Mithril.Vnode<{}, this>) {
    super.oninit(vnode);
    this.repos = this.read();
    this.tests = this.repos.map(() => ({ testing: false, ok: null, reason: null }));
  }

  view() {
    return (
      <div className="UpgradeAdvisorRepos">
        <p className="UpgradeAdvisorRepos-intro">{app.translator.trans('fof-upgrade-advisor.admin.repositories.help')}</p>

        {this.repos.length === 0 ? (
          <p className="UpgradeAdvisorRepos-empty">{app.translator.trans('fof-upgrade-advisor.admin.repositories.empty')}</p>
        ) : null}

        {this.repos.map((repo, index) => this.repoRow(repo, index))}

        <div className="UpgradeAdvisorRepos-add">
          <Button className="Button" icon="fas fa-plus" onclick={() => this.addFloxum()}>
            {app.translator.trans('fof-upgrade-advisor.admin.repositories.add_floxum')}
          </Button>
          <Button className="Button" icon="fas fa-plus" onclick={() => this.addComposer()}>
            {app.translator.trans('fof-upgrade-advisor.admin.repositories.add_composer')}
          </Button>
        </div>

        <div className="UpgradeAdvisorRepos-actions">
          <Button className="Button Button--primary" loading={this.saving} disabled={this.saving} onclick={() => this.save()}>
            {app.translator.trans('core.admin.settings.submit_button')}
          </Button>
        </div>
      </div>
    );
  }

  repoRow(repo: RepoRow, index: number) {
    return (
      <div className="UpgradeAdvisorRepos-row">
        <div className="UpgradeAdvisorRepos-rowHeader">
          <Select
            value={repo.type}
            options={{
              floxum: app.translator.trans('fof-upgrade-advisor.admin.repositories.type_floxum'),
              composer: app.translator.trans('fof-upgrade-advisor.admin.repositories.type_composer'),
            }}
            onchange={(value: RepoType) => this.setType(index, value)}
          />
          <Button
            className="Button Button--icon"
            icon="fas fa-trash"
            onclick={() => this.remove(index)}
            aria-label={app.translator.trans('fof-upgrade-advisor.admin.repositories.remove')}
          />
        </div>

        <div className="UpgradeAdvisorRepos-fields">
          {repo.type === 'composer'
            ? [
                this.field(
                  app.translator.trans('fof-upgrade-advisor.admin.repositories.url'),
                  'text',
                  repo.url,
                  (v) => (repo.url = v),
                  'https://repo.packagist.com/acme/'
                ),
                this.field(
                  app.translator.trans('fof-upgrade-advisor.admin.repositories.username'),
                  'text',
                  repo.username,
                  (v) => (repo.username = v)
                ),
              ]
            : null}
          {this.field(app.translator.trans('fof-upgrade-advisor.admin.repositories.token'), 'password', repo.token, (v) => (repo.token = v))}
        </div>

        <div className="UpgradeAdvisorRepos-test">
          <Button className="Button Button--text" icon="fas fa-plug" loading={this.tests[index]?.testing} onclick={() => this.test(index)}>
            {app.translator.trans('fof-upgrade-advisor.admin.repositories.test')}
          </Button>
          {this.testResult(index)}
        </div>
      </div>
    );
  }

  testResult(index: number): Mithril.Children {
    const state = this.tests[index];

    if (!state || state.testing || state.ok === null) {
      return null;
    }

    if (state.ok) {
      return (
        <span className="UpgradeAdvisorRepos-testResult UpgradeAdvisorRepos-testResult--ok">
          {app.translator.trans('fof-upgrade-advisor.admin.repositories.test_ok')}
        </span>
      );
    }

    return (
      <span className="UpgradeAdvisorRepos-testResult UpgradeAdvisorRepos-testResult--fail">
        {app.translator.trans(`fof-upgrade-advisor.admin.repositories.test_fail_${state.reason || 'unreachable'}`)}
      </span>
    );
  }

  field(label: Mithril.Children, type: string, value: string | undefined, set: (value: string) => void, placeholder = '') {
    return (
      <div className="Form-group">
        <label>{label}</label>
        <input
          className="FormControl"
          type={type}
          value={value || ''}
          placeholder={placeholder}
          oninput={(e: InputEvent) => set((e.target as HTMLInputElement).value)}
        />
      </div>
    );
  }

  setType(index: number, type: RepoType) {
    this.repos[index].type = type;
    this.resetTest(index);
  }

  addFloxum() {
    this.repos.push({ type: 'floxum', token: '' });
    this.tests.push({ testing: false, ok: null, reason: null });
  }

  addComposer() {
    this.repos.push({ type: 'composer', url: '', username: '', token: '' });
    this.tests.push({ testing: false, ok: null, reason: null });
  }

  remove(index: number) {
    this.repos.splice(index, 1);
    this.tests.splice(index, 1);
  }

  resetTest(index: number) {
    this.tests[index] = { testing: false, ok: null, reason: null };
  }

  test(index: number) {
    const state = this.tests[index];

    if (state.testing) return;

    state.testing = true;
    state.ok = null;
    state.reason = null;
    m.redraw();

    const repo = this.repos[index];

    app
      .request<{ ok: boolean; reason: string }>({
        method: 'POST',
        url: app.forum.attribute('apiUrl') + '/fof/upgrade-advisor/test-repository',
        body: {
          type: repo.type,
          url: repo.url,
          username: repo.username,
          token: repo.token,
        },
      })
      .then((result) => {
        state.testing = false;
        state.ok = !!result.ok;
        state.reason = result.reason;
        m.redraw();
      })
      .catch(() => {
        state.testing = false;
        state.ok = false;
        state.reason = 'unreachable';
        m.redraw();
      });
  }

  save() {
    if (this.saving) return;
    this.saving = true;

    saveSettings({ [SETTING]: JSON.stringify(this.clean()) })
      .then(() => {
        app.alerts.show({ type: 'success' }, app.translator.trans('core.admin.settings.saved_message'));
      })
      .catch(() => {})
      .then(() => {
        this.saving = false;
        m.redraw();
      });
  }

  /**
   * Drop empty rows and trim values before persisting.
   */
  clean(): RepoRow[] {
    return this.repos
      .map((repo) => {
        if (repo.type === 'floxum') {
          return { type: 'floxum', token: (repo.token || '').trim() } as RepoRow;
        }

        return {
          type: 'composer',
          url: (repo.url || '').trim(),
          username: (repo.username || '').trim(),
          token: (repo.token || '').trim(),
        } as RepoRow;
      })
      .filter((repo) => (repo.type === 'floxum' ? !!repo.token : !!repo.url));
  }

  read(): RepoRow[] {
    const raw = app.data.settings[SETTING];

    if (!raw) return [];

    try {
      const parsed = JSON.parse(raw);
      return Array.isArray(parsed) ? parsed : [];
    } catch (e) {
      return [];
    }
  }
}
