import { BrowserRouter } from "react-router";
import AppErrorBoundary from "./components/errors/AppErrorBoundary";
import ToastViewport from "./components/common/ToastViewport";
import AppRoutes from "./routes/AppRoutes";

export default function App() {
  return (
    <BrowserRouter>
      <AppErrorBoundary>
        <AppRoutes />
        <ToastViewport />
      </AppErrorBoundary>
    </BrowserRouter>
  );
}
