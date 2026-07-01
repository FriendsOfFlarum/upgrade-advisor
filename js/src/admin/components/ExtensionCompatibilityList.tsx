import app from 'flarum/admin/app';
import Component, { ComponentAttrs } from 'flarum/common/Component';
import LinkButton from 'flarum/common/components/LinkButton';
import icon from 'flarum/common/helpers/icon';
import type Mithril from 'mithril';

type ExtStatus = 'compatible' | 'incompatible' | 'unknown' | 'superseded' | 'abandoned';

interface ContactAuthor {
  name: string | null;
  email: string | null;
  homepage: string | null;
}

interface Contact {
  forum: string | null;
  issues: string | null;
  source: string | null;
  authors: ContactAuthor[];
}

interface ExtensionCompat {
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
  source: 'packagist' | 'discuss' | 'core' | null;
  contact: Contact;
}

// Statuses where the extension is "stuck" and the admin may want to ask the
// author about upgrade plans.
const CONTACTABLE: ExtStatus[] = ['incompatible', 'unknown', 'abandoned'];

interface Attrs extends ComponentAttrs {
  extensions: ExtensionCompat[];
}

const STATUS_ICONS: Record<ExtStatus, string> = {
  compatible: 'fas fa-check',
  incompatible: 'fas fa-times',
  unknown: 'fas fa-question',
  superseded: 'fas fa-box-archive',
  abandoned: 'fas fa-triangle-exclamation',
};

export default class ExtensionCompatibilityList extends Component<Attrs> {
  view(vnode: Mithril.Vnode<Attrs, this>) {
    const extensions = this.attrs.extensions;

    if (!extensions.length) {
      return null;
    }

    // Show problems first: superseded/abandoned, incompatible, unknown,
    // compatible; the advisor's own "remove me last" note sorts to the bottom.
    const order: Record<ExtStatus, number> = { superseded: 0, abandoned: 0, incompatible: 1, unknown: 2, compatible: 3 };
    const rank = (ext: ExtensionCompat) => (ext.reason === 'self' ? 99 : order[ext.status]);
    const sorted = [...extensions].sort((a, b) => rank(a) - rank(b) || a.title.localeCompare(b.title));

    return (
      <table className="UpgradeAdvisorPage-extensions">
        <thead>
          <tr>
            <th>{app.translator.trans('fof-upgrade-advisor.admin.extensions.name')}</th>
            <th>{app.translator.trans('fof-upgrade-advisor.admin.extensions.installed')}</th>
            <th>{app.translator.trans('fof-upgrade-advisor.admin.extensions.status')}</th>
          </tr>
        </thead>
        <tbody>{sorted.map((ext) => this.row(ext))}</tbody>
      </table>
    );
  }

  row(ext: ExtensionCompat) {
    // The advisor itself is informational rather than a blocker, so give it a
    // calmer variant of the superseded style.
    const variant = ext.reason === 'self' ? 'self' : ext.status;

    return (
      <tr className={`UpgradeAdvisorPage-extension UpgradeAdvisorPage-extension--${variant}`}>
        <td>
          <span className="UpgradeAdvisorPage-extension-title">{ext.title}</span>
          <span className="UpgradeAdvisorPage-extension-name">{ext.name}</span>
        </td>
        <td>{ext.installedVersion || '—'}</td>
        <td>
          <span className="UpgradeAdvisorPage-extension-status">
            {icon(ext.reason === 'self' ? 'fas fa-circle-info' : STATUS_ICONS[ext.status])}
            {this.statusLabel(ext)}
          </span>
          {this.contactLinks(ext)}
        </td>
      </tr>
    );
  }

  contactLinks(ext: ExtensionCompat): Mithril.Children {
    if (!CONTACTABLE.includes(ext.status)) {
      return null;
    }

    const contact = ext.contact;
    const links: Mithril.Children[] = [];

    if (contact.forum) {
      links.push(this.contactLink('fas fa-comments', app.translator.trans('fof-upgrade-advisor.admin.extensions.contact.forum'), contact.forum));
    }

    if (contact.issues) {
      links.push(this.contactLink('fas fa-bug', app.translator.trans('fof-upgrade-advisor.admin.extensions.contact.issues'), contact.issues));
    } else if (contact.source) {
      links.push(this.contactLink('fas fa-code-branch', app.translator.trans('fof-upgrade-advisor.admin.extensions.contact.source'), contact.source));
    }

    contact.authors.forEach((author) => {
      const href = author.email ? `mailto:${author.email}` : author.homepage;

      if (!href) {
        return;
      }

      const label = author.name || author.email || author.homepage!;
      links.push(this.contactLink(author.email ? 'fas fa-envelope' : 'fas fa-user', label, href));
    });

    if (!links.length) {
      return null;
    }

    return (
      <div className="UpgradeAdvisorPage-extension-contact">
        <span className="UpgradeAdvisorPage-extension-contactLabel">
          {app.translator.trans('fof-upgrade-advisor.admin.extensions.contact.label')}
        </span>
        {links}
      </div>
    );
  }

  contactLink(iconName: string, label: Mithril.Children, href: string): Mithril.Children {
    return (
      <LinkButton className="Button Button--link UpgradeAdvisorPage-extension-contactLink" href={href} icon={iconName} external={true}>
        {label}
      </LinkButton>
    );
  }

  statusLabel(ext: ExtensionCompat): Mithril.Children {
    if (ext.status === 'compatible') {
      return app.translator.trans('fof-upgrade-advisor.admin.extensions.compatible', { version: ext.compatibleVersion });
    }

    if (ext.status === 'unknown') {
      return (
        <LinkButton className="Button Button--link" href={app.route('extension', { id: 'fof-upgrade-advisor', page: 'repositories' })}>
          {app.translator.trans('fof-upgrade-advisor.admin.extensions.unknown')}
        </LinkButton>
      );
    }

    if (ext.status === 'superseded') {
      if (ext.reason === 'replaced') {
        return app.translator.trans('fof-upgrade-advisor.admin.extensions.superseded_replaced', { replacement: ext.replacement });
      }

      if (ext.reason === 'self') {
        return app.translator.trans('fof-upgrade-advisor.admin.extensions.superseded_self');
      }

      return app.translator.trans('fof-upgrade-advisor.admin.extensions.superseded_into_core');
    }

    if (ext.status === 'abandoned') {
      return this.abandonedLabel(ext);
    }

    return app.translator.trans('fof-upgrade-advisor.admin.extensions.incompatible', { version: ext.latestVersion });
  }

  abandonedLabel(ext: ExtensionCompat): Mithril.Children {
    if (!ext.replacement) {
      return app.translator.trans('fof-upgrade-advisor.admin.extensions.abandoned');
    }

    // A replacement is only useful if it is itself ready for the target version.
    // Highlight this case in green so the clear upgrade path stands out from the
    // otherwise-red abandoned row.
    if (ext.replacementCompatible === true) {
      return (
        <span className="UpgradeAdvisorPage-extension-replacementReady">
          {app.translator.trans('fof-upgrade-advisor.admin.extensions.abandoned_replacement_ready', { replacement: ext.replacement })}
        </span>
      );
    }

    if (ext.replacementCompatible === false) {
      return app.translator.trans('fof-upgrade-advisor.admin.extensions.abandoned_replacement_not_ready', { replacement: ext.replacement });
    }

    return app.translator.trans('fof-upgrade-advisor.admin.extensions.abandoned_replacement', { replacement: ext.replacement });
  }
}
