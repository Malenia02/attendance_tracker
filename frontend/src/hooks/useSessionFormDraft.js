import { useCallback, useEffect, useRef, useState } from "react";
import {
  clearFormDraft,
  readFormDraft,
  saveFormDraft,
} from "../lib/formDrafts";

function safeForm(form, excludedFields) {
  const excluded = new Set(excludedFields);
  return Object.fromEntries(
    Object.entries(form || {}).filter(([field]) => !excluded.has(field)),
  );
}

export default function useSessionFormDraft({
  isOpen,
  draftKey,
  initialForm,
  form,
  setForm,
  excludedFields = [],
}) {
  const [restored, setRestored] = useState(false);
  const initializedKey = useRef("");
  const baseline = useRef(initialForm);
  const excluded = excludedFields.join("|");

  useEffect(() => {
    if (!isOpen || !draftKey) {
      initializedKey.current = "";
      return undefined;
    }

    if (initializedKey.current === draftKey) return;

    initializedKey.current = draftKey;
    baseline.current = initialForm;
    const draft = readFormDraft(draftKey);
    const timeout = window.setTimeout(() => {
      if (draft) {
        setForm((current) => ({ ...current, ...draft.data }));
        setRestored(true);
      } else {
        setRestored(false);
      }
    }, 0);

    return () => window.clearTimeout(timeout);
  }, [draftKey, initialForm, isOpen, setForm]);

  useEffect(() => {
    if (!isOpen || !draftKey || initializedKey.current !== draftKey) return undefined;

    const excludedFieldsList = excluded ? excluded.split("|") : [];
    const currentSafe = safeForm(form, excludedFieldsList);
    const baselineSafe = safeForm(baseline.current, excludedFieldsList);

    if (JSON.stringify(currentSafe) === JSON.stringify(baselineSafe)) {
      return undefined;
    }

    saveFormDraft(draftKey, form, excludedFieldsList);
    return undefined;
  }, [draftKey, excluded, form, isOpen, restored]);

  const discard = useCallback(() => {
    clearFormDraft(draftKey);
    setForm(baseline.current);
    setRestored(false);
  }, [draftKey, setForm]);

  const clear = useCallback(() => {
    clearFormDraft(draftKey);
    baseline.current = form;
    setRestored(false);
  }, [draftKey, form]);

  return { draftRestored: restored, discardDraft: discard, clearDraft: clear };
}
