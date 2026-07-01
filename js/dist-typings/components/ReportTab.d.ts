import Component from 'flarum/common/Component';
import type Mithril from 'mithril';
import Report, { CheckData } from '../models/Report';
export default class ReportTab extends Component {
    report: Report | null;
    loading: boolean;
    oninit(vnode: Mithril.Vnode<{}, this>): void;
    view(): JSX.Element;
    overallBanner(): JSX.Element;
    categorySection(category: string): JSX.Element;
    checkRow(check: CheckData): JSX.Element;
    /**
     * The translation key for a check's description. Warnings may have subtypes
     * (e.g. the database check distinguishes "couldn't determine" from "below
     * recommended version").
     */
    descriptionKey(check: CheckData): string;
    checkDetails(check: CheckData): Mithril.Children;
    /**
     * Distinct categories, in the order the checks were returned by the backend.
     */
    categories(): string[];
    load(): void;
}
