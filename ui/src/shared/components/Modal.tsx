import { useEffect, useRef, type ReactNode } from 'react';
import { Button } from './ui';

/**
 * A dialog the app owns.
 *
 * The prototype used native `confirm()` for "Finish workout early?" — it blocks
 * the JS thread, renders as browser chrome that looks broken on mobile, and
 * cannot be styled or made to match anything. It also has no place in a
 * full-screen player.
 *
 * Escape closes, focus moves in on open and returns to the trigger on close, and
 * the overlay click is a deliberate cancel.
 */
export function Modal({
  title,
  children,
  confirmLabel,
  cancelLabel = 'Cancel',
  tone = 'primary',
  onConfirm,
  onCancel,
}: {
  title: string;
  children?: ReactNode;
  confirmLabel: string;
  cancelLabel?: string;
  tone?: 'primary' | 'danger';
  onConfirm: () => void;
  onCancel: () => void;
}) {
  const confirmRef = useRef<HTMLButtonElement>(null);
  const returnFocusTo = useRef<Element | null>(null);

  useEffect(() => {
    returnFocusTo.current = document.activeElement;
    confirmRef.current?.focus();

    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        onCancel();
      }
    };

    document.addEventListener('keydown', onKey);

    return () => {
      document.removeEventListener('keydown', onKey);
      (returnFocusTo.current as HTMLElement | null)?.focus?.();
    };
  }, [onCancel]);

  return (
    <div
      className="fc-modal-overlay"
      onClick={(event) => {
        if (event.target === event.currentTarget) {
          onCancel();
        }
      }}
    >
      <div className="fc-modal" role="dialog" aria-modal="true" aria-label={title}>
        <h3>{title}</h3>
        {children && <div className="fc-text-muted fc-text-sm fc-mb-24">{children}</div>}
        <div className="fc-flex fc-gap-10">
          <Button variant="secondary" block onClick={onCancel}>
            {cancelLabel}
          </Button>
          <Button ref={confirmRef} variant={tone} block onClick={onConfirm}>
            {confirmLabel}
          </Button>
        </div>
      </div>
    </div>
  );
}
