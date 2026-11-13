import { Component, type ErrorInfo, type ReactNode } from 'react';

interface Props {
  fallback: (reset: () => void) => ReactNode;
  children: ReactNode;
}

/** Catches render errors in a subtree so the rest of the shell stays usable. */
export class ErrorBoundary extends Component<Props, { failed: boolean }> {
  override state = { failed: false };

  static getDerivedStateFromError() {
    return { failed: true };
  }

  override componentDidCatch(error: Error, info: ErrorInfo) {
    if (import.meta.env.DEV) console.error(error, info.componentStack);
  }

  reset = () => this.setState({ failed: false });

  override render() {
    return this.state.failed ? this.props.fallback(this.reset) : this.props.children;
  }
}
