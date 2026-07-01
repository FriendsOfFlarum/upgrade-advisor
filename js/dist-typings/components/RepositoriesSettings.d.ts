import Component from 'flarum/common/Component';
import type Mithril from 'mithril';
type RepoType = 'floxum' | 'composer';
interface RepoRow {
    type: RepoType;
    url?: string;
    username?: string;
    token?: string;
}
interface TestState {
    testing: boolean;
    ok: boolean | null;
    reason: string | null;
}
export default class RepositoriesSettings extends Component {
    repos: RepoRow[];
    tests: TestState[];
    saving: boolean;
    oninit(vnode: Mithril.Vnode<{}, this>): void;
    view(): JSX.Element;
    repoRow(repo: RepoRow, index: number): JSX.Element;
    testResult(index: number): Mithril.Children;
    field(label: Mithril.Children, type: string, value: string | undefined, set: (value: string) => void, placeholder?: string): JSX.Element;
    setType(index: number, type: RepoType): void;
    addFloxum(): void;
    addComposer(): void;
    remove(index: number): void;
    resetTest(index: number): void;
    test(index: number): void;
    save(): void;
    /**
     * Drop empty rows and trim values before persisting.
     */
    clean(): RepoRow[];
    read(): RepoRow[];
}
export {};
