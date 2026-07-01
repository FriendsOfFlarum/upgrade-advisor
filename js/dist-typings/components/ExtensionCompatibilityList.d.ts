import Component, { ComponentAttrs } from 'flarum/common/Component';
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
interface Attrs extends ComponentAttrs {
    extensions: ExtensionCompat[];
}
export default class ExtensionCompatibilityList extends Component<Attrs> {
    view(vnode: Mithril.Vnode<Attrs, this>): JSX.Element | null;
    row(ext: ExtensionCompat): JSX.Element;
    contactLinks(ext: ExtensionCompat): Mithril.Children;
    contactLink(iconName: string, label: Mithril.Children, href: string): Mithril.Children;
    statusLabel(ext: ExtensionCompat): Mithril.Children;
    abandonedLabel(ext: ExtensionCompat): Mithril.Children;
}
export {};
