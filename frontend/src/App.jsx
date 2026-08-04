import { BrowserRouter } from "react-router";
import AppErrorBoundary from "./components/errors/AppErrorBoundary";
import AppRoutes from "./routes/AppRoutes";

export default function App() {
  return (
    <BrowserRouter>
      <AppErrorBoundary>
        <AppRoutes />
      </AppErrorBoundary>
    </BrowserRouter>
  );
}
