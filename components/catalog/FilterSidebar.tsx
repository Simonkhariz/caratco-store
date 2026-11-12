import React from 'react';
import { FilterState } from '@/types';
import { CatalogFacets } from '@/lib/api-client';
import { FilterContent } from './FilterContent';

interface FilterSidebarProps {
  filters: FilterState;
  onFilterChange: (newFilters: Partial<FilterState>) => void;
  onReset: () => void;
  facets: CatalogFacets | null;
}

export const FilterSidebar: React.FC<FilterSidebarProps> = ({ filters, onFilterChange, onReset, facets }) => {
  return (
    <aside className="hidden lg:block w-72 shrink-0" aria-label="Product filters">
      <div className="border border-neutral-200 rounded-3xl p-6 bg-white sticky top-28 shadow-sm">
        <FilterContent filters={filters} onFilterChange={onFilterChange} onReset={onReset} facets={facets} />
      </div>
    </aside>
  );
};
