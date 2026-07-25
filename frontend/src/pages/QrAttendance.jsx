import { useEffect, useMemo, useRef, useState } from "react";
import {
  AlertTriangle,
  BadgeCheck,
  Camera,
  CameraOff,
  CheckCircle2,
  Clock3,
  IdCard,
  ImageUp,
  Keyboard,
  MapPin,
  Printer,
  QrCode,
  RefreshCw,
  ScanLine,
  Search,
  ShieldCheck,
  Smartphone,
  Users,
  X,
  XCircle,
} from "lucide-react";
import { apiFetch } from "../lib/auth";


const DEVICE_KEY = "dilg_qr_kiosk_device";

function getDeviceIdentifier() {
  let value = localStorage.getItem(DEVICE_KEY);

  if (!value) {
    value = `KIOSK-${crypto.randomUUID()}`;
    localStorage.setItem(DEVICE_KEY, value);
  }

  return value;
}

function photoUrl(value) {
  if (!value) return null;
  if (/^(https?:)?\/\//.test(value) || value.startsWith("/")) return value;
  return `/storage/${value.replace(/^storage[\\/]/, "").replaceAll("\\", "/")}`;
}

function initials(name = "") {
  return name.split(" ").filter(Boolean).map((part) => part[0]).slice(0, 2).join("").toUpperCase();
}

async function readResponse(response) {
  const payload = await response.json().catch(() => ({}));
  if (!response.ok) throw Object.assign(new Error(payload.message || "The request could not be completed."), { payload });
  return payload;
}

function playFeedback(success) {
  try {
    const AudioContextClass = window.AudioContext || window.webkitAudioContext;
    const context = new AudioContextClass();
    const oscillator = context.createOscillator();
    const gain = context.createGain();
    oscillator.frequency.value = success ? 880 : 220;
    gain.gain.setValueAtTime(0.08, context.currentTime);
    gain.gain.exponentialRampToValueAtTime(0.001, context.currentTime + 0.18);
    oscillator.connect(gain);
    gain.connect(context.destination);
    oscillator.start();
    oscillator.stop(context.currentTime + 0.18);
  } catch {
    // Audio feedback is optional.
  }

  if (navigator.vibrate) navigator.vibrate(success ? 90 : [80, 60, 80]);
}

function getDevicePosition() {
  return new Promise((resolve, reject) => {
    if (!navigator.geolocation) {
      reject(new Error("Location services are not supported by this device."));
      return;
    }

    navigator.geolocation.getCurrentPosition(
      (position) => resolve({
        latitude: position.coords.latitude,
        longitude: position.coords.longitude,
        accuracy: position.coords.accuracy,
      }),
      (positionError) => {
        const message = positionError.code === positionError.PERMISSION_DENIED
          ? "Location permission was denied."
          : positionError.code === positionError.TIMEOUT
            ? "GPS location timed out."
            : "The device location could not be determined.";
        reject(new Error(message));
      },
      { enableHighAccuracy: true, timeout: 12000, maximumAge: 30000 },
    );
  });
}

export default function QrAttendance() {
  const [data, setData] = useState({
    summary: { active_personnel: 0, accepted_today: 0, rejected_today: 0, duplicates_today: 0 },
    recent_scans: [],
    personnel: [],
    can_manage_codes: false,
  });
  const [tab, setTab] = useState("scanner");
  const [now, setNow] = useState(new Date());
  const [loading, setLoading] = useState(true);
  const [cameraActive, setCameraActive] = useState(false);
  const [photoDecoding, setPhotoDecoding] = useState(false);
  const [locationState, setLocationState] = useState({ status: "idle", accuracy: null });
  const [scanBusy, setScanBusy] = useState(false);
  const [manualCode, setManualCode] = useState("");
  const [scanResult, setScanResult] = useState(null);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [cardSearch, setCardSearch] = useState("");
  const [regeneratingId, setRegeneratingId] = useState(null);
  const scannerRef = useRef(null);
  const scanBusyRef = useRef(false);

  async function loadData() {
    try {
      const payload = await apiFetch("/qr-attendance").then(readResponse);
      setData(payload);
    } catch (requestError) {
      setError(requestError.message);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    const controller = new AbortController();
    apiFetch("/qr-attendance", { signal: controller.signal })
      .then(readResponse)
      .then((payload) => setData(payload))
      .catch((requestError) => {
        if (requestError.name !== "AbortError") setError(requestError.message);
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    const timer = window.setInterval(() => setNow(new Date()), 1000);

    return () => {
      controller.abort();
      window.clearInterval(timer);
      const scanner = scannerRef.current;
      if (scanner?.isScanning) scanner.stop().catch(() => {});
    };
  }, []);

  async function submitScan(code) {
    const cleanedCode = code.trim();
    if (!cleanedCode || scanBusyRef.current) return;

    scanBusyRef.current = true;
    setScanBusy(true);
    setError("");
    setNotice("");
    if (scannerRef.current?.isScanning) scannerRef.current.pause(true);

    try {
      let position = null;
      setLocationState({ status: "checking", accuracy: null });

      try {
        position = await getDevicePosition();
        setLocationState({ status: "ready", accuracy: position.accuracy });
      } catch {
        setLocationState({ status: "denied", accuracy: null });
      }

      const response = await apiFetch("/qr-attendance/scan", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          code: cleanedCode,
          device_identifier: getDeviceIdentifier(),
          ...(position ? {
            latitude: position.latitude,
            longitude: position.longitude,
          } : {}),
        }),
      });
      const payload = await response.json().catch(() => ({}));
      const accepted = response.ok;
      const validationMessage = Object.values(payload.errors || {}).flat()[0];
      setScanResult({
        accepted,
        message: validationMessage || payload.message || (accepted ? "Attendance accepted." : "Attendance rejected."),
        scan: payload.scan,
        personnel: payload.personnel,
        attendance: payload.attendance,
      });
      playFeedback(accepted);
      if (accepted) setManualCode("");
      await loadData();
    } catch (requestError) {
      setError(requestError.message);
      playFeedback(false);
    } finally {
      window.setTimeout(() => {
        scanBusyRef.current = false;
        setScanBusy(false);
        if (scannerRef.current?.isScanning) {
          try {
            scannerRef.current.resume();
          } catch {
            // The camera may have been stopped while feedback was visible.
          }
        }
      }, 2200);
    }
  }

  async function startCamera() {
    setError("");

    if (!window.isSecureContext && !["localhost", "127.0.0.1"].includes(window.location.hostname)) {
      setError("Camera scanning requires HTTPS on a phone. Use localhost with a computer webcam or configure HTTPS.");
      return;
    }

    try {
      const { Html5Qrcode } = await import("html5-qrcode");
      const scanner = scannerRef.current || new Html5Qrcode("qr-reader");
      scannerRef.current = scanner;
      await scanner.start(
        { facingMode: "environment" },
        { fps: 10, qrbox: { width: 245, height: 245 }, aspectRatio: 1 },
        (decodedText) => submitScan(decodedText),
        () => {},
      );
      setCameraActive(true);
    } catch (cameraError) {
      setError(cameraError?.message || "The camera could not be started. Check browser permission.");
    }
  }

  async function stopCamera() {
    const scanner = scannerRef.current;

    try {
      if (scanner?.isScanning) await scanner.stop();
      setCameraActive(false);
    } catch (cameraError) {
      setError(cameraError?.message || "The camera could not be stopped.");
    }
  }

  async function scanPhoto(event) {
    const file = event.target.files?.[0];
    event.target.value = "";

    if (!file || scanBusyRef.current) return;

    setError("");
    setPhotoDecoding(true);

    try {
      if (scannerRef.current?.isScanning) {
        await scannerRef.current.stop();
        setCameraActive(false);
      }

      const { Html5Qrcode } = await import("html5-qrcode");
      const scanner = scannerRef.current || new Html5Qrcode("qr-reader");
      scannerRef.current = scanner;
      const decodedText = await scanner.scanFile(file, true);
      await submitScan(decodedText);
    } catch {
      setError("No readable QR code was found in that photo. Use the original sharp image and keep the entire QR border visible.");
      playFeedback(false);
    } finally {
      setPhotoDecoding(false);
    }
  }

  async function regenerateCard(person) {
    const confirmation = person.has_qr
      ? window.confirm(`Regenerate ${person.full_name}'s QR card? The previous printed card will stop working.`)
      : true;
    if (!confirmation) return;

    setRegeneratingId(person.personnel_id);
    setError("");

    try {
      const payload = await apiFetch(`/qr-attendance/personnel/${person.personnel_id}/regenerate`, {
        method: "POST",
      }).then(readResponse);

      setData((current) => ({
        ...current,
        personnel: current.personnel.map((item) =>
          item.personnel_id === person.personnel_id ? payload.personnel : item),
      }));
      setNotice(payload.message);
      window.setTimeout(() => setNotice(""), 3500);
    } catch (requestError) {
      setError(requestError.message);
    } finally {
      setRegeneratingId(null);
    }
  }

  const filteredPersonnel = useMemo(() => {
    const query = cardSearch.trim().toLowerCase();
    return data.personnel.filter((person) => !query
      || person.full_name.toLowerCase().includes(query)
      || person.employee_number.toLowerCase().includes(query)
      || person.department?.code.toLowerCase().includes(query));
  }, [data.personnel, cardSearch]);

  const cards = [
    { label: "Active Personnel", value: data.summary.active_personnel, icon: Users, tone: "blue" },
    { label: "Accepted Today", value: data.summary.accepted_today, icon: CheckCircle2, tone: "green" },
    { label: "Rejected Today", value: data.summary.rejected_today, icon: XCircle, tone: "red" },
    { label: "Duplicates", value: data.summary.duplicates_today, icon: RefreshCw, tone: "orange" },
  ];

  return (
    <section className="qr-page">
      <header className="qr-hero">
        <div>
          <span><ShieldCheck size={14} /> Authorized attendance station</span>
          <h1>QR Attendance Kiosk</h1>
          <p>Scan signed personnel cards. The server validates schedules and records the correct attendance action.</p>
        </div>
        <div className="qr-live-time">
          <span><i></i>Asia/Manila</span>
          <strong>{now.toLocaleTimeString("en-PH", { hour: "2-digit", minute: "2-digit", second: "2-digit" })}</strong>
          <small>{now.toLocaleDateString("en-PH", { weekday: "long", month: "long", day: "numeric" })}</small>
        </div>
        <i className="qr-hero-orbit"></i>
      </header>

      {notice && <div className="users-notice success"><BadgeCheck size={18} />{notice}</div>}
      {error && <div className="users-notice error"><X size={18} />{error}</div>}

      <div className="qr-summary-grid">
        {cards.map(({ label, value, icon: Icon, tone }, index) => (
          <article key={label} style={{ "--delay": `${index * 60}ms` }}>
            <span className={tone}><Icon size={20} /></span>
            <div><strong>{loading ? "—" : value}</strong><small>{label}</small></div>
          </article>
        ))}
      </div>

      <div className="qr-tabs">
        <button type="button" className={tab === "scanner" ? "active" : ""} onClick={() => setTab("scanner")}>
          <ScanLine size={16} /> Scanner
        </button>
        {data.can_manage_codes && (
          <button type="button" className={tab === "cards" ? "active" : ""} onClick={() => setTab("cards")}>
            <IdCard size={16} /> Personnel QR Cards
          </button>
        )}
      </div>

      {tab === "scanner" ? (
        <div className="qr-kiosk-layout">
          <div className="qr-scanner-card">
            <div className="qr-card-heading">
              <div><span>Camera scanner</span><h2>Position the QR card inside the frame</h2></div>
              <QrCode size={25} />
            </div>

            {!window.isSecureContext && !["localhost", "127.0.0.1"].includes(window.location.hostname) && (
              <div className="qr-security-warning">
                <AlertTriangle size={17} />
                <span>Phone camera access needs HTTPS. Manual entry remains available.</span>
              </div>
            )}

            <div className={`qr-location-status ${locationState.status}`}>
              <MapPin size={16} />
              <span>
                {locationState.status === "checking"
                  ? "Checking office location..."
                  : locationState.status === "ready"
                    ? `GPS ready (±${Math.round(locationState.accuracy || 0)} m)`
                    : locationState.status === "denied"
                      ? "GPS unavailable — allow location access before scanning"
                      : "Office GPS verification is required for every scan"}
              </span>
            </div>

            <div className={`qr-reader-shell ${cameraActive ? "active" : ""} ${scanBusy ? "processing" : ""}`}>
              <div id="qr-reader"></div>
              {!cameraActive && (
                <div className="qr-camera-placeholder">
                  <span><Camera size={34} /></span>
                  <strong>Camera is off</strong>
                  <small>Use a computer webcam on localhost or open the system through HTTPS.</small>
                </div>
              )}
              {cameraActive && <div className="qr-scan-corners"><i></i><i></i><i></i><i></i></div>}
              {scanBusy && <div className="qr-processing"><RefreshCw size={23} /><span>Checking attendance…</span></div>}
            </div>

            <div className="qr-camera-actions">
              {!cameraActive ? (
                <button type="button" className="start" onClick={startCamera}><Camera size={17} />Start camera</button>
              ) : (
                <button type="button" className="stop" onClick={stopCamera}><CameraOff size={17} />Stop camera</button>
              )}
              <label className={`qr-photo-upload ${photoDecoding ? "busy" : ""}`}>
                <ImageUp size={17} />
                {photoDecoding ? "Reading photo..." : "Upload QR photo"}
                <input type="file" accept="image/*" onChange={scanPhoto} disabled={photoDecoding || scanBusy} />
              </label>
            </div>

            <form className="qr-manual-entry" onSubmit={(event) => {
              event.preventDefault();
              submitScan(manualCode);
            }}>
              <label htmlFor="manual-qr-code"><Keyboard size={15} />Manual scanner input</label>
              <div>
                <input
                  id="manual-qr-code"
                  value={manualCode}
                  onChange={(event) => setManualCode(event.target.value)}
                  placeholder="Paste or scan the QR code here"
                  autoComplete="off"
                />
                <button type="submit" disabled={!manualCode.trim() || scanBusy}>Submit</button>
              </div>
            </form>
          </div>

          <aside className="qr-result-card">
            <div className="qr-card-heading">
              <div><span>Last result</span><h2>Scan confirmation</h2></div>
              <Clock3 size={21} />
            </div>
            {scanResult ? (
              <ScanResult result={scanResult} />
            ) : (
              <div className="qr-result-empty">
                <span><Smartphone size={28} /></span>
                <strong>Ready to scan</strong>
                <small>Personnel identity and attendance action will appear here.</small>
              </div>
            )}
          </aside>
        </div>
      ) : (
        <div className="panel qr-cards-panel">
          <div className="qr-cards-toolbar">
            <div><span>Printable credentials</span><h2>Personnel QR Cards</h2></div>
            <div>
              <label><Search size={16} /><input value={cardSearch} onChange={(event) => setCardSearch(event.target.value)} placeholder="Search personnel..." /></label>
              <button type="button" onClick={() => window.print()}><Printer size={16} />Print cards</button>
            </div>
          </div>
          <div className="qr-print-grid">
            {filteredPersonnel.map((person) => (
              <PersonnelQrCard
                key={person.personnel_id}
                person={person}
                busy={regeneratingId === person.personnel_id}
                onRegenerate={() => regenerateCard(person)}
              />
            ))}
          </div>
        </div>
      )}

      <div className="panel qr-history-panel">
        <div className="qr-history-heading">
          <div><span>Audit trail</span><h2>Recent QR scans</h2></div>
          <button type="button" onClick={loadData}><RefreshCw size={15} />Refresh</button>
        </div>
        <div className="users-table-wrap">
          <table className="users-table qr-history-table">
            <thead><tr><th>Personnel</th><th>Action</th><th>Result</th><th>Time</th><th>Operator</th><th>Message</th></tr></thead>
            <tbody>
              {loading ? (
                <tr><td colSpan="6" className="users-empty">Loading QR activity…</td></tr>
              ) : data.recent_scans.length ? data.recent_scans.map((scan) => (
                <tr key={scan.qr_scan_id}>
                  <td><strong>{scan.full_name || "Unknown QR"}</strong><small>{scan.employee_number || "No personnel match"}</small></td>
                  <td>{scan.scan_action}</td>
                  <td><span className={`qr-scan-status ${scan.scan_status.toLowerCase().replaceAll(" ", "-")}`}>{scan.scan_status}</span></td>
                  <td>{scan.time}</td>
                  <td>{scan.scanner || "—"}</td>
                  <td className="qr-message-cell">{scan.message}</td>
                </tr>
              )) : <tr><td colSpan="6" className="users-empty">No QR scans have been recorded.</td></tr>}
            </tbody>
          </table>
        </div>
      </div>
    </section>
  );
}

