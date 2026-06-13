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

# Captura linhas como "[1/11] https://..." ou "[Fase 2] Buscando detalhes de 11 jogadores..."
_RE_PLAYER  = re.compile(r"\[(\d+)/(\d+)\]\s+https?://")
_RE_TOTAL   = re.compile(r"\[Fase 2\].*?(\d+)\s+jogadores")
_RE_NAME    = re.compile(r"Player:\s+(.+?)\s+\|")


# ── helpers ──────────────────────────────────────────────────

def status_path(label: str) -> str:
    return os.path.join(DATA_DIR, f"status_{label}.json")


def write_status(label: str, data: dict) -> None:
    with open(status_path(label), "w", encoding="utf-8") as fh:
        json.dump(data, fh, ensure_ascii=False)


# ── worker ───────────────────────────────────────────────────

def run_scraper(label: str, url: str) -> None:
    """Executa o scraper em background, lê stdout linha a linha e atualiza progresso."""
    write_status(label, {
        "status":  "running",
        "label":   label,
        "message": "Scraping iniciado...",
        "current": 0,
        "total":   0,
        "player":  "",
    })

    cmd = [
        "docker", "compose",
        "-f", os.path.join(COMPOSE_DIR, "docker-compose.yml"),
        "run", "--rm", "scraper",
        "python", "runner.py",
        "--module", "squads",
        "--op",     "scrape",
        "--url",    url,
        "--ajax",   "http://localhost",
        "--nonce",  "local",
    ]

    try:
        proc = subprocess.Popen(
            cmd,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            text=True,
            cwd=COMPOSE_DIR,
        )

        current = 0
        total   = 0
        player  = ""
        buf     = []

        for line in proc.stdout:
            buf.append(line)

            m_total = _RE_TOTAL.search(line)
            if m_total:
                total = int(m_total.group(1))

            m_player = _RE_PLAYER.search(line)
            if m_player:
                current = int(m_player.group(1))
                total   = total or int(m_player.group(2))

            m_name = _RE_NAME.search(line)
            if m_name:
                player = m_name.group(1).strip()

            if current or total:
                write_status(label, {
                    "status":  "running",
                    "label":   label,
                    "message": f"Buscando jogador {current} de {total}...",
                    "current": current,
                    "total":   total,
                    "player":  player,
                })

        proc.wait()

        if proc.returncode == 0:
            write_status(label, {
                "status":  "done",
                "label":   label,
                "message": "Concluído com sucesso",
                "current": total,
                "total":   total,
                "player":  player,
            })
        else:
            snippet = "".join(buf)[-600:]
            write_status(label, {
                "status":  "error",
                "label":   label,
                "message": snippet,
                "current": current,
                "total":   total,
                "player":  player,
            })

    except Exception as exc:
        write_status(label, {
            "status":  "error",
            "label":   label,
            "message": str(exc),
            "current": 0,
            "total":   0,
            "player":  "",
        })


# ── handler ──────────────────────────────────────────────────

class Handler(BaseHTTPRequestHandler):

    # ── POST /run-scrape | /refresh-squads ──────────────────
    def do_POST(self):
        if   self.path == "/run-scrape":
            self._handle_run_scrape()
        elif self.path == "/refresh-squads":
            self._handle_refresh_squads()
        else:
            return self.reply(404, {"error": "not found"})

    def _handle_refresh_squads(self):
        """Roda squads --op scan de forma síncrona e devolve o índice atualizado."""
        cmd = [
            "docker", "compose",
            "-f", os.path.join(COMPOSE_DIR, "docker-compose.yml"),
            "--project-name", "futbin-scraper",
            "run", "--rm", "scraper",
            "python", "runner.py",
            "--module", "squads",
            "--op",     "scan",
            "--url",    "https://www.futbin.com/squads",
        ]
        try:
            proc = subprocess.run(
                cmd, capture_output=True, text=True, timeout=90, cwd=COMPOSE_DIR,
            )
            if proc.returncode != 0:
                snippet = (proc.stdout + proc.stderr)[-600:]
                return self.reply(500, {"error": "scraper_failed", "detail": snippet})

            squads = []
            if os.path.exists(os.path.join(DATA_DIR, "squads_index.json")):
                with open(os.path.join(DATA_DIR, "squads_index.json"), encoding="utf-8") as fh:
                    squads = json.load(fh)

            self.reply(200, {"status": "ok", "squads": squads, "total": len(squads)})
        except subprocess.TimeoutExpired:
            self.reply(504, {"error": "timeout"})
        except Exception as exc:
            self.reply(500, {"error": str(exc)})

    def _handle_run_scrape(self):
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
