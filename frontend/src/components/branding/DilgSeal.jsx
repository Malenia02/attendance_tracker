export default function DilgSeal({ className = "", alt = "DILG official seal" }) {
  return (
    <img
      className={`dilg-seal-image ${className}`.trim()}
      src="/images/dilg-seal.png"
      alt={alt}
      draggable="false"
    />
  );
}
