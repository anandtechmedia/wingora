import { Suspense, useEffect, useState } from 'react';
import { BillingBanner } from './billing-banner';
import { Link, NavLink, Outlet, useLocation, useNavigate } from 'react-router';
import { modules, navigationFor, type ModuleDefinition } from '@wingora/permissions';
import {
  Avatar, Badge, BellIcon, Breadcrumbs, BuildingIcon, ChevronDownIcon, Drawer, Dropdown, GlobeIcon, IconButton, LoadingState,
  LogOutIcon, MenuIcon, SearchIcon, SidebarIcon, UserIcon,
} from '@/components/ui';
import { buttonClasses } from '@/components/ui/button';
import { cn } from '@/lib/cn';
import { useSession } from '@/auth/session';
import { useI18n } from '@/i18n/i18n';
import { AppErrorPage } from '@/pages/error-pages';
import { ErrorBoundary } from './error-boundary';
import { GlobalSearch } from './global-search';
import { groupLabel, moduleIcons, moduleLabel } from './module-meta';
import { NotificationCenter, useNotifications } from './notification-center';
import { useRegionName, useWorkspace } from './workspace';

function Brand({ compact }: { compact?: boolean }) {
  const { t } = useI18n();
  return (
    <Link to="/dashboard" className="flex items-center gap-2.5 rounded-md focus-visible:outline-nav-indicator">
      <span aria-hidden="true" className="inline-flex size-8 shrink-0 items-center justify-center rounded-md bg-accent text-sm font-bold text-on-accent">W</span>
      <span className={compact ? 'sr-only' : 'flex flex-col leading-none'}>
        <span className="text-md font-semibold tracking-tight text-nav-active-fg">{t('app.name')}</span>
        <span className="mt-1 text-2xs text-nav-fg-muted">{t('app.positioning')}</span>
      </span>
    </Link>
  );
}

function NavList({ collapsed, onNavigate }: { collapsed: boolean; onNavigate?: () => void }) {
  const { t } = useI18n();
  const { permissions } = useSession();
  return (
    <div className="flex flex-col gap-(--nav-group-gap)">
      {navigationFor(permissions).map(({ group, modules: items }) => (
        <div key={group}>
          <p className={cn('mb-1.5 px-3 text-2xs font-semibold tracking-wide text-nav-fg-muted uppercase', collapsed && 'sr-only')}>{t(groupLabel(group))}</p>
          <ul className="flex flex-col gap-0.5">
            {items.map((m) => {
              const Icon = moduleIcons[m.id];
              return (
                <li key={m.id}>
                  <NavLink to={`/${m.path}`} onClick={onNavigate} title={collapsed ? t(moduleLabel(m.id)) : undefined}
                    className={({ isActive }) => cn('relative flex h-(--nav-item-height) items-center gap-3 rounded-(--nav-item-radius) px-3 text-sm font-medium transition-colors duration-(--duration-fast) focus-visible:outline-nav-indicator',
                      collapsed && 'justify-center px-0', isActive ? 'bg-nav-active-bg text-nav-active-fg' : 'text-nav-fg hover:bg-nav-hover-bg hover:text-nav-active-fg')}>
                    {({ isActive }) => (
                      <>
                        {isActive && <span aria-hidden="true" className="absolute inset-y-1.5 start-0 w-0.5 rounded-full bg-nav-indicator" />}
                        <Icon size={18} />
                        <span className={cn(collapsed && 'sr-only')}>{t(moduleLabel(m.id))}</span>
                      </>
                    )}
                  </NavLink>
                </li>
              );
            })}
          </ul>
        </div>
      ))}
    </div>
  );
}

function OrganizationSwitcher({ className }: { className?: string }) {
  const { t } = useI18n();
  const { organizations, organization, setOrganization } = useWorkspace();
  if (organizations.length === 0) return null;
  return (
    <Dropdown label={t('shell.switchOrganization')} align="start" className={className}
      triggerClassName={cn(buttonClasses('tertiary', 'md'), 'max-w-56 justify-start')}
      trigger={<><BuildingIcon className="shrink-0 text-fg-muted" /><span className="truncate">{organization.name}</span><ChevronDownIcon className="shrink-0 text-fg-muted" /></>}
      items={organizations.map((o) => ({ id: o.id, label: o.name, checked: o.id === organization.id, onSelect: () => setOrganization(o.id) }))} />
  );
}

