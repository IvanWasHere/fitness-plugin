import { useState } from 'react';
import { Icon } from '@shared/components/Icon';
import { toIconName } from '@shared/components/icon-paths';
import { Modal } from '@shared/components/Modal';
import { Button, Card, ErrorState, PageHeader, Skeleton } from '@shared/components/ui';
import { useHealthActions, useHealthSummary } from '../api/queries';
import type { HealthCard, LatestMeasurements, MeasurementField } from '../api/types';
import { HealthDetailModal } from '../features/health/HealthDetailModal';
import { MeasurementsModal } from '../features/health/MeasurementsModal';

/**
 * The Health screen (W2.2).
 *
 * One request for the whole screen (`GET /health/summary`). The eight cards are
 * **described by the server**, not hardcoded here: the prototype built its card
 * list in the view, so every card indexed the tail of an array
 * (`d.weight[d.weight.length - 1].date`) and the whole screen threw for a member
 * who had logged nothing — which is every member on day one.
 *
 * Nothing here invents a reading. A metric with no entry shows a dash and says
 * so, rather than a plausible number nobody measured.
 */
export function Health() {
  const { data, isLoading, error, refetch } = useHealthSummary();

  const [detail, setDetail] = useState<HealthCard | null>(null);
  const [addingWeight, setAddingWeight] = useState(false);
  const [editingMeasurements, setEditingMeasurements] = useState(false);

  if (error) {
    return (
      <>
        <PageHeader title="Health" />
        <ErrorState message={error.message} onRetry={() => void refetch()} />
      </>
    );
  }

  if (isLoading || !data) {
    return (
      <>
        <PageHeader title="Health" />
        <div className="fc-grid fc-grid-2 fc-gap-16" aria-busy="true" aria-label="Loading">
          {Array.from({ length: 8 }, (_, i) => (
            <Skeleton key={i} height={88} />
          ))}
        </div>
      </>
    );
  }

  const weightCard = data.cards.find((card) => card.key === 'weight') ?? null;

  return (
    <>
      <PageHeader
        title="Health"
        subtitle="Monitor your health metrics"
        actions={
          <Button variant="primary" icon="plus" onClick={() => setAddingWeight(true)}>
            Log weight
          </Button>
        }
      />

      {data.target_weight_kg !== null && weightCard?.value !== null && weightCard && (
        <p className="fc-text-sm fc-text-muted fc-mb-16">
          Target {data.target_weight_kg} kg &middot;{' '}
          {formatGap(weightCard.value, data.target_weight_kg)}
        </p>
      )}

      <div className="fc-grid fc-grid-2 fc-gap-16 fc-mb-24">
        {data.cards.map((card) => (
          <MetricCard key={card.key} card={card} onOpen={() => setDetail(card)} />
        ))}
      </div>

      <MeasurementsPanel latest={data.measurements} onEdit={() => setEditingMeasurements(true)} />

      {detail && <HealthDetailModal card={detail} onClose={() => setDetail(null)} />}

      {addingWeight && (
        <WeightModal current={weightCard?.value ?? null} onClose={() => setAddingWeight(false)} />
      )}

      {editingMeasurements && (
        <MeasurementsModal
          latest={data.measurements}
          onClose={() => setEditingMeasurements(false)}
        />
      )}
    </>
  );
}

/**
 * One metric card.
 *
 * A real `<button>`, unlike the prototype's `<div onclick>`: the whole card is
 * the control that opens the history, and a div is unfocusable, unactivatable
 * by keyboard, and invisible to assistive technology.
 */
function MetricCard({ card, onOpen }: { card: HealthCard; onOpen: () => void }) {
  const empty = card.value === null;

  return (
    <Card interactive>
      <button type="button" className="fc-health-card" onClick={onOpen}>
        <span
          className="fc-stat__icon"
          style={{
            background: `color-mix(in srgb, ${card.tone} 14%, transparent)`,
            color: card.tone,
          }}
        >
          <Icon name={toIconName(card.icon)} size={20} />
        </span>

        <span className="fc-health-card__info">
          <span className="fc-font-bold">{card.label}</span>
          <span className="fc-text-xs fc-text-muted">
            {empty ? 'Not logged yet' : (card.detail ?? '')}
          </span>
        </span>

        <span className="fc-health-card__value">
          {empty ? (
            <span className="fc-text-muted">&mdash;</span>
          ) : (
            <>
              {card.display}
              {card.unit && <small className="fc-health-card__unit">{card.unit}</small>}
            </>
          )}
          <TrendArrow card={card} />
        </span>

        <Icon name="chevronRight" size={14} />
      </button>
    </Card>
  );
}

