/**
 * Inline SVG icons.
 *
 * The prototype pulled Font Awesome from a CDN — a GDPR exposure in the EU and a
 * hard dependency on someone else's uptime — and then wrote `m('i.fa-solid
 * fa-list')` in 35 places, where the space instead of a dot means the class list
 * was only `fa-solid`, so those icons never rendered at all.
 *
 * Inline paths remove both problems: nothing to load, nothing to mistype, and an
 * icon is a component the compiler checks. They are decorative by default
 * (`aria-hidden`); pass a `title` when an icon carries meaning on its own.
 */
import type { SVGProps } from 'react';

const PATHS = {
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
} as const;

export type IconName = keyof typeof PATHS;

interface IconProps extends Omit<SVGProps<SVGSVGElement>, 'name'> {
  name: IconName;
  size?: number;
  /** Give the icon an accessible name when it is not accompanied by text. */
  title?: string;
}

export function Icon({ name, size = 18, title, ...rest }: IconProps) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={1.8}
      strokeLinecap="round"
      strokeLinejoin="round"
      role={title ? 'img' : undefined}
      aria-hidden={title ? undefined : true}
      focusable="false"
      {...rest}
    >
      {title && <title>{title}</title>}
      <path d={PATHS[name]} />
    </svg>
  );
}
