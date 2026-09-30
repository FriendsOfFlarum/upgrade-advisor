import type Mithril from 'mithril';
import type { ExtensionCompat } from '../models/Report';
/**
 * The extension's title, linked to its admin page. The advisor's own row stays
 * plain, as it would link to the page you're already on.
 */
export declare function extensionTitle(ext: ExtensionCompat): Mithril.Children;
export declare function hintText(ext: ExtensionCompat): string;
/**
 * Ways to ask the author about upgrade plans, as compact icon links: the
 * support thread, the issue tracker (or source), and each author's email or
 * homepage.
 */
export declare function contactLinks(ext: ExtensionCompat): Mithril.Children;