/**
 * Which way the last reading moved, and by how much.
 *
 * Deliberately unstyled by sentiment — no green for down, no red for up. The
 * server does not say whether a direction is good, because that depends on what
 * the member is training for; colouring it here would smuggle the judgement
 * back in.
 */
function TrendArrow({ card }: { card: HealthCard }) {
  const trend = card.trend;

  if (!trend || trend.direction === 'flat') {
    return null;
  }

  const magnitude = Math.abs(trend.delta).toFixed(card.decimals);

  return (
    <small className="fc-health-card__trend">
      <Icon name={trend.direction === 'up' ? 'arrowUp' : 'arrowDown'} size={12} />
      {magnitude}
      <span className="fc-visually-hidden">
        {trend.direction === 'up' ? 'higher' : 'lower'} than the previous reading
      </span>
    </small>
  );
}

const MEASUREMENT_LABELS: Array<[MeasurementField, string]> = [
  ['chest_cm', 'Chest'],
  ['waist_cm', 'Waist'],
  ['hips_cm', 'Hips'],
  ['shoulders_cm', 'Shoulders'],
  ['arms_cm', 'Arms'],
  ['thighs_cm', 'Thighs'],
  ['neck_cm', 'Neck'],
  ['calves_cm', 'Calves'],
];

/**
 * The latest of each measurement, each with the date it was taken.
 *
 * A value older than the most recent session is labelled with its own date
 * rather than shown alongside fresh ones as if it were current — measurements
 * are taken in different combinations at different times, and "chest: 101" with
 * no date is a claim the record does not support.
 */
function MeasurementsPanel({
  latest,
  onEdit,
}: {
  latest: LatestMeasurements | null;
  onEdit: () => void;
}) {
  const entries = MEASUREMENT_LABELS.flatMap(([key, label]) => {
    const field = latest?.fields[key];

    return field ? [{ key, label, ...field }] : [];
  });

  return (
    <Card>
      <div className="fc-flex fc-flex-between fc-flex-c fc-gap-12 fc-mb-16">
        <div>
          <h3 className="fc-text-lg">Body measurements</h3>
          <p className="fc-text-xs fc-text-muted">
            {latest ? `Last taken ${latest.last_recorded_on}` : 'Nothing recorded yet'}
          </p>
        </div>
        <Button icon="plus" onClick={onEdit}>
          {latest ? 'Update' : 'Add'}
        </Button>
      </div>

      {entries.length === 0 ? (
        <p className="fc-text-sm fc-text-muted">
          Measurements are monthly rather than daily, and they catch progress the scale misses
          &mdash; a waist that drops while weight holds is fat traded for muscle.
        </p>
      ) : (
        <ul className="fc-measure-grid">
          {entries.map((entry) => (
            <li key={entry.key}>
              <span className="fc-text-xs fc-text-muted">{entry.label}</span>
              <strong>{entry.value} cm</strong>
              {latest && entry.date !== latest.last_recorded_on && (
                <span className="fc-text-xs fc-text-muted">{entry.date}</span>
              )}
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}

/**
 * The quick weigh-in — the entry members make most often, so it is one field and
 * one button rather than a trip through the detail modal.
 */
function WeightModal({ current, onClose }: { current: number | null; onClose: () => void }) {
  const { saveStat } = useHealthActions();
  const [value, setValue] = useState(current === null ? '' : String(current));

  const parsed = Number(value);
  const valid = value.trim() !== '' && Number.isFinite(parsed) && parsed >= 20 && parsed <= 500;

  return (
    <Modal
      title="Log weight"
      variant="form"
      confirmLabel={saveStat.isPending ? 'Saving…' : 'Save weight'}
      confirmDisabled={!valid || saveStat.isPending}
      onCancel={onClose}
      onConfirm={() => saveStat.mutate({ weight_kg: parsed }, { onSuccess: onClose })}
    >
      <div className="fc-field">
        <label className="fc-label" htmlFor="fc-weight">
          Weight (kg)
        </label>
        <input
          id="fc-weight"
          type="number"
          step="0.1"
          min="20"
          max="500"
          inputMode="decimal"
          value={value}
          onChange={(event) => setValue(event.target.value)}
        />
      </div>

      <p className="fc-text-xs fc-text-muted">
        Recorded against today. Logging again today updates it rather than adding a second entry.
      </p>

      {saveStat.error && <p className="fc-text-sm fc-text-danger">{saveStat.error.message}</p>}
    </Modal>
  );
}

/** "2.5 kg to go" — never a percentage of a target the member never set. */
function formatGap(current: number, target: number): string {
  const gap = Math.abs(current - target);

  if (gap < 0.05) {
    return 'target reached';
  }

  return current > target ? `${gap.toFixed(1)} kg to go` : `${gap.toFixed(1)} kg below target`;
}
