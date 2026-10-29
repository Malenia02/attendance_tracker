import { Menu, Moon, Sun, X } from "lucide-react";
import { useEffect, useState } from "react";
import { Link } from "react-router";
import DilgSeal from "../branding/DilgSeal";
import { useTheme } from "../../context/ThemeContext";

const navItems = [
  ["Home", "#home"],
  ["Features", "#features"],
  ["How It Works", "#how-it-works"],
  ["About", "#about"],
];

export default function LandingNavbar({ actionLabel, actionPath }) {
  const [menuOpen, setMenuOpen] = useState(false);
  const [scrolled, setScrolled] = useState(false);
  const { theme, toggleTheme } = useTheme();

  useEffect(() => {
    const handleScroll = () => setScrolled(window.scrollY > 12);
    handleScroll();
    window.addEventListener("scroll", handleScroll, { passive: true });
    return () => window.removeEventListener("scroll", handleScroll);
  }, []);

  useEffect(() => {
    if (!menuOpen) return undefined;

    const handleKeyDown = (event) => {
      if (event.key === "Escape") setMenuOpen(false);
    };

    window.addEventListener("keydown", handleKeyDown);
    return () => window.removeEventListener("keydown", handleKeyDown);
  }, [menuOpen]);

  const closeMenu = () => setMenuOpen(false);

  return (
    <header className={`lp-navbar${scrolled ? " is-scrolled" : ""}`}>
      <div className="lp-container lp-navbar-inner">
        <a className="lp-brand" href="#home" onClick={closeMenu} aria-label="DILG Attendance Hub home">
          <span className="lp-brand-seal"><DilgSeal /></span>
          <span className="lp-brand-copy">
            <strong>DILG Attendance Hub</strong>
            <small>Zamboanga Sibugay Provincial Office</small>
          </span>
        </a>

        <button
          type="button"
          className="lp-menu-toggle"
          aria-label={menuOpen ? "Close navigation menu" : "Open navigation menu"}
          aria-expanded={menuOpen}
          aria-controls="landing-primary-navigation"
          onClick={() => setMenuOpen((open) => !open)}
        >
          {menuOpen ? <X aria-hidden="true" /> : <Menu aria-hidden="true" />}
        </button>

        <nav
          id="landing-primary-navigation"
          className={`lp-nav-links${menuOpen ? " is-open" : ""}`}
          aria-label="Public page navigation"
        >
          {navItems.map(([label, href]) => (
            <a href={href} onClick={closeMenu} key={href}>{label}</a>
          ))}
          <button
            type="button"
            className="lp-theme-toggle"
            onClick={toggleTheme}
            aria-label={`Switch to ${theme === "dark" ? "light" : "dark"} theme`}
            title={`Switch to ${theme === "dark" ? "light" : "dark"} theme`}
          >
            {theme === "dark" ? <Sun aria-hidden="true" /> : <Moon aria-hidden="true" />}
          </button>
          <Link className="lp-button lp-button-primary lp-nav-signin" to={actionPath} onClick={closeMenu}>
            {actionLabel}
          </Link>
        </nav>
      </div>
    </header>
  );
}
