import { createContext, useContext } from 'react';
import { ApiNotConnectedError } from './dashboard';
import { apiRequest, ensureCsrfCookie } from './http';
import type { Page } from './employees';

/** Mirrors the Assets API (docs/architecture/assets.md). */
export type AssetStatus = 'available' | 'assigned' | 'in_maintenance' | 'retired';
export type AssetCondition = 'new' | 'good' | 'fair' | 'poor' | 'damaged';
export type MaintenanceKind = 'repair' | 'service' | 'inspection' | 'upgrade';
export type MaintenanceStatus = 'scheduled' | 'in_progress' | 'completed' | 'cancelled';
export interface PersonRef { id: string; name: string; employee_number: string }
export interface AssetCategory { id: string; name: string; description: string | null; active: boolean; assets_count: number; version: number }
export interface Asset {
  id: string; asset_tag: string; name: string; serial_number: string | null; category: { id: string; name: string } | null;
  status: AssetStatus; condition: AssetCondition; purchased_on: string | null; purchase_cost: string | null; currency: string | null;
  warranty_ends_on: string | null; notes: string | null; assigned_to: PersonRef | null; version: number;
}
export interface AssetInput {
  category_id: string; asset_tag: string; name: string; serial_number: string | null; condition: AssetCondition; purchased_on: string | null;
  purchase_cost: string | null; currency: string | null; warranty_ends_on: string | null; notes: string | null;
}
export interface Assignment {
  id: string; asset?: { id: string; asset_tag: string; name: string; serial_number: string | null; category: string | null };
  employee?: PersonRef | null; assigned_by?: string | null; returned_by?: string | null;
  assigned_on: string; due_back_on: string | null; returned_on: string | null; condition_out: AssetCondition; condition_in: AssetCondition | null;
  notes: string | null; return_notes: string | null;
}
export interface MaintenanceRecord {
  id: string; kind: MaintenanceKind; description: string; vendor: string | null; cost: string | null; currency: string | null;
  status: MaintenanceStatus; scheduled_on: string | null; completed_on: string | null; created_at: string | null; version: number;
}
export interface MaintenanceInput { kind: MaintenanceKind; description: string; vendor: string | null; cost: string | null; currency: string | null; status: 'scheduled' | 'in_progress'; scheduled_on: string | null }
export interface AssetHistory { assignments: Assignment[]; maintenance: MaintenanceRecord[] }
export interface AssetQuery { page: number; q?: string; status?: AssetStatus | ''; category?: string }

export interface AssetsSource {
  mine(page: number, signal?: AbortSignal): Promise<Page<Assignment>>;
  assets(q: AssetQuery, signal?: AbortSignal): Promise<Page<Asset>>;
  asset(id: string, signal?: AbortSignal): Promise<Asset>;
  saveAsset(input: AssetInput, id?: string, version?: number): Promise<Asset>;
  setStatus(id: string, status: 'available' | 'retired', version: number): Promise<Asset>;
  assign(id: string, input: { employee_id: string; assigned_on: string | null; due_back_on: string | null; notes: string | null }, version: number): Promise<Asset>;
  returnAsset(id: string, input: { condition: AssetCondition; returned_on: string | null; notes: string | null }, version: number): Promise<Asset>;
  history(id: string, signal?: AbortSignal): Promise<AssetHistory>;
  addMaintenance(id: string, input: MaintenanceInput): Promise<MaintenanceRecord>;
  updateMaintenance(id: string, status: MaintenanceStatus, version: number): Promise<MaintenanceRecord>;
  categories(signal?: AbortSignal): Promise<AssetCategory[]>;
  saveCategory(input: { name: string; description: string | null; active: boolean }, id?: string, version?: number): Promise<AssetCategory>;
}

const v1 = '/api/v1';
const data = async <T,>(p: Promise<{ data: T }>) => (await p).data;
async function write<T>(method: 'POST' | 'PATCH', path: string, body: unknown): Promise<T> {
  await ensureCsrfCookie();
  return data(apiRequest<{ data: T }>(method, path, { body }));
}

export const httpAssetsSource: AssetsSource = {
  mine: (page, signal) => apiRequest('GET', `${v1}/assets/mine`, { query: { page: String(page) }, signal }),
  assets: (q, signal) => apiRequest('GET', `${v1}/assets`, { query: { page: String(q.page), q: q.q || undefined, status: q.status || undefined, category: q.category || undefined }, signal }),
  asset: (id, signal) => data(apiRequest('GET', `${v1}/assets/${id}`, { signal })),
  saveAsset: (input, id, version) => (id ? write('PATCH', `${v1}/assets/${id}`, { ...input, version }) : write('POST', `${v1}/assets`, input)),
  setStatus: (id, status, version) => write('PATCH', `${v1}/assets/${id}`, { status, version }),
  assign: (id, input, version) => write('POST', `${v1}/assets/${id}/assign`, { ...input, version }),
  returnAsset: (id, input, version) => write('POST', `${v1}/assets/${id}/return`, { ...input, version }),
  history: (id, signal) => data(apiRequest('GET', `${v1}/assets/${id}/history`, { signal })),
  addMaintenance: (id, input) => write('POST', `${v1}/assets/${id}/maintenance`, input),
  updateMaintenance: (id, status, version) => write('PATCH', `${v1}/assets/maintenance/${id}`, { status, version }),
  categories: (signal) => data(apiRequest('GET', `${v1}/assets/categories`, { signal })),
  saveCategory: (input, id, version) => (id ? write('PATCH', `${v1}/assets/categories/${id}`, { ...input, version }) : write('POST', `${v1}/assets/categories`, input)),
};

const notConnected = () => Promise.reject(new ApiNotConnectedError());
export const disconnectedAssetsSource: AssetsSource = Object.fromEntries(
  Object.keys(httpAssetsSource).map((key) => [key, notConnected]),
) as unknown as AssetsSource;
const AssetsSourceContext = createContext<AssetsSource>(disconnectedAssetsSource);
export const AssetsSourceProvider = AssetsSourceContext.Provider;
export const useAssetsSource = () => useContext(AssetsSourceContext);
