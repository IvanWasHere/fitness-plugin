/**
 * Inline SVG icons.
 *
 * Nothing to load and nothing to mistype: an icon is a component the compiler
 * checks. They are decorative by default (`aria-hidden`); pass a `title` when an
 * icon carries meaning on its own.
 *
 * The paths and the `toIconName` guard live in `icon-paths.ts` so this module
 * exports only its component — see the note there.
 */
import type { SVGProps } from 'react';
import { ICON_PATHS, type IconName } from './icon-paths';

export type { IconName };

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
      <path d={ICON_PATHS[name]} />
    </svg>
  );
}