function EntitySelector({ className }: { className?: string }) {
  const { t, locale } = useI18n();
  const region = useRegionName();
  const { organizations, entities, entity, setEntity } = useWorkspace();
  if (organizations.length === 0) return null;
  const countries = new Set(entities.map((e) => e.countryCode)).size;
  return (
    <Dropdown label={t('shell.legalEntity')} align="start" className={className} menuClassName="w-72"
      triggerClassName={cn(buttonClasses('secondary', 'md'), 'max-w-64 justify-start')}
      trigger={<><GlobeIcon className="shrink-0 text-fg-muted" /><span className="truncate">{entity ? entity.name : t('shell.allEntities')}</span>
        {entity && <span className="shrink-0 font-mono text-2xs text-fg-muted">{entity.countryCode}</span>}<ChevronDownIcon className="shrink-0 text-fg-muted" /></>}
      items={[
        { id: 'all', label: t('shell.allEntities'), description: t('shell.allEntitiesDescription', { count: entities.length, countries }), checked: entity === null, onSelect: () => setEntity(null) },
        { type: 'separator', id: 'sep' },
        ...entities.map((e) => ({ id: e.id, label: e.name, description: t('shell.entityDescription', { country: region(locale, e.countryCode), currency: e.currency }),
          checked: entity?.id === e.id, onSelect: () => setEntity(e.id) })),
      ]} />
  );
}

function ProfileMenu() {
  const { t } = useI18n();
  const { me, signOut } = useSession();
  const navigate = useNavigate();
  if (!me) return null;
  return (
    <Dropdown label={t('shell.account')} variant="tertiary" triggerClassName="inline-flex rounded-full" menuClassName="w-64"
      trigger={<Avatar name={me.user.name} size="sm" decorative />}
      header={<><p className="text-2xs text-fg-muted">{t('shell.signedInAs')}</p><p className="truncate text-sm font-medium text-fg">{me.user.name}</p><p className="truncate text-xs text-fg-secondary">{me.user.email}</p></>}
      items={[
        { id: 'profile', label: t('shell.profile'), icon: <UserIcon />, onSelect: () => navigate('/employees') },
        { type: 'separator', id: 'sep' },
        { id: 'sign-out', label: t('shell.signOut'), icon: <LogOutIcon />, onSelect: () => { void signOut().then(() => navigate('/login', { replace: true })); } },
      ]} />
  );
}

function currentModule(pathname: string): ModuleDefinition | undefined {
  const segment = pathname.split('/')[1];
  return modules.find((m) => m.path === segment);
}

/**
 * Responsive application frame: full sidebar ≥ lg (collapsible to a rail), icon rail on tablet,
 * top bar + navigation drawer on mobile.
 */
