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
  variant = 'confirm',
  confirmDisabled = false,
  onConfirm,
  onCancel,
}: {
  title: string;
  children?: ReactNode;
  confirmLabel: string;
  cancelLabel?: string;
  tone?: 'primary' | 'danger';
  /**
   * `confirm` is a question with a sentence in it — the body is muted and
   * secondary. `form` is a panel the member fills in, so the body is not styled
   * down and the dialog is wider. Same focus, escape and overlay behaviour
   * either way; only the shape of the content differs.
   */
  variant?: 'confirm' | 'form';
  confirmDisabled?: boolean;
  onConfirm: () => void;
  onCancel: () => void;
}) {
  const isForm = 'form' === variant;
  const bodyRef = useRef<HTMLDivElement>(null);
  const confirmRef = useRef<HTMLButtonElement>(null);
  const returnFocusTo = useRef<Element | null>(null);

  /**
   * Escape reads the *current* handler through a ref rather than closing over it.
   *
   * This effect must run **once**, on mount. It used to depend on `onCancel`,
   * and every caller passes an inline arrow — so the identity changed on every
   * render, the effect re-ran, and it moved focus again. In a form dialog that
   * was a live bug: type one character, state changes, focus jumps from the
   * field to the confirm button, and the next space bar press activates it. A
   * trainer writing "My schedule is full" declined the request on the space
   * after "My".
   */
  const cancelRef = useRef(onCancel);
  cancelRef.current = onCancel;

  useEffect(() => {
    returnFocusTo.current = document.activeElement;

    // A form dialog opens on its first field. Landing on the confirm button
    // instead makes the member tab backwards to reach the thing they opened the
    // dialog to fill in — and when that button is destructive, one stray space
    // is enough to do the destructive thing.
    const firstField = isForm
      ? bodyRef.current?.querySelector<HTMLElement>('input:not([type="hidden"]), textarea, select')
      : null;

    (firstField ?? confirmRef.current)?.focus();

    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        cancelRef.current();
      }
    };

    document.addEventListener('keydown', onKey);

    return () => {
      document.removeEventListener('keydown', onKey);
      (returnFocusTo.current as HTMLElement | null)?.focus?.();
    };
  }, [isForm]);

  return (
    <div
      className="fc-modal-overlay"
      onClick={(event) => {
        if (event.target === event.currentTarget) {
          onCancel();
        }
      }}
    >
      <div
        className={`fc-modal${isForm ? ' fc-modal--form' : ''}`}
        role="dialog"
        aria-modal="true"
        aria-label={title}
      >
        <h3>{title}</h3>
        {children && (
          <div ref={bodyRef} className={isForm ? 'fc-mb-16' : 'fc-text-muted fc-text-sm fc-mb-24'}>
            {children}
          </div>
        )}
        <div className="fc-flex fc-gap-10">
          <Button variant="secondary" block onClick={onCancel}>
            {cancelLabel}
          </Button>
          <Button
            ref={confirmRef}
            variant={tone}
            block
            disabled={confirmDisabled}
            onClick={onConfirm}
          >
            {confirmLabel}
          </Button>
        </div>
      </div>
    </div>
  );
}
