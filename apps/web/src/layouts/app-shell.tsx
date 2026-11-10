import { useState, type ComponentType, type ReactNode } from 'react';
import type { MessageKey } from '@wingora/localization';
import { Avatar, BellIcon, BoxIcon, BriefcaseIcon, ChartIcon, ClockIcon, Drawer, Dropdown, FileIcon, IconButton, MenuIcon, PlaneIcon, SearchIcon, SettingsIcon, SidebarIcon, SparklesIcon, SunIcon, TargetIcon, UsersIcon, WalletIcon, RefreshIcon } from '@/components/ui';
import { cn } from '@/lib/cn';
import { useI18n } from '@/i18n/i18n';

interface NavItem {
  key: MessageKey;
  href: string;
  icon: ComponentType<{ size?: number }>;
}

/** The 13 product areas. Links are placeholders until the modules exist. */
export const navigation: Array<{ group: MessageKey; items: NavItem[] }> = [
  { group: 'nav.group.workspace', items: [
    { key: 'nav.coreHr', href: '#core-hr', icon: UsersIcon },
    { key: 'nav.workforce', href: '#workforce', icon: ClockIcon },
    { key: 'nav.timeOff', href: '#time-off', icon: SunIcon },
    { key: 'nav.compensation', href: '#compensation', icon: WalletIcon },
    { key: 'nav.talent', href: '#talent', icon: BriefcaseIcon },
    { key: 'nav.lifecycle', href: '#lifecycle', icon: RefreshIcon },
    { key: 'nav.performance', href: '#performance', icon: TargetIcon },
    { key: 'nav.operations', href: '#operations', icon: BoxIcon },
    { key: 'nav.travel', href: '#travel', icon: PlaneIcon },
  ] },
  { group: 'nav.group.insights', items: [
    { key: 'nav.intelligence', href: '#intelligence', icon: ChartIcon },
    { key: 'nav.reports', href: '#reports', icon: FileIcon },
    { key: 'nav.ai', href: '#ai', icon: SparklesIcon },
  ] },
  { group: 'nav.group.administration', items: [
    { key: 'nav.platform', href: '#platform', icon: SettingsIcon },
  ] },
];

function Brand({ compact }: { compact?: boolean }) {
  const { t } = useI18n();
  return (
    <div className="flex items-center gap-2.5">
      <span aria-hidden="true" className="inline-flex size-8 shrink-0 items-center justify-center rounded-md bg-accent text-sm font-bold text-on-accent">W</span>
      {!compact && (
        <span className="flex flex-col leading-none">
          <span className="text-md font-semibold tracking-tight text-nav-active-fg">{t('app.name')}</span>
          <span className="mt-1 text-2xs text-nav-fg-muted">{t('app.positioning')}</span>
        </span>
      )}
    </div>
  );
}

function NavList({ active, collapsed, onNavigate }: { active: string; collapsed: boolean; onNavigate?: () => void }) {
  const { t } = useI18n();
  return (
    <div className="flex flex-col gap-(--nav-group-gap)">
      {navigation.map(({ group, items }) => (
        <div key={group}>
          <p className={cn('mb-1.5 px-3 text-2xs font-semibold tracking-wide text-nav-fg-muted uppercase', collapsed && 'sr-only')}>{t(group)}</p>
          <ul className="flex flex-col gap-0.5">
            {items.map(({ key, href, icon: Icon }) => {
              const current = href === active;
              return (
                <li key={key}>
                  <a href={href} onClick={onNavigate} aria-current={current ? 'page' : undefined} title={collapsed ? t(key) : undefined}
                    className={cn('relative flex h-(--nav-item-height) items-center gap-3 rounded-(--nav-item-radius) px-3 text-sm font-medium transition-colors duration-(--duration-fast)',
                      'focus-visible:outline-nav-indicator', collapsed && 'justify-center px-0',
                      current ? 'bg-nav-active-bg text-nav-active-fg' : 'text-nav-fg hover:bg-nav-hover-bg hover:text-nav-active-fg')}>
                    {current && <span aria-hidden="true" className="absolute inset-y-1.5 start-0 w-0.5 rounded-full bg-nav-indicator" />}
                    <Icon size={18} />
                    <span className={cn(collapsed && 'sr-only')}>{t(key)}</span>
                  </a>
                </li>
              );
            })}
          </ul>
        </div>
      ))}
    </div>
  );
}

export interface AppShellProps {
  children: ReactNode;
  /** Href of the active navigation item. */
  active?: string;
  userName?: string;
  headerActions?: ReactNode;
}

/**
 * Responsive application frame.
 * - Desktop (≥ lg): full sidebar, collapsible to an icon rail.
 * - Tablet (md–lg): icon rail by default.
 * - Mobile (< md): top bar; navigation opens in a start-side drawer.
 */
