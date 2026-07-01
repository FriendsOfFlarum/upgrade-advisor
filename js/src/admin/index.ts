import app from 'flarum/admin/app';

import Report from './models/Report';
import UpgradeAdvisorPage from './components/UpgradeAdvisorPage';

app.initializers.add('fof/upgrade-advisor', () => {
  app.store.models['fof-upgrade-advisor-reports'] = Report;

  app.extensionData.for('fof-upgrade-advisor').registerPage(UpgradeAdvisorPage);
});
