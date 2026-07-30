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
import QRCode from "qrcode";
import DilgSeal from "../components/branding/DilgSeal";
import useConfirmDialog from "../hooks/useConfirmDialog";
import { apiFetch } from "../lib/auth";
import Pagination from "../components/common/Pagination";
import { formatDuration } from "../lib/duration";


const DEVICE_KEY = "dilg_qr_kiosk_device";

function createQrScanner(qrModule) {
  return new qrModule.Html5Qrcode("qr-reader", {
    formatsToSupport: [qrModule.Html5QrcodeSupportedFormats.QR_CODE],
    useBarCodeDetectorIfSupported: true,
    verbose: false,
  });
}

async function detectQrWithBrowser(file) {
  if (!("BarcodeDetector" in window) || typeof window.createImageBitmap !== "function") {
    return "";
  }

  let bitmap;

  try {
    const supportedFormats = typeof window.BarcodeDetector.getSupportedFormats === "function"
      ? await window.BarcodeDetector.getSupportedFormats()
      : ["qr_code"];

    if (!supportedFormats.includes("qr_code")) return "";

    bitmap = await window.createImageBitmap(file);
    const detector = new window.BarcodeDetector({ formats: ["qr_code"] });
    const results = await detector.detect(bitmap);

    return results.find((result) => result.rawValue)?.rawValue || "";
  } catch {
    return "";
  } finally {
    bitmap?.close?.();
  }
}

function canvasFile(canvas, name) {
  return new Promise((resolve, reject) => {
    canvas.toBlob((blob) => {
      if (!blob) {
        reject(new Error("The uploaded image could not be prepared for scanning."));
        return;
      }

      resolve(new File([blob], name, { type: "image/png" }));
    }, "image/png");
  });
}

async function createFocusedQrCandidates(file) {
  if (typeof window.createImageBitmap !== "function") return [];

  const bitmap = await window.createImageBitmap(file);

  try {
    const cropSize = Math.max(1, Math.round(Math.min(bitmap.width, bitmap.height) * 0.78));
    const maxX = Math.max(0, bitmap.width - cropSize);
    const maxY = Math.max(0, bitmap.height - cropSize);
    const positions = [
      [maxX, maxY / 2],
      [maxX / 2, maxY / 2],
      [0, maxY / 2],
      [maxX, 0],
      [maxX, maxY],
      [maxX / 2, 0],
      [maxX / 2, maxY],
      [0, 0],
      [0, maxY],
    ];
    const outputSize = 900;
    const candidates = [];

    for (let index = 0; index < positions.length; index += 1) {
      const [x, y] = positions[index];
      const canvas = document.createElement("canvas");
      canvas.width = outputSize;
      canvas.height = outputSize;
      const context = canvas.getContext("2d", { alpha: false });

      if (!context) continue;

      context.fillStyle = "#ffffff";
      context.fillRect(0, 0, outputSize, outputSize);
      context.imageSmoothingEnabled = false;
      context.drawImage(
        bitmap,
        Math.round(x),
        Math.round(y),
        cropSize,
        cropSize,
        0,
        0,
        outputSize,
        outputSize,
      );
      candidates.push(await canvasFile(canvas, `qr-focus-${index}.png`));
    }

    return candidates;
  } finally {
    bitmap.close?.();
  }
}

async function decodeQrPhoto(file, scanner) {
  const nativeResult = await detectQrWithBrowser(file);
  if (nativeResult) return nativeResult;

  try {
    return await scanner.scanFile(file, true);
  } catch {
    const candidates = await createFocusedQrCandidates(file);

    for (const candidate of candidates) {
      try {
        return await scanner.scanFile(candidate, false);
      } catch {
        // Try the next focused region before rejecting the uploaded card.
      }
    }
  }

  throw new Error("No readable QR code was found.");
}

