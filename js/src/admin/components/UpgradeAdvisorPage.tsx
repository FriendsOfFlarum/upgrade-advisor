import app from 'flarum/admin/app';
import ExtensionPage, { ExtensionPageAttrs } from 'flarum/admin/components/ExtensionPage';
import LinkButton from 'flarum/common/components/LinkButton';
import type Mithril from 'mithril';

import ReportTab from './ReportTab';
import RepositoriesSettings from './RepositoriesSettings';

export default class UpgradeAdvisorPage extends ExtensionPage<ExtensionPageAttrs> {
  content() {
    const page = m.route.param('page') || 'report';

    return (
      <div className="UpgradeAdvisorPage">
        <div className="UpgradeAdvisorPage-menu">
          <div className="container">
            {this.menuButton('report', 'fas fa-clipboard-check')}
            {this.menuButton('repositories', 'fas fa-box')}
          </div>
        </div>
        <div className="container">{page === 'repositories' ? <RepositoriesSettings /> : <ReportTab />}</div>
      </div>
    );
  }

  menuButton(page: string, iconName: string) {
    const current = m.route.param('page') || 'report';

    return (
      <LinkButton
        className={`Button ${current === page ? 'item-selected' : ''}`}
        href={app.route('extension', { id: 'fof-upgrade-advisor', page: page === 'report' ? undefined : page })}
        icon={iconName}
      >
        {app.translator.trans(`fof-upgrade-advisor.admin.tabs.${page}`)}
      </LinkButton>
    );
  }
}
