import { useState } from 'react';
import { Modal } from '@shared/components/Modal';
import { useHealthActions } from '../../api/queries';
import type { LatestMeasurements, MeasurementField, MeasurementsInput } from '../../api/types';

/**
 * The body-measurement form (W2.2).
 *
 * **Nothing is prefilled**, and that is the point. Prefilling from the last set
 * looks like a convenience and is actually a data-integrity hole: submitting
 * the form would stamp today's date on a chest measurement taken a month ago,
 * and the progress chart would then show a reading that was never measured. The
 * previous value is offered as a placeholder — visible, not submitted — so the
 * member can see what they were last at while only recording what they actually
 * measured today.
 *
 * Fields left empty stay unmeasured; the summary keeps showing the last real
 * value for each of them, dated.
 */
const FIELDS: Array<[MeasurementField, string, number, number]> = [
  ['chest_cm', 'Chest', 30, 250],
  ['waist_cm', 'Waist', 30, 250],
  ['hips_cm', 'Hips', 30, 250],
  ['shoulders_cm', 'Shoulders', 40, 250],
  ['arms_cm', 'Arms', 10, 100],
  ['thighs_cm', 'Thighs', 20, 150],
  ['neck_cm', 'Neck', 20, 100],
  ['calves_cm', 'Calves', 15, 100],
];

export function MeasurementsModal({
  latest,
  onClose,
}: {
  latest: LatestMeasurements | null;
  onClose: () => void;
}) {
  const { saveMeasurements } = useHealthActions();
  const [values, setValues] = useState<Record<string, string>>({});

  const entry = buildEntry(values);

  return (
    <Modal
      title="Body measurements"
      variant="form"
      confirmLabel={saveMeasurements.isPending ? 'Saving…' : 'Save measurements'}
      confirmDisabled={entry === null || saveMeasurements.isPending}
      onCancel={onClose}
      onConfirm={() => {
        if (entry) {
          saveMeasurements.mutate(entry, { onSuccess: onClose });
        }
      }}
    >
      <p className="fc-text-sm fc-text-muted fc-mb-16">
        Fill in only what you measured today. Anything left blank keeps its last recorded value.
      </p>

      <div className="fc-grid fc-grid-2 fc-gap-12">
        {FIELDS.map(([field, label, min, max]) => {
          const previous = latest?.fields[field];

          return (
            <div className="fc-field" key={field}>
              <label className="fc-label" htmlFor={`fc-measure-${field}`}>
                {label} (cm)
              </label>
              <input
                id={`fc-measure-${field}`}
                type="number"
                step="0.1"
                min={min}
                max={max}
                inputMode="decimal"
                placeholder={previous ? String(previous.value) : ''}
                value={values[field] ?? ''}
                onChange={(event) =>
                  setValues((current) => ({ ...current, [field]: event.target.value }))
                }
              />
              {previous && <span className="fc-text-xs fc-text-muted">Last: {previous.date}</span>}
            </div>
          );
        })}
      </div>

      {saveMeasurements.error && (
        <p className="fc-text-sm fc-text-danger">{saveMeasurements.error.message}</p>
      )}
    </Modal>
  );
}

/**
 * What was typed, or null when nothing usable was.
 *
 * Out-of-range values return null rather than being sent: the server would
 * refuse them anyway, and a disabled button says so before the round trip.
 */
function buildEntry(values: Record<string, string>): MeasurementsInput | null {
  const entry: MeasurementsInput = {};
  let filled = 0;

  for (const [field, , min, max] of FIELDS) {
    const raw = (values[field] ?? '').trim();

    if (raw === '') {
      continue;
    }

    const parsed = Number(raw);

    if (!Number.isFinite(parsed) || parsed < min || parsed > max) {
      return null;
    }

    entry[field] = parsed;
    filled += 1;
  }

  return filled > 0 ? entry : null;
}
