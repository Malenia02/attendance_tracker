export function formatDuration(minutesValue) {
  const minutes = Math.max(0, Math.round(Number(minutesValue) || 0));

  if (minutes < 60) return `${minutes} min`;

  const hours = Math.floor(minutes / 60);
  const remainder = minutes % 60;
  const hourLabel = `${hours} ${hours === 1 ? "hr" : "hrs"}`;

  return remainder ? `${hourLabel} ${remainder} min` : hourLabel;
}
