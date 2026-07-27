import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Icon } from '@shared/components/Icon';
import { Modal } from '@shared/components/Modal';
import { Button, Card, EmptyState, ErrorState, Skeleton } from '@shared/components/ui';
import { useAdminActions, useAdminList, type ListQuery } from '../api/queries';
import type { AdminRow } from '../api/types';

/**
 * One generic table for every admin resource (plans/05-admin-app.md, W2.5).
 *
 * The admin prototype's `CrudTable(config)` was its one good idea — a
 * declarative column spec driving every screen, so a new resource is a config
 * rather than a component. This keeps that API and changes the substrate:
 *
 * | Prototype | Here |
 * | --- | --- |
 * | `db[table].toCollection()` — **loads every row** | `GET /admin/{resource}?page&…` |
 * | search and filter in the browser | in SQL |
 * | client-side pagination, 8/page hardcoded globally | server pagination, per-page selector |
 * | no sorting UI | sortable headers |
 * | delete is unconditional | server refuses with a 409 and a reason |
 *
 * State lives in the **URL**, so a filtered view is a link an administrator can
 * send to a colleague, and the browser's back button walks the filters rather
 * than leaving the screen.
 */
export interface Column<T = AdminRow> {
  key: string;
  label: string;
  /** Registry sort key. Omit for a column the server cannot sort by. */
  sort?: string;
  render?: (row: T) => ReactNode;
  align?: 'left' | 'right';
}

export interface FilterSpec {
  name: string;
  label: string;
  options: Array<{ value: string; label: string }>;
}

export interface ResourceConfig<T = AdminRow> {
  resource: string;
  title: string;
  singular: string;
  columns: Array<Column<T>>;
  filters?: FilterSpec[];
  searchPlaceholder?: string;
  defaultSort?: string;
  defaultOrder?: 'asc' | 'desc';
  /** Row label used in the delete confirmation, so it names the thing. */
  describe?: (row: T) => string;
  /** Rendered inside the add/edit modal. Omitted resources are read-only. */
  form?: (props: FormProps<T>) => ReactNode;
  creatable?: boolean;
}

export interface FormProps<T = AdminRow> {
  row: T | null;
  onSubmit: (payload: Record<string, unknown>) => void;
  saving: boolean;
  error: string | null;
}

const PER_PAGE_OPTIONS = [10, 20, 50];

