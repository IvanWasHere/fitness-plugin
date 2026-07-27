import { useMemo, useState } from 'react';
import { Modal } from '@shared/components/Modal';
import { Icon } from '@shared/components/Icon';
import { useFoodSearch } from '../../api/queries';
import type { Food, MealItemInput, MealType } from '../../api/types';

/**
 * Add Meal — the food search and the basket, in one modal.
 *
 * The prototype had these as two separate modals stacked on each other, which
 * on a phone means the search covers the thing you are building. Here the
 * basket stays visible underneath the results, so you can see the meal add up.
 *
 * **Nutrients for a database food are never sent.** Only `food_id` and
 * `quantity` go to the server, which then copies the macros from the food row
 * itself — so a client cannot claim a doughnut has four calories, and the
 * copied-not-joined rule has exactly one implementation.
 */
const MEAL_TYPES: { value: MealType; label: string }[] = [
  { value: 'breakfast', label: 'Breakfast' },
  { value: 'lunch', label: 'Lunch' },
  { value: 'dinner', label: 'Dinner' },
  { value: 'snack', label: 'Snack' },
  { value: 'other', label: 'Other' },
];

/** A line in the basket. Foods keep their row so the total can be shown live. */
interface BasketLine {
  key: string;
  food: Food | null;
  name: string;
  quantity: number;
  unit: string | null;
  calories: number;
  protein_g: number;
  carbs_g: number;
  fat_g: number;
}

