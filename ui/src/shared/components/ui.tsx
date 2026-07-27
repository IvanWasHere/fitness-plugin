import { forwardRef, type ButtonHTMLAttributes, type ReactNode } from 'react';
import { Icon, type IconName } from './Icon';

/**
 * The small pieces every screen is built from (plans/04-user-app.md#structure).
 *
 * Deliberately thin: each renders the ported class names from
 * `styles/app.css` and adds nothing. The prototype's equivalents were `<div>`s
 * with `onclick` handlers — unfocusable and invisible to assistive tech — so the
 * one rule here is that anything clickable is a real `<button>` or `<a>`.
 */

export function Card({
  children,
  className = '',
  interactive = false,
}: {
  children: ReactNode;
  className?: string;
  interactive?: boolean;
}) {
  return (
    <div className={`fc-card ${interactive ? 'fc-card--link' : ''} ${className}`.trim()}>
      {children}
    </div>
  );
}

type ButtonVariant = 'primary' | 'secondary' | 'danger';

interface ButtonProps extends Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'type'> {
  variant?: ButtonVariant;
  size?: 'sm' | 'md' | 'lg';
  block?: boolean;
  icon?: IconName;
  /**
   * Defaults to `button`, which is the safe default and why this was originally
   * not settable: a bare `<button>` inside a form submits it, so every icon
   * button in a form would have submitted on click. The admin forms need a real
   * submit control, so it is now opt-in rather than unavailable.
   */
  type?: 'button' | 'submit';
}

// forwardRef because dialogs and the player need to move focus onto a specific
// button — React 18 has no ref-as-prop.
export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
  {
    variant = 'secondary',
    size = 'md',
    block = false,
    icon,
    type = 'button',
    children,
    className = '',
    ...rest
  },
  ref,
) {
  const classes = [
    'fc-btn',
    `fc-btn--${variant}`,
    size !== 'md' ? `fc-btn--${size}` : '',
    block ? 'fc-btn--block' : '',
    className,
  ]
    .filter(Boolean)
    .join(' ');

  return (
    <button ref={ref} type={type} className={classes} {...rest}>
      {icon && <Icon name={icon} />}
      {children}
    </button>
  );
});

const TAG_TONES = {
  beginner: 'green',
  intermediate: 'orange',
  advanced: 'red',
  assigned: 'blue',
  in_progress: 'orange',
  completed: 'green',
  paused: 'orange',
  abandoned: 'red',
} as const;

export function Tag({ children, tone }: { children: ReactNode; tone?: string }) {
  const mapped =
    tone && tone in TAG_TONES ? TAG_TONES[tone as keyof typeof TAG_TONES] : (tone ?? 'green');

  return <span className={`fc-tag fc-tag--${mapped}`}>{children}</span>;
}

export function ProgressBar({ value, tone = 'var(--accent)' }: { value: number; tone?: string }) {
  const clamped = Math.max(0, Math.min(100, value));

  return (
    <div
      className="fc-progress"
      role="progressbar"
      aria-valuenow={Math.round(clamped)}
      aria-valuemin={0}
      aria-valuemax={100}
    >
      <div className="fc-progress__fill" style={{ width: `${clamped}%`, background: tone }} />
    </div>
  );
}

export function StatCard({
  icon,
  value,
  label,
  tone = 'var(--accent)',
}: {
  icon: IconName;
  value: ReactNode;
  label: string;
  tone?: string;
}) {
  return (
    <Card>
      <div className="fc-flex fc-flex-c fc-gap-12">
        <div
          className="fc-stat__icon"
          style={{ background: `color-mix(in srgb, ${tone} 14%, transparent)`, color: tone }}
        >
          <Icon name={icon} size={20} />
        </div>
        <div>
          <div className="fc-stat__value">{value}</div>
          <div className="fc-stat__label">{label}</div>
        </div>
      </div>
    </Card>
  );
}

export function EmptyState({
  title,
  children,
  action,
}: {
  title: string;
  children?: ReactNode;
  action?: ReactNode;
}) {
  return (
    <div className="fc-empty">
      <h3>{title}</h3>
      {children && <p className="fc-text-sm">{children}</p>}
      {action && <div className="fc-mt-16">{action}</div>}
    </div>
  );
}

/**
 * Loading placeholders. The prototype had none — a slow connection showed blank
 * rectangles and no indication anything was happening.
 */
export function Skeleton({
  height = 16,
  width = '100%',
}: {
  height?: number | string;
  width?: number | string;
}) {
  return <div className="fc-skeleton" style={{ height, width }} aria-hidden="true" />;
}

export function SkeletonCards({ count = 3 }: { count?: number }) {
  return (
    <div className="fc-grid fc-grid-3" aria-busy="true" aria-label="Loading">
      {Array.from({ length: count }, (_, i) => (
        <Skeleton key={i} height={220} />
      ))}
    </div>
  );
}

/**
 * Error state with a way out. Every failed query gets one of these rather than a
 * blank screen.
 */
export function ErrorState({ message, onRetry }: { message: string; onRetry?: () => void }) {
  return (
    <div className="fc-empty">
      <h3>Something went wrong</h3>
      <p className="fc-text-sm">{message}</p>
      {onRetry && (
        <div className="fc-mt-16">
          <Button icon="refresh" onClick={onRetry}>
            Try again
          </Button>
        </div>
      )}
    </div>
  );
}

export function PageHeader({
  title,
  subtitle,
  actions,
}: {
  title: string;
  subtitle?: string;
  actions?: ReactNode;
}) {
  return (
    <header className="fc-page-header fc-flex fc-flex-between fc-flex-c fc-gap-12 fc-flex-wrap">
      <div>
        <h1>{title}</h1>
        {subtitle && <p>{subtitle}</p>}
      </div>
      {actions}
    </header>
  );
}
