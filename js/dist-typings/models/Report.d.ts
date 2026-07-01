import Model from 'flarum/common/Model';
export type CheckStatus = 'pass' | 'warning' | 'fail';
export interface CheckData {
    id: string;
    category: string;
    status: CheckStatus;
    current: string | null;
    meta: Record<string, any>;
}
export default class Report extends Model {
    overall(): CheckStatus;
    flarumMajor(): string;
    checks(): CheckData[];
}
