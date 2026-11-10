import { useEffect, type RefObject } from 'react';

/** Calls `onDismiss` on Escape or a pointer press outside every given element. */
export function useDismiss(refs: Array<RefObject<HTMLElement | null>>, active: boolean, onDismiss: () => void) {
  useEffect(() => {
    if (!active) return;
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.stopPropagation();
        onDismiss();
      }
    };
    const onPointerDown = (event: PointerEvent) => {
      const target = event.target as Node;
      if (refs.every((ref) => !ref.current?.contains(target))) onDismiss();
    };
    document.addEventListener('keydown', onKeyDown);
    document.addEventListener('pointerdown', onPointerDown);
    return () => {
      document.removeEventListener('keydown', onKeyDown);
      document.removeEventListener('pointerdown', onPointerDown);
    };
  }, [active, onDismiss, refs]);
}
