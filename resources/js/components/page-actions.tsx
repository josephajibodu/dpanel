import { createContext, type ReactNode, useContext } from 'react';
import { createPortal } from 'react-dom';

/** The element at the right of the top bar, once the layout has rendered it. */
export const PageActionsContext = createContext<HTMLElement | null>(null);

/** Places a page's main buttons at the right of the top bar, beside its title. */
export function PageActions({ children }: { children: ReactNode }) {
    const target = useContext(PageActionsContext);

    return target ? createPortal(children, target) : null;
}