function getDeviceIdentifier() {
  let value = localStorage.getItem(DEVICE_KEY);

  if (!value) {
    value = `KIOSK-${crypto.randomUUID()}`;
    localStorage.setItem(DEVICE_KEY, value);
  }

  return value;
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

    let bestPosition = null;
    let watchId = null;
    let settled = false;

    const cleanup = () => {
      if (watchId !== null) navigator.geolocation.clearWatch(watchId);
      window.clearTimeout(timeoutId);
    };
    const finish = (callback, value) => {
      if (settled) return;
      settled = true;
      cleanup();
      callback(value);
    };
    const toPosition = (position) => ({
        latitude: position.coords.latitude,
        longitude: position.coords.longitude,
        accuracy: position.coords.accuracy,
        timestamp: position.timestamp,
      });
    const timeoutId = window.setTimeout(() => {
      if (bestPosition) {
        finish(resolve, bestPosition);
        return;
      }

      finish(reject, new Error("GPS location timed out."));
    }, 12000);

    watchId = navigator.geolocation.watchPosition(
      (position) => {
        const candidate = toPosition(position);

        if (!bestPosition || candidate.accuracy < bestPosition.accuracy) {
          bestPosition = candidate;
        }

        if (candidate.accuracy <= 100) {
          finish(resolve, candidate);
        }
      },
      (positionError) => {
        if (positionError.code !== positionError.PERMISSION_DENIED && bestPosition) return;

        const message = positionError.code === positionError.PERMISSION_DENIED
          ? "Location permission was denied."
          : positionError.code === positionError.TIMEOUT
            ? "GPS location timed out."
            : "The device location could not be determined.";
        finish(reject, new Error(message));
      },
      { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 },
    );

    if (settled && watchId !== null) navigator.geolocation.clearWatch(watchId);
  });
}

