import { createContext, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { useDashboardSource } from '@/api/dashboard';
import { useSession } from '@/auth/session';
import { legalEntities, organizations, type LegalEntityOption, type OrganizationOption } from '@/mocks/fixtures';

/**
 * Selected organization and legal entity (UI context only). The server still derives the tenant from the
 * authenticated user; switching organization will re-authenticate once multi-organization accounts exist.
 */
interface WorkspaceValue {
  organizations: OrganizationOption[];
  organization: OrganizationOption;
  setOrganization: (id: string) => void;
  entities: LegalEntityOption[];
  /** `null` means all legal entities. */
  entity: LegalEntityOption | null;
  setEntity: (id: string | null) => void;
}

const WorkspaceContext = createContext<WorkspaceValue | null>(null);

/**
 * `live` uses the signed-in user's organization and the tenant's legal entities from the API;
 * otherwise the prototype fixtures are shown.
 */
export function WorkspaceProvider({ children, live = false }: { children: ReactNode; live?: boolean }) {
  return live ? <LiveWorkspace>{children}</LiveWorkspace> : <PrototypeWorkspace>{children}</PrototypeWorkspace>;
}

function LiveWorkspace({ children }: { children: ReactNode }) {
  const { me } = useSession();
  const source = useDashboardSource();
  const [entityId, setEntityId] = useState<string | null>(null);
  const [loaded, setLoaded] = useState<{ orgId: string; entities: LegalEntityOption[] } | null>(null);
  const org = me?.organization ?? null;

  useEffect(() => {
    if (!org) return;
    const controller = new AbortController();
    source.legalEntities(controller.signal).then(
      (list) => setLoaded({ orgId: org.id, entities: list.map((e) => ({ id: e.id, name: e.name, countryCode: e.country_code, currency: e.currency_code ?? '' })) }),
      () => { if (!controller.signal.aborted) setLoaded({ orgId: org.id, entities: [] }); },
    );
    return () => controller.abort();
  }, [source, org]);

  const value = useMemo<WorkspaceValue>(() => {
    const organization: OrganizationOption = org
      ? { id: org.id, name: org.name, countryCode: org.country_code }
      : { id: '', name: '', countryCode: '' };
    const entities = loaded?.orgId === organization.id ? loaded.entities : [];
    return {
      organizations: org ? [organization] : [], organization, entities,
      entity: entities.find((e) => e.id === entityId) ?? null,
      setOrganization: () => {},
      setEntity: setEntityId,
    };
  }, [org, loaded, entityId]);
  return <WorkspaceContext.Provider value={value}>{children}</WorkspaceContext.Provider>;
}

function PrototypeWorkspace({ children }: { children: ReactNode }) {
  const { me } = useSession();
  const platformStaff = me != null && me.organization === null;
  const [orgId, setOrgId] = useState(organizations[0]!.id);
  const [entityId, setEntityId] = useState<string | null>(null);
  const value = useMemo<WorkspaceValue>(() => {
    const organization = organizations.find((o) => o.id === orgId) ?? organizations[0]!;
    const entities = platformStaff ? [] : legalEntities[organization.id] ?? [];
    return {
      organizations: platformStaff ? [] : organizations, organization, entities,
      entity: entities.find((e) => e.id === entityId) ?? null,
      setOrganization: (id) => { setOrgId(id); setEntityId(null); },
      setEntity: setEntityId,
    };
  }, [orgId, entityId, platformStaff]);
  return <WorkspaceContext.Provider value={value}>{children}</WorkspaceContext.Provider>;
}

export function useWorkspace(): WorkspaceValue {
  const value = useContext(WorkspaceContext);
  if (!value) throw new Error('useWorkspace must be used inside <WorkspaceProvider>');
  return value;
}

export function useRegionName() {
  return (locale: string, code: string) => {
    try {
      return new Intl.DisplayNames([locale], { type: 'region' }).of(code) ?? code;
    } catch {
      return code;
    }
  };
}
