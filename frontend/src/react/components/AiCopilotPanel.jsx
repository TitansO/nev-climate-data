import { useEffect, useRef, useState } from "react";
import { useAuth } from "../providers/AuthProvider";
import { useI18n } from "../providers/I18nProvider";
import { SparklesIcon } from "./ui/icons";

const STORAGE_KEY = "nev_ai_copilot_history";
const MAX_STORED_MESSAGES = 50;

function loadHistory() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    return raw ? JSON.parse(raw) : [];
  } catch (error) {
    return [];
  }
}

function saveHistory(messages) {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(messages.slice(-MAX_STORED_MESSAGES)));
  } catch (error) {
    // Per-viewer convenience only (A2.11-style reasoning) - a full/blocked
    // storage just means history doesn't persist across reloads, never a
    // reason to break the chat itself.
  }
}

/**
 * C2.1 : tiroir de chat RAG, monté globalement via Footer.jsx (rendu sur
 * toutes les pages, jamais dans le header/la navbar - contrainte explicite
 * de l'utilisateur pendant C1.7/C1.8). Visible seulement en session
 * authentifiée : le backend (`POST /api/ai/ask`) exige un JWT de toute
 * façon (quota par rôle, coût réel par appel).
 */
export default function AiCopilotPanel() {
  const { isAuthenticated, authorizedFetch, API_BASE_URL } = useAuth();
  const { t } = useI18n();
  const [open, setOpen] = useState(false);
  const [messages, setMessages] = useState(loadHistory);
  const [question, setQuestion] = useState("");
  const [sending, setSending] = useState(false);
  const listRef = useRef(null);

  useEffect(() => saveHistory(messages), [messages]);

  useEffect(() => {
    if (listRef.current) {
      listRef.current.scrollTop = listRef.current.scrollHeight;
    }
  }, [messages, open]);

  if (!isAuthenticated()) {
    return null;
  }

  async function handleSubmit(event) {
    event.preventDefault();
    const trimmed = question.trim();
    if ("" === trimmed || sending) {
      return;
    }

    setMessages((prev) => [...prev, { role: "user", text: trimmed }]);
    setQuestion("");
    setSending(true);

    try {
      const response = await authorizedFetch(API_BASE_URL + "/api/ai/ask", {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ question: trimmed }),
      });
      const body = await response.json().catch(() => null);

      if (!response.ok) {
        const detail = body && body.message ? body.message : t("aiCopilot.genericError", "Une erreur est survenue.");
        setMessages((prev) => [...prev, { role: "assistant", error: true, text: detail }]);
        return;
      }

      if (!body.available) {
        setMessages((prev) => [...prev, { role: "assistant", text: t("aiCopilot.noAnswer", "Je n'ai trouvé aucune information pertinente pour répondre à cette question.") }]);
        return;
      }

      setMessages((prev) => [...prev, { role: "assistant", text: body.answer, sources: body.sources || [] }]);
    } catch (networkError) {
      setMessages((prev) => [...prev, { role: "assistant", error: true, text: t("aiCopilot.networkError", "Impossible de contacter le serveur.") }]);
    } finally {
      setSending(false);
    }
  }

  return (
    <div className="fixed bottom-5 right-5 z-[9998] flex flex-col items-end gap-3">
      {open && (
        <div role="dialog" aria-modal="false" aria-label={t("aiCopilot.title", "Assistant IA")} className="flex h-[480px] w-[360px] max-w-[calc(100vw-2.5rem)] flex-col overflow-hidden rounded-lg border border-stroke bg-white shadow-card">
          <div className="flex items-center justify-between border-b border-stroke bg-deep px-4 py-3 text-white">
            <span className="flex items-center gap-2 text-sm font-semibold">
              <SparklesIcon className="h-4 w-4 text-accent" />
              {t("aiCopilot.title", "Assistant IA")}
            </span>
            <button
              type="button"
              onClick={() => setOpen(false)}
              aria-label={t("aiCopilot.close", "Fermer")}
              className="rounded p-1 text-white/70 transition hover:bg-white/10 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/70"
            >
              ✕
            </button>
          </div>

          <div ref={listRef} className="flex-1 space-y-3 overflow-y-auto px-4 py-3">
            {0 === messages.length && (
              <p className="text-sm text-dark-4">
                {t("aiCopilot.intro", "Posez une question sur les données de financement climatique de la plateforme - je réponds uniquement à partir des données et documents disponibles, avec mes sources.")}
              </p>
            )}
            {messages.map((message, index) => (
              <div key={index} className={"flex " + ("user" === message.role ? "justify-end" : "justify-start")}>
                <div
                  className={
                    "max-w-[85%] rounded-lg px-3 py-2 text-sm " +
                    ("user" === message.role
                      ? "bg-primary text-white"
                      : message.error
                        ? "bg-danger/10 text-danger"
                        : "bg-gray-2 text-dark")
                  }
                >
                  <p className="whitespace-pre-wrap">{message.text}</p>
                  {message.sources && message.sources.length > 0 && (
                    <p className="mt-1.5 border-t border-dark/10 pt-1.5 text-[11px] text-dark-5">
                      {t("aiCopilot.sources", "Sources")} : {message.sources.join(", ")}
                    </p>
                  )}
                </div>
              </div>
            ))}
            {sending && <p className="text-xs text-dark-5">{t("aiCopilot.thinking", "Recherche en cours…")}</p>}
          </div>

          <form onSubmit={handleSubmit} className="flex items-center gap-2 border-t border-stroke p-3">
            <input
              type="text"
              value={question}
              onChange={(event) => setQuestion(event.target.value)}
              placeholder={t("aiCopilot.placeholder", "Votre question…")}
              disabled={sending}
              className="flex-1 rounded-md border border-stroke px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
            />
            <button
              type="submit"
              disabled={sending || "" === question.trim()}
              className="rounded-md bg-primary px-3 py-2 text-sm font-semibold text-white transition hover:bg-primary-dark disabled:cursor-not-allowed disabled:opacity-50"
            >
              {t("aiCopilot.send", "Envoyer")}
            </button>
          </form>
        </div>
      )}

      <button
        type="button"
        onClick={() => setOpen((prev) => !prev)}
        aria-label={t("aiCopilot.toggle", "Assistant IA")}
        aria-expanded={open}
        className="flex h-14 w-14 items-center justify-center rounded-full bg-primary text-white shadow-card transition hover:bg-primary-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
      >
        <SparklesIcon className="h-6 w-6" />
      </button>
    </div>
  );
}