export default function QrAttendance() {
  const { confirm, confirmationDialog } = useConfirmDialog();
  const [data, setData] = useState({
    summary: { active_personnel: 0, accepted_today: 0, rejected_today: 0, duplicates_today: 0 },
    recent_scans: [],
    personnel: [],
    can_scan: false,
    can_view_cards: false,
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
  const [cardPage, setCardPage] = useState(1);
  const [cardPagination, setCardPagination] = useState(null);
  const [selectedCardIds, setSelectedCardIds] = useState([]);
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
    if (!data.can_manage_codes || (data.can_scan && tab !== "cards")) return undefined;

    const controller = new AbortController();
    const timer = window.setTimeout(() => {
      const params = new URLSearchParams({
        page: String(cardPage),
        per_page: "12",
      });
      if (cardSearch.trim()) params.set("search", cardSearch.trim());

      apiFetch(`/qr-attendance/cards?${params}`, { signal: controller.signal })
        .then(readResponse)
        .then((payload) => {
          setData((current) => ({ ...current, personnel: payload.data }));
          setCardPagination(payload.meta?.pagination || null);
          if (!payload.data.length && cardPage > 1) {
            setCardPage((current) => Math.max(1, current - 1));
          }
        })
        .catch((requestError) => {
          if (requestError.name !== "AbortError") setError(requestError.message);
        });
    }, 250);

    return () => {
      window.clearTimeout(timer);
      controller.abort();
    };
  }, [data.can_manage_codes, data.can_scan, tab, cardSearch, cardPage]);

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

      const deviceIdentifier = getDeviceIdentifier();
      const challengeResponse = await apiFetch("/qr-attendance/challenge", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ device_identifier: deviceIdentifier }),
      });
      const challengePayload = await challengeResponse.json().catch(() => ({}));

      if (!challengeResponse.ok || !challengePayload.challenge) {
        throw new Error(
          challengePayload.message || "A secure kiosk scan could not be started.",
        );
      }

      const response = await apiFetch("/qr-attendance/scan", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          code: cleanedCode,
          challenge: challengePayload.challenge,
          device_identifier: deviceIdentifier,
          ...(position ? {
            latitude: position.latitude,
            longitude: position.longitude,
            accuracy: position.accuracy,
            position_timestamp: new Date(position.timestamp).toISOString(),
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
      const qrModule = await import("html5-qrcode");
      const scanner = scannerRef.current || createQrScanner(qrModule);
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

      const qrModule = await import("html5-qrcode");
      const scanner = scannerRef.current || createQrScanner(qrModule);
      scannerRef.current = scanner;
      const decodedText = await decodeQrPhoto(file, scanner);
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
      ? await confirm({
        title: "Regenerate QR credential?",
        message: `Generate a new secure QR card for ${person.full_name}?`,
        note: "The previous printed card will stop working immediately.",
        confirmLabel: "Regenerate QR",
        tone: "warning",
      })
      : true;
    if (!confirmation) return;

    setRegeneratingId(person.personnel_id);
    setError("");

    try {
      const payload = await apiFetch(`/qr-attendance/personnel/${person.personnel_id}/regenerate`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: "{}",
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
      || String(person.full_name || "").toLowerCase().includes(query)
      || String(person.employee_number || "").toLowerCase().includes(query)
      || String(person.department?.code || "").toLowerCase().includes(query));
  }, [data.personnel, cardSearch]);

  const selectedCardIdSet = useMemo(
    () => new Set(selectedCardIds),
    [selectedCardIds],
  );
  const visibleCardIdSet = useMemo(
    () => new Set(filteredPersonnel.map((person) => person.personnel_id)),
    [filteredPersonnel],
  );
  const allVisibleCardsSelected = filteredPersonnel.length > 0
    && filteredPersonnel.every((person) => selectedCardIdSet.has(person.personnel_id));

  function toggleCardSelection(personnelId) {
    setSelectedCardIds((current) => current.includes(personnelId)
      ? current.filter((id) => id !== personnelId)
      : [...current, personnelId]);
  }

  function toggleVisibleCardSelection() {
    const visibleIds = filteredPersonnel.map((person) => person.personnel_id);

    setSelectedCardIds((current) => {
      if (allVisibleCardsSelected) {
        return current.filter((id) => !visibleCardIdSet.has(id));
      }

      return [...new Set([...current, ...visibleIds])];
    });
  }

  const cards = [
    { label: "Active Personnel", value: data.summary.active_personnel, icon: Users, tone: "blue" },
    { label: "Accepted Today", value: data.summary.accepted_today, icon: CheckCircle2, tone: "green" },
    { label: "Rejected Today", value: data.summary.rejected_today, icon: XCircle, tone: "red" },
    { label: "Duplicates", value: data.summary.duplicates_today, icon: RefreshCw, tone: "orange" },
  ];
  const activeTab = data.can_scan ? tab : "cards";

  return (
    <section className="qr-page">
      <header className="qr-hero">
        <div>
          <span><ShieldCheck size={14} /> {data.can_scan ? "Authorized attendance station" : "Personal attendance credential"}</span>
          <h1>{data.can_scan ? "QR Attendance Kiosk" : "My QR ID"}</h1>
          <p>
            {data.can_scan
              ? "Scan signed personnel cards. The server validates schedules and records the correct attendance action."
              : "View and print your secure personnel card. Attendance scans remain protected and recorded by authorized kiosks."}
          </p>
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
        {data.can_scan && (
          <button type="button" className={activeTab === "scanner" ? "active" : ""} onClick={() => setTab("scanner")}>
            <ScanLine size={16} /> Scanner
          </button>
        )}
        {data.can_view_cards && (
          <button type="button" className={activeTab === "cards" ? "active" : ""} onClick={() => setTab("cards")}>
            <IdCard size={16} /> {data.can_manage_codes ? "Personnel QR Cards" : "My QR ID"}
          </button>
        )}
      </div>

      {activeTab === "scanner" ? (
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
            <div>
              <span>
                {data.can_manage_codes
                  ? "Select an ID to print · click the card to flip"
                  : "Your linked personnel credential · click the card to flip"}
              </span>
              <h2>{data.can_manage_codes ? "Personnel QR Cards" : "My Personnel Card"}</h2>
            </div>
            {data.can_manage_codes && (
              <div>
                <label><Search size={16} /><input value={cardSearch} onChange={(event) => { setCardSearch(event.target.value); setCardPage(1); setSelectedCardIds([]); }} placeholder="Search personnel..." /></label>
              </div>
            )}
          </div>
          {data.can_manage_codes && (
            <div className="qr-print-selection-bar">
            <div className="qr-print-selection-count">
              <CheckCircle2 size={17} />
              <span><strong>{selectedCardIds.length}</strong> {selectedCardIds.length === 1 ? "ID" : "IDs"} selected for printing</span>
            </div>
            <div className="qr-print-selection-actions">
              <button
                type="button"
                className="secondary"
                onClick={toggleVisibleCardSelection}
                disabled={!filteredPersonnel.length}
              >
                {allVisibleCardsSelected ? "Unselect visible" : `Select visible (${filteredPersonnel.length})`}
              </button>
              <button
                type="button"
                className="secondary"
                onClick={() => setSelectedCardIds([])}
                disabled={!selectedCardIds.length}
              >
                Clear
              </button>
              <button
                type="button"
                className="primary"
                onClick={() => window.print()}
                disabled={!selectedCardIds.length}
              >
                <Printer size={16} />Print selected
              </button>
            </div>
            </div>
          )}
          {!data.can_manage_codes && data.personnel.length > 0 && (
            <div className="qr-print-selection-bar">
              <div className="qr-print-selection-count">
                <IdCard size={17} />
                <span>Only your linked personnel card is available.</span>
              </div>
              <div className="qr-print-selection-actions">
                <button type="button" className="primary" onClick={() => window.print()}>
                  <Printer size={16} />Print my card
                </button>
              </div>
            </div>
          )}
          <div className="qr-print-grid">
            {filteredPersonnel.map((person) => (
              <div
                key={person.personnel_id}
                className={[
                  "personnel-card-choice",
                  (!data.can_manage_codes || selectedCardIdSet.has(person.personnel_id))
                    ? "is-print-selected"
                    : "",
                  visibleCardIdSet.has(person.personnel_id) ? "" : "is-filtered-out",
                ].filter(Boolean).join(" ")}
              >
                {data.can_manage_codes && (
                  <button
                    type="button"
                    className="personnel-card-select"
                    aria-pressed={selectedCardIdSet.has(person.personnel_id)}
                    aria-label={`${selectedCardIdSet.has(person.personnel_id) ? "Remove" : "Select"} ${person.full_name} ${selectedCardIdSet.has(person.personnel_id) ? "from" : "for"} printing`}
                    onClick={() => toggleCardSelection(person.personnel_id)}
                  >
                    <span aria-hidden="true">
                      {selectedCardIdSet.has(person.personnel_id) && <CheckCircle2 size={14} />}
                    </span>
                    {selectedCardIdSet.has(person.personnel_id) ? "Selected" : "Select ID"}
                  </button>
                )}
                <PersonnelQrCard
                  person={person}
                  busy={regeneratingId === person.personnel_id}
                  canRegenerate={data.can_manage_codes}
                  onRegenerate={() => regenerateCard(person)}
                />
              </div>
            ))}
            {!filteredPersonnel.length && (
              <div className="qr-card-search-empty">
                <Search size={24} />
                <strong>{data.personnel.length ? "No personnel found" : "No personnel profile linked"}</strong>
                <span>
                  {data.personnel.length
                    ? "Try a different name, employee number, or office code."
                    : "Ask an Administrator to link this system account to your personnel record."}
                </span>
              </div>
            )}
          </div>
          <Pagination
            pagination={cardPagination}
            onPageChange={(nextPage) => {
              setCardPage(nextPage);
              setSelectedCardIds([]);
            }}
            disabled={loading}
            itemLabel="personnel cards"
          />
        </div>
      )}

      <div className="panel qr-history-panel">
        <div className="qr-history-heading">
          <div>
            <span>Audit trail</span>
            <h2>Recent QR scans</h2>
            <small>These are historical results. After correcting a schedule, scan the card again to create a new result.</small>
          </div>
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
      {confirmationDialog}
    </section>
  );
}

function ScanResult({ result }) {
  const person = result.personnel;
  const image = person?.photo_url;

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
        <div className="qr-late-alert"><Clock3 size={14} />Flagged late by {formatDuration(result.attendance.late_minutes)}</div>
      )}
    </div>
  );
}

