import { useState } from 'react';
import { Modal } from '@shared/components/Modal';
import { Skeleton } from '@shared/components/ui';
import { useHealthActions, useHealthStats } from '../../api/queries';
import type { HealthCard, HealthField, HealthInput, HealthStat } from '../../api/types';

/**
 * The history behind one card, and the form to add to it (W2.2).
 *
 * Two things the prototype's version got wrong, both of which changed what got
 * stored rather than how it looked:
 *
 * - it offered an "Add Entry" box for **BMI**, a number the server derives from
 *   height and weight and recomputes on the next weigh-in — so the entry was
 *   accepted, displayed, and then silently discarded;
 * - it took **one** number for blood pressure and wrote
 *   `diastolic = systolic * 0.65`, storing a fabricated reading indistinguishable
 *   from a measured one. Blood pressure is two inputs here, and the server
 *   refuses half a reading.
 */
export function HealthDetailModal({ card, onClose }: { card: HealthCard; onClose: () => void }) {
  const metrics = metricsFor(card);
  const { data, isLoading } = useHealthStats(metrics, metrics !== undefined);
  const { saveStat, deleteStat } = useHealthActions();

  const [values, setValues] = useState<Record<string, string>>({});

  const inputs = INPUTS[card.key] ?? [];
  const entry = buildEntry(inputs, values);
  const canSave = card.editable && entry !== null;

  const history = (data?.items ?? []).filter((item) => describe(card, item) !== null);

  return (
    <Modal
      title={card.label}
      variant="form"
      cancelLabel="Close"
      confirmLabel={saveStat.isPending ? 'Saving…' : 'Add entry'}
      confirmDisabled={!canSave || saveStat.isPending}
      onCancel={onClose}
      onConfirm={() => {
        if (entry) {
          saveStat.mutate(entry, { onSuccess: onClose });
        }
      }}
    >
      <div className="fc-flex fc-flex-c fc-gap-16 fc-mb-24">
        <div>
          <div className="fc-health-detail__value" style={{ color: card.tone }}>
            {card.display ?? '—'}
            {card.unit && card.display && (
              <small className="fc-health-card__unit">{card.unit}</small>
            )}
          </div>
          <p className="fc-text-xs fc-text-muted">{card.detail ?? 'Nothing logged yet'}</p>
        </div>
      </div>

      <h4 className="fc-text-xs fc-text-muted fc-uppercase fc-mb-12">History</h4>

      {isLoading && <Skeleton height={80} />}

      {!isLoading && history.length === 0 && (
        <p className="fc-text-sm fc-text-muted fc-mb-16">
          {card.editable
            ? 'No readings yet. The first one you add appears here.'
            : 'BMI is calculated from your height and weight — log a weight and it fills in.'}
        </p>
      )}

      {history.length > 0 && (
        <ul className="fc-history-list fc-mb-16">
          {history.map((item) => (
            <li key={item.id}>
              <span className="fc-text-xs fc-text-muted">{item.date}</span>
              <span>{describe(card, item)}</span>
              {/* Q10: a reading a trainer or administrator entered says so,
                  rather than leaving the member to wonder where it came from. */}
              {item.source === 'admin' && (
                <span className="fc-text-xs fc-text-muted">added for you</span>
              )}
              {card.editable && (
                <button
                  type="button"
                  className="fc-link fc-text-xs"
                  disabled={deleteStat.isPending}
                  onClick={() => deleteStat.mutate(item.id)}
                >
                  Remove
                </button>
              )}
            </li>
          ))}
        </ul>
      )}

      {card.editable && (
        <>
          <h4 className="fc-text-xs fc-text-muted fc-uppercase fc-mb-12">Add a reading</h4>

          {inputs.map((input) =>
            input.scale ? (
              <ScaleInput
                key={input.field}
                input={input}
                value={values[input.field] ?? ''}
                onChange={(next) => setValues((current) => ({ ...current, [input.field]: next }))}
              />
            ) : (
              <div className="fc-field" key={input.field}>
                <label className="fc-label" htmlFor={`fc-health-${input.field}`}>
                  {input.label}
                </label>
                <input
                  id={`fc-health-${input.field}`}
                  type="number"
                  step={input.step}
                  min={input.min}
                  max={input.max}
                  inputMode="decimal"
                  value={values[input.field] ?? ''}
                  onChange={(event) =>
                    setValues((current) => ({ ...current, [input.field]: event.target.value }))
                  }
                />
              </div>
            ),
          )}

          <p className="fc-text-xs fc-text-muted">
            Recorded against today. Adding again today updates today&rsquo;s entry rather than
            creating a second one.
          </p>
        </>
      )}

      {saveStat.error && <p className="fc-text-sm fc-text-danger">{saveStat.error.message}</p>}
      {deleteStat.error && <p className="fc-text-sm fc-text-danger">{deleteStat.error.message}</p>}
    </Modal>
  );
}

