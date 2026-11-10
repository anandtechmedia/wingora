import { useState } from 'react';
import { Link } from 'react-router';
import { canAccessModule, findModule } from '@wingora/permissions';
import { Badge, Button, Drawer, EmptyState, InboxIcon, Tabs } from '@/components/ui';
import { useSession } from '@/auth/session';
import { useI18n } from '@/i18n/i18n';
import { notifications as fixtures, type NotificationItem } from '@/mocks/fixtures';

/** Notifications are filtered to modules the user can open before anything (including counts) uses them. */
export function useNotifications() {
  const { permissions } = useSession();
  const [all, setItems] = useState<NotificationItem[]>(fixtures);
  const items = all.filter((n) => {
    const module = findModule(n.moduleId);
    return module ? canAccessModule(permissions, module) : false;
  });
  return {
    items,
    unread: items.filter((n) => !n.read).length,
    markRead: (id: string) => setItems((list) => list.map((n) => (n.id === id ? { ...n, read: true } : n))),
    markAllRead: () => setItems((list) => list.map((n) => ({ ...n, read: true }))),
  };
}

export function NotificationCenter({ open, onClose, state }: { open: boolean; onClose: () => void; state: ReturnType<typeof useNotifications> }) {
  const { t, locale } = useI18n();
  const relative = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });
  const when = (minutes: number) => (minutes < 60 ? relative.format(-minutes, 'minute') : minutes < 1440 ? relative.format(-Math.round(minutes / 60), 'hour') : relative.format(-Math.round(minutes / 1440), 'day'));
  const visible = state.items;

  const list = (items: NotificationItem[]) => items.length === 0
    ? <EmptyState icon={<InboxIcon size={22} />} title={t('notifications.emptyTitle')} description={t('notifications.emptyDescription')} />
    : (
      <ul className="-mx-2 flex flex-col">
        {items.map((n) => {
          const module = findModule(n.moduleId)!;
          return (
            <li key={n.id} className="flex gap-3 rounded-md px-2 py-3 hover:bg-surface-muted">
              <span aria-hidden="true" className={`mt-1.5 size-2 shrink-0 rounded-full ${n.read ? 'bg-transparent' : 'bg-accent'}`} />
              <div className="min-w-0 flex-1">
                <p className="flex flex-wrap items-center gap-2 text-sm font-medium text-fg">
                  <Link to={`/${module.path}`} onClick={() => { state.markRead(n.id); onClose(); }} className="rounded-sm hover:underline">{n.title}</Link>
                  {!n.read && <Badge tone="accent" size="sm">{t('notifications.newBadge')}</Badge>}
                </p>
                <p className="mt-0.5 text-sm text-fg-secondary">{n.body}</p>
                <p className="mt-1 text-xs text-fg-muted">{when(n.minutesAgo)}</p>
              </div>
              {!n.read && <Button size="sm" variant="tertiary" onClick={() => state.markRead(n.id)} aria-label={`${t('notifications.markRead')}: ${n.title}`}>{t('notifications.markRead')}</Button>}
            </li>
          );
        })}
      </ul>
    );

  const unread = visible.filter((n) => !n.read);
  return (
    <Drawer open={open} onClose={onClose} side="end" size="sm" title={t('notifications.title')}
      footer={unread.length > 0 ? <Button variant="secondary" onClick={state.markAllRead}>{t('notifications.markAllRead')}</Button> : undefined}>
      <Tabs label={t('notifications.title')} tabs={[
        { id: 'all', label: t('notifications.all'), content: list(visible) },
        { id: 'unread', label: t('notifications.unread'), badge: unread.length ? <Badge size="sm">{unread.length}</Badge> : undefined, content: list(unread) },
      ]} />
    </Drawer>
  );
}