export function CrudTable<T extends AdminRow>({ config }: { config: ResourceConfig<T> }) {
  const [params, setParams] = useSearchParams();

  // A debounced mirror of the search box: typing updates the input every
  // keystroke but the URL and the request only after a pause, so a five-letter
  // name is one query rather than five.
  const [searchDraft, setSearchDraft] = useState(() => params.get('q') ?? '');

  useEffect(() => {
    const timer = setTimeout(() => {
      const current = params.get('q') ?? '';

      if (searchDraft !== current) {
        update({ q: searchDraft || undefined, page: undefined });
      }
    }, 300);

    return () => clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchDraft]);

  const query: ListQuery = useMemo(() => {
    const built: ListQuery = {
      page: Number(params.get('page') ?? 1),
      per_page: Number(params.get('per_page') ?? 20),
      q: params.get('q') ?? undefined,
      sort: params.get('sort') ?? config.defaultSort,
      order: (params.get('order') as 'asc' | 'desc' | null) ?? config.defaultOrder ?? 'desc',
    };

    for (const filter of config.filters ?? []) {
      built[filter.name] = params.get(filter.name) ?? undefined;
    }

    return built;
  }, [params, config]);

  const { data, isLoading, isFetching, error, refetch } = useAdminList(config.resource, query);
  const { create, update: updateRow, destroy } = useAdminActions(config.resource);

  const [editing, setEditing] = useState<T | null | undefined>(undefined);
  const [confirmDelete, setConfirmDelete] = useState<T | null>(null);
  const [deleteError, setDeleteError] = useState<string | null>(null);

  function update(next: Record<string, string | number | undefined>) {
    const merged = new URLSearchParams(params);

    for (const [key, value] of Object.entries(next)) {
      if (value === undefined || value === '') {
        merged.delete(key);
      } else {
        merged.set(key, String(value));
      }
    }

    setParams(merged, { replace: true });
  }

  function toggleSort(sortKey: string) {
    const active = query.sort === sortKey;

    update({
      sort: sortKey,
      order: active && query.order === 'asc' ? 'desc' : 'asc',
      page: undefined,
    });
  }

  if (error) {
    return <ErrorState message={error.message} onRetry={() => void refetch()} />;
  }

  const rows = (data?.items ?? []) as T[];
  const total = data?.total ?? 0;
  const perPage = data?.per_page ?? 20;
  const page = data?.page ?? 1;
  const pages = Math.max(1, Math.ceil(total / perPage));
  const canCreate = config.creatable !== false && Boolean(config.form);

  return (
    <>
      <header className="fc-admin-head">
        <div>
          <h1>{config.title}</h1>
          <p className="fc-text-sm fc-text-muted">
            {isLoading ? 'Loading…' : `${total} record${total === 1 ? '' : 's'}`}
          </p>
        </div>

        {canCreate && (
          <Button variant="primary" icon="plus" onClick={() => setEditing(null)}>
            Add {config.singular}
          </Button>
        )}
      </header>

      <div className="fc-admin-toolbar">
        <label className="fc-search">
          <Icon name="list" size={14} />
          <input
            type="search"
            value={searchDraft}
            placeholder={config.searchPlaceholder ?? `Search ${config.title.toLowerCase()}…`}
            onChange={(event) => setSearchDraft(event.target.value)}
            aria-label={`Search ${config.title}`}
          />
        </label>

        {(config.filters ?? []).map((filter) => (
          <select
            key={filter.name}
            value={(query[filter.name] as string) ?? ''}
            aria-label={filter.label}
            onChange={(event) => update({ [filter.name]: event.target.value, page: undefined })}
          >
            <option value="">All {filter.label.toLowerCase()}</option>
            {filter.options.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        ))}

        <select
          className="fc-per-page"
          value={perPage}
          aria-label="Rows per page"
          onChange={(event) => update({ per_page: event.target.value, page: undefined })}
        >
          {PER_PAGE_OPTIONS.map((option) => (
            <option key={option} value={option}>
              {option} / page
            </option>
          ))}
        </select>
      </div>

      {isLoading ? (
        <Skeleton height={320} />
      ) : (
        <Card className={isFetching ? 'fc-refreshing' : ''}>
          <div className="fc-table-wrap">
            <table className="fc-table">
              <thead>
                <tr>
                  {config.columns.map((column) => (
                    <th
                      key={column.key}
                      className={column.align === 'right' ? 'fc-text-right' : ''}
                    >
                      {column.sort ? (
                        <button
                          type="button"
                          className="fc-sort-btn"
                          onClick={() => toggleSort(column.sort as string)}
                          aria-label={`Sort by ${column.label}`}
                        >
                          {column.label}
                          {query.sort === column.sort && (
                            <Icon
                              name={query.order === 'asc' ? 'arrowUp' : 'arrowDown'}
                              size={12}
                            />
                          )}
                        </button>
                      ) : (
                        column.label
                      )}
                    </th>
                  ))}
                  <th className="fc-text-right">Actions</th>
                </tr>
              </thead>
              <tbody>
                {rows.length === 0 && (
                  <tr>
                    <td colSpan={config.columns.length + 1}>
                      <EmptyState title="No records found">
                        {query.q || (config.filters ?? []).some((f) => query[f.name])
                          ? 'Nothing matches the current search and filters.'
                          : `No ${config.title.toLowerCase()} yet.`}
                      </EmptyState>
                    </td>
                  </tr>
                )}

                {rows.map((row) => (
                  <tr key={row.id}>
                    {config.columns.map((column) => (
                      <td
                        key={column.key}
                        className={column.align === 'right' ? 'fc-text-right' : ''}
                      >
                        {column.render ? column.render(row) : plain(row[column.key])}
                      </td>
                    ))}
                    <td className="fc-text-right">
                      <div className="fc-row-actions">
                        {config.form && (
                          <button
                            type="button"
                            className="fc-btn fc-btn--icon"
                            aria-label={`Edit ${config.singular}`}
                            onClick={() => setEditing(row)}
                          >
                            <Icon name="check" size={14} />
                          </button>
                        )}
                        <button
                          type="button"
                          className="fc-btn fc-btn--icon fc-btn--danger"
                          aria-label={`Delete ${config.singular}`}
                          onClick={() => {
                            setDeleteError(null);
                            setConfirmDelete(row);
                          }}
                        >
                          <Icon name="x" size={14} />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}

      {pages > 1 && (
        <div className="fc-admin-pager">
          <span className="fc-text-sm fc-text-muted">
            {(page - 1) * perPage + 1}–{Math.min(page * perPage, total)} of {total}
          </span>
          <div className="fc-flex fc-gap-8">
            <Button
              icon="chevronLeft"
              disabled={page <= 1}
              onClick={() => update({ page: page - 1 })}
            >
              Previous
            </Button>
            <Button disabled={page >= pages} onClick={() => update({ page: page + 1 })}>
              Next
            </Button>
          </div>
        </div>
      )}

      {editing !== undefined && config.form && (
        <Modal
          title={editing ? `Edit ${config.singular}` : `Add ${config.singular}`}
          variant="form"
          confirmLabel="Close"
          cancelLabel="Cancel"
          onConfirm={() => setEditing(undefined)}
          onCancel={() => setEditing(undefined)}
        >
          {config.form({
            row: editing,
            saving: create.isPending || updateRow.isPending,
            error: (editing ? updateRow.error : create.error)?.message ?? null,
            onSubmit: (payload) => {
              if (editing) {
                updateRow.mutate(
                  { id: editing.id, payload },
                  { onSuccess: () => setEditing(undefined) },
                );
              } else {
                create.mutate(payload, { onSuccess: () => setEditing(undefined) });
              }
            },
          })}
        </Modal>
      )}

      {confirmDelete && (
        <Modal
          title={`Delete this ${config.singular}?`}
          tone="danger"
          confirmLabel={destroy.isPending ? 'Deleting…' : 'Delete'}
          confirmDisabled={destroy.isPending}
          onCancel={() => setConfirmDelete(null)}
          onConfirm={() =>
            destroy.mutate(confirmDelete.id, {
              onSuccess: () => setConfirmDelete(null),
              // The server refuses deletes that would orphan live data and says
              // why (409). Showing that message is the whole value of the
              // refusal — the prototype deleted unconditionally.
              onError: (err) => setDeleteError(err.message),
            })
          }
        >
          {config.describe?.(confirmDelete) ?? `#${confirmDelete.id}`}. This cannot be undone.
          {deleteError && <p className="fc-text-sm fc-text-danger fc-mt-8">{deleteError}</p>}
        </Modal>
      )}
    </>
  );
}

function plain(value: unknown): ReactNode {
  if (value === null || value === undefined || value === '') {
    return <span className="fc-text-subtle">—</span>;
  }

  if (Array.isArray(value)) {
    return value.join(', ');
  }

  if (typeof value === 'boolean') {
    return value ? 'Yes' : 'No';
  }

  return String(value);
}