export function AddMealModal({
  onClose,
  onSubmit,
  saving = false,
  defaultMealType = 'breakfast',
  error,
}: {
  onClose: () => void;
  onSubmit: (mealType: MealType, items: MealItemInput[], notes: string) => void;
  saving?: boolean;
  defaultMealType?: MealType;
  error?: string | null;
}) {
  const [mealType, setMealType] = useState<MealType>(defaultMealType);
  const [term, setTerm] = useState('');
  const [lines, setLines] = useState<BasketLine[]>([]);
  const [notes, setNotes] = useState('');

  const search = useFoodSearch(term);
  const results = search.data?.items ?? [];

  const totals = useMemo(
    () =>
      lines.reduce(
        (sum, line) => ({
          calories: sum.calories + line.calories * line.quantity,
          protein_g: sum.protein_g + line.protein_g * line.quantity,
          carbs_g: sum.carbs_g + line.carbs_g * line.quantity,
          fat_g: sum.fat_g + line.fat_g * line.quantity,
        }),
        { calories: 0, protein_g: 0, carbs_g: 0, fat_g: 0 },
      ),
    [lines],
  );

  const addFood = (food: Food) => {
    setLines((current) => [
      ...current,
      {
        key: `food-${food.id}-${current.length}`,
        food,
        name: food.name,
        quantity: 1,
        unit: food.serving_size,
        calories: food.calories,
        protein_g: food.protein_g,
        carbs_g: food.carbs_g,
        fat_g: food.fat_g,
      },
    ]);
    setTerm('');
  };

  const addFreeText = () => {
    const name = term.trim();
    if (name === '') {
      return;
    }

    setLines((current) => [
      ...current,
      {
        key: `custom-${current.length}-${name}`,
        food: null,
        name,
        quantity: 1,
        unit: null,
        calories: 0,
        protein_g: 0,
        carbs_g: 0,
        fat_g: 0,
      },
    ]);
    setTerm('');
  };

  const setLine = (key: string, patch: Partial<BasketLine>) => {
    setLines((current) => current.map((line) => (line.key === key ? { ...line, ...patch } : line)));
  };

  const removeLine = (key: string) => {
    setLines((current) => current.filter((line) => line.key !== key));
  };

  const submit = () => {
    const items: MealItemInput[] = lines.map((line) =>
      line.food
        ? // A known food: identity and amount only. The server owns the macros.
          { food_id: line.food.id, quantity: line.quantity }
        : {
            custom_name: line.name,
            quantity: line.quantity,
            calories: line.calories,
            protein_g: line.protein_g,
            carbs_g: line.carbs_g,
            fat_g: line.fat_g,
          },
    );

    onSubmit(mealType, items, notes);
  };

  const reset = () => {
    setLines([]);
    setTerm('');
    setNotes('');
    onClose();
  };

  return (
    <Modal
      title="Add a meal"
      variant="form"
      confirmLabel={saving ? 'Saving…' : 'Add meal'}
      confirmDisabled={saving || lines.length === 0}
      onConfirm={submit}
      onCancel={reset}
    >
      <div className="fc-field">
        <label className="fc-label" htmlFor="fc-meal-type">
          Meal
        </label>
        <div className="fc-layout-toggle" id="fc-meal-type">
          {MEAL_TYPES.map((option) => (
            <button
              key={option.value}
              type="button"
              aria-pressed={mealType === option.value}
              onClick={() => setMealType(option.value)}
            >
              {option.label}
            </button>
          ))}
        </div>
      </div>

      <div className="fc-field">
        <label className="fc-label" htmlFor="fc-food-search">
          Search foods
        </label>
        <input
          id="fc-food-search"
          type="search"

          placeholder="Start typing — chicken, oats…"
          value={term}
          onChange={(event) => setTerm(event.target.value)}
          autoComplete="off"
        />
      </div>

      {term.trim().length >= 2 && (
        <div className="fc-food-results">
          {search.isLoading && <p className="fc-text-sm fc-text-muted">Searching…</p>}

          {!search.isLoading && results.length === 0 && (
            <div className="fc-flex fc-flex-between fc-flex-c fc-gap-8">
              <span className="fc-text-sm fc-text-muted">No match for “{term.trim()}”.</span>
              <button
                type="button"
                className="fc-btn fc-btn--secondary fc-btn--sm"
                onClick={addFreeText}
              >
                Add as free text
              </button>
            </div>
          )}

          {results.map((food) => (
            <button
              key={food.id}
              type="button"
              className="fc-food-row"
              onClick={() => addFood(food)}
            >
              <span>
                <strong>{food.name}</strong>
                {food.brand ? <span className="fc-text-muted"> · {food.brand}</span> : null}
                <span className="fc-text-xs fc-text-muted"> — {food.serving_size}</span>
              </span>
              <span className="fc-text-sm">{food.calories} kcal</span>
            </button>
          ))}
        </div>
      )}

      <h4 className="fc-text-sm fc-mt-16 fc-mb-8">In this meal</h4>

      {lines.length === 0 && (
        <p className="fc-text-sm fc-text-muted">
          Nothing yet — search above, or type a name and add it as free text.
        </p>
      )}

      {lines.map((line) => (
        <div key={line.key} className="fc-basket-row">
          <div style={{ flex: 1, minWidth: 0 }}>
            <div className="fc-truncate">{line.name}</div>
            <div className="fc-text-xs fc-text-muted">
              {Math.round(line.calories * line.quantity)} kcal
              {line.unit ? ` · ${line.unit}` : ''}
            </div>
          </div>

          <input
            type="number"
            className="fc-input-tiny"
            min={0.25}
            step={0.25}
            value={line.quantity}
            aria-label={`Quantity of ${line.name}`}
            onChange={(event) =>
              setLine(line.key, { quantity: Math.max(0.25, Number(event.target.value) || 1) })
            }
          />

          {!line.food && (
            <input
              type="number"
              className="fc-input-tiny"
              min={0}
              value={line.calories}
              aria-label={`Calories in ${line.name}`}
              onChange={(event) =>
                setLine(line.key, { calories: Math.max(0, Number(event.target.value) || 0) })
              }
            />
          )}

          <button
            type="button"
            className="fc-btn fc-btn--icon"
            onClick={() => removeLine(line.key)}
            aria-label={`Remove ${line.name}`}
          >
            <Icon name="x" size={14} />
          </button>
        </div>
      ))}

      {lines.length > 0 && (
        <p className="fc-text-sm fc-mt-12">
          <strong>{Math.round(totals.calories)} kcal</strong>
          <span className="fc-text-muted">
            {' '}
            · {Math.round(totals.protein_g)}p / {Math.round(totals.carbs_g)}c /{' '}
            {Math.round(totals.fat_g)}f
          </span>
        </p>
      )}

      <div className="fc-field fc-mt-16">
        <label className="fc-label" htmlFor="fc-meal-notes">
          Notes <span className="fc-text-muted">(optional)</span>
        </label>
        <input
          id="fc-meal-notes"
          type="text"

          value={notes}
          onChange={(event) => setNotes(event.target.value)}
        />
      </div>

      {error && <div className="fc-alert fc-alert--error fc-mt-12">{error}</div>}
    </Modal>
  );
}
