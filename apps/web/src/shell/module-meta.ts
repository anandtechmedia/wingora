import type { ComponentType } from 'react';
import type { MessageKey } from '@wingora/localization';
import type { ModuleGroup, ModuleId } from '@wingora/permissions';
import {
  BoxIcon, BriefcaseIcon, CalendarCheckIcon, ChartIcon, ClockIcon, CreditCardIcon, FolderIcon, HomeIcon, KanbanIcon,
  LifebuoyIcon, SettingsIcon, ShieldIcon, TargetIcon, UserMinusIcon, UserPlusIcon, UsersIcon, WalletIcon,
} from '@/components/ui';

export const moduleIcons: Record<ModuleId, ComponentType<{ size?: number; className?: string }>> = {
  dashboard: HomeIcon, employees: UsersIcon, attendance: ClockIcon, leave: CalendarCheckIcon, payroll: WalletIcon,
  recruitment: BriefcaseIcon, onboarding: UserPlusIcon, offboarding: UserMinusIcon, performance: TargetIcon,
  assets: BoxIcon, helpdesk: LifebuoyIcon, projects: KanbanIcon, reports: ChartIcon, documents: FolderIcon,
  settings: SettingsIcon, billing: CreditCardIcon, platform: ShieldIcon,
};

export const moduleLabel = (id: ModuleId): MessageKey => `nav.module.${id}`;
export const groupLabel = (group: ModuleGroup): MessageKey => `nav.group.${group}`;