function ScanResult({ result }) {
  const person = result.personnel;
  const image = photoUrl(person?.photo);

  return (
    <div className={`qr-result ${result.accepted ? "accepted" : "rejected"}`}>
      <div className="qr-result-symbol">
        {result.accepted ? <CheckCircle2 size={34} /> : <XCircle size={34} />}
      </div>
      <strong>{result.accepted ? "Attendance accepted" : "Scan rejected"}</strong>
      <p>{result.message}</p>
      {person && (
        <div className="qr-personnel-confirm">
          {image ? <img src={image} alt="" /> : <span>{initials(person.full_name)}</span>}
          <div><strong>{person.full_name}</strong><small>{person.employee_number} · {person.department?.code || "No office"}</small></div>
        </div>
      )}
      {result.scan && (
        <div className="qr-result-meta">
          <span><small>Action</small><strong>{result.scan.scan_action}</strong></span>
          <span><small>Server time</small><strong>{result.scan.time}</strong></span>
          {result.scan.distance_from_office_meters != null && (
            <span>
              <small>Office distance</small>
              <strong>{Math.round(result.scan.distance_from_office_meters).toLocaleString()} m</strong>
            </span>
          )}
        </div>
      )}
      {!!result.attendance?.late_minutes && (
        <div className="qr-late-alert"><Clock3 size={14} />Flagged late by {result.attendance.late_minutes} minutes</div>
      )}
    </div>
  );
}

