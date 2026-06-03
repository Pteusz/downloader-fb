#!/usr/bin/env python3
"""
Scraper API
───────────
Microserviço HTTP que dispara o scraper e reporta progresso.

POST /run-scrape   body: {"label": "UCLWinners", "url": "https://..."}
GET  /status/{label}
"""
import json
import os
import re
import subprocess
import threading
from http.server import HTTPServer, BaseHTTPRequestHandler

DATA_DIR    = "/data"
COMPOSE_DIR = "/compose"
PORT        = 8001
LABEL_RE    = re.compile(r"^[a-zA-Z0-9_-]+$")


# ── helpers ──────────────────────────────────────────────────

def status_path(label: str) -> str:
    return os.path.join(DATA_DIR, f"status_{label}.json")


def write_status(label: str, data: dict) -> None:
    with open(status_path(label), "w", encoding="utf-8") as fh:
        json.dump(data, fh, ensure_ascii=False)


# ── worker ───────────────────────────────────────────────────

def run_scraper(label: str, url: str) -> None:
    """Executa o scraper em background e atualiza status_{label}.json."""
    write_status(label, {
        "status":  "running",
        "label":   label,
        "message": "Scraping iniciado...",
    })
    try:
        result = subprocess.run(
            [
                "docker", "compose",
                "-f", os.path.join(COMPOSE_DIR, "docker-compose.yml"),
                "run", "--rm", "scraper",
                "python", "runner.py",
                "--module", "squads",
                "--op",     "scrape",
                "--url",    url,
            ],
            capture_output=True,
            text=True,
            cwd=COMPOSE_DIR,
        )
        if result.returncode == 0:
            write_status(label, {
                "status":  "done",
                "label":   label,
                "message": "Concluído com sucesso",
            })
        else:
            snippet = (result.stderr or result.stdout or "erro desconhecido")[-600:]
            write_status(label, {
                "status":  "error",
                "label":   label,
                "message": snippet,
            })
    except Exception as exc:
        write_status(label, {
            "status":  "error",
            "label":   label,
            "message": str(exc),
        })


# ── handler ──────────────────────────────────────────────────

class Handler(BaseHTTPRequestHandler):

    # ── POST /run-scrape ─────────────────────────────────────
    def do_POST(self):
        if self.path != "/run-scrape":
            return self.reply(404, {"error": "not found"})

        try:
            n    = int(self.headers.get("Content-Length", 0))
            body = json.loads(self.rfile.read(n))
        except Exception:
            return self.reply(400, {"error": "JSON inválido"})

        label = (body.get("label") or "").strip()
        url   = (body.get("url")   or "").strip()

        if not label or not LABEL_RE.match(label):
            return self.reply(400, {"error": "label inválido"})
        if not url:
            return self.reply(400, {"error": "url obrigatória"})

        # Evita double-run
        sp = status_path(label)
        if os.path.exists(sp):
            with open(sp, encoding="utf-8") as fh:
                current = json.load(fh)
            if current.get("status") == "running":
                return self.reply(409, {"error": "já em execução", "current": current})

        threading.Thread(target=run_scraper, args=(label, url), daemon=True).start()
        self.reply(200, {"status": "started", "label": label})

    # ── GET /status/{label} ──────────────────────────────────
    def do_GET(self):
        if not self.path.startswith("/status/"):
            return self.reply(404, {"error": "not found"})

        label = self.path[len("/status/"):]
        if not label or not LABEL_RE.match(label):
            return self.reply(400, {"error": "label inválido"})

        sp = status_path(label)
        if os.path.exists(sp):
            with open(sp, encoding="utf-8") as fh:
                return self.reply(200, json.load(fh))

        self.reply(404, {"status": "not_started", "label": label})

    # ── OPTIONS (CORS preflight) ─────────────────────────────
    def do_OPTIONS(self):
        self.send_response(204)
        self._cors()
        self.end_headers()

    # ── utils ────────────────────────────────────────────────
    def reply(self, code: int, data: dict) -> None:
        body = json.dumps(data, ensure_ascii=False).encode()
        self.send_response(code)
        self.send_header("Content-Type",   "application/json")
        self.send_header("Content-Length", str(len(body)))
        self._cors()
        self.end_headers()
        self.wfile.write(body)

    def _cors(self) -> None:
        self.send_header("Access-Control-Allow-Origin",  "*")
        self.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "Content-Type")

    def log_message(self, *_):
        pass  # silencia logs de acesso


# ── entry point ──────────────────────────────────────────────
if __name__ == "__main__":
    print(f"[scraper-api] escutando :{PORT}")
    HTTPServer(("0.0.0.0", PORT), Handler).serve_forever()
