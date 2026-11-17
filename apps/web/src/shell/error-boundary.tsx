import { Component, type ErrorInfo, type ReactNode } from 'react';

interface Props {
  fallback: (reset: () => void, error: unknown) => ReactNode;
  children: ReactNode;
}

/** Catches render errors in a subtree so the rest of the shell stays usable. */
export class ErrorBoundary extends Component<Props, { failed: boolean; error: unknown }> {
  override state: { failed: boolean; error: unknown } = { failed: false, error: null };

  static getDerivedStateFromError(error: unknown) {
    return { failed: true, error };
  }

  override componentDidCatch(error: Error, info: ErrorInfo) {
    if (import.meta.env.DEV) console.error(error, info.componentStack);
  }

  reset = () => this.setState({ failed: false, error: null });

  override render() {
    return this.state.failed ? this.props.fallback(this.reset, this.state.error) : this.props.children;
  }
}