function PersonnelQrCard({ person, busy, canRegenerate, onRegenerate }) {
  const [renderedQr, setRenderedQr] = useState({ payload: null, image: "", error: "" });
  const [flipped, setFlipped] = useState(false);
  const personImage = person.photo_url;
  const signatureImage = person.signature_url;

  useEffect(() => {
    let active = true;

    if (!person.qr_payload) {
      return () => { active = false; };
    }

    QRCode.toDataURL(person.qr_payload, {
        width: 360,
        margin: 2,
        errorCorrectionLevel: "Q",
        color: { dark: "#09244f", light: "#ffffff" },
      })
      .then((value) => {
        if (active) {
          setRenderedQr({ payload: person.qr_payload, image: value, error: "" });
        }
      })
      .catch(() => {
        if (active) {
          setRenderedQr({ payload: person.qr_payload, image: "", error: "QR rendering failed" });
        }
      });

    return () => { active = false; };
  }, [person.qr_payload]);

  const image = renderedQr.payload === person.qr_payload ? renderedQr.image : "";
  const renderError = renderedQr.payload === person.qr_payload ? renderedQr.error : "";
  const credentialNumber = `DILG-GIP-${String(person.personnel_id).padStart(5, "0")}`;

  function handleFlip() {
    setFlipped((current) => !current);
  }

  function handleFlipKeyDown(event) {
    if (event.target !== event.currentTarget || !["Enter", " "].includes(event.key)) return;

    event.preventDefault();
    handleFlip();
  }

  return (
    <div
      className={`personnel-card-pair${flipped ? " is-flipped" : ""}`}
      role="button"
      tabIndex="0"
      aria-label={`${flipped ? "Show front of" : "Show back of"} ${person.full_name}'s personnel card`}
      aria-pressed={flipped}
      onClick={handleFlip}
      onKeyDown={handleFlipKeyDown}
    >
      <div className="personnel-card-flipper">
        <article className="personnel-qr-card personnel-qr-card-front">
          <QrCardHeader />
      <div className="qr-card-ribbon">
        <span>Authorized personnel credential</span>
      </div>
      <div className="personnel-qr-body">
        <div className="personnel-card-identity">
          <div className="personnel-card-profile">
            <div className="personnel-card-photo">
              {personImage ? <img src={personImage} alt="" /> : <span>{initials(person.full_name)}</span>}
            </div>
            <div className="personnel-card-name">
              <small>Cardholder</small>
              <strong>{person.full_name}</strong>
              <span>{person.position_title || `${person.personnel_type} Personnel`}</span>
            </div>
          </div>
          <div className="personnel-card-details">
            <div><small>Employee number</small><strong>{person.employee_number}</strong></div>
            <div><small>Office / Unit</small><strong>{person.department?.code || "Not assigned"}</strong></div>
            <div><small>Personnel type</small><strong>{person.personnel_type}</strong></div>
            <div><small>Validity</small><strong>{person.validity_label || "While active"}</strong></div>
          </div>
        </div>
        <div className="personnel-card-code">
          <span><ShieldCheck size={11} /> Secure attendance QR</span>
          <div className="personnel-qr-frame">
            {image ? (
              <img className="personnel-qr-image" src={image} alt={`Attendance QR for ${person.full_name}`} />
            ) : (
              <div className="personnel-no-qr">
                <QrCode size={35} />
                <span>{renderError || (person.qr_payload ? "Rendering QR…" : "No QR generated")}</span>
              </div>
            )}
          </div>
          <small>Scan only at an authorized DILG kiosk</small>
        </div>
      </div>
      <footer>
        <div className="personnel-card-serial">
          <ShieldCheck size={15} />
          <span><small>Credential number</small><strong>{credentialNumber}</strong></span>
        </div>
        <div className="personnel-card-security">Digitally signed attendance credential</div>
        {canRegenerate && (
          <button
            className="personnel-qr-action"
            type="button"
            onClick={(event) => {
              event.stopPropagation();
              onRegenerate();
            }}
            disabled={busy}
          >
            {person.has_qr ? <RefreshCw size={13} /> : <QrCode size={13} />}
            {busy ? "Generating…" : person.has_qr ? "Regenerate" : "Generate"}
          </button>
        )}
      </footer>
        </article>

        <article className="personnel-qr-card personnel-qr-card-back">
          <QrCardHeader />
        <div className="qr-card-ribbon">
          <span>Card care and security</span>
        </div>
        <div className="personnel-card-back-body">
          <DilgSeal className="personnel-card-back-watermark" alt="" />
          <div className="personnel-card-rules">
            <span>Cardholder responsibilities</span>
            <ol>
              <li>Present this card only at an authorized DILG attendance kiosk.</li>
              <li>Do not lend, copy, alter, or allow another person to use this credential.</li>
              <li>Report a lost or damaged card to the issuing office immediately.</li>
            </ol>
            <div className="personnel-card-privacy">
              Attendance scans are recorded with the kiosk operator, time, and verified office location.
            </div>
          </div>
          <div className="personnel-card-return">
            <div className="personnel-card-return-heading">
              <MapPin size={13} />
              <span>If found, return to</span>
            </div>
            <strong>{person.department?.name || "DILG Issuing Office"}</strong>
            <p>{person.department?.location || "Return this card to the office that issued the credential."}</p>
            <div className="personnel-card-signature">
              {signatureImage && <img src={signatureImage} alt={`${person.full_name}'s signature`} />}
              <span></span>
              <small>Cardholder signature</small>
            </div>
          </div>
        </div>
        <footer>
          <div className="personnel-card-serial">
            <ShieldCheck size={15} />
            <span><small>Credential number</small><strong>{credentialNumber}</strong></span>
          </div>
          <div className="personnel-card-property">This credential remains the property of DILG.</div>
        </footer>
        </article>
      </div>
    </div>
  );
}

function QrCardHeader() {
  return (
    <header>
      <div className="qr-card-brand">
        <span><DilgSeal /></span>
        <div>
          <strong>Department of the Interior and Local Government</strong>
          <small> Attendance Management System</small>
        </div>
      </div>
      <div className="qr-card-classification">
        <small>PERSONNEL ID</small>
      </div>
    </header>
  );
}
