import Component, { ComponentAttrs } from 'flarum/common/Component';
import type Mithril from 'mithril';
import type { CheckData, ExtensionCompat } from '../models/Report';
type GroupKey = 'environment' | 'decision' | 'unknown' | 'replace' | 'ready';
interface Attrs extends ComponentAttrs {
    extensions: ExtensionCompat[];
    checks: CheckData[];
}
/**
 * Every extension in one table, grouped by what the admin needs to do. Each
 * group explains itself once in its header; rows carry only what's specific
 * to that extension.
 */
export default class ExtensionCompatibilityList extends Component<Attrs> {
    collapsed: Record<GroupKey, boolean>;
    copied: string | null;
    view(): JSX.Element;
    group(key: GroupKey, iconName: string, count: number, rows: Mithril.Children[], footer?: Mithril.Children): JSX.Element;
    row(ext: ExtensionCompat): JSX.Element;
    checkRow(check: CheckData): JSX.Element;
    /**
     * The one thing that's specific to this row; the group header covers the rest.
     * The full hint is in the tooltip.
     */
    detail(ext: ExtensionCompat): Mithril.Children;
    /**
     * One command for everything to remove before upgrading, and one for the
     * replacements to install afterwards.
     */
    commands(items: ExtensionCompat[]): Mithril.Children;
    command(step: 'before' | 'after', text: string): JSX.Element;
    copy(text: string): void;
    sorted(extensions: ExtensionCompat[]): ExtensionCompat[];
}
export {};
