import { useMemo } from 'react';
import { createBrowserRouter, RouterProvider } from 'react-router';
import { ToastProvider } from '@/components/ui';
import { SessionProvider } from '@/auth/session';
import { I18nProvider } from '@/i18n/i18n';
import { DashboardSourceProvider, disconnectedDashboardSource, httpDashboardSource } from '@/api/dashboard';
import { disconnectedEmployeeSource, EmployeeSourceProvider, httpEmployeeSource } from '@/api/employees';
import { disconnectedTimeSource, httpTimeSource, TimeSourceProvider } from '@/api/time';
import { disconnectedPayrollSource, httpPayrollSource, PayrollSourceProvider } from '@/api/payroll';
import { CareersSourceProvider, disconnectedCareersSource, disconnectedRecruitmentSource, httpCareersSource, httpRecruitmentSource, RecruitmentSourceProvider } from '@/api/recruitment';
import { disconnectedHelpdeskSource, HelpdeskSourceProvider, httpHelpdeskSource } from '@/api/helpdesk';
import { DocumentsSourceProvider, disconnectedDocumentsSource, httpDocumentsSource } from '@/api/documents';
import { BillingSourceProvider, disconnectedBillingSource, httpBillingSource } from '@/api/billing';
import { createLiveGateway } from '@/auth/live-gateway';
import { createMockGateway } from '@/mocks/mock-gateway';
import { WorkspaceProvider } from '@/shell/workspace';
import { createRoutes } from './app/routes';

/**
 * `VITE_API_MODE=live` signs in against the Laravel API and loads real dashboard figures.
 * Anything else is the visual prototype: mock sign-in and no figures (never invented ones).
 */
const live = import.meta.env.VITE_API_MODE === 'live';
const gateway = live ? createLiveGateway() : createMockGateway();
const dashboardSource = live ? httpDashboardSource : disconnectedDashboardSource;
const employeeSource = live ? httpEmployeeSource : disconnectedEmployeeSource;
const timeSource = live ? httpTimeSource : disconnectedTimeSource;
const payrollSource = live ? httpPayrollSource : disconnectedPayrollSource;
const recruitmentSource = live ? httpRecruitmentSource : disconnectedRecruitmentSource;
const careersSource = live ? httpCareersSource : disconnectedCareersSource;
const helpdeskSource = live ? httpHelpdeskSource : disconnectedHelpdeskSource;
const documentsSource = live ? httpDocumentsSource : disconnectedDocumentsSource;
const billingSource = live ? httpBillingSource : disconnectedBillingSource;

export function App() {
  const router = useMemo(() => createBrowserRouter(createRoutes({ prototypeHint: !live })), []);
  const locale = navigator.language.toLowerCase().startsWith('es') ? 'es-MX' : 'en-US';
  return (
    <I18nProvider locale={locale}>
      <ToastProvider>
        <SessionProvider gateway={gateway}>
          <DashboardSourceProvider value={dashboardSource}>
            <EmployeeSourceProvider value={employeeSource}>
              <TimeSourceProvider value={timeSource}>
                <PayrollSourceProvider value={payrollSource}>
                  <RecruitmentSourceProvider value={recruitmentSource}>
                    <CareersSourceProvider value={careersSource}>
                      <HelpdeskSourceProvider value={helpdeskSource}>
                        <DocumentsSourceProvider value={documentsSource}>
                          <BillingSourceProvider value={billingSource}>
                            <WorkspaceProvider live={live}>
                              <RouterProvider router={router} />
                            </WorkspaceProvider>
                          </BillingSourceProvider>
                        </DocumentsSourceProvider>
                      </HelpdeskSourceProvider>
                    </CareersSourceProvider>
                  </RecruitmentSourceProvider>
                </PayrollSourceProvider>
              </TimeSourceProvider>
            </EmployeeSourceProvider>
          </DashboardSourceProvider>
        </SessionProvider>
      </ToastProvider>
    </I18nProvider>
  );
}