/**
 * A 1–5 or 1–10 rating as buttons rather than a number box.
 *
 * Mood is not a quantity you type. A number input also accepts 7 for a
 * five-point scale, which the server then rejects — a validation error for
 * something the control should never have allowed.
 */
function ScaleInput({
  input,
  value,
  onChange,
}: {
  input: MetricInput;
  value: string;
  onChange: (value: string) => void;
}) {
  const options = Array.from({ length: input.max - input.min + 1 }, (_, i) => input.min + i);

  return (
    <fieldset className="fc-field fc-scale">
      <legend className="fc-label">{input.label}</legend>
      <div className="fc-scale__row">
        {options.map((option) => (
          <button
            key={option}
            type="button"
            className={`fc-scale__dot${value === String(option) ? ' fc-scale__dot--on' : ''}`}
            aria-pressed={value === String(option)}
            onClick={() => onChange(value === String(option) ? '' : String(option))}
          >
            {option}
          </button>
        ))}
      </div>
    </fieldset>
  );
}

interface MetricInput {
  field: HealthField;
  label: string;
  step: string;
  min: number;
  max: number;
  scale?: boolean;
}

/**
 * What each card asks for. Bounds mirror the server's, so the control refuses
 * what the API would refuse instead of round-tripping to find out.
 */
const INPUTS: Record<string, MetricInput[]> = {
  weight: [{ field: 'weight_kg', label: 'Weight (kg)', step: '0.1', min: 20, max: 500 }],
  body_fat: [{ field: 'body_fat_percentage', label: 'Body fat (%)', step: '0.1', min: 1, max: 70 }],
  sleep: [{ field: 'sleep_hours', label: 'Sleep (hours)', step: '0.1', min: 0, max: 24 }],
  heart_rate: [
    {
      field: 'heart_rate_resting',
      label: 'Resting heart rate (bpm)',
      step: '1',
      min: 25,
      max: 220,
    },
  ],
  // Two fields, never one. See the class docblock.
  blood_pressure: [
    { field: 'systolic_pressure', label: 'Systolic (mmHg)', step: '1', min: 60, max: 260 },
    { field: 'diastolic_pressure', label: 'Diastolic (mmHg)', step: '1', min: 30, max: 200 },
  ],
  mood: [{ field: 'mood_score', label: 'Mood', step: '1', min: 1, max: 5, scale: true }],
  energy: [{ field: 'energy_score', label: 'Energy', step: '1', min: 1, max: 10, scale: true }],
};

/** Which metrics the history request should filter to. `undefined` for BMI. */
function metricsFor(card: HealthCard): string | undefined {
  const inputs = INPUTS[card.key];

  if (inputs) {
    return inputs.map((input) => input.field).join(',');
  }

  // BMI has no column of its own to filter by; its history is the weight
  // history, which is what the card is derived from anyway.
  return card.key === 'bmi' ? 'weight_kg' : undefined;
}

/**
 * The submitted entry, or null when nothing usable was typed.
 *
 * Blood pressure returns null unless **both** boxes are filled: the server
 * refuses half a reading, and disabling the button is a kinder way to say so
 * than a 400 after the fact.
 */
function buildEntry(inputs: MetricInput[], values: Record<string, string>): HealthInput | null {
  const entry: HealthInput = {};
  let filled = 0;

  for (const input of inputs) {
    const raw = (values[input.field] ?? '').trim();

    if (raw === '') {
      continue;
    }

    const parsed = Number(raw);

    if (!Number.isFinite(parsed) || parsed < input.min || parsed > input.max) {
      return null;
    }

    entry[input.field] = parsed;
    filled += 1;
  }

  return filled === inputs.length && filled > 0 ? entry : null;
}

/** One history row, rendered the way its card reads. */
function describe(card: HealthCard, item: HealthStat): string | null {
  if (card.key === 'bmi') {
    return item.bmi === null ? null : String(item.bmi);
  }

  if (card.key === 'blood_pressure') {
    return item.systolic_pressure === null || item.diastolic_pressure === null
      ? null
      : `${item.systolic_pressure}/${item.diastolic_pressure} mmHg`;
  }

  const field = INPUTS[card.key]?.[0]?.field;
  const value = field ? item[field] : null;

  return value === null || value === undefined ? null : `${value} ${card.unit}`.trim();
}
