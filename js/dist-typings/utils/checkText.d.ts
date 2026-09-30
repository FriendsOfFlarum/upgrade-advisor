import type { CheckData } from '../models/Report';
/**
 * A check's description. Warnings may have subtypes (e.g. the database check
 * distinguishes "couldn't determine" from "below recommended version").
 *
 * Mirrors FoF\UpgradeAdvisor\CsvReport::checkRow() so the CSV says the same.
 */
export declare function checkDescription(check: CheckData): string;
/**
 * A short label for the header chip, e.g. "PHP 8.3.33". Checks without a
 * `chip` translation fall back to their current value, then their title.
 */
export declare function checkChip(check: CheckData): string;
