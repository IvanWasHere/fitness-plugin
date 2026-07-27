import { useState, type ReactNode } from 'react';
import { Button } from '@shared/components/ui';
import type { AdminRow } from '../api/types';

/**
 * A field-list form for resources whose editor is just their columns (W2.5).
 *
 * The prototype's forms mutated `state.modal.data` on every keystroke and
 * validated with `if (!d.name) return showToast('required')` — so a half-typed
 * edit was already applied to the row behind the modal, and cancelling did not
 * undo it. This holds a draft and submits it, which is why Cancel works.
 *
 * 05 asks for `react-hook-form` + `zod` schemas shared with the server's `args`.
 * That is the right destination and it is not this package: the six forms here
 * are flat field lists, and the shared-schema generation it implies is a piece
 * of tooling in its own right. What is delivered instead is the behaviour the
 * plan actually names — field-level required marks, disabled-while-submitting,
 * and server errors surfaced rather than swallowed.
 */
export interface FieldSpec {
  name: string;
  label: string;
  type?: 'text' | 'number' | 'date' | 'textarea' | 'select' | 'checkbox';
  options?: string[];
  step?: string;
  required?: boolean;
}

export function SimpleForm({
  row,
  fields,
  onSubmit,
  saving,
  error,
  notice,
}: {
  row: AdminRow | null;
  fields: FieldSpec[];
  onSubmit: (payload: Record<string, unknown>) => void;
  saving: boolean;
  error: string | null;
  notice?: ReactNode;
}) {
  const [draft, setDraft] = useState<Record<string, unknown>>(() => {
    const initial: Record<string, unknown> = {};

    for (const field of fields) {
      const value = row?.[field.name];

      initial[field.name] = field.type === 'checkbox' ? Boolean(value) : (value ?? '');
    }

    return initial;
  });

  const missing = fields.filter(
    (field) => field.required && String(draft[field.name] ?? '').trim() === '',
  );

  return (
    <form
      onSubmit={(event) => {
        event.preventDefault();

        if (missing.length === 0 && !saving) {
          onSubmit(draft);
        }
      }}
    >
      {notice}

      <div className="fc-admin-form-grid">
        {fields.map((field) => (
          <div className="fc-field" key={field.name}>
            <label className="fc-label" htmlFor={`fc-f-${field.name}`}>
              {field.label}
              {field.required && <span aria-hidden="true"> *</span>}
            </label>

            {field.type === 'textarea' ? (
              <textarea
                id={`fc-f-${field.name}`}
                rows={3}
                value={String(draft[field.name] ?? '')}
                onChange={(e) => setDraft((d) => ({ ...d, [field.name]: e.target.value }))}
              />
            ) : field.type === 'select' ? (
              <select
                id={`fc-f-${field.name}`}
                value={String(draft[field.name] ?? '')}
                onChange={(e) => setDraft((d) => ({ ...d, [field.name]: e.target.value }))}
              >
                <option value="">—</option>
                {(field.options ?? []).map((option) => (
                  <option key={option} value={option}>
                    {option}
                  </option>
                ))}
              </select>
            ) : field.type === 'checkbox' ? (
              <input
                id={`fc-f-${field.name}`}
                type="checkbox"
                checked={Boolean(draft[field.name])}
                onChange={(e) => setDraft((d) => ({ ...d, [field.name]: e.target.checked }))}
              />
            ) : (
              <input
                id={`fc-f-${field.name}`}
                type={field.type ?? 'text'}
                step={field.step}
                value={String(draft[field.name] ?? '')}
                onChange={(e) => setDraft((d) => ({ ...d, [field.name]: e.target.value }))}
              />
            )}
          </div>
        ))}
      </div>

      {/* The server's message, not a generic "something went wrong": it is the
          one that explains *why* — a reserved slug, a trainer with clients. */}
      {error && <p className="fc-text-sm fc-text-danger">{error}</p>}

      {missing.length > 0 && (
        <p className="fc-text-xs fc-text-muted">
          Required: {missing.map((f) => f.label).join(', ')}
        </p>
      )}

      <Button variant="primary" block type="submit" disabled={missing.length > 0 || saving}>
        {saving ? 'Saving…' : row ? 'Save changes' : 'Create'}
      </Button>
    </form>
  );
}