export function AppLayout() {
  const { t } = useI18n();
  const { permissions } = useSession();
  const location = useLocation();
  const [collapsed, setCollapsed] = useState(false);
  const [mobileNav, setMobileNav] = useState(false);
  const [searchOpen, setSearchOpen] = useState(false);
  const [notificationsOpen, setNotificationsOpen] = useState(false);
  const notifications = useNotifications();
  const allowed = navigationFor(permissions).flatMap((g) => g.modules);
  const module = currentModule(location.pathname);
  const visibleModule = module && allowed.includes(module) ? module : undefined;

  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        setSearchOpen(true);
      }
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, []);

  useEffect(() => {
    document.title = visibleModule ? `${t(moduleLabel(visibleModule.id))} · ${t('app.name')}` : t('app.name');
  }, [visibleModule, t]);

  const crumbs = visibleModule && visibleModule.id !== 'dashboard'
    ? [{ label: t('shell.home'), render: (c: React.ReactNode, cls: string) => <Link to="/dashboard" className={cls}>{c}</Link> }, { label: t(moduleLabel(visibleModule.id)) }]
    : [];

  return (
    <div className="min-h-dvh bg-canvas">
      <a href="#main-content" className="sr-only z-(--z-toast) rounded-md bg-surface px-4 py-2 text-sm font-medium text-fg shadow-lg focus:not-sr-only focus:fixed focus:start-4 focus:top-4">
        {t('shell.skipToContent')}
      </a>

      <aside className={cn('fixed inset-y-0 start-0 z-(--z-sticky) hidden flex-col bg-nav-bg md:flex', collapsed ? 'w-(--container-sidebar-collapsed)' : 'w-(--container-sidebar-collapsed) lg:w-(--container-sidebar)')}>
        <div className={cn('flex h-(--container-header) shrink-0 items-center px-5', collapsed ? 'justify-center' : 'justify-center lg:justify-start')}>
          <span className={collapsed ? 'hidden' : 'hidden lg:block'}><Brand /></span>
          <span className={collapsed ? undefined : 'lg:hidden'}><Brand compact /></span>
        </div>
        <nav aria-label={t('shell.mainNavigation')} className="flex-1 overflow-y-auto px-3 py-4">
          <div className={collapsed ? 'hidden' : 'hidden lg:block'}><NavList collapsed={false} /></div>
          <div className={collapsed ? undefined : 'lg:hidden'}><NavList collapsed /></div>
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
        <header className="sticky top-0 z-(--z-sticky) flex h-(--container-header-mobile) items-center gap-2 border-b border-line bg-surface/95 px-3 backdrop-blur md:h-(--container-header) md:px-6">
          <IconButton label={t('shell.openNavigation')} icon={<MenuIcon size={20} />} className="md:hidden" onClick={() => setMobileNav(true)} aria-expanded={mobileNav} />
          <span className="md:hidden"><Brand compact /></span>
          <div className="hidden items-center gap-2 lg:flex">
            <OrganizationSwitcher />
            <EntitySelector />
          </div>
          <button type="button" onClick={() => setSearchOpen(true)} aria-haspopup="dialog" aria-keyshortcuts="Control+K Meta+K"
            className="ms-auto hidden h-(--control-height-md) w-full max-w-72 items-center gap-2 rounded-md border border-line bg-canvas px-3 text-sm text-fg-muted hover:border-line-strong sm:flex lg:ms-4">
            <SearchIcon className="shrink-0" /><span className="min-w-0 flex-1 truncate text-start">{t('search.placeholder')}</span>
            <kbd className="rounded-sm border border-line bg-surface px-1.5 font-sans text-2xs text-fg-muted">{t('search.shortcut')}</kbd>
          </button>
          <div className="ms-auto flex items-center gap-1 sm:ms-2">
            <IconButton label={t('search.open')} icon={<SearchIcon size={18} />} className="sm:hidden" onClick={() => setSearchOpen(true)} aria-haspopup="dialog" />
            <span className="relative">
              <IconButton label={t('notifications.trigger', { count: notifications.unread })} icon={<BellIcon size={18} />} onClick={() => setNotificationsOpen(true)} aria-haspopup="dialog" />
              {notifications.unread > 0 && <span aria-hidden="true" className="pointer-events-none absolute end-1.5 top-1.5 size-2 rounded-full bg-danger-solid ring-2 ring-surface" />}
            </span>
            <ProfileMenu />
          </div>
        </header>

        <main id="main-content" tabIndex={-1} className="flex-1 focus:outline-none">
          <div className="mx-auto w-full max-w-(--container-page-max) px-4 py-6 md:px-6 md:py-8 lg:px-8">
            <div className="mb-4 flex flex-col gap-3 lg:hidden">
              <div className="flex flex-wrap gap-2"><OrganizationSwitcher /><EntitySelector /></div>
            </div>
            <BillingBanner />
            {crumbs.length > 0 && <div className="mb-4"><Breadcrumbs items={crumbs} /></div>}
            <ErrorBoundary key={location.pathname} fallback={(reset) => <AppErrorPage onRetry={reset} />}>
              <Suspense fallback={<LoadingState label={t('shell.loadingModule')} className="py-24" />}>
                <Outlet />
              </Suspense>
            </ErrorBoundary>
          </div>
        </main>
      </div>

      <Drawer open={mobileNav} onClose={() => setMobileNav(false)} side="start" size="sm" title={t('app.name')} description={t('app.tagline')}>
        <nav aria-label={t('shell.mainNavigation')} className="-mx-3 rounded-lg bg-nav-bg p-3">
          <NavList collapsed={false} onNavigate={() => setMobileNav(false)} />
        </nav>
        <p className="mt-4"><Badge tone="warning" dot>{t('shell.prototype')}</Badge></p>
      </Drawer>
      <GlobalSearch open={searchOpen} onClose={() => setSearchOpen(false)} modules={allowed} />
      <NotificationCenter open={notificationsOpen} onClose={() => setNotificationsOpen(false)} state={notifications} />
    </div>
  );
}
