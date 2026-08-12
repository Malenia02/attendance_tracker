const DRAFT_PREFIX = "dilg_form_draft:v1:";
const AUTH_USER_KEY = "dilg_auth_user";

function currentUserId() {
  try {
    const user = JSON.parse(sessionStorage.getItem(AUTH_USER_KEY) || "null");
    return user?.user_id || user?.id || "anonymous";
  } catch {
    return "anonymous";
  }
}

export function formDraftKey(formName, recordId = "new") {
  return `${DRAFT_PREFIX}${currentUserId()}:${formName}:${recordId}`;
}

export function readFormDraft(key) {
  try {
    const draft = JSON.parse(sessionStorage.getItem(key) || "null");
    return draft?.data && typeof draft.data === "object" ? draft : null;
  } catch {
    sessionStorage.removeItem(key);
    return null;
  }
} 

export function saveFormDraft(key, data, excludedFields = []) {
  const excluded = new Set(excludedFields);
  const safeData = Object.fromEntries(
    Object.entries(data).filter(([field]) => !excluded.has(field)),
  );

  sessionStorage.setItem(key, JSON.stringify({
    data: safeData,
    saved_at: new Date().toISOString(),
  }));
}

export function clearFormDraft(key) {
  if (key) sessionStorage.removeItem(key);
}

export function clearAllFormDrafts() {
  for (let index = sessionStorage.length - 1; index >= 0; index -= 1) {
    const key = sessionStorage.key(index);
    if (key?.startsWith(DRAFT_PREFIX)) sessionStorage.removeItem(key);
  }
}
