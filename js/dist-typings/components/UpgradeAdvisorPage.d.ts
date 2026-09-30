import ExtensionPage, { ExtensionPageAttrs } from 'flarum/admin/components/ExtensionPage';
import type Mithril from 'mithril';
export default class UpgradeAdvisorPage extends ExtensionPage<ExtensionPageAttrs> {
    sections(vnode: Mithril.VnodeDOM<ExtensionPageAttrs, this>): import("flarum/common/utils/ItemList").default<unknown>;
    content(): JSX.Element;
    menuButton(page: string, iconName: string): JSX.Element;
}
