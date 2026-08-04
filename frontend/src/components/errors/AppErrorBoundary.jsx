import { Component } from "react";
import { useLocation } from "react-router";
import ErrorPage from "../../pages/ErrorPage";

class ErrorBoundary extends Component {
  constructor(props) {
    super(props);
    this.state = { error: null };
  }

  static getDerivedStateFromError(error) {
    return { error };
  }

  componentDidCatch(error, information) {
    console.error("Unexpected application error", error, information);
  }

  componentDidUpdate(previousProps) {
    if (
      this.state.error
      && previousProps.locationKey !== this.props.locationKey
    ) {
      this.setState({ error: null });
    }
  }

  render() {
    if (this.state.error) {
      return (
        <ErrorPage
          code={500}
          error={this.state.error}
          onReset={() => this.setState({ error: null })}
        />
      );
    }

    return this.props.children;
  }
}

export default function AppErrorBoundary({ children }) {
  const location = useLocation();

  return (
    <ErrorBoundary locationKey={location.key}>
      {children}
    </ErrorBoundary>
  );
}
