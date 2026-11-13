import { createContext, useContext } from 'react';
import { ApiNotConnectedError } from './dashboard';
import { apiRequest, ensureCsrfCookie } from './http';
import type { Page } from './employees';

/** Mirrors the Projects and timesheets API (docs/architecture/projects-timesheets.md). */
export type ProjectStatus = 'planned' | 'active' | 'on_hold' | 'completed' | 'cancelled';
export type TaskStatus = 'todo' | 'in_progress' | 'done';
export type TimesheetStatus = 'draft' | 'submitted' | 'approved' | 'rejected';
export interface PersonRef { id: string; name: string; employee_number: string }

export interface Project {
  id: string; code: string; name: string; client: string | null; description: string | null; status: ProjectStatus;
  starts_on: string | null; ends_on: string | null; budget_hours: string | null; manager: PersonRef | null;
  members_count: number; tasks_count: number; can_manage: boolean; version: number; members?: PersonRef[];
}
export interface ProjectInput {
  code: string; name: string; client: string | null; description: string | null; status: ProjectStatus;
  starts_on: string | null; ends_on: string | null; budget_hours: string | null; manager_id: string | null;
}
export interface Task {
  id: string; title: string; description: string | null; status: TaskStatus; assignee: PersonRef | null;
  due_on: string | null; estimate_hours: string | null; version: number; project?: { id: string; code: string; name: string };
}
export interface TaskInput { title: string; description: string | null; assignee_id: string | null; due_on: string | null; estimate_hours: string | null }
export interface TimesheetEntry { project: { id: string; code: string; name: string }; task: { id: string; title: string } | null; work_date: string; minutes: number; note: string | null }
export interface EntryInput { project_id: string; task_id: string | null; work_date: string; minutes: number; note: string | null }
export interface Timesheet {
  id: string | null; week_start: string; status: TimesheetStatus; total_minutes: number; version: number;
  submitted_at?: string | null; decided_at?: string | null; decided_by?: string | null; decision_note?: string | null;
  employee?: PersonRef | null; entries?: TimesheetEntry[];
}
export interface LoggableProject { id: string; code: string; name: string; tasks: { id: string; title: string }[] }
export interface MyWeek { timesheet: Timesheet; projects: LoggableProject[] }
export interface Hours { approved_minutes: number; pending_minutes: number }
export interface ProjectReport {
  totals: Hours & { budget_minutes: number | null; budget_used_percent: number | null };
  by_employee: (Hours & { employee: PersonRef | null })[];
  by_task: (Hours & { task: { id: string; title: string } | null })[];
  by_week: (Hours & { week_start: string })[];
}
export interface ProjectQuery { page: number; q?: string; status?: ProjectStatus | '' }

export interface ProjectsSource {
  projects(q: ProjectQuery, signal?: AbortSignal): Promise<Page<Project>>;
  project(id: string, signal?: AbortSignal): Promise<Project>;
  saveProject(input: ProjectInput, id?: string, version?: number): Promise<Project>;
  addMember(id: string, employeeId: string): Promise<Project>;
  removeMember(id: string, employeeId: string): Promise<Project>;
  report(id: string, range: { from?: string; to?: string }, signal?: AbortSignal): Promise<ProjectReport>;
  tasks(projectId: string, signal?: AbortSignal): Promise<Page<Task>>;
  myTasks(signal?: AbortSignal): Promise<Page<Task>>;
  saveTask(input: Partial<TaskInput> & { status?: TaskStatus }, projectId: string, id?: string, version?: number): Promise<Task>;
  myWeek(week: string, signal?: AbortSignal): Promise<MyWeek>;
  saveWeek(week: string, entries: EntryInput[], version: number): Promise<Timesheet>;
  submit(id: string, version: number): Promise<Timesheet>;
  approvals(signal?: AbortSignal): Promise<Page<Timesheet>>;
  timesheet(id: string, signal?: AbortSignal): Promise<Timesheet>;
  decide(id: string, decision: 'approve' | 'reject', version: number, note: string | null): Promise<Timesheet>;
}

const v1 = '/api/v1';
const data = async <T,>(p: Promise<{ data: T }>) => (await p).data;
async function write<T>(method: 'POST' | 'PATCH' | 'PUT' | 'DELETE', path: string, body?: unknown): Promise<T> {
  await ensureCsrfCookie();
  return data(apiRequest<{ data: T }>(method, path, body === undefined ? {} : { body }));
}

export const httpProjectsSource: ProjectsSource = {
  projects: (q, signal) => apiRequest('GET', `${v1}/projects`, { query: { page: String(q.page), q: q.q || undefined, status: q.status || undefined }, signal }),
  project: (id, signal) => data(apiRequest('GET', `${v1}/projects/${id}`, { signal })),
  saveProject: (input, id, version) => (id ? write('PATCH', `${v1}/projects/${id}`, { ...input, version }) : write('POST', `${v1}/projects`, input)),
  addMember: (id, employeeId) => write('POST', `${v1}/projects/${id}/members`, { employee_id: employeeId }),
  removeMember: (id, employeeId) => write('DELETE', `${v1}/projects/${id}/members/${employeeId}`),
  report: (id, range, signal) => data(apiRequest('GET', `${v1}/projects/${id}/report`, { query: { from: range.from || undefined, to: range.to || undefined }, signal })),
  tasks: (projectId, signal) => apiRequest('GET', `${v1}/projects/${projectId}/tasks`, { query: { per_page: '100' }, signal }),
  myTasks: (signal) => apiRequest('GET', `${v1}/projects/tasks/mine`, { query: { per_page: '100' }, signal }),
  saveTask: (input, projectId, id, version) => (id ? write('PATCH', `${v1}/projects/tasks/${id}`, { ...input, version }) : write('POST', `${v1}/projects/${projectId}/tasks`, input)),
  myWeek: (week, signal) => data(apiRequest('GET', `${v1}/timesheets/mine`, { query: { week }, signal })),
  saveWeek: (week, entries, version) => write('PUT', `${v1}/timesheets/mine/${week}`, { entries, version }),
  submit: (id, version) => write('POST', `${v1}/timesheets/${id}/submit`, { version }),
  approvals: (signal) => apiRequest('GET', `${v1}/timesheets/approvals`, { query: { per_page: '100' }, signal }),
  timesheet: (id, signal) => data(apiRequest('GET', `${v1}/timesheets/${id}`, { signal })),
  decide: (id, decision, version, note) => write('POST', `${v1}/timesheets/${id}/${decision}`, { version, note }),
};

const notConnected = () => Promise.reject(new ApiNotConnectedError());
export const disconnectedProjectsSource: ProjectsSource = Object.fromEntries(
  Object.keys(httpProjectsSource).map((key) => [key, notConnected]),
) as unknown as ProjectsSource;

const ProjectsSourceContext = createContext<ProjectsSource>(disconnectedProjectsSource);
export const ProjectsSourceProvider = ProjectsSourceContext.Provider;
export const useProjectsSource = () => useContext(ProjectsSourceContext);
