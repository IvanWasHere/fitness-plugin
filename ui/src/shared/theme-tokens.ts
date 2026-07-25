import type { BootTheme } from './boot';

/**
 * Write theme tokens onto an element as CSS custom properties.
 *
 * Kept apart from `ThemeProvider` so the module that exports a component exports
 * nothing else — that is what keeps Fast Refresh working in `npm run dev`.
 *
 * The names match exactly what the PHP shell emits (plans/07-theming.md), on the
 * same node, so whichever writes last wins and the two can never disagree about
 * spelling: `colors.primary` → `--fc-color-primary`, `typography.base_size` →
 * `--fc-type-base-size`, `layout.radius` → `--fc-layout-radius`.
 */
const GROUP_PREFIX: Record<string, string> = {
  colors: 'color',
  typography: 'type',
  layout: 'layout',
};

export function applyThemeTokens(theme: BootTheme, target: HTMLElement | null): void {
  if (!target) {
    return;
  }

  for (const [group, prefix] of Object.entries(GROUP_PREFIX)) {
    const tokens = theme[group as keyof BootTheme];
    if (!tokens || typeof tokens !== 'object') {
      continue;
    }

    for (const [name, value] of Object.entries(tokens as Record<string, unknown>)) {
      if (typeof value === 'string' || typeof value === 'number') {
        target.style.setProperty(`--fc-${prefix}-${name.replace(/_/g, '-')}`, String(value));
      }
    }
  }
}
