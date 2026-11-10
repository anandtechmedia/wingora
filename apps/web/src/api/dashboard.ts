import { createContext, useContext } from 'react';
import { ApiError, apiRequest } from './http';

/** Mirrors `GET /api/v1/dashboard` (docs/architecture/dashboard.md). Widgets the user may not see are absent. */
export interface MetricWidget { state: 'ok'; value: number }
export interface UpcomingItem { employee_id: string; name: string; date: string; in_days: number; years?: number }
export interface ListWidget { state: 'ok'; items: UpcomingItem[] }
export interface TrendPoint { month: string; headcount: number; hires: number; exits: number }
export interface TrendWidget { state: 'ok'; series: TrendPoint[] }
export interface UnavailableWidget { state: 'unavailable'; module: string }

export interface DashboardWidgets {
  total_employees?: MetricWidget;
  active_employees?: MetricWidget;
  new_joiners?: MetricWidget;
  upcoming_birthdays?: ListWidget;
  upcoming_anniversaries?: ListWidget;
  workforce_trends?: TrendWidget;
  pending_leave_approvals?: UnavailableWidget;
  todays_attendance?: UnavailableWidget;
  absentees?: UnavailableWidget;
  pending_onboarding_tasks?: UnavailableWidget;
  contract_expirations?: UnavailableWidget;
  recruitment_pipeline?: UnavailableWidget;
}
export type WidgetKey = keyof DashboardWidgets;

export interface DashboardData {
  scope: 'organization' | 'departments' | 'none';
  filters: { country: string | null; legal_entity: string | null; from: string; to: string };
  generated_at: string;
  widgets: DashboardWidgets;
}

export interface DashboardQuery { country: string; legalEntity: string; from: string; to: string }
export interface LegalEntitySummary { id: string; name: string; country_code: string }

export interface DashboardSource {
  load(query: DashboardQuery, signal: AbortSignal): Promise<DashboardData>;
  legalEntities(signal: AbortSignal): Promise<LegalEntitySummary[]>;
}

/** Raised when the app runs without a backend; the dashboard then shows no figures at all. */
export class ApiNotConnectedError extends Error {
  constructor() {
    super('api_not_connected');
    this.name = 'ApiNotConnectedError';
  }
}

export const httpDashboardSource: DashboardSource = {
  async load(q, signal) {
    const query = { country: q.country, legal_entity: q.legalEntity, from: q.from, to: q.to };
    return (await apiRequest<{ data: DashboardData }>('GET', '/api/v1/dashboard', { query, signal })).data;
  },
  async legalEntities(signal) {
    try {
      return (await apiRequest<{ data: LegalEntitySummary[] }>('GET', '/api/v1/legal-entities', { query: { per_page: '100' }, signal })).data;
    } catch (error) {
      if (error instanceof ApiError && error.status === 403) return [];
      throw error;
    }
  },
};

export const disconnectedDashboardSource: DashboardSource = {
  load: () => Promise.reject(new ApiNotConnectedError()),
  legalEntities: () => Promise.resolve([]),
};

const DashboardSourceContext = createContext<DashboardSource>(disconnectedDashboardSource);
export const DashboardSourceProvider = DashboardSourceContext.Provider;
export const useDashboardSource = () => useContext(DashboardSourceContext);
