/// <reference types="mithril" />
import ExtensionPage, { ExtensionPageAttrs } from 'flarum/admin/components/ExtensionPage';
export default class UpgradeAdvisorPage extends ExtensionPage<ExtensionPageAttrs> {
    content(): JSX.Element;
    menuButton(page: string, iconName: string): JSX.Element;
}
