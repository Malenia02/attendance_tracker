import { useState } from "react";
import { Outlet } from "react-router";
import Footer from "./Footer";
import Header from "./Header";
import Sidebar from "./Sidebar";

export default function MainLayout() {
  const [sidebarOpen, setSidebarOpen] = useState(false);

  return (
    <div className={`app-shell ${sidebarOpen ? "sidebar-toggled" : ""}`}>
      <Sidebar isOpen={sidebarOpen} isCollapsed={sidebarOpen} />

      <button
        type="button"
        className="sidebar-backdrop"
        aria-label="Close navigation menu"
        onClick={() => setSidebarOpen(false)}
      />

      <div className="main-wrapper">
        <Header
          onToggleSidebar={() => setSidebarOpen((current) => !current)}
          sidebarToggled={sidebarOpen}
        />

        <main className="main-content">
          <Outlet />
        </main>

        <Footer />
      </div>
    </div>
  );
}