export function AppShell({ children, active = '#core-hr', userName = 'Alex Morgan', headerActions }: AppShellProps) {
  const { t } = useI18n();
  const [collapsed, setCollapsed] = useState(false);
  const [mobileOpen, setMobileOpen] = useState(false);

  return (
    <div className="min-h-dvh bg-canvas">
      <a href="#main-content" className="sr-only z-(--z-toast) rounded-md bg-surface px-4 py-2 text-sm font-medium text-fg shadow-lg focus:not-sr-only focus:fixed focus:start-4 focus:top-4">
        {t('shell.skipToContent')}
      </a>

      <aside className={cn('fixed inset-y-0 start-0 z-(--z-sticky) hidden flex-col bg-nav-bg md:flex',
        'w-(--container-sidebar-collapsed)', !collapsed && 'lg:w-(--container-sidebar)')}>
        <div className={cn('flex h-(--container-header) items-center px-5', 'justify-center lg:justify-start', collapsed && 'lg:justify-center')}>
          <span className={collapsed ? 'hidden' : 'hidden lg:block'}><Brand /></span>
          <span className={collapsed ? undefined : 'lg:hidden'}><Brand compact /></span>
        </div>
        <nav aria-label={t('shell.mainNavigation')} className="flex-1 overflow-y-auto px-3 py-4">
          <div className={collapsed ? 'hidden' : 'hidden lg:block'}><NavList active={active} collapsed={false} /></div>
          <div className={collapsed ? undefined : 'lg:hidden'}><NavList active={active} collapsed /></div>
        </nav>
        <div className="hidden border-t border-nav-hover-bg p-3 lg:block">
          <button type="button" onClick={() => setCollapsed((c) => !c)} aria-pressed={collapsed}
            className={cn('flex h-(--nav-item-height) w-full items-center gap-3 rounded-(--nav-item-radius) px-3 text-sm text-nav-fg hover:bg-nav-hover-bg focus-visible:outline-nav-indicator', collapsed && 'justify-center px-0')}>
            <SidebarIcon size={18} />
            <span className={cn(collapsed && 'sr-only')}>{collapsed ? t('shell.expandSidebar') : t('shell.collapseSidebar')}</span>
          </button>
        </div>
      </aside>

      <div className={cn('flex min-h-dvh flex-col md:ps-(--container-sidebar-collapsed)', !collapsed && 'lg:ps-(--container-sidebar)')}>
        <header className="sticky top-0 z-(--z-sticky) flex h-(--container-header-mobile) items-center gap-3 border-b border-line bg-surface/95 px-4 backdrop-blur md:h-(--container-header) md:px-6">
          <IconButton label={t('shell.openNavigation')} icon={<MenuIcon size={20} />} className="md:hidden" onClick={() => setMobileOpen(true)} aria-expanded={mobileOpen} />
          <div className="relative hidden max-w-(--container-form) flex-1 sm:block">
            <label htmlFor="global-search" className="sr-only">{t('ui.search')}</label>
            <SearchIcon className="pointer-events-none absolute start-3 top-1/2 -translate-y-1/2 text-fg-muted" />
            <input id="global-search" type="search" placeholder={t('ui.search')}
              className="h-(--control-height-md) w-full rounded-md border border-line bg-canvas ps-9 pe-3 text-md text-fg placeholder:text-fg-muted focus-visible:border-focus sm:text-sm" />
          </div>
          <div className="ms-auto flex items-center gap-1">
            {headerActions}
            <IconButton label={t('ui.notifications')} icon={<BellIcon size={18} />} />
            <Dropdown variant="tertiary" label={t('shell.account')} trigger={<Avatar name={userName} size="sm" decorative />}
              items={[{ id: 'profile', label: userName, onSelect: () => undefined }, { type: 'separator', id: 'sep' }, { id: 'settings', label: t('nav.platform'), onSelect: () => undefined }]} />
          </div>
        </header>

        <main id="main-content" tabIndex={-1} className="flex-1 focus:outline-none">
          <div className="mx-auto w-full max-w-(--container-page-max) px-4 py-6 md:px-6 md:py-8 lg:px-8">{children}</div>
        </main>
      </div>

      <Drawer open={mobileOpen} onClose={() => setMobileOpen(false)} side="start" size="sm" title={t('app.name')} description={t('app.tagline')}>
        <nav aria-label={t('shell.mainNavigation')} className="-mx-3 rounded-lg bg-nav-bg p-3">
          <NavList active={active} collapsed={false} onNavigate={() => setMobileOpen(false)} />
        </nav>
      </Drawer>
    </div>
  );
}

export function PageHeader({ title, description, actions, eyebrow }: { title: ReactNode; description?: ReactNode; actions?: ReactNode; eyebrow?: ReactNode }) {
  return (
    <div className="mb-8 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
      <div className="flex flex-col gap-1.5">
        {eyebrow && <p className="text-2xs font-semibold tracking-wide text-accent-fg uppercase">{eyebrow}</p>}
        <h1 className="text-2xl font-semibold tracking-tight text-fg">{title}</h1>
        {description && <p className="max-w-(--container-prose) text-sm text-fg-secondary">{description}</p>}
      </div>
      {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </div>
  );
}
