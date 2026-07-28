import { useCallback, useEffect, useRef, useState } from "react";
import ConfirmDialog from "../components/common/ConfirmDialog";

export default function useConfirmDialog() {
  const [options, setOptions] = useState(null);
  const resolver = useRef(null);

  const finish = useCallback((result) => {
    resolver.current?.(result);
    resolver.current = null;
    setOptions(null);
  }, []);

  const confirm = useCallback((nextOptions) => new Promise((resolve) => {
    resolver.current?.(false);
    resolver.current = resolve;
    setOptions({
      title: "Confirm action",
      tone: "danger",
      ...nextOptions,
    });
  }), []);

  useEffect(() => () => resolver.current?.(false), []);

  return {
    confirm,
    confirmationDialog: options
      ? <ConfirmDialog options={options} onResult={finish} />
      : null,
  };
}