function PersonnelQrCard({ person, busy, onRegenerate }) {
  const [image, setImage] = useState("");
  const personImage = photoUrl(person.photo);

  useEffect(() => {
    let active = true;

    if (!person.qr_payload) {
      return () => { active = false; };
    }

    import("qrcode")
      .then(({ default: QRCode }) => QRCode.toDataURL(person.qr_payload, {
        width: 260,
        margin: 1,
        errorCorrectionLevel: "M",
        color: { dark: "#10234d", light: "#ffffff" },
      }))
      .then((value) => {
        if (active) setImage(value);
      })
      .catch(() => {});

    return () => { active = false; };
  }, [person.qr_payload]);

  return (
    <article className="personnel-qr-card">
      <header>
        <div className="qr-card-brand"><ShieldCheck size={16} /><span>DILG GIP Attendance</span></div>
        <small>Personnel QR Card</small>
      </header>
      <div className="personnel-qr-body">
        <div className="personnel-card-identity">
          {personImage ? <img src={personImage} alt="" /> : <span>{initials(person.full_name)}</span>}
          <div><strong>{person.full_name}</strong><small>{person.employee_number}</small><em>{person.department?.code || person.personnel_type}</em></div>
        </div>
        {image ? (
          <img className="personnel-qr-image" src={image} alt={`Attendance QR for ${person.full_name}`} />
        ) : (
          <div className="personnel-no-qr"><QrCode size={35} /><span>No QR generated</span></div>
        )}
      </div>
      <footer>
        <span>Present this card only at an authorized attendance kiosk.</span>
        <button type="button" onClick={onRegenerate} disabled={busy}>
          {person.has_qr ? <RefreshCw size={13} /> : <QrCode size={13} />}
          {busy ? "Generating…" : person.has_qr ? "Regenerate" : "Generate"}
        </button>
      </footer>
    </article>
  );
}
