import app from 'flarum/admin/app';
import Link from 'flarum/common/components/Link';
import LinkButton from 'flarum/common/components/LinkButton';
import Tooltip from 'flarum/common/components/Tooltip';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';

import type { ExtensionCompat } from '../models/Report';

/**
 * The extension's title, linked to its admin page. The advisor's own row stays
 * plain, as it would link to the page you're already on.
 */
export function extensionTitle(ext: ExtensionCompat): Mithril.Children {
  if (ext.id === 'fof-upgrade-advisor') {
    return <span className="UpgradeAdvisorPage-extension-title">{ext.title}</span>;
  }

  // Most actions (disable, uninstall, check settings) start from the extension's own admin page.
  return (
    <Link className="UpgradeAdvisorPage-extension-title" href={app.route('extension', { id: ext.id })}>
      {ext.title}
    </Link>
  );
}

export function hintText(ext: ExtensionCompat): string {
  return extractText(app.translator.trans(`fof-upgrade-advisor.admin.hints.${ext.hint.key}`, ext.hint.params));
}

/**
 * Ways to ask the author about upgrade plans, as compact icon links: the
 * support thread, the issue tracker (or source), and each author's email or
 * homepage.
 */
export function contactLinks(ext: ExtensionCompat): Mithril.Children {
  const contact = ext.contact;
  const links: Mithril.Children[] = [];

  if (contact.forum) {
    links.push(contactLink('fas fa-comments', app.translator.trans('fof-upgrade-advisor.admin.extensions.contact.forum'), contact.forum));
  }

  if (contact.issues) {
    links.push(contactLink('fas fa-bug', app.translator.trans('fof-upgrade-advisor.admin.extensions.contact.issues'), contact.issues));
  } else if (contact.source) {
    links.push(contactLink('fas fa-code-branch', app.translator.trans('fof-upgrade-advisor.admin.extensions.contact.source'), contact.source));
  }

  contact.authors.forEach((author) => {
    const href = author.email ? `mailto:${author.email}` : author.homepage;

    if (!href) {
      return;
    }

    const label = author.name || author.email || author.homepage!;
    links.push(contactLink(author.email ? 'fas fa-envelope' : 'fas fa-user', label, href));
  });

  return links.length ? <span className="UpgradeAdvisorList-contactLinks">{links}</span> : null;
}

function contactLink(iconName: string, label: Mithril.Children, href: string): Mithril.Children {
  const text = extractText(label);

  return (
    <Tooltip text={text}>
      <LinkButton className="Button Button--icon Button--link" href={href} icon={iconName} external={true} target="_blank" aria-label={text} />
    </Tooltip>
  );
}
