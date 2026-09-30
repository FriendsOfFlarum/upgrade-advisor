import app from 'flarum/admin/app';
import extractText from 'flarum/common/utils/extractText';

import type { CheckData } from '../models/Report';

/**
 * A check's description. Warnings may have subtypes (e.g. the database check
 * distinguishes "couldn't determine" from "below recommended version").
 *
 * Mirrors FoF\UpgradeAdvisor\CsvReport::checkRow() so the CSV says the same.
 */
export function checkDescription(check: CheckData): string {
  const base = `fof-upgrade-advisor.admin.checks.${check.id}`;
  const key = check.status === 'warning' && check.meta.warningType ? `${base}.warning_${check.meta.warningType}` : `${base}.${check.status}`;

  return extractText(
    app.translator.trans(key, {
      current: check.current,
      required: check.meta.required,
      recommended: check.meta.recommended,
    })
  );
}

/**
 * A short label for the header chip, e.g. "PHP 8.3.33". Checks without a
 * `chip` translation fall back to their current value, then their title.
 */
export function checkChip(check: CheckData): string {
  const key = `fof-upgrade-advisor.admin.checks.${check.id}.chip`;

  if (check.current && app.translator.translations[key]) {
    return extractText(app.translator.trans(key, { current: check.current }));
  }

  return check.current || extractText(app.translator.trans(`fof-upgrade-advisor.admin.checks.${check.id}.title`));
}
