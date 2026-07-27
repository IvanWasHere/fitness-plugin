/**
 * The icon set, kept out of `Icon.tsx` so that file exports only its component.
 *
 * Same split as `session-context.ts` beside `session.tsx`: mixing components and
 * plain values in one module breaks React Fast Refresh, which then reloads the
 * whole page on every edit instead of swapping the component.
 *
 * Paths are inline because the prototype pulled Font Awesome from a CDN — a GDPR
 * exposure in the EU and a hard dependency on someone else's uptime — and then
 * wrote `m('i.fa-solid fa-list')` in 35 places, where the space instead of a dot
 * meant the class list was only `fa-solid`, so those icons never rendered.
 */
export const ICON_PATHS = {
  home: 'M3 10.5 12 3l9 7.5M5 9.5V21h14V9.5',
  dumbbell: 'M6.5 6.5v11M17.5 6.5v11M3 9.5v5M21 9.5v5M6.5 12h11',
  play: 'M7 4.5v15l13-7.5z',
  pause: 'M8.5 4.5v15M15.5 4.5v15',
  check: 'M4 12.5 9.5 18 20 6.5',
  x: 'M5.5 5.5 18.5 18.5M18.5 5.5 5.5 18.5',
  menu: 'M4 7h16M4 12h16M4 17h16',
  chevronLeft: 'M15 5 8 12l7 7',
  chevronRight: 'M9 5l7 7-7 7',
  clock: 'M12 7v5.5l3.5 2M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
  flame:
    'M12 22c4 0 6.5-2.6 6.5-6 0-4.5-4.5-6.5-4-12C10 6 7 8 7 12c0 1.4.6 2.6 1.5 3.4C8.8 13 10 11.6 11 11c-.6 2.4-1 3.6-1 5 0 3.4 2 6 2 6z',
  trophy:
    'M8 4h8v4a4 4 0 0 1-8 0V4zM8 5H5v1.5A3.5 3.5 0 0 0 8.5 10M16 5h3v1.5A3.5 3.5 0 0 1 15.5 10M12 12v4M9 20h6M10 20l.5-4h3l.5 4',
  activity: 'M3 12h4l3 8 4-16 3 8h4',
  user: 'M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z',
  logOut: 'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9',
  grid: 'M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z',
  list: 'M8 6h13M8 12h13M8 18h13M3.5 6h.01M3.5 12h.01M3.5 18h.01',
  weight: 'M4 8h16l-1.5 12h-13zM8.5 8a3.5 3.5 0 1 1 7 0',
  wifiOff:
    'M3 3l18 18M8.5 15.5a5 5 0 0 1 7 0M12 20h.01M5 12a11 11 0 0 1 4-2.6M19 12a11 11 0 0 0-7-3',
  refresh: 'M20 11a8 8 0 1 0-.6 4M20 5v6h-6',
  plus: 'M12 5v14M5 12h14',
  minus: 'M5 12h14',
  skip: 'M5 5l9 7-9 7zM19 5v14',
  bell: 'M18 9a6 6 0 1 0-12 0c0 5-2 6-2 6h16s-2-1-2-6M13.7 20a2 2 0 0 1-3.4 0',
  target:
    'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM12 16a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM12 13a1 1 0 1 0 0-2 1 1 0 0 0 0 2z',
  heart: 'M12 20.3 4.6 13a4.8 4.8 0 0 1 0-6.8 4.8 4.8 0 0 1 6.8 0l.6.6.6-.6a4.8 4.8 0 0 1 6.8 6.8z',
  moon: 'M20.5 14.5A8.5 8.5 0 0 1 9.5 3.5a8.5 8.5 0 1 0 11 11z',
  smile: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM8.5 14.5a4.5 4.5 0 0 0 7 0M9 9.5h.01M15 9.5h.01',
  zap: 'M13 2 4.5 13.5H11l-1 8.5L19 10.5h-6.5z',
  gauge: 'M12 14.5 16 9M4.6 18a9 9 0 1 1 14.8 0M12 14.5a1 1 0 1 0 0-2 1 1 0 0 0 0 2z',
  ruler: 'M3 15.5 8.5 21 21 8.5 15.5 3zM7.5 11l2 2M11 7.5l2 2M14.5 4l2 2',
  arrowUp: 'M12 19V5M6 11l6-6 6 6',
  arrowDown: 'M12 5v14M6 13l6 6 6-6',
} as const;

export type IconName = keyof typeof ICON_PATHS;

/**
 * Narrow a server-supplied string to an icon we actually have.
 *
 * The Health screen's cards name their icon in PHP, so the name arrives as a
 * `string`. Casting it blindly would render `<path d={undefined}>` — an
 * invisible icon and no error — the first time a card is added on the server
 * without one being added here.
 */
export function toIconName(name: string, fallback: IconName = 'activity'): IconName {
  return name in ICON_PATHS ? (name as IconName) : fallback;
}
