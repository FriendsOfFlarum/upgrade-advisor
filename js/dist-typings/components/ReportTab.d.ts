import Component from 'flarum/common/Component';
import type Mithril from 'mithril';
import Report, { CheckData, CheckStatus } from '../models/Report';
/**
 * Matches FoF\UpgradeAdvisor\CacheGeneration::COOLDOWN; the server ignores
 * refreshes inside this window anyway.
 */
/**
 * Bar segments, coloured to match the groups below: to-dos amber like
 * "Remove or replace", blockers red like "Needs a decision".
 */
type Segment = 'ready' | 'tasks' | 'blocked' | 'unknown';
export default class ReportTab extends Component {
    report: Report | null;
    loading: boolean;
    refreshing: boolean;
    cooldownUntil: number;
    ticker: number | undefined;
    oninit(vnode: Mithril.Vnode<{}, this>): void;
    onremove(vnode: Mithril.VnodeDOM<{}, this>): void;
    view(): JSX.Element;
    summary(): JSX.Element;
    /**
     * Says what stands between the forum and the upgrade, rather than a bare
     * "not ready": real blockers (red), then work with a clear path (amber),
     * then ready (green). Unchecked extensions are named but don't block.
     */
    headline(): {
        status: CheckStatus;
        text: Mithril.Children;
    };
    join(parts: Mithril.Children[]): Mithril.Children[];
    legend(kind: Segment, count: number): JSX.Element | null;
    segment(kind: Segment, count: number, total: number): JSX.Element | null;
    chip(check: CheckData): JSX.Element;
    toolbar(): JSX.Element;
    load(): void;
    /**
     * Re-run the checks with fresh remote lookups. Keeps the current report on
     * screen while it runs, since fetching every package can take a while.
     */
    refresh(): void;
    request(method: 'GET' | 'POST', path: string): Promise<void>;
}
export {};
