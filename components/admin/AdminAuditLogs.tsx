'use client';

import { useState } from 'react';
import { ChevronDown, ChevronRight, RefreshCw } from 'lucide-react';
import { adminGetAuditLogs, AuditLogRow } from '@/lib/admin-api';
import { formatDateTime, humanize } from '@/lib/admin-format';
import { Button, DataState, FilterSelect, PageHeader, Pagination, Panel, Table, Toolbar, useAdminData, useUrlFilters } from '@/components/admin/ui';

function parseState(value: unknown): unknown {
  if (typeof value !== 'string') return value;
  try {
    return JSON.parse(value);
  } catch {
    return value;
  }
}

function pretty(value: unknown): string {
  const parsed = parseState(value);
  if (parsed === null || parsed === undefined || parsed === '') return '—';
  return typeof parsed === 'string' ? parsed : JSON.stringify(parsed, null, 2);
}

/** Every change an administrator made, with who, when, from where, and before/after values. */
export function AdminAuditLogs() {
  const [filters, setFilters] = useUrlFilters({ action: 'all', entity_type: 'all', page: '1' });
  const page = Math.max(1, parseInt(filters.page, 10) || 1);
  const { data, loading, error, reload } = useAdminData('AdminAuditLogs',
    () => adminGetAuditLogs({ action: filters.action, entity_type: filters.entity_type, page, per_page: 25 }),
    [filters.action, filters.entity_type, page]
  );
  const [expanded, setExpanded] = useState<number | null>(null);

  const actions = data?.filters.actions ?? [];
  const entityTypes = data?.filters.entity_types ?? [];
  // Keep the selected value listed even if the API's list doesn't contain it.
  const withSelected = (list: string[], selected: string) => (selected !== 'all' && !list.includes(selected) ? [selected, ...list] : list);
  const rows = data?.items ?? [];

  return (
    <div className="flex w-full flex-col">
      <PageHeader
        title="Audit log"
        description="Every change made in the admin, newest first."
        actions={
          <Button onClick={reload} loading={loading && !!data}>
            <RefreshCw className="size-3.5" aria-hidden="true" />
            Refresh
          </Button>
        }
      />
      <Toolbar>
        <FilterSelect
          label="Action"
          value={filters.action}
          onChange={(v) => setFilters({ action: v })}
          options={[{ value: 'all', label: 'All actions' }, ...withSelected(actions, filters.action).map((a) => ({ value: a, label: humanize(a) }))]}
        />
        <FilterSelect
          label="Record type"
          value={filters.entity_type}
          onChange={(v) => setFilters({ entity_type: v })}
          options={[{ value: 'all', label: 'All records' }, ...withSelected(entityTypes, filters.entity_type).map((t) => ({ value: t, label: humanize(t) }))]}
        />
        {(filters.action !== 'all' || filters.entity_type !== 'all') && (
          <Button variant="ghost" onClick={() => setFilters({ action: 'all', entity_type: 'all' })}>
            Clear filters
          </Button>
        )}
      </Toolbar>

      <Panel>
        <DataState loading={loading} error={error} onRetry={reload} hasData={!!data} empty={rows.length === 0} emptyTitle="No audit entries match">
          <Table minWidth={820}>
            <thead>
              <tr>
                <th scope="col">
                  <span className="sr-only">Details</span>
                </th>
                <th scope="col">When</th>
                <th scope="col">Admin</th>
                <th scope="col">Action</th>
                <th scope="col">Record</th>
                <th scope="col">IP address</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <AuditRow key={row.id} row={row} open={expanded === row.id} onToggle={() => setExpanded(expanded === row.id ? null : row.id)} />
              ))}
            </tbody>
          </Table>
          <Pagination meta={data?.pagination} onPage={(p) => setFilters({ page: String(p) })} />
        </DataState>
      </Panel>
    </div>
  );
}

function AuditRow({ row, open, onToggle }: { row: AuditLogRow; open: boolean; onToggle: () => void }) {
  const detailId = `audit-detail-${row.id}`;
  return (
    <>
      <tr>
        <td className="w-10">
          <button
            type="button"
            onClick={onToggle}
            aria-expanded={open}
            aria-controls={detailId}
            aria-label={open ? 'Hide changes' : 'Show changes'}
            className="rounded p-1 text-[#6b7280] outline-none hover:bg-black/[0.05] hover:text-[#111827] focus-visible:ring-[3px] focus-visible:ring-[#9ca3af]/50"
          >
            {open ? <ChevronDown className="size-4" aria-hidden="true" /> : <ChevronRight className="size-4" aria-hidden="true" />}
          </button>
        </td>
        <td className="whitespace-nowrap text-xs text-[#374151]">{formatDateTime(row.created_at)}</td>
        <td className="max-w-[220px]">
          {row.admin_id || row.admin_name ? (
            <>
              {/* A deleted admin has no account any more: the kept name says so. */}
              <span className="block truncate" title={row.admin_name || undefined}>{row.admin_name || 'Admin'}</span>
              {row.admin_email && <span className="block truncate text-xs text-[#6b7280]">{row.admin_email}</span>}
            </>
          ) : (
            <span className="text-[#6b7280]">System</span>
          )}
        </td>
        <td className="whitespace-nowrap font-medium text-[#111827]">{humanize(row.action)}</td>
        <td className="whitespace-nowrap">
          {humanize(row.entity_type)}
          {row.entity_id && <span className="ml-1 font-mono text-xs text-[#6b7280]">#{row.entity_id}</span>}
        </td>
        <td className="whitespace-nowrap font-mono text-xs text-[#6b7280]">{row.ip_address || '—'}</td>
      </tr>
      {open && (
        <tr id={detailId}>
          <td colSpan={6} className="bg-[#fafafa]">
            <div className="grid gap-3 py-1 md:grid-cols-2">
              <div className="min-w-0">
                <p className="mb-1 text-xs font-semibold text-[#6b7280]">Before</p>
                <pre className="max-h-72 overflow-auto rounded-lg border border-[#e5e7eb] bg-white p-3 font-mono text-xs leading-relaxed text-[#1f2937]">
                  {pretty(row.before_state)}
                </pre>
              </div>
              <div className="min-w-0">
                <p className="mb-1 text-xs font-semibold text-[#6b7280]">After</p>
                <pre className="max-h-72 overflow-auto rounded-lg border border-[#e5e7eb] bg-white p-3 font-mono text-xs leading-relaxed text-[#1f2937]">
                  {pretty(row.after_state)}
                </pre>
              </div>
              {row.user_agent && (
                <p className="truncate text-xs text-[#6b7280] md:col-span-2" title={row.user_agent}>
                  Browser: {row.user_agent}
                </p>
              )}
            </div>
          </td>
        </tr>
      )}
    </>
  );
}
