import { createContext, useContext, useMemo, useState, type ReactNode } from 'react';
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

export function WorkspaceProvider({ children }: { children: ReactNode }) {
  const [orgId, setOrgId] = useState(organizations[0]!.id);
  const [entityId, setEntityId] = useState<string | null>(null);
  const value = useMemo<WorkspaceValue>(() => {
    const organization = organizations.find((o) => o.id === orgId) ?? organizations[0]!;
    const entities = legalEntities[organization.id] ?? [];
    return {
      organizations, organization, entities,
      entity: entities.find((e) => e.id === entityId) ?? null,
      setOrganization: (id) => { setOrgId(id); setEntityId(null); },
      setEntity: setEntityId,
    };
  }, [orgId, entityId]);
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
